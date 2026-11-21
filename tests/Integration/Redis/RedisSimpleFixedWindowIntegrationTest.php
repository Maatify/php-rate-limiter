<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\FixedWindowSimpleRateLimiter;
use Maatify\RateLimiter\Service\SimpleRateLimitOperationalReader;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\NativeDomain;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\StateSnapshot;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\SharedCommon\Infrastructure\SystemClock;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Proves the fixed-window simple throttle capability against a real,
 * non-mocked Redis server: persistence, stable expiry, the allowed/denied
 * boundary, rotation migration, and raw-subject key privacy.
 *
 * RedisFullCapabilityStore judges budget-epoch validity at the caller's time
 * (#153 phase 2), while key lifetimes stay relative on the Redis clock, so
 * this suite uses a real SystemClock and short real waits for the
 * boundary/expiry scenarios instead of a FixedClock.
 */
#[Group('php32')]
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

    public function testUnrepresentableBoundaryIsRejectedBeforeRealRedisCreatesState(): void
    {
        $limiter = $this->limiter($this->clock, 'current-secret', null, PHP_INT_MAX);
        $keysBefore = $this->executor->execute(['KEYS', '*']);

        $this->expectException(\Maatify\RateLimiter\Exception\RateLimiterException::class);
        $this->expectExceptionMessage('reset boundary must be representable');
        try {
            $limiter->consume('checkout', 'subject-redis-overflow');
        } finally {
            self::assertSame($keysBefore, $this->executor->execute(['KEYS', '*']));
        }
    }

    public function testResetBoundaryRefusalPreservesPhysicallyPresentEndedWindows(): void
    {
        $now = 1_800_000_000;
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('@' . $now));
        $subject = 'ended-windows';
        foreach (['current-secret', 'previous-secret'] as $secret) {
            $key = $this->redisBudgetKey($secret, 2, PHP_INT_MAX, $subject);
            $this->writeBudgetFixture($key, $now - 60);
        }
        $this->executor->execute(['SET', 'sentinel', 'unrelated', 'EX', '600']);
        $before = StateSnapshot::all($this->executor);
        self::assertCount(3, $before);

        $limiter = $this->limiter($clock, 'current-secret', 'previous-secret', PHP_INT_MAX);
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Simple fixed-window reset boundary must be representable as a PHP integer.');
        try {
            $limiter->consume('checkout', $subject);
        } finally {
            self::assertSame($before, StateSnapshot::all($this->executor));
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function selectedCurrentWindows(): iterable
    {
        yield 'ordinary increment rejects an out-of-range selected window' => [false, false];
        yield 'seed increment rejects an out-of-range selected window' => [true, false];
        yield 'ordinary increment accepts the exact native limit' => [false, true];
        yield 'seed increment accepts the exact native limit' => [true, true];
    }

    #[DataProvider('selectedCurrentWindows')]
    public function testConsumeChecksTheWindowRedisActuallySelects(bool $seed, bool $fits): void
    {
        $now = 1_800_000_000;
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('@' . $now));
        $subject = 'selected-current';
        $currentKey = $this->redisBudgetKey('current-secret', 2, 60, $subject);
        $previousKey = $this->redisBudgetKey('previous-secret', 2, 60, $subject);
        if ($seed) {
            $this->writeBudgetFixture($previousKey, $now - 1);
        }
        $this->executor->execute(['SET', 'sentinel', 'unrelated', 'EX', '600']);
        $beforeMutation = null;
        $executor = $this->beforeBudgetWrite(function () use ($currentKey, $fits, &$beforeMutation): void {
            // A concurrent Current takes precedence over both an earlier miss and a Previous seed.
            self::assertNull($beforeMutation);
            $this->writeBudgetFixture($currentKey, PHP_INT_MAX - ($fits ? 60 : 59));
            $beforeMutation = StateSnapshot::all($this->executor);
        });
        $limiter = new FixedWindowSimpleRateLimiter(
            [new FixedWindowThrottlePolicy('checkout', 2, 60)],
            new RedisFullCapabilityStore($executor, 'integration:simple-throttle'),
            $clock,
            'current-secret',
            'prod',
            $seed ? 'previous-secret' : null,
        );

        if (! $fits) {
            $this->expectException(RateLimiterException::class);
            $this->expectExceptionMessage('Simple fixed-window reset boundary must be representable as a PHP integer.');
            try {
                $limiter->consume('checkout', $subject);
            } finally {
                self::assertNotNull($beforeMutation);
                self::assertSame($beforeMutation, StateSnapshot::all($this->executor));
            }

            return;
        }

        $result = $limiter->consume('checkout', $subject);
        self::assertNotNull($beforeMutation);
        self::assertTrue($result->allowed);
        self::assertSame(0, $result->remaining);
        self::assertSame(PHP_INT_MAX, $result->resetAt);
        self::assertSame('2', $this->executor->execute(['HGET', $currentKey, 'count']));
    }

    public function testConsumeChecksAFreshWindowWhenTheObservedWindowIsGone(): void
    {
        $now = 2_147_483_588;
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('@' . $now));
        $subject = 'fresh-after-expiry';
        $key = $this->redisBudgetKey('current-secret', 2, 60, $subject);
        $this->writeBudgetFixture($key, $now - 1);
        $this->executor->execute(['SET', 'sentinel', 'unrelated', 'EX', '600']);
        $sentinel = StateSnapshot::of($this->executor, 'sentinel');
        $executor = $this->beforeBudgetWrite(function () use ($key): void {
            // Model Redis expiry between the read and the write as a deterministic key removal.
            self::assertSame(1, $this->executor->execute(['DEL', $key]));
        });
        $limiter = new FixedWindowSimpleRateLimiter(
            [new FixedWindowThrottlePolicy('checkout', 2, 60)],
            new RedisFullCapabilityStore($executor, 'integration:simple-throttle'),
            $clock,
            'current-secret',
            'prod',
        );

        if (PHP_INT_SIZE === 4) {
            $this->expectException(RateLimiterException::class);
            $this->expectExceptionMessage('Simple fixed-window reset boundary must be representable as a PHP integer.');
            try {
                $limiter->consume('checkout', $subject);
            } finally {
                self::assertSame(['sentinel' => $sentinel], StateSnapshot::all($this->executor));
            }

            return;
        }

        $result = $limiter->consume('checkout', $subject);
        self::assertTrue($result->allowed);
        self::assertSame(1, $result->remaining);
        self::assertSame(2_147_483_648, $result->resetAt);
        self::assertSame('1', $this->executor->execute(['HGET', $key, 'count']));
        self::assertSame($sentinel, StateSnapshot::of($this->executor, 'sentinel'));
    }

    public function testBuilderConsumeUsesOneClockSampleForTheWindowAndRetryAfter(): void
    {
        $firstTime = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $secondTime = $firstTime->modify('+2 seconds');
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects($this->exactly(2))->method('now')->willReturn($firstTime, $secondTime);
        $runtime = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('current-secret', 'fingerprint-secret', 'prod'),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)
            ->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('checkout', 1, 60))
            ->build();

        $first = $runtime->consume('checkout', 'subject');
        $second = $runtime->consume('checkout', 'subject');
        $resetAt = $firstTime->getTimestamp() + 60;

        self::assertTrue($first->allowed);
        self::assertSame($resetAt, $first->resetAt);
        self::assertFalse($second->allowed);
        self::assertSame($resetAt, $second->resetAt);
        self::assertSame(58, $second->retryAfter);
    }

    public function testRedisLuaImpossibleExpiryFailsBeforeFreshBranchCreatesPartialState(): void
    {
        $interval = NativeDomain::int('9007199254740992', 'none: no native interval on a 32-bit build reaches the Lua exact-integer limit; the budget native-range rejections before any write are covered by CounterIncrementNativeRangeRedisTest.');
        $limiter = $this->limiter($this->clock, 'current-secret', null, $interval);
        $keysBefore = $this->executor->execute(['KEYS', '*']);

        // #185 F-185-21: an expiry the backend cannot represent is an input/contract failure the store reports
        // (RateLimiterException), not an operational outage, so it propagates instead of becoming FAIL_CLOSED.
        try {
            $limiter->consume('checkout', 'subject-redis-lua-overflow');
            self::fail('An unrepresentable expiry was not reported.');
        } catch (RateLimiterException) {
            self::addToAssertionCount(1);
        }
        self::assertSame($keysBefore, $this->executor->execute(['KEYS', '*']));
    }

    public function testRedisLuaImpossibleExpiryFailsBeforeSeedBranchCreatesCurrentAndPreservesPrevious(): void
    {
        $interval = NativeDomain::int('9007199254740992', 'none: no native interval on a 32-bit build reaches the Lua exact-integer limit; the budget native-range rejections before any write are covered by CounterIncrementNativeRangeRedisTest.');
        $subject = 'subject-redis-lua-seed-overflow';
        $previousKey = $this->redisBudgetKey('previous-secret', 2, $interval, $subject);
        $previousState = [
            'count', '1',
            'epochStart', (string) $this->clock->now()->getTimestamp(),
            'epochDuration', (string) $interval,
        ];
        $this->executor->execute(['HSET', $previousKey, ...$previousState]);
        $this->executor->execute(['EXPIRE', $previousKey, 60]);
        $previousRawValueBefore = $this->executor->execute(['HGETALL', $previousKey]);

        // #185 F-185-21: the store's input/contract failure propagates (see the fresh-branch test above).
        try {
            $this->limiter($this->clock, 'current-secret', 'previous-secret', $interval)->consume('checkout', $subject);
            self::fail('An unrepresentable expiry was not reported.');
        } catch (RateLimiterException) {
            self::addToAssertionCount(1);
        }
        self::assertSame([], $this->executor->execute(['HGETALL', $this->redisBudgetKey('current-secret', 2, $interval, $subject)]));
        self::assertSame($previousRawValueBefore, $this->executor->execute(['HGETALL', $previousKey]));
    }

    public function testLargeCurrentCountRemainsExactPastLuaDoublePrecision(): void
    {
        if (PHP_INT_SIZE < 8) {
            self::markTestSkipped('Outside the 32-bit public input domain: this exactness proof needs counts above 2^53, which a 32-bit int cannot hold. Covered on this width by: CounterIncrementNativeRangeRedisTest::testTheSimpleThrottleRejectsAConsumptionThatWouldPassTheRangeAndWritesNothing (the native-range rejection of the same consumer).');
        }

        $largeCost = 9007199254740993;
        $limiter = new FixedWindowSimpleRateLimiter(
            [new FixedWindowThrottlePolicy('large', 9007199254740995, 60)],
            $this->store,
            $this->clock,
            'current-secret',
            'prod',
            null,
        );

        $first = $limiter->consume('large', 'subject-redis-large-current', $largeCost);
        $second = $limiter->consume('large', 'subject-redis-large-current', 1);

        self::assertTrue($first->allowed);
        self::assertTrue($second->allowed);
        self::assertSame(1, $second->remaining);
        self::assertSame(9007199254740994, $this->largeReader('current-secret', null)->read('large', 'subject-redis-large-current')->count);
    }

    public function testLargePreviousCountMigratesExactlyAndPreviousRemainsUnchanged(): void
    {
        if (PHP_INT_SIZE < 8) {
            self::markTestSkipped('Outside the 32-bit public input domain: this exactness proof needs counts above 2^53, which a 32-bit int cannot hold. Covered on this width by: CounterIncrementNativeRangeRedisTest::testTheSimpleThrottleRejectsAConsumptionThatWouldPassTheRangeAndWritesNothing (the native-range rejection of the same consumer).');
        }

        $largeCost = 9007199254740993;
        $previousLimiter = new FixedWindowSimpleRateLimiter(
            [new FixedWindowThrottlePolicy('large', 9007199254740995, 60)],
            $this->store,
            $this->clock,
            'previous-secret',
            'prod',
            null,
        );
        self::assertTrue($previousLimiter->consume('large', 'subject-redis-large-rotation', $largeCost)->allowed);
        $keysBefore = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keysBefore);
        self::assertCount(1, $keysBefore);
        $previousKey = $keysBefore[0];
        self::assertIsString($previousKey);
        $previousValueBefore = $this->executor->execute(['HGETALL', $previousKey]);

        $rotatedLimiter = new FixedWindowSimpleRateLimiter(
            [new FixedWindowThrottlePolicy('large', 9007199254740995, 60)],
            $this->store,
            $this->clock,
            'current-secret',
            'prod',
            'previous-secret',
        );
        $migrated = $rotatedLimiter->consume('large', 'subject-redis-large-rotation', 1);

        self::assertTrue($migrated->allowed);
        self::assertSame(1, $migrated->remaining);
        self::assertSame(9007199254740994, $this->largeReader('current-secret', 'previous-secret')->read('large', 'subject-redis-large-rotation')->count);
        self::assertSame($previousValueBefore, $this->executor->execute(['HGETALL', $previousKey]));
    }

    public function testActiveCurrentIgnoresOverflowingSeed(): void
    {
        if (PHP_INT_SIZE < 8) {
            self::markTestSkipped('Outside the 32-bit public input domain: this exactness proof needs counts above 2^53, which a 32-bit int cannot hold. Covered on this width by: CounterIncrementNativeRangeRedisTest::testTheSimpleThrottleRejectsAConsumptionThatWouldPassTheRangeAndWritesNothing (the native-range rejection of the same consumer).');
        }

        $current = $this->store->incrementBudget('seed-overflow-current', 60, 1);
        $migrated = $this->store->incrementBudgetWithSeed(
            'seed-overflow-current',
            60,
            new BudgetStateDTO(PHP_INT_MAX, $current->epochStart),
            1,
        );

        self::assertSame(1, $current->count);
        self::assertSame(2, $migrated->count);
        self::assertSame($current->epochStart, $migrated->epochStart);
    }

    public function testExpiredSeedIgnoresOverflowingSumAndStartsFreshEpoch(): void
    {
        if (PHP_INT_SIZE < 8) {
            self::markTestSkipped('Outside the 32-bit public input domain: this exactness proof needs counts above 2^53, which a 32-bit int cannot hold. Covered on this width by: CounterIncrementNativeRangeRedisTest::testTheSimpleThrottleRejectsAConsumptionThatWouldPassTheRangeAndWritesNothing (the native-range rejection of the same consumer).');
        }

        $before = time();
        $fresh = $this->store->incrementBudgetWithSeed(
            'seed-overflow-expired',
            60,
            new BudgetStateDTO(PHP_INT_MAX, $before - 61),
            1,
        );

        self::assertSame(1, $fresh->count);
        self::assertGreaterThanOrEqual($before, $fresh->epochStart);
    }

    public function testActiveSeedOverflowFailsOnlyWhenActiveSeedBranchWinsWithoutPartialMutation(): void
    {
        if (PHP_INT_SIZE < 8) {
            self::markTestSkipped('Outside the 32-bit public input domain: this exactness proof needs counts above 2^53, which a 32-bit int cannot hold. Covered on this width by: CounterIncrementNativeRangeRedisTest::testTheSimpleThrottleRejectsAConsumptionThatWouldPassTheRangeAndWritesNothing (the native-range rejection of the same consumer).');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('seeded budget count overflow');
        try {
            $this->store->incrementBudgetWithSeed(
                'seed-overflow-active',
                60,
                new BudgetStateDTO(PHP_INT_MAX, time()),
                1,
            );
        } finally {
            self::assertSame([], $this->executor->execute(['KEYS', '*']));
        }
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

    /** @param \Closure(): void $beforeWrite */
    private function beforeBudgetWrite(\Closure $beforeWrite): RedisCommandExecutorInterface
    {
        return new class ($this->executor, $beforeWrite) implements RedisCommandExecutorInterface {
            /** @param \Closure(): void $beforeWrite */
            public function __construct(
                private readonly RedisCommandExecutorInterface $executor,
                private readonly \Closure $beforeWrite,
            ) {}

            public function execute(array $command): mixed
            {
                if ($command[0] === 'EVAL' && is_string($command[1] ?? null) && str_contains($command[1], "'HINCRBY'")) {
                    ($this->beforeWrite)();
                }

                return $this->executor->execute($command);
            }
        };
    }

    private function writeBudgetFixture(string $key, int $epochStart): void
    {
        self::assertSame(3, $this->executor->execute([
            'HSET', $key, 'count', '1', 'epochStart', (string) $epochStart, 'epochDuration', '60',
        ]));
        self::assertSame(1, $this->executor->execute(['EXPIRE', $key, '600']));
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

    private function largeReader(string $keySecret, ?string $previousKeySecret): SimpleRateLimitOperationalReader
    {
        return new SimpleRateLimitOperationalReader(
            [new FixedWindowThrottlePolicy('large', 9007199254740995, 60)],
            $this->store,
            $this->clock,
            $keySecret,
            'prod',
            $previousKeySecret,
        );
    }

    private function redisBudgetKey(string $secret, int $limit, int $intervalSeconds, string $subject): string
    {
        $encode = static fn(string $component): string => pack('N', strlen($component)) . $component;
        $logical = hash_hmac(
            'sha256',
            $encode('rate_limiter')
            . $encode('simple_fixed_window')
            . $encode('v1')
            . $encode('prod')
            . $encode('checkout')
            . $encode((string) $limit)
            . $encode((string) $intervalSeconds)
            . $encode($subject),
            $secret,
        );

        return 'maatify:rate-limiter:v1:'
            . hash('sha256', 'integration:simple-throttle')
            . ':budget:' . hash('sha256', $logical);
    }
}
