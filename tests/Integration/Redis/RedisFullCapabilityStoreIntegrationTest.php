<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\TestCase;

final class RedisFullCapabilityStoreIntegrationTest extends TestCase
{
    private RedisFullCapabilityStore $store;

    private RespRedisCommandExecutor $executor;

    private string $namespace = 'integration:test';

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
        $this->store = new RedisFullCapabilityStore($this->executor, $this->namespace);
    }

    public function testScoreBudgetCorrelationAndIsolationUseRealRedis(): void
    {
        self::assertTrue($this->store->isHealthy());
        self::assertSame(2, $this->store->increment('score', 60, 2));
        self::assertSame(5, $this->store->increment('score', 60, 3));
        self::assertSame(5, $this->store->get('score')?->value);
        $budget = $this->store->incrementBudget('budget', 60, 2);
        self::assertSame(2, $budget->count);
        $storedBudget = $this->store->getBudget('budget');
        self::assertNotNull($storedBudget);
        self::assertSame($budget->count, $storedBudget->count);
        self::assertSame($budget->epochStart, $storedBudget->epochStart);
        self::assertSame(1, $this->store->addDistinct('members', 'one', 60));
        self::assertSame(1, $this->store->incrementWatchFlag('watch', 60));
    }

    public function testEveryFullCapabilityOperationRoundTripsAgainstRealRedis(): void
    {
        $this->store->set('set-value', 7, 60);
        self::assertSame(7, $this->store->get('set-value')?->value);

        $this->store->block('blocked', 3, 60);
        self::assertSame(3, $this->store->checkBlock('blocked')?->level);

        $seed = new BudgetStateDTO(5, time() - 60);
        $budget = $this->store->incrementBudgetWithSeed('seeded-budget', 600, $seed, 2);
        self::assertSame(7, $budget->count);
        self::assertSame($seed->epochStart, $budget->epochStart);
        self::assertSame(7, $this->store->getBudget('seeded-budget')?->count);

        self::assertSame(1, $this->store->addDistinctAcrossRotation(
            'distinct-current',
            'distinct-bridge',
            'distinct-previous',
            'new-member',
            'unknown-previous-member',
            60,
        ));
        self::assertSame(1, $this->store->incrementWatchFlag('watch-previous', 60));
        self::assertSame(2, $this->store->incrementWatchFlagAcrossRotation('watch-current', 'watch-previous', 60));
        self::assertSame(1, $this->store->getWatchFlag('watch-current'));
    }

    public function testPublicBuilderWorkflowUsesTheOfficialRedisAggregateStore(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $limiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('redis-key-secret', 'redis-fingerprint-secret', 'prod'),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->build();
        $context = new RateLimitContextDTO(
            '198.51.100.20',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'redis-public-workflow',
            ['device' => 'integration'],
        );

        $login = $limiter->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $otp = $limiter->limit($context, RateLimitCommand::recordFailure('otp_protection'));
        $api = $limiter->limit($context, new RateLimitCommand('api_heavy_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $login->decision);
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $otp->decision);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $api->decision);
    }

    public function testConcurrentIncrementsAreNotLost(): void
    {
        $this->runConcurrentWorkers(12, function (int $index): void {
            $this->workerStore()->increment('concurrent-increment', 600);
        });

        self::assertSame(12, $this->store->get('concurrent-increment')?->value);
    }

    public function testConcurrentBudgetSeedInitializationKeepsOneEpochAndAllIncrements(): void
    {
        $seed = new BudgetStateDTO(5, time() - 60);
        $this->runConcurrentWorkers(12, function (int $index) use ($seed): void {
            $this->workerStore()->incrementBudgetWithSeed('concurrent-budget', 600, $seed);
        });

        $budget = $this->store->getBudget('concurrent-budget');
        self::assertNotNull($budget);
        self::assertSame(17, $budget->count);
        self::assertSame($seed->epochStart, $budget->epochStart);
    }

    public function testConcurrentRotatedAdmissionsNeverExceedEffectiveCapacity(): void
    {
        $this->store->addDistinct('concurrent-previous', 'old-member', 600);
        $this->runConcurrentWorkers(12, function (int $index): void {
            $this->workerStore()->addDistinctBoundedWithSnapshotAcrossRotation(
                'concurrent-current',
                'concurrent-bridge',
                'concurrent-previous',
                'member-' . $index,
                'unknown-previous-' . $index,
                600,
                4,
            );
        });

        $snapshot = $this->store->addDistinctBoundedWithSnapshotAcrossRotation(
            'concurrent-current',
            'concurrent-bridge',
            'concurrent-previous',
            'observer',
            'unknown-observer',
            600,
            4,
        );
        self::assertFalse($snapshot->accepted);
        self::assertSame(4, $snapshot->count);
        self::assertCount(3, $this->rawMembers('concurrent-bridge'));
    }

    public function testConcurrentProbeLeaseHasExactlyOneWinner(): void
    {
        $now = time();
        $this->runConcurrentWorkers(12, function (int $index) use ($now): void {
            $store = $this->workerStore();
            if ($store->acquireProbeLease('concurrent-policy', $now, 60)) {
                $store->increment('probe-winners', 600);
            }
        });

        self::assertSame(1, $this->store->get('probe-winners')?->value);
        self::assertFalse($this->store->acquireProbeLease('concurrent-policy', $now + 1, 60));
    }

    public function testConcurrentHardBlockCycleTransitionActivatesOnePause(): void
    {
        $base = time();
        $this->store->blockWithCycleTracking('concurrent-hard-block', null, 2, 5, $base, 21600, 2, 600, 86400);
        $this->runConcurrentWorkers(12, function (int $index) use ($base): void {
            $store = $this->workerStore();
            $result = $store->blockWithCycleTracking('concurrent-hard-block', null, 2, 100, $base + 10, 21600, 2, 600, 86400);
            if ($result->pauseActivated) {
                $store->increment('pause-activation-winners', 600);
            }
        });

        self::assertSame(1, $this->store->get('pause-activation-winners')?->value);
        $pause = $this->store->readDecayPauseState('concurrent-hard-block', null, $base, $base + 10);
        self::assertSame($base + 610, $pause->activePauseUntil);
    }

    public function testBoundedSnapshotAndCircuitStateRoundTrip(): void
    {
        $first = $this->store->addDistinctBoundedWithSnapshot('bounded', 'one', 60, 2);
        self::assertSame(['one'], $first->members);
        self::assertTrue($this->store->addDistinctBoundedWithSnapshot('bounded', 'two', 60, 2)->added);
        self::assertFalse($this->store->addDistinctBoundedWithSnapshot('bounded', 'three', 60, 2)->accepted);
    }

    public function testRotatedBoundedStateUsesCapacityAndEstablishesBothFixedTtls(): void
    {
        self::assertSame(1, $this->store->addDistinct('previous', 'old', 60));
        $previousExpiry = $this->integer($this->raw(['HGET', $this->key('distinct-meta', 'previous'), 'expiresAt']));

        $snapshot = $this->store->addDistinctBoundedWithSnapshotAcrossRotation(
            'current',
            'bridge',
            'previous',
            'new',
            'new-old-alias',
            60,
            2,
        );

        self::assertSame(2, $snapshot->count);
        self::assertTrue($snapshot->accepted);
        self::assertTrue($snapshot->added);
        self::assertSame(['old', 'new'], $snapshot->members);
        self::assertGreaterThan(0, $this->integer($this->raw(['TTL', $this->key('distinct', 'current')])));
        self::assertGreaterThan(0, $this->integer($this->raw(['TTL', $this->key('distinct', 'bridge')])));
        self::assertLessThanOrEqual($previousExpiry, $this->integer($this->raw(['HGET', $this->key('distinct-meta', 'bridge'), 'expiresAt'])));
        self::assertSame($previousExpiry, $this->integer($this->raw(['HGET', $this->key('distinct-meta', 'previous'), 'expiresAt'])));
    }

    public function testRotatedBoundedStateRejectsNewMemberAtCapacityWithoutGrowth(): void
    {
        $this->store->addDistinct('previous', 'old-one', 60);
        $this->store->addDistinct('previous', 'old-two', 60);

        $rejected = $this->store->addDistinctBoundedWithSnapshotAcrossRotation(
            'current',
            'bridge',
            'previous',
            'new',
            'not-known',
            60,
            2,
        );

        self::assertFalse($rejected->accepted);
        self::assertFalse($rejected->added);
        self::assertSame(2, $rejected->count);
        self::assertSame(['old-one', 'old-two'], $rejected->members);
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('distinct', 'bridge')])));
    }

    public function testPreviousPauseIsAdoptedAndReadAcrossRotation(): void
    {
        $base = time();
        $this->hardBlock('previous', $base, 10);
        $this->hardBlock('previous', $base + 10, 10);

        $state = $this->store->readDecayPauseState('current', 'previous', $base, $base + 20);

        self::assertSame(10, $state->elapsedPausedSeconds);
        self::assertSame($base + 610, $state->activePauseUntil);
    }

    public function testPreviousCompletedPauseRemainsVisibleToLazyDecayUntilExactRetentionBoundary(): void
    {
        $base = time();
        $this->hardBlock('previous', $base, 10);
        $this->hardBlock('previous', $base + 10, 10);

        $retained = $this->store->readDecayPauseState('current', 'previous', $base, $base + 610 + 86400 - 1);
        self::assertSame(600, $retained->elapsedPausedSeconds);

        $expired = $this->store->readDecayPauseState('current', 'previous', $base, $base + 610 + 86400);
        self::assertSame(0, $expired->elapsedPausedSeconds);
    }

    public function testActivePreviousPausePreventsCurrentPauseRenewalAndAdoptionIsStable(): void
    {
        $base = time();
        $this->hardBlock('previous', $base, 10);
        $this->hardBlock('previous', $base + 10, 10);

        $first = $this->hardBlock('current', $base + 20, 10, 'previous');
        self::assertFalse($first->pauseActivated);
        self::assertSame($base + 610, $first->pauseUntil);

        $second = $this->hardBlock('current', $base + 30, 10, 'previous');
        self::assertFalse($second->pauseActivated);
        self::assertSame($base + 610, $second->pauseUntil);

        $currentOnly = $this->store->readDecayPauseState('current', null, $base, $base + 30);
        self::assertSame($base + 610, $currentOnly->activePauseUntil);
    }

    public function testCompleteCircuitStateRoundTripAndProbeLeaseBoundary(): void
    {
        $state = new CircuitBreakerStateDTO('OPEN', [10, 20], 20, 20, 30, [40], 50);
        $this->store->save('policy', $state);
        $loaded = $this->store->load('policy');
        self::assertNotNull($loaded);
        self::assertSame($state->jsonSerialize(), $loaded->jsonSerialize());
        self::assertTrue($this->store->acquireProbeLease('policy', 100, 10));
        self::assertFalse($this->store->acquireProbeLease('policy', 109, 10));
        self::assertTrue($this->store->acquireProbeLease('policy', 110, 10));
    }

    private function hardBlock(string $currentKey, int $now, int $duration, ?string $previousKey = null): HardBlockCycleResultDTO
    {
        return $this->store->blockWithCycleTracking(
            $currentKey,
            $previousKey,
            2,
            $duration,
            $now,
            21600,
            2,
            600,
            86400,
        );
    }

    /** @param non-empty-list<int|string|float> $command */
    private function raw(array $command): mixed
    {
        return $this->executor->execute($command);
    }

    /**
     * @param callable(int): void $worker
     */
    private function runConcurrentWorkers(int $workerCount, callable $worker): void
    {
        $pids = [];
        for ($index = 0; $index < $workerCount; $index++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Unable to fork Redis concurrency worker.');
            }
            if ($pid === 0) {
                try {
                    $worker($index);
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            $status = 0;
            self::assertSame($pid, pcntl_waitpid($pid, $status));
            if (! is_int($status)) {
                self::fail('Redis concurrency worker status was malformed.');
            }
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
    }

    private function workerStore(): RedisFullCapabilityStore
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $portValue = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $portValue === false) {
            throw new \RuntimeException('Redis integration environment is missing.');
        }

        return new RedisFullCapabilityStore(
            new RespRedisCommandExecutor($host, (int) $portValue),
            $this->namespace,
        );
    }

    /**
     * @return list<string>
     */
    private function rawMembers(string $logicalKey): array
    {
        $members = $this->raw(['SMEMBERS', $this->key('distinct', $logicalKey)]);
        self::assertIsArray($members);
        /** @var list<mixed> $members */
        $members = array_values($members);
        $result = [];
        foreach ($members as $member) {
            self::assertIsString($member);
            $result[] = $member;
        }

        return $result;
    }

    private function key(string $family, string $logical): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', $this->namespace)
            . ':' . $family . ':' . hash('sha256', $logical);
    }

    private function integer(mixed $value): int
    {
        self::assertTrue(is_int($value) || is_string($value) || is_float($value));

        return (int) $value;
    }
}
