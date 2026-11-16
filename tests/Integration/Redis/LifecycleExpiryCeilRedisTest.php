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
 * #246: the logical expiry of a fresh generation-bound score is `ceil((nowMilliseconds + ttl * 1000) / 1000)`. The script used to add a rounding offset of 999
 * to a sum that is already close to 2^53 - 1, which pushed the numerator out of Lua's exact integer range near the top of the declared clock domain and
 * returned (and stored) an expiry one whole second too late, even where the exact sum was a whole second.
 *
 * The answers below are written as independent decimal constants (never recomputed with the formula under test). The same fixed clocks run on both widths:
 * a 64-bit worker must answer and PERSIST the exact value; a 32-bit worker cannot hold those seconds in a native `int` and must reject the call before any
 * write (#242), never skip. Contemporary clocks must succeed on both. Every success is judged on what Redis persisted (the logical fields and the exact
 * physical expiry inside the Redis TIME before/after interval, direct and after `transactRequest()`); every refusal on the byte-exact DUMP and exact
 * PEXPIRETIME of EVERY key, a foreign control included.
 *
 * The retained-expiry path never evaluates this formula; the existing #244 protection (LifecycleTtlPreflightRedisTest: matched Current, Previous and legacy
 * sources) covers it for TTL and remainder, and `testARetainedExpiryIsNotRecomputed` here pins it at this very boundary.
 */
#[Group('integration')]
#[Group('php32')]
#[Group('lifecycle-expiry-ceil')]
final class LifecycleExpiryCeilRedisTest extends TestCase
{
    private const NAMESPACE = 'lifecycle-ceil';

    private const NOT_REPRESENTABLE = 'Generation-bound score expiry is not representable.';

    private RespRedisCommandExecutor $executor;

    private RedisFullCapabilityStore $store;

    private string $beforeMs = '0';

    private string $afterMs = '0';

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

    private function claim(): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':reentry-claim:' . hash('sha256', 'k');
    }

    private function redisMs(): string
    {
        $time = $this->executor->execute(['TIME']);
        self::assertIsArray($time);
        self::assertIsString($time[0]);
        self::assertIsString($time[1]);

        return ExactDecimal::add(ExactDecimal::thousand($time[0]), (string) intdiv((int) $time[1], 1000));
    }

    private function mutate(?GenerationBoundScoreStateDTO $expected, int $ttl, bool $overlay, string $ms): GenerationBoundScoreMutationDTO
    {
        $call = fn(): GenerationBoundScoreMutationDTO => $this->store->mutateGenerationBoundScoreAtMilliseconds('k', null, $expected, $ttl, 9, $ms);
        $this->beforeMs = $this->redisMs();
        $result = $overlay ? $this->store->transactRequest($call) : $call();
        $this->afterMs = $this->redisMs();

        return $result;
    }

    /** What Redis holds for Current: the fields, no evidence, no marker, and the exact physical lifetime inside the Redis TIME interval. */
    private function assertPersisted(string $updatedAt, string $generation, string $expiresAt, int $ttlSeconds, string $label): void
    {
        $key = $this->score();
        self::assertSame('9', $this->executor->execute(['HGET', $key, 'value']), $label);
        self::assertSame($updatedAt, $this->executor->execute(['HGET', $key, 'updatedAt']), $label . ': updatedAt');
        self::assertSame($generation, $this->executor->execute(['HGET', $key, 'generation']), $label . ': generation');
        self::assertSame($expiresAt, $this->executor->execute(['HGET', $key, 'expiresAt']), $label . ': the persisted logical expiry');
        foreach (['reentryId', 'reentryValidUntil', 'reentryGeneration'] as $field) {
            self::assertSame(0, $this->executor->execute(['HEXISTS', $key, $field]), $label . ': ' . $field . ' is dropped.');
        }
        self::assertSame(0, $this->executor->execute(['EXISTS', $this->claim()]), $label . ': the claim marker is dropped.');
        $expiry = $this->executor->execute(['PEXPIRETIME', $key]);
        self::assertTrue(is_int($expiry) || is_string($expiry));
        $expiry = ExactDecimal::fromReply($expiry);
        $lifetime = $ttlSeconds . '000';
        self::assertGreaterThanOrEqual(0, ExactDecimal::compare($expiry, ExactDecimal::add($this->beforeMs, $lifetime)), $label . ': lives at least the TTL.');
        self::assertLessThanOrEqual(0, ExactDecimal::compare($expiry, ExactDecimal::add($this->afterMs, $lifetime)), $label . ': lives no longer than the TTL.');
        self::assertSame('kept', $this->executor->execute(['GET', 'unrelated-control']));
    }

    /**
     * Clock (decimal ms), TTL (s), the exact updatedAt second, the exact expiry second, and whether the caller's native `int` can hold them everywhere.
     *
     * @return array<string, array{string, int, string, string, bool}>
     */
    public static function clocks(): array
    {
        return [
            // The three clocks of #246: 1 ms before, exactly at, and 1 ms after the whole second 9007199254739 s.
            'one ms before the whole second' => ['9007199254738999', 1, '9007199254738', '9007199254740', false],
            'exactly on the whole second (the defect)' => ['9007199254739000', 1, '9007199254739', '9007199254740', false],
            'one ms after the whole second' => ['9007199254739001', 1, '9007199254739', '9007199254741', false],
            // A fraction of a second inside the range, with a longer TTL.
            'a half second, two seconds' => ['9007199254737500', 2, '9007199254737', '9007199254740', false],
            // The sum reaches exactly 2^53 - 1 ms.
            'the sum is exactly 2^53 - 1' => ['9007199254739991', 1, '9007199254739', '9007199254741', false],
            // Contemporary clocks: representable on every width.
            'contemporary, a whole second' => ['1800000000000', 1, '1800000000', '1800000001', true],
            'contemporary, one ms into the second' => ['1800000000001', 1, '1800000000', '1800000002', true],
            'contemporary, 999 ms into the second' => ['1800000000999', 1, '1800000000', '1800000002', true],
            'contemporary, the next whole second, two seconds' => ['1800000001000', 2, '1800000001', '1800000003', true],
        ];
    }

    /** @return array<string, array{string, int, string, string, bool, bool}> */
    public static function clocksAndPaths(): array
    {
        $cases = [];
        foreach (self::clocks() as $label => [$ms, $ttl, $updatedAt, $expiresAt, $native]) {
            foreach (['direct' => false, 'overlay' => true] as $path => $overlay) {
                $cases[$label . ' / ' . $path] = [$ms, $ttl, $updatedAt, $expiresAt, $native, $overlay];
            }
        }

        return $cases;
    }

    /** The exact expiry is answered and persisted where the caller's int holds it; otherwise the call is rejected before any write. */
    #[DataProvider('clocksAndPaths')]
    public function testTheLogicalExpiryIsTheExactCeilOfTheSumInMilliseconds(string $ms, int $ttl, string $updatedAt, string $expiresAt, bool $native, bool $overlay): void
    {
        if (PHP_INT_SIZE < 8 && ! $native) {
            $before = StateSnapshot::all($this->executor);
            try {
                $this->mutate(null, $ttl, $overlay, $ms);
                self::fail('Seconds above 2^31 - 1 cannot be held by a 32-bit int.');
            } catch (RateLimiterException $exception) {
                self::assertStringContainsString('exceeds the caller native integer range', $exception->getMessage());
            }
            self::assertSame($before, StateSnapshot::all($this->executor), 'Rejected before any write: every key, its bytes and its exact expiry are unchanged.');

            return;
        }

        $result = $this->mutate(null, $ttl, $overlay, $ms);

        self::assertTrue($result->applied);
        self::assertSame(1, $result->state?->generation);
        self::assertSame($expiresAt, (string) $result->state->expiresAt, 'The answered expiry.');
        self::assertSame($updatedAt, (string) $result->state->updatedAt, 'The answered updatedAt.');
        $this->assertPersisted($updatedAt, '1', $expiresAt, $ttl, $ms);
    }

    /** One millisecond above the exact range (2^53 - 1) the expiry is not representable on any width: refused before any write. */
    #[DataProvider('overlayPaths')]
    public function testAStepAboveTheExactRangeIsRefusedBeforeAnyWrite(bool $overlay): void
    {
        $before = StateSnapshot::all($this->executor);
        try {
            $this->mutate(null, 1, $overlay, '9007199254739992');
            self::fail('A sum of 2^53 ms does not fit.');
        } catch (RateLimiterException $exception) {
            self::assertSame(self::NOT_REPRESENTABLE, $exception->getMessage());
        }
        self::assertSame($before, StateSnapshot::all($this->executor));
    }

    /** @return array<string, array{bool}> */
    public static function overlayPaths(): array
    {
        return ['direct' => [false], 'overlay' => [true]];
    }

    /** A logically expired Current (evidence and claim marker) is overwritten with the exact expiry at the defect's clock; the control stays. */
    #[DataProvider('overlayPaths')]
    public function testAnExpiredCurrentIsOverwrittenWithTheExactExpiry(bool $overlay): void
    {
        $this->executor->execute(['HSET', $this->score(), 'value', '8', 'updatedAt', '1799999000', 'generation', '3', 'expiresAt', '1799999990', 'reentryId', str_repeat('c', 32), 'reentryValidUntil', '1799999990', 'reentryGeneration', '3']);
        $this->executor->execute(['EXPIRE', $this->score(), '600']);
        $this->executor->execute(['SET', $this->claim(), 'marker', 'EX', '600']);
        $control = StateSnapshot::of($this->executor, 'unrelated-control');

        if (PHP_INT_SIZE < 8) {
            $before = StateSnapshot::all($this->executor);
            try {
                $this->mutate(null, 1, $overlay, '9007199254739000');
                self::fail('Seconds above 2^31 - 1 cannot be held by a 32-bit int.');
            } catch (RateLimiterException $exception) {
                self::assertStringContainsString('exceeds the caller native integer range', $exception->getMessage());
            }
            self::assertSame($before, StateSnapshot::all($this->executor), 'Current, its evidence, the claim marker and the control are unchanged, with their exact expiries.');

            return;
        }

        $result = $this->mutate(null, 1, $overlay, '9007199254739000');

        self::assertTrue($result->applied);
        self::assertSame('9007199254740', (string) $result->state?->expiresAt);
        $this->assertPersisted('9007199254739', '1', '9007199254740', 1, 'overwrite');
        self::assertSame($control, StateSnapshot::of($this->executor, 'unrelated-control'));
    }

    /** 64-bit only (the snapshot's seconds are above a 32-bit int): a matched source keeps its stored expiry exactly; the formula is never applied to it. */
    #[DataProvider('overlayPaths')]
    public function testARetainedExpiryIsNotRecomputed(bool $overlay): void
    {
        if (PHP_INT_SIZE < 8) {
            self::markTestSkipped('Outside the 32-bit public input domain: the matched snapshot\'s seconds (9007199254740) are above 2^31 - 1, so no such DTO exists. Covered on this width by LifecycleTtlPreflightRedisTest::testAMatchedLiveSourceKeepsItsRetainedExpiryAndLifeWhateverTheRequestedTtl.');
        }
        $this->executor->execute(['HSET', $this->score(), 'value', '8', 'updatedAt', '9007199254738', 'generation', '1', 'expiresAt', '9007199254740']);
        $this->executor->execute(['EXPIRE', $this->score(), '600']);
        $expected = new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, 8, 9_007_199_254_738, 9_007_199_254_740, 1);

        $result = $this->mutate($expected, 1, $overlay, '9007199254739000');

        self::assertTrue($result->applied);
        self::assertSame(2, $result->state?->generation);
        self::assertSame('9007199254740', (string) $result->state->expiresAt, 'The stored expiry, not a recomputed 9007199254741.');
        self::assertSame('9007199254740', $this->executor->execute(['HGET', $this->score(), 'expiresAt']));
        $expiry = $this->executor->execute(['PEXPIRETIME', $this->score()]);
        self::assertTrue(is_int($expiry) || is_string($expiry));
        self::assertGreaterThanOrEqual(0, ExactDecimal::compare(ExactDecimal::fromReply($expiry), ExactDecimal::add($this->beforeMs, '1000')), 'Remaining logical life: 9007199254740000 - 9007199254739000 ms.');
        self::assertLessThanOrEqual(0, ExactDecimal::compare(ExactDecimal::fromReply($expiry), ExactDecimal::add($this->afterMs, '1000')));
    }
}
