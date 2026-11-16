<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\ExactDecimal;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\StateSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #244: the requested TTL of a generation-bound mutation is judged only where it decides something. A fresh score's expiry is the caller's second plus
 * that TTL, so it is judged atomically in the script on the exact decimal clock (and on the caller's native range, #242); it is NOT bounded by a native
 * millisecond product (`ttl * 1000` above a 32-bit `int`), and a matched live source that already supplies the retained expiry never reads the requested
 * TTL for its retained lifetime (the script still parses it for positivity). The same fixed inputs are used on both widths: a 32-bit worker must answer exactly
 * what a 64-bit one does wherever the answer fits its `int`.
 *
 * Every success is judged on what Redis PERSISTED, not only on the reply, on the direct primitive AND after `transactRequest()` has committed: the value,
 * `updatedAt`, generation and logical expiry, the removal of lifecycle evidence and claim marker, and the exact absolute physical expiry (`PEXPIRETIME`, read
 * outside Lua) inside the interval [Redis TIME before + lifetime, Redis TIME after + lifetime] — no sleep, no tolerance. A fresh score lives `ttl` seconds;
 * a retained one lives its logical remainder (`expiresAt - now`), never the requested TTL. Every refusal and stale control is judged on the byte-exact DUMP
 * and exact PEXPIRETIME of EVERY key, a foreign control included.
 *
 * Reachability limit: the public K4/K4P runtime passes a fixed TTL of 86400 s to this primitive (EvaluationPipeline), so a TTL of 2147484 s or more is
 * reachable only through the store's own public method; the existing public K4/K4P protection (CallerTimeDecimalMillisecondsRedisTest) is kept as it is.
 */
#[Group('integration')]
#[Group('php32')]
#[Group('lifecycle-ttl')]
final class LifecycleTtlPreflightRedisTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const NOW_MS = '1800000000000';

    private const NOW_SECONDS = '1800000000';

    private const NAMESPACE = 'lifecycle-ttl';

    private const NOT_REPRESENTABLE = 'Generation-bound score expiry is not representable.';

    private RespRedisCommandExecutor $executor;

    private RedisFullCapabilityStore $store;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->executor = new RespRedisCommandExecutor($host, (int) $port);
        $this->executor->execute(['FLUSHDB']);
        $this->store = new RedisFullCapabilityStore($this->executor, self::NAMESPACE);
        $this->executor->execute(['SET', 'unrelated-control', 'kept', 'EX', '5000']);
    }

    private function score(): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':score:' . hash('sha256', 'k');
    }

    private function seedRetained(): GenerationBoundScoreStateDTO
    {
        $this->executor->execute(['HSET', $this->score(), 'value', '8', 'updatedAt', (string) self::NOW, 'generation', '1', 'expiresAt', (string) (self::NOW + 601)]);
        $this->executor->execute(['EXPIRE', $this->score(), '600']);

        return new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, 8, self::NOW, self::NOW + 601, 1);
    }

    private string $beforeMs = '0';

    private string $afterMs = '0';

    /** Redis TIME as decimal epoch milliseconds (whole seconds and the floor of the microseconds' milliseconds). */
    private function redisMs(): string
    {
        $time = $this->executor->execute(['TIME']);
        self::assertIsArray($time);
        self::assertIsString($time[0]);
        self::assertIsString($time[1]);

        return ExactDecimal::add(ExactDecimal::thousand($time[0]), (string) intdiv((int) $time[1], 1000));
    }

    private function mutate(?GenerationBoundScoreStateDTO $expected, int $ttl, bool $overlay, string $ms = self::NOW_MS, ?string $previous = null, int $newValue = 9): GenerationBoundScoreMutationDTO
    {
        $call = fn(): GenerationBoundScoreMutationDTO => $this->store->mutateGenerationBoundScoreAtMilliseconds('k', $previous, $expected, $ttl, $newValue, $ms);
        $this->beforeMs = $this->redisMs();
        $result = $overlay ? $this->store->transactRequest($call) : $call();
        $this->afterMs = $this->redisMs();

        return $result;
    }

    /** The absolute physical expiry of `$key` lies in [before + lifetime, after + lifetime] (Redis's own clock around the call), exactly. */
    private function assertLifetime(string $key, string $lifetimeMilliseconds, string $label): void
    {
        $expiry = $this->executor->execute(['PEXPIRETIME', $key]);
        self::assertTrue(is_int($expiry) || is_string($expiry));
        $expiry = ExactDecimal::fromReply($expiry);
        self::assertGreaterThanOrEqual(0, ExactDecimal::compare($expiry, ExactDecimal::add($this->beforeMs, $lifetimeMilliseconds)), $label . ': lives at least its lifetime.');
        self::assertLessThanOrEqual(0, ExactDecimal::compare($expiry, ExactDecimal::add($this->afterMs, $lifetimeMilliseconds)), $label . ': lives no longer than its lifetime.');
    }

    /** What Redis holds for Current after an applied call: fields, no evidence, no claim marker, and the exact physical lifetime. */
    private function assertPersistedCurrent(string $generation, string $expiresAt, string $lifetimeMilliseconds, string $updatedAt = self::NOW_SECONDS, string $label = 'Current'): void
    {
        $key = $this->score();
        self::assertSame('9', $this->executor->execute(['HGET', $key, 'value']), $label);
        self::assertSame($updatedAt, $this->executor->execute(['HGET', $key, 'updatedAt']), $label);
        self::assertSame($generation, $this->executor->execute(['HGET', $key, 'generation']), $label);
        self::assertSame($expiresAt, $this->executor->execute(['HGET', $key, 'expiresAt']), $label);
        foreach (['reentryId', 'reentryValidUntil', 'reentryGeneration'] as $field) {
            self::assertSame(0, $this->executor->execute(['HEXISTS', $key, $field]), $label . ': ' . $field . ' is dropped.');
        }
        self::assertSame(0, $this->executor->execute(['EXISTS', $this->claim()]), $label . ': the claim marker is dropped.');
        $this->assertLifetime($key, $lifetimeMilliseconds, $label);
    }

    private function claim(): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':reentry-claim:' . hash('sha256', 'k');
    }

    private function other(string $logical): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':score:' . hash('sha256', $logical);
    }

    /** The foreign control key is byte- and millisecond-identical. */
    private function assertControlUntouched(string $control): void
    {
        self::assertSame($control, StateSnapshot::of($this->executor, 'unrelated-control'), 'The foreign control key is untouched.');
    }

    private function seedExpiredCurrentWithEvidence(): void
    {
        $this->executor->execute(['HSET', $this->score(), 'value', '8', 'updatedAt', '1799999000', 'generation', '3', 'expiresAt', '1799999990', 'reentryId', str_repeat('c', 32), 'reentryValidUntil', '1799999990', 'reentryGeneration', '3']);
        $this->executor->execute(['EXPIRE', $this->score(), '600']);
        $this->executor->execute(['SET', $this->claim(), 'marker', 'EX', '600']);
    }

    /** @return array<string, array{int, bool}> */
    public static function ttlsAndPaths(): array
    {
        $cases = [];
        foreach ([2_147_483, 2_147_484, 3_000_000] as $ttl) {
            foreach (['direct' => false, 'overlay' => true] as $path => $overlay) {
                $cases['ttl ' . $ttl . ' / ' . $path] = [$ttl, $overlay];
            }
        }

        return $cases;
    }

    /** @return array<string, array{bool}> */
    public static function overlayPaths(): array
    {
        return ['direct' => [false], 'overlay' => [true]];
    }

    /** A fresh score whose expiry (1.80e9 + TTL) fits a 32-bit `int`: applied and persisted for the whole requested TTL, on both widths. */
    #[DataProvider('ttlsAndPaths')]
    public function testAFreshScoreWithARepresentableExpiryIsAppliedAndLivesTheRequestedTtlOnEveryWidth(int $ttl, bool $overlay): void
    {
        $result = $this->mutate(null, $ttl, $overlay);

        self::assertTrue($result->applied);
        self::assertSame(1, $result->state?->generation);
        self::assertSame(self::NOW + $ttl, $result->state->expiresAt);
        $this->assertPersistedCurrent('1', (string) (self::NOW + $ttl), $ttl . '000', label: 'fresh');
    }

    /** A matched live source keeps its own expiry and its own remaining life; the requested TTL does not renew or replace either. */
    #[DataProvider('ttlsAndPaths')]
    public function testAMatchedLiveSourceKeepsItsRetainedExpiryAndLifeWhateverTheRequestedTtl(int $ttl, bool $overlay): void
    {
        $state = $this->seedRetained();

        $result = $this->mutate($state, $ttl, $overlay);

        self::assertTrue($result->applied);
        self::assertSame(2, $result->state?->generation);
        self::assertSame(self::NOW + 601, $result->state->expiresAt, 'The retained expiry, not now + the requested TTL.');
        $this->assertPersistedCurrent('2', (string) (self::NOW + 601), '601000', label: 'retained');
    }

    /** A matched Previous (Current physically absent) with a large unused TTL: Current is created with the retained expiry and life; Previous and the control stay. */
    #[DataProvider('overlayPaths')]
    public function testAMatchedPreviousWithALargeUnusedTtlKeepsItsRetainedExpiryAndLeavesPreviousUntouched(bool $overlay): void
    {
        $previous = $this->other('prev');
        $this->executor->execute(['HSET', $previous, 'value', '8', 'updatedAt', (string) self::NOW, 'generation', '1', 'expiresAt', (string) (self::NOW + 601)]);
        $this->executor->execute(['EXPIRE', $previous, '600']);
        $expected = new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_PREVIOUS, 8, self::NOW, self::NOW + 601, 1);
        $previousBefore = StateSnapshot::of($this->executor, $previous);
        $control = StateSnapshot::of($this->executor, 'unrelated-control');

        $result = $this->mutate($expected, 3_000_000, $overlay, previous: 'prev');

        self::assertTrue($result->applied);
        self::assertSame(2, $result->state?->generation);
        self::assertSame(self::NOW + 601, $result->state->expiresAt);
        $this->assertPersistedCurrent('2', (string) (self::NOW + 601), '601000', label: 'previous source');
        self::assertSame($previousBefore, StateSnapshot::of($this->executor, $previous), 'Previous is read-only: bytes and exact expiry are unchanged.');
        $this->assertControlUntouched($control);
    }

    /** A legacy source (no stored expiry) is fenced by value and updatedAt; the snapshot's expiry is the retained one, whatever the unused TTL. */
    #[DataProvider('overlayPaths')]
    public function testALegacySourceKeepsTheSnapshotExpiryAndLifeWhateverTheRequestedTtl(bool $overlay): void
    {
        $this->executor->execute(['HSET', $this->score(), 'value', '8', 'updatedAt', (string) self::NOW]);
        $this->executor->execute(['EXPIRE', $this->score(), '600']);
        $expected = new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, 8, self::NOW, self::NOW + 601, null);
        $control = StateSnapshot::of($this->executor, 'unrelated-control');

        $result = $this->mutate($expected, 3_000_000, $overlay);

        self::assertTrue($result->applied);
        self::assertSame(1, $result->state?->generation);
        self::assertSame(self::NOW + 601, $result->state->expiresAt);
        $this->assertPersistedCurrent('1', (string) (self::NOW + 601), '601000', label: 'legacy source');
        $this->assertControlUntouched($control);
    }

    /** A snapshot of a source that is not there is the ordinary unapplied answer, whatever the TTL, and writes nothing. */
    #[DataProvider('overlayPaths')]
    public function testASnapshotOfAMissingSourceWithALargeTtlIsUnappliedAndWritesNothing(bool $overlay): void
    {
        $missing = new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, 8, self::NOW, self::NOW + 601, 1);
        $before = StateSnapshot::all($this->executor);

        $result = $this->mutate($missing, 3_000_000, $overlay);

        self::assertFalse($result->applied);
        self::assertSame($before, StateSnapshot::all($this->executor));
    }

    /** A logically expired Current (with evidence and a claim marker) is overwritten as a fresh epoch that lives the long TTL; the control stays. */
    #[DataProvider('overlayPaths')]
    public function testAnExpiredCurrentIsOverwrittenAsAFreshEpochForTheLongTtl(bool $overlay): void
    {
        $this->seedExpiredCurrentWithEvidence();
        $control = StateSnapshot::of($this->executor, 'unrelated-control');

        $result = $this->mutate(null, 3_000_000, $overlay);

        self::assertTrue($result->applied);
        self::assertSame(1, $result->state?->generation);
        self::assertSame(self::NOW + 3_000_000, $result->state->expiresAt);
        $this->assertPersistedCurrent('1', (string) (self::NOW + 3_000_000), '3000000000', label: 'overwrite');
        $this->assertControlUntouched($control);
    }

    /** A true Lua-bound refusal over an expired Current (evidence, marker) happens before any part of the overwrite. */
    #[DataProvider('overlayPaths')]
    public function testARealRefusalOverAnExpiredCurrentChangesNothing(bool $overlay): void
    {
        $this->seedExpiredCurrentWithEvidence();
        $before = StateSnapshot::all($this->executor);
        try {
            $this->mutate(null, 1, $overlay, '9007199254740000');
            self::fail('One second of TTL does not fit below 2^53 - 1 ms.');
        } catch (RateLimiterException $exception) {
            self::assertSame(self::NOT_REPRESENTABLE, $exception->getMessage());
        }
        self::assertSame($before, StateSnapshot::all($this->executor), 'Current, its evidence, the claim marker and the control are unchanged, with their exact expiries.');
    }

    /** The native boundary reached with a long TTL: 2147483647 is answered on every width, 2147483648 only where the caller's int holds it. */
    #[DataProvider('overlayPaths')]
    public function testANativeExpiryReachedWithALongTtlIsAnsweredAtTheMaximumAndRejectedBeyondItBefore32BitWrites(bool $overlay): void
    {
        $atMaximum = $this->mutate(null, 3_000_000, $overlay, '2144483647000');
        self::assertTrue($atMaximum->applied);
        self::assertSame(2_147_483_647, $atMaximum->state?->expiresAt);
        $this->assertPersistedCurrent('1', '2147483647', '3000000000', '2144483647', 'at the native maximum');

        $this->executor->execute(['FLUSHDB']);
        $this->executor->execute(['SET', 'unrelated-control', 'kept', 'EX', '5000']);
        if (PHP_INT_SIZE < 8) {
            $before = StateSnapshot::all($this->executor);
            try {
                $this->mutate(null, 3_000_000, $overlay, '2144483648000');
                self::fail('Expiry 2147483648 cannot be held by a 32-bit int.');
            } catch (RateLimiterException $exception) {
                self::assertStringContainsString('exceeds the caller native integer range', $exception->getMessage());
            }
            self::assertSame($before, StateSnapshot::all($this->executor), 'Rejected before any write: every key, its bytes and its exact expiry are unchanged.');

            return;
        }
        $beyond = $this->mutate(null, 3_000_000, $overlay, '2144483648000');
        self::assertTrue($beyond->applied);
        self::assertSame(2_147_483_648, $beyond->state?->expiresAt);
        $this->assertPersistedCurrent('1', '2147483648', '3000000000', '2144483648', 'beyond the native maximum');
    }

    /** The largest native TTL is unused by a matched source; on a fresh score it is the #242 native-range rejection on 32-bit and an answer on 64-bit. */
    #[DataProvider('overlayPaths')]
    public function testTheLargestNativeTtlIsIgnoredByAMatchedSourceAndRangeCheckedOnAFreshScore(bool $overlay): void
    {
        $state = $this->seedRetained();
        $retained = $this->mutate($state, 2_147_483_647, $overlay);
        self::assertTrue($retained->applied);
        self::assertSame(self::NOW + 601, $retained->state?->expiresAt);
        $this->assertPersistedCurrent('2', (string) (self::NOW + 601), '601000', label: 'retained, largest TTL');

        $this->executor->execute(['FLUSHDB']);
        $this->executor->execute(['SET', 'unrelated-control', 'kept', 'EX', '5000']);
        $before = StateSnapshot::all($this->executor);
        if (PHP_INT_SIZE < 8) {
            try {
                $this->mutate(null, 2_147_483_647, $overlay);
                self::fail('A fresh expiry of 3947483647 cannot be held by a 32-bit int.');
            } catch (RateLimiterException $exception) {
                self::assertStringContainsString('exceeds the caller native integer range', $exception->getMessage());
            }
            self::assertSame($before, StateSnapshot::all($this->executor), 'Rejected before any write: every key, its bytes and its exact expiry are unchanged.');

            return;
        }
        $fresh = $this->mutate(null, 2_147_483_647, $overlay);
        self::assertTrue($fresh->applied);
        self::assertSame(3_947_483_647, $fresh->state?->expiresAt);
        $this->assertPersistedCurrent('1', '3947483647', '2147483647000', label: 'fresh, largest TTL');
    }

    /** A stale snapshot is the ordinary unapplied answer whatever the TTL, and writes nothing. */
    #[DataProvider('overlayPaths')]
    public function testAStaleSnapshotWithALargeTtlIsUnappliedAndWritesNothing(bool $overlay): void
    {
        $this->seedRetained();
        $stale = new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, 7, self::NOW, self::NOW + 601, 1);
        $before = StateSnapshot::all($this->executor);

        $result = $this->mutate($stale, 3_000_000, $overlay);

        self::assertFalse($result->applied);
        self::assertSame($before, StateSnapshot::all($this->executor));
    }

    /** A genuinely unrepresentable fresh expiry (the caller clock leaves less than the TTL's milliseconds below 2^53) is still refused, before any write, on both widths. */
    #[DataProvider('overlayPaths')]
    public function testARealLuaBoundRefusalStillHoldsOnEveryWidth(bool $overlay): void
    {
        $before = StateSnapshot::all($this->executor);
        try {
            $this->mutate(null, 1, $overlay, '9007199254740000');
            self::fail('One second of TTL does not fit below 2^53 - 1 ms.');
        } catch (RateLimiterException $exception) {
            self::assertSame(self::NOT_REPRESENTABLE, $exception->getMessage());
        }
        self::assertSame($before, StateSnapshot::all($this->executor));
    }

    /** A non-positive TTL is still a parameter error and a malformed source is still a state error, whatever a large TTL would otherwise allow; neither writes. */
    #[DataProvider('overlayPaths')]
    public function testPositiveTtlAndMalformedStateAreStillValidatedBeforeAnyWrite(bool $overlay): void
    {
        $before = StateSnapshot::all($this->executor);
        try {
            $this->mutate(null, 0, $overlay);
            self::fail('A zero TTL must be rejected.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('must be positive', $exception->getMessage());
        }
        self::assertSame($before, StateSnapshot::all($this->executor));

        $this->executor->execute(['HSET', $this->score(), 'value', '8', 'updatedAt', (string) self::NOW, 'generation', 'x', 'expiresAt', (string) (self::NOW + 601)]);
        $this->executor->execute(['EXPIRE', $this->score(), '600']);
        $before = StateSnapshot::all($this->executor);
        try {
            $this->mutate(null, 3_000_000, $overlay);
            self::fail('A malformed generation must be rejected, not overwritten.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('alformed', $exception->getMessage());
        }
        self::assertSame($before, StateSnapshot::all($this->executor), 'Malformed state is not repaired: every key, its bytes and its exact expiry.');
    }

    /** 64-bit only: a TTL beyond 2^53 ms is refused on a fresh score and unused by a matched source. */
    #[DataProvider('overlayPaths')]
    public function testATtlBeyondTheExactRangeIsRefusedOnAFreshScoreAndIgnoredByAMatchedSource(bool $overlay): void
    {
        if (PHP_INT_SIZE < 8) {
            self::markTestSkipped('Outside the 32-bit public input domain: a TTL of 9007199254741 s is above 2^31 - 1. Covered on this width by testARealLuaBoundRefusalStillHoldsOnEveryWidth (the same script bound, reached through the clock) and testTheLargestNativeTtlIsIgnoredByAMatchedSourceAndRangeCheckedOnAFreshScore.');
        }
        $before = StateSnapshot::all($this->executor);
        try {
            $this->mutate(null, 9_007_199_254_741, $overlay);
            self::fail('A TTL past 2^53 ms must be refused.');
        } catch (RateLimiterException $exception) {
            self::assertSame(self::NOT_REPRESENTABLE, $exception->getMessage());
        }
        self::assertSame($before, StateSnapshot::all($this->executor));

        $retained = $this->mutate($this->seedRetained(), 9_007_199_254_741, $overlay);
        self::assertTrue($retained->applied);
        self::assertSame(self::NOW + 601, $retained->state?->expiresAt);
        $this->assertPersistedCurrent('2', (string) (self::NOW + 601), '601000', label: 'retained, TTL beyond 2^53 ms');
    }
}
