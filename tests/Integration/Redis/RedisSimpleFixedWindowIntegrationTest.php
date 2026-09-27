<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use DateTimeZone;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\FixedWindowSimpleRateLimiter;
use Maatify\RateLimiter\Service\SimpleRateLimitOperationalReader;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\SharedCommon\Infrastructure\SystemClock;
use PHPUnit\Framework\TestCase;

/**
 * Proves the fixed-window simple throttle capability against a real,
 * non-mocked Redis server: persistence, stable expiry, the allowed/denied
 * boundary, rotation migration, and raw-subject key privacy.
 *
 * RedisFullCapabilityStore resolves budget-epoch validity from the Redis
 * server's own real-time clock (Lua TIME), not from an injected
 * ClockInterface, so this suite uses a real SystemClock and short real waits
 * for the boundary/expiry scenarios instead of a FixedClock.
 */
final class RedisSimpleFixedWindowIntegrationTest extends TestCase
{
    private RedisFullCapabilityStore $store;

    private RespRedisCommandExecutor $executor;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $portValue = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $portValue === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $port = (int) $portValue;
        $this->executor = new RespRedisCommandExecutor($host, $port);
        $this->executor->execute(['FLUSHDB']);
        $this->store = new RedisFullCapabilityStore($this->executor, 'integration:simple-throttle');
        $this->clock = new SystemClock(new DateTimeZone('UTC'));
    }

    public function testFixedWindowPersistsAndDeniesPastTheLimitWithStableExpiry(): void
    {
        $limiter = $this->limiter($this->clock, 'current-secret', null, 60);

        $first = $limiter->consume('checkout', 'subject-redis-1');
        $second = $limiter->consume('checkout', 'subject-redis-1');
        $third = $limiter->consume('checkout', 'subject-redis-1');

        self::assertTrue($first->allowed);
        self::assertTrue($second->allowed);
        self::assertFalse($third->allowed, 'A consume beyond the limit must be denied.');
        self::assertSame($first->resetAt, $second->resetAt);
        self::assertSame($first->resetAt, $third->resetAt, 'A denied consume must not renew or extend the window.');
        self::assertGreaterThan(0, $third->retryAfter ?? 0);
        self::assertLessThanOrEqual(60, $third->retryAfter ?? 0);
    }

    public function testWeightedConsumesAccumulateAndOperationalReadReportsThePersistedCount(): void
    {
        $limiter = new FixedWindowSimpleRateLimiter(
            [new FixedWindowThrottlePolicy('weighted', 10, 60)],
            $this->store,
            $this->clock,
            'current-secret',
            'prod',
            null,
        );

        $first = $limiter->consume('weighted', 'subject-redis-weighted', 2);
        $second = $limiter->consume('weighted', 'subject-redis-weighted', 3);
        $denied = $limiter->consume('weighted', 'subject-redis-weighted', 6);

        self::assertTrue($first->allowed);
        self::assertSame(8, $first->remaining);
        self::assertTrue($second->allowed);
        self::assertSame(5, $second->remaining);
        self::assertFalse($denied->allowed);
        self::assertSame(0, $denied->remaining);
        self::assertSame($first->resetAt, $denied->resetAt);

        $snapshot = (new SimpleRateLimitOperationalReader(
            [new FixedWindowThrottlePolicy('weighted', 10, 60)],
            $this->store,
            $this->clock,
            'current-secret',
            'prod',
            null,
        ))->read('weighted', 'subject-redis-weighted');

        self::assertSame(11, $snapshot->count);
        self::assertSame(0, $snapshot->remaining);
        self::assertSame($denied->resetAt, $snapshot->resetAt);
    }

    public function testDeniedConsumeDoesNotRenewExpiryAndANewEpochStartsAfterRealExpiry(): void
    {
        $limiter = new FixedWindowSimpleRateLimiter(
            [new FixedWindowThrottlePolicy('checkout', 1, 2)],
            $this->store,
            $this->clock,
            'current-secret',
            'prod',
            null,
        );

        $first = $limiter->consume('checkout', 'subject-redis-2');
        self::assertTrue($first->allowed);

        $denied = $limiter->consume('checkout', 'subject-redis-2');
        self::assertFalse($denied->allowed, 'The second consume of a limit-1 window must be denied.');
        self::assertSame($first->resetAt, $denied->resetAt, 'A denied consume must not renew the expiry.');

        sleep(3);

        $newEpoch = $limiter->consume('checkout', 'subject-redis-2');
        self::assertTrue($newEpoch->allowed, 'A consume after the real fixed-window expiry must start a new epoch.');
        self::assertGreaterThan($first->resetAt, $newEpoch->resetAt);
    }

    public function testCurrentPreviousMigrationSeedsAtomicallyAndPreviousStaysReadOnly(): void
    {
        $previousLimiter = $this->limiter($this->clock, 'previous-secret', null, 60);
        $previousLimiter->consume('checkout', 'subject-redis-3');

        $keysBeforeMigration = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keysBeforeMigration);
        self::assertCount(1, $keysBeforeMigration);
        $previousRawKey = $keysBeforeMigration[0];
        self::assertIsString($previousRawKey);
        $previousRawValueBefore = $this->executor->execute(['HGETALL', $previousRawKey]);

        $rotatedLimiter = $this->limiter($this->clock, 'current-secret', 'previous-secret', 60);
        $migrated = $rotatedLimiter->consume('checkout', 'subject-redis-3');

        self::assertTrue($migrated->allowed);
        self::assertSame(0, $migrated->remaining, 'Current must be seeded with previous count(1) + 1 = 2, exhausting the limit-2 window.');

        $previousRawValueAfter = $this->executor->execute(['HGETALL', $previousRawKey]);
        self::assertSame($previousRawValueBefore, $previousRawValueAfter, 'Previous state must be byte-identical after migration.');

        $keysAfterMigration = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keysAfterMigration);
        self::assertCount(2, $keysAfterMigration, 'Migration must create a distinct Current key, not overwrite Previous.');
    }

    public function testRawSubjectDoesNotAppearInAnyPersistedRedisKey(): void
    {
        $limiter = $this->limiter($this->clock, 'current-secret', null, 60);
        $limiter->consume('checkout', 'redis-raw-subject-marker-value');

        $keys = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        self::assertNotEmpty($keys);
        foreach ($keys as $key) {
            self::assertIsString($key);
            self::assertStringNotContainsString('redis-raw-subject-marker-value', $key);
        }
    }

    /**
     * Proves the Operational Read side of DEC-012 against a real,
     * non-mocked Redis server: it observes the exact persisted state a real
     * consume() wrote, without itself adding a consume or creating any
     * additional Redis key, and never exposes the raw subject.
     */
    public function testOperationalReadObservesExactPersistedStateWithoutMutating(): void
    {
        $limiter = $this->limiter($this->clock, 'current-secret', null, 60);
        $first = $limiter->consume('checkout', 'subject-redis-op-1');
        self::assertTrue($first->allowed);
        $second = $limiter->consume('checkout', 'subject-redis-op-1');
        self::assertTrue($second->allowed);

        $keysAfterConsume = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keysAfterConsume);
        self::assertCount(1, $keysAfterConsume, 'Two consumes for the same subject must persist exactly one Current key.');
        $currentRawKey = $keysAfterConsume[0];
        self::assertIsString($currentRawKey);
        $currentRawValueBefore = $this->executor->execute(['HGETALL', $currentRawKey]);

        $reader = $this->reader($this->clock, 'current-secret', null, 60);
        $snapshot = $reader->read('checkout', 'subject-redis-op-1');

        self::assertSame(2, $snapshot->count);
        self::assertSame(0, $snapshot->remaining, 'limit=2, count=2 → remaining clamped to 0.');
        self::assertSame($second->resetAt, $snapshot->resetAt);
        self::assertFalse($snapshot->fromPreviousGeneration);
        self::assertNotNull($snapshot->epochStart);
        self::assertSame($snapshot->epochStart + 60, $snapshot->resetAt);
        self::assertSame($second->resetAt - 60, $snapshot->epochStart);

        $currentRawValueAfter = $this->executor->execute(['HGETALL', $currentRawKey]);
        self::assertSame($currentRawValueBefore, $currentRawValueAfter, 'The operational read must preserve the Current value byte-for-byte.');

        $keysAfterRead = $this->executor->execute(['KEYS', '*']);
        self::assertSame($keysAfterConsume, $keysAfterRead, 'A read must not create any additional Redis key.');

        $thirdAfterRead = $limiter->consume('checkout', 'subject-redis-op-1');
        self::assertFalse($thirdAfterRead->allowed, 'The read must not have added a consume that shifts the quota.');

        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('subject-redis-op-1', $json);
        self::assertStringNotContainsString('current-secret', $json);
        foreach ($keysAfterRead as $key) {
            self::assertIsString($key);
            self::assertStringNotContainsString('subject-redis-op-1', $key);
        }
    }

    /**
     * Previous-only state must be reported with fromPreviousGeneration=true,
     * Previous's raw persisted value must stay byte-identical, and reading
     * it must never create a Current key.
     */
    public function testOperationalReadReportsPreviousOnlyStateWithoutCreatingCurrent(): void
    {
        $previousLimiter = $this->limiter($this->clock, 'previous-secret', null, 60);
        $previousLimiter->consume('checkout', 'subject-redis-op-2');

        $keysBeforeRead = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keysBeforeRead);
        self::assertCount(1, $keysBeforeRead);
        $previousRawKey = $keysBeforeRead[0];
        self::assertIsString($previousRawKey);
        $previousRawValueBefore = $this->executor->execute(['HGETALL', $previousRawKey]);

        $reader = $this->reader($this->clock, 'current-secret', 'previous-secret', 60);
        $snapshot = $reader->read('checkout', 'subject-redis-op-2');

        self::assertSame(1, $snapshot->count);
        self::assertTrue($snapshot->fromPreviousGeneration);

        $previousRawValueAfter = $this->executor->execute(['HGETALL', $previousRawKey]);
        self::assertSame($previousRawValueBefore, $previousRawValueAfter, 'Previous must stay byte-identical after a read.');

        $keysAfterRead = $this->executor->execute(['KEYS', '*']);
        self::assertSame($keysBeforeRead, $keysAfterRead, 'A Previous-only read must not create a Current key.');
    }

    /**
     * When both Current and Previous exist, Current must win outright: no
     * max()/sum() merge of the two counts.
     */
    public function testOperationalReadPrefersCurrentOverPreviousWithoutMerging(): void
    {
        $previousLimiter = $this->limiter($this->clock, 'previous-secret', null, 60);
        $previousLimiter->consume('checkout', 'subject-redis-op-3');
        $previousLimiter->consume('checkout', 'subject-redis-op-3');

        $currentLimiter = $this->limiter($this->clock, 'current-secret', null, 60);
        $currentLimiter->consume('checkout', 'subject-redis-op-3');

        $reader = $this->reader($this->clock, 'current-secret', 'previous-secret', 60);
        $snapshot = $reader->read('checkout', 'subject-redis-op-3');

        self::assertSame(1, $snapshot->count, 'Current(1) must win over Previous(2); it must never use max() or sum() merging.');
        self::assertFalse($snapshot->fromPreviousGeneration);
    }

    private function limiter(
        ClockInterface $clock,
        string $keySecret,
        ?string $previousKeySecret,
        int $intervalSeconds,
    ): FixedWindowSimpleRateLimiter {
        return new FixedWindowSimpleRateLimiter(
            [new FixedWindowThrottlePolicy('checkout', 2, $intervalSeconds)],
            $this->store,
            $clock,
            $keySecret,
            'prod',
            $previousKeySecret,
        );
    }

    private function reader(
        ClockInterface $clock,
        string $keySecret,
        ?string $previousKeySecret,
        int $intervalSeconds,
    ): SimpleRateLimitOperationalReader {
        return new SimpleRateLimitOperationalReader(
            [new FixedWindowThrottlePolicy('checkout', 2, $intervalSeconds)],
            $this->store,
            $clock,
            $keySecret,
            'prod',
            $previousKeySecret,
        );
    }
}
