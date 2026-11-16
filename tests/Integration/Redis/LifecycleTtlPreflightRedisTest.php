<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\StateSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #244: the requested TTL of a generation-bound mutation is judged only where it decides something. A fresh score's expiry is the caller's second plus
 * that TTL, so it is judged atomically in the script on the exact decimal clock (and on the caller's native range, #242); it is NOT bounded by a native
 * millisecond product (`ttl * 1000` above a 32-bit `int`), and a matched live source that already supplies the retained expiry never reads the requested
 * TTL at all. The same fixed inputs are used on both widths: a 32-bit worker must answer exactly what a 64-bit one does wherever the answer fits its `int`.
 */
#[Group('integration')]
#[Group('php32')]
#[Group('lifecycle-ttl')]
final class LifecycleTtlPreflightRedisTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const NOW_MS = '1800000000000';

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

    private function mutate(?GenerationBoundScoreStateDTO $expected, int $ttl, bool $overlay, string $ms = self::NOW_MS): GenerationBoundScoreMutationDTO
    {
        $call = fn(): GenerationBoundScoreMutationDTO => $this->store->mutateGenerationBoundScoreAtMilliseconds('k', null, $expected, $ttl, 9, $ms);

        return $overlay ? $this->store->transactRequest($call) : $call();
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

    /** A fresh score whose expiry (1.80e9 + TTL) fits a 32-bit `int`: applied on both widths, however long the TTL's milliseconds are. */
    #[DataProvider('ttlsAndPaths')]
    public function testAFreshScoreWithARepresentableExpiryIsAppliedOnEveryWidth(int $ttl, bool $overlay): void
    {
        $result = $this->mutate(null, $ttl, $overlay);

        self::assertTrue($result->applied);
        self::assertSame(1, $result->state?->generation);
        self::assertSame(self::NOW + $ttl, $result->state->expiresAt);
        if (! $overlay) {
            self::assertSame((string) (self::NOW + $ttl), $this->executor->execute(['HGET', $this->score(), 'expiresAt']));
        }
    }

    /** A matched live source keeps its own expiry; the requested TTL is never read, whatever its size. */
    #[DataProvider('ttlsAndPaths')]
    public function testAMatchedLiveSourceKeepsItsRetainedExpiryAndIgnoresTheRequestedTtl(int $ttl, bool $overlay): void
    {
        $state = $this->seedRetained();

        $result = $this->mutate($state, $ttl, $overlay);

        self::assertTrue($result->applied);
        self::assertSame(2, $result->state?->generation);
        self::assertSame(self::NOW + 601, $result->state->expiresAt, 'The retained expiry, not now + the requested TTL.');
        if (! $overlay) {
            self::assertSame((string) (self::NOW + 601), $this->executor->execute(['HGET', $this->score(), 'expiresAt']));
        }
    }

    /** The largest native TTL is also unused by a matched source; on a fresh score it is the #242 native-range rejection on 32-bit and an answer on 64-bit. */
    #[DataProvider('overlayPaths')]
    public function testTheLargestNativeTtlIsIgnoredByAMatchedSourceAndRangeCheckedOnAFreshScore(bool $overlay): void
    {
        $state = $this->seedRetained();
        $retained = $this->mutate($state, 2_147_483_647, $overlay);
        self::assertTrue($retained->applied);
        self::assertSame(self::NOW + 601, $retained->state?->expiresAt);

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
    }

    /** @return array<string, array{bool}> */
    public static function overlayPaths(): array
    {
        return ['direct' => [false], 'overlay' => [true]];
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

    /** 64-bit only: a TTL whose milliseconds exceed 2^53 is refused on a fresh score and unused by a matched source. */
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
    }
}
