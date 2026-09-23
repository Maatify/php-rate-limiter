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
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
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

    public function testPublicBuilderWorkflowUsesOfficialRedisRotationShapes(): void
    {
        $shapes = [
            ['new-outer', 'stable-fingerprint', 'old-outer', null],
            ['stable-outer', 'new-fingerprint', null, 'old-fingerprint'],
            ['new-outer-both', 'new-fingerprint-both', 'old-outer-both', 'old-fingerprint-both'],
        ];

        foreach ($shapes as $index => [$outer, $fingerprint, $previousOuter, $previousFingerprint]) {
            $account = 'redis-public-rotation-' . $index;
            $oldLimiter = RateLimiterBuilder::fromFullCapabilityStore(
                new RateLimiterConfig(
                    $previousOuter ?? $outer,
                    $previousFingerprint ?? $fingerprint,
                    'prod',
                ),
                $this->store,
                new RecordingFailureSignalEmitter(),
            )->build();
            for ($device = 1; $device <= 3; $device++) {
                $oldLimiter->limit($this->deviceContext($account, $device), RateLimitCommand::checkOnly('login_protection'));
            }

            $currentLimiter = RateLimiterBuilder::fromFullCapabilityStore(
                new RateLimiterConfig($outer, $fingerprint, 'prod', $previousOuter, $previousFingerprint),
                $this->store,
                new RecordingFailureSignalEmitter(),
            )->build();
            $rotated = $currentLimiter->limit(
                $this->deviceContext($account, 4),
                RateLimitCommand::checkOnly('login_protection'),
            );

            self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $rotated->decision);
            self::assertSame(2, $rotated->blockLevel);
        }
    }

    public function testPublicBuilderWorkflowUsesOfficialRedisBudgetAndCircuitProbePaths(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $limiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('redis-public-key', 'redis-public-fingerprint', 'prod'),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->build();
        $context = new RateLimitContextDTO(
            '198.51.100.30',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'redis-public-budget',
            ['device' => 'budget'],
        );

        self::assertNotSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $limiter->limit($context, RateLimitCommand::recordFailure('login_protection'))->decision);
        self::assertNotSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $limiter->limit($context, RateLimitCommand::recordFailure('login_protection'))->decision);

        $openedAt = $clock->now()->getTimestamp();
        $this->store->save('api_heavy_protection', new CircuitBreakerStateDTO('OPEN', [$openedAt, $openedAt, $openedAt], $openedAt, $openedAt, 0, [$openedAt], 0));
        $clock->setNow(new \DateTimeImmutable('@' . ($openedAt + 300)));
        $probe = $limiter->limit($context, new RateLimitCommand('api_heavy_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $probe->decision);
        self::assertSame('DEGRADED_MODE', $probe->failureMode);
        self::assertGreaterThan(0, $this->integer($this->raw(['TTL', $this->key('probe', 'api_heavy_protection')])));
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

    public function testCurrentCycleTtlExtendsToTheLatestCycleAndWindow(): void
    {
        $base = time();
        $this->store->blockWithCycleTracking('retention-cycle', null, 2, 1, $base, 60, 10, 30, 120);
        $cycleKey = $this->key('cycle', 'retention-cycle');
        $this->raw(['EXPIRE', $cycleKey, 1]);

        $this->store->blockWithCycleTracking('retention-cycle', null, 2, 1, $base + 10, 60, 10, 30, 120);

        self::assertGreaterThanOrEqual(50, $this->integer($this->raw(['TTL', $cycleKey])));
    }

    public function testFirstPauseTtlCoversPauseDurationAndRetention(): void
    {
        $base = time();
        $result = $this->store->blockWithCycleTracking('retention-pause', null, 2, 1, $base, 60, 1, 60, 120);

        self::assertTrue($result->pauseActivated);
        self::assertGreaterThanOrEqual(170, $this->integer($this->raw(['TTL', $this->key('pause', 'retention-pause')])));
    }

    public function testLaterRetainedPauseExtendsCurrentPauseTtlWithoutTouchingPreviousTtl(): void
    {
        $base = time();
        $this->store->blockWithCycleTracking('retention-pauses', null, 2, 1, $base, 21600, 1, 60, 120);
        $pauseKey = $this->key('pause', 'retention-pauses');
        $this->raw(['EXPIRE', $pauseKey, 1]);

        $later = $this->store->blockWithCycleTracking('retention-pauses', null, 2, 1, $base + 70, 21600, 2, 60, 120);

        self::assertTrue($later->pauseActivated);
        self::assertGreaterThanOrEqual(170, $this->integer($this->raw(['TTL', $pauseKey])));
    }

    public function testPreviousHistoryTtlIsReadOnlyDuringAdoption(): void
    {
        $base = time();
        $this->store->blockWithCycleTracking('retention-previous', null, 2, 1, $base, 60, 1, 60, 120);
        $previousCycleKey = $this->key('cycle', 'retention-previous');
        $this->raw(['EXPIRE', $previousCycleKey, 30]);
        $before = $this->integer($this->raw(['TTL', $previousCycleKey]));

        $this->store->blockWithCycleTracking('retention-current', 'retention-previous', 2, 1, $base + 1, 60, 10, 60, 120);

        $after = $this->integer($this->raw(['TTL', $previousCycleKey]));
        self::assertLessThanOrEqual($before, $after);
        self::assertGreaterThanOrEqual($before - 1, $after);
    }

    public function testMalformedPersistedScoreBlockBudgetAndCorrelationStateFailsExplicitly(): void
    {
        $scoreKey = $this->key('score', 'malformed-score');
        $this->raw(['HSET', $scoreKey, 'value', 'not-an-integer', 'updatedAt', '1']);
        $this->raw(['EXPIRE', $scoreKey, 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->get('malformed-score'));
        $this->raw(['HSET', $scoreKey, 'value', '1', 'updatedAt', '1.5']);
        $this->assertOperationFails(fn(): mixed => $this->store->increment('malformed-score', 60));

        $scoreWithoutTtl = $this->key('score', 'malformed-score-without-ttl');
        $this->raw(['HSET', $scoreWithoutTtl, 'value', '1', 'updatedAt', '1']);
        $this->assertOperationFails(fn(): mixed => $this->store->increment('malformed-score-without-ttl', 60));

        $blockKey = $this->key('block', 'malformed-block');
        $this->raw(['HSET', $blockKey, 'level', '2.5', 'expiresAt', (string) (time() + 60)]);
        $this->raw(['EXPIRE', $blockKey, 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->checkBlock('malformed-block'));

        $budgetKey = $this->key('budget', 'malformed-budget');
        $this->raw(['HSET', $budgetKey, 'count', '1']);
        $this->raw(['EXPIRE', $budgetKey, 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->incrementBudget('malformed-budget', 60));
        $this->raw(['HSET', $budgetKey, 'count', '1', 'epochStart', (string) time(), 'epochDuration', '1.5']);
        $this->assertOperationFails(fn(): mixed => $this->store->getBudget('malformed-budget'));

        $distinctKey = $this->key('distinct', 'malformed-distinct');
        $distinctMetaKey = $this->key('distinct-meta', 'malformed-distinct');
        $this->raw(['SADD', $distinctKey, 'member']);
        $this->raw(['EXPIRE', $distinctKey, 60]);
        $this->raw(['HSET', $distinctMetaKey, 'expiresAt', 'not-an-integer']);
        $this->raw(['EXPIRE', $distinctMetaKey, 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->addDistinct('malformed-distinct', 'new', 60));

        $watchKey = $this->key('watch', 'malformed-watch');
        $this->raw(['SET', $watchKey, 'not-an-integer', 'EX', 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->getWatchFlag('malformed-watch'));
        $this->raw(['SET', $watchKey, '1', 'EX', 60]);
        $this->raw(['HSET', $this->key('watch-meta', 'malformed-watch'), 'expiresAt', 'not-an-integer']);
        $this->raw(['EXPIRE', $this->key('watch-meta', 'malformed-watch'), 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->incrementWatchFlag('malformed-watch', 60));

        $boundedKey = $this->key('distinct', 'malformed-bounded');
        $this->raw(['SADD', $boundedKey, 'member']);
        $this->raw(['EXPIRE', $boundedKey, 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->addDistinctBoundedWithSnapshot('malformed-bounded', 'new', 60, 2));

        $previousKey = $this->key('distinct', 'malformed-rotation-previous');
        $this->raw(['SADD', $previousKey, 'old']);
        $this->raw(['EXPIRE', $previousKey, 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->addDistinctBoundedWithSnapshotAcrossRotation('malformed-rotation-current', 'malformed-rotation-bridge', 'malformed-rotation-previous', 'new', 'unknown', 60, 2));
    }

    public function testMalformedLeaseAndHardBlockHistoryFailExplicitly(): void
    {
        $leaseKey = $this->key('probe', 'malformed-lease');
        $this->raw(['SET', $leaseKey, 'not-an-integer', 'EX', 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->acquireProbeLease('malformed-lease', time(), 60));
        $this->raw(['SET', $leaseKey, (string) time()]);
        $this->assertOperationFails(fn(): mixed => $this->store->acquireProbeLease('malformed-lease', time(), 60));

        $cycleKey = $this->key('cycle', 'malformed-cycle');
        $this->raw(['ZADD', $cycleKey, 1, 'not-a-timestamp']);
        $this->raw(['EXPIRE', $cycleKey, 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->blockWithCycleTracking('malformed-cycle', null, 2, 60, time(), 21600, 2, 600, 86400));

        $pauseKey = $this->key('pause', 'malformed-pause');
        $this->raw(['ZADD', $pauseKey, 1, 'not-an-interval']);
        $this->raw(['EXPIRE', $pauseKey, 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->readDecayPauseState('malformed-pause', null, time() - 60, time()));
    }

    public function testRedisIntegrationScriptPreservesVerificationAndCleanupStatuses(): void
    {
        $cases = [
            [0, 0, 0],
            [7, 0, 7],
            [0, 9, 9],
            [7, 9, 7],
        ];
        $script = dirname(__DIR__, 3) . '/scripts/ci/run-integration-with-redis.sh';
        $fakeDocker = dirname(__DIR__, 2) . '/Support/Redis/fake-docker.sh';

        foreach ($cases as [$verificationStatus, $cleanupStatus, $expectedStatus]) {
            $environment = getenv();
            $environment['REDIS_INTEGRATION_DOCKER_BIN'] = $fakeDocker;
            $environment['REDIS_INTEGRATION_TEST_STATUS'] = (string) $verificationStatus;
            $environment['REDIS_FAKE_COMPOSE_UP_STATUS'] = '0';
            $environment['REDIS_FAKE_COMPOSE_DOWN_STATUS'] = (string) $cleanupStatus;
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open('bash ' . escapeshellarg($script), $descriptors, $pipes, dirname(__DIR__, 3), $environment);
            if (! is_resource($process)) {
                self::fail('Unable to start Redis integration script harness.');
            }
            /** @var array{0: resource, 1: resource, 2: resource} $pipes */
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame($expectedStatus, proc_close($process));
        }
    }

    public function testNamespaceValidationAndFamilyIsolationUseHashNamespacedKeys(): void
    {
        $this->assertOperationFails(fn(): RedisFullCapabilityStore => new RedisFullCapabilityStore($this->executor, 'invalid namespace'));
        $otherNamespace = new RedisFullCapabilityStore($this->executor, 'integration:other');

        self::assertSame(1, $this->store->increment('same-logical-key', 60));
        self::assertNull($otherNamespace->get('same-logical-key'));

        $this->store->block('same-logical-key', 2, 60);
        self::assertSame(1, $this->store->get('same-logical-key')?->value);
        self::assertSame(2, $this->store->checkBlock('same-logical-key')?->level);
    }

    public function testScoreAndBlockWindowsKeepFixedTtlAndExpireAtExactBoundary(): void
    {
        self::assertSame(2, $this->store->increment('score-window', 60, 2));
        $scoreKey = $this->key('score', 'score-window');
        $scoreTtl = $this->integer($this->raw(['TTL', $scoreKey]));
        self::assertGreaterThan(0, $scoreTtl);
        self::assertSame(5, $this->store->increment('score-window', 60, 3));
        self::assertLessThanOrEqual($scoreTtl, $this->integer($this->raw(['TTL', $scoreKey])));
        $this->store->set('score-window', 9, 60);
        self::assertSame(9, $this->store->get('score-window')?->value);
        $this->raw(['PEXPIRE', $scoreKey, 50]);
        usleep(80_000);
        self::assertNull($this->store->get('score-window'));

        $this->store->block('block-window', 2, 60);
        self::assertSame(2, $this->store->checkBlock('block-window')?->level);
        $this->raw(['PEXPIRE', $this->key('block', 'block-window'), 50]);
        usleep(80_000);
        self::assertNull($this->store->checkBlock('block-window'));
    }

    public function testBudgetEpochAndSeedSemanticsAreFixedAndExplicit(): void
    {
        $first = $this->store->incrementBudget('budget-matrix', 60, 2);
        $budgetKey = $this->key('budget', 'budget-matrix');
        $ttl = $this->integer($this->raw(['TTL', $budgetKey]));
        $second = $this->store->incrementBudget('budget-matrix', 60, 3);
        self::assertSame($first->epochStart, $second->epochStart);
        self::assertLessThanOrEqual($ttl, $this->integer($this->raw(['TTL', $budgetKey])));

        $this->raw(['PEXPIRE', $budgetKey, 50]);
        usleep(1_100_000);
        self::assertNull($this->store->getBudget('budget-matrix'));
        $newEpoch = $this->store->incrementBudget('budget-matrix', 60, 1);
        self::assertNotSame($first->epochStart, $newEpoch->epochStart);

        $seed = new BudgetStateDTO(20, time() - 10);
        $seeded = $this->store->incrementBudgetWithSeed('budget-seeded-valid', 60, $seed, 2);
        self::assertSame(22, $seeded->count);
        self::assertSame($seed->epochStart, $seeded->epochStart);
        $seededAgain = $this->store->incrementBudgetWithSeed('budget-seeded-valid', 60, new BudgetStateDTO(999, time()), 3);
        self::assertSame(25, $seededAgain->count);
        self::assertSame($seed->epochStart, $seededAgain->epochStart);

        $expiredSeed = $this->store->incrementBudgetWithSeed('budget-seeded-expired', 60, new BudgetStateDTO(20, time() - 120), 2);
        self::assertSame(2, $expiredSeed->count);
        $current = $this->store->incrementBudget('budget-seed-current-wins', 60, 4);
        $currentWins = $this->store->incrementBudgetWithSeed('budget-seed-current-wins', 60, new BudgetStateDTO(999, time() - 10), 1);
        self::assertSame(5, $currentWins->count);
        self::assertSame($current->epochStart, $currentWins->epochStart);
    }

    public function testDistinctWatchAndBoundedWindowsDoNotRefreshAndExpire(): void
    {
        self::assertSame(1, $this->store->addDistinct('distinct-matrix', 'one', 60));
        $distinctKey = $this->key('distinct', 'distinct-matrix');
        $distinctTtl = $this->integer($this->raw(['TTL', $distinctKey]));
        self::assertSame(1, $this->store->addDistinct('distinct-matrix', 'one', 60));
        self::assertLessThanOrEqual($distinctTtl, $this->integer($this->raw(['TTL', $distinctKey])));
        self::assertSame(2, $this->store->addDistinct('distinct-matrix', 'two', 60));
        $this->raw(['PEXPIRE', $distinctKey, 50]);
        $this->raw(['PEXPIRE', $this->key('distinct-meta', 'distinct-matrix'), 50]);
        usleep(80_000);
        self::assertSame(1, $this->store->addDistinct('distinct-matrix', 'after-expiry', 60));

        self::assertSame(0, $this->store->getWatchFlag('missing-watch'));
        self::assertSame(1, $this->store->incrementWatchFlag('watch-matrix', 60));
        $watchKey = $this->key('watch', 'watch-matrix');
        $watchTtl = $this->integer($this->raw(['TTL', $watchKey]));
        self::assertSame(2, $this->store->incrementWatchFlag('watch-matrix', 60));
        self::assertLessThanOrEqual($watchTtl, $this->integer($this->raw(['TTL', $watchKey])));
        $this->raw(['PEXPIRE', $watchKey, 50]);
        $this->raw(['PEXPIRE', $this->key('watch-meta', 'watch-matrix'), 50]);
        usleep(80_000);
        self::assertSame(1, $this->store->incrementWatchFlag('watch-matrix', 60));

        $first = $this->store->addDistinctBoundedWithSnapshot('bounded-matrix', 'one', 60, 2);
        $boundedExpiry = $first->expiresAt;
        $second = $this->store->addDistinctBoundedWithSnapshot('bounded-matrix', 'one', 60, 2);
        self::assertSame($boundedExpiry, $second->expiresAt);
        self::assertTrue($this->store->addDistinctBoundedWithSnapshot('bounded-matrix', 'two', 60, 2)->accepted);
        self::assertFalse($this->store->addDistinctBoundedWithSnapshot('bounded-matrix', 'three', 60, 2)->accepted);
    }

    public function testRotationKeepsPreviousReadOnlyAndBoundsBridgeTtlWithoutRefresh(): void
    {
        self::assertSame(1, $this->store->addDistinct('rotation-previous', 'old', 60));
        $previousKey = $this->key('distinct', 'rotation-previous');
        $previousMetaKey = $this->key('distinct-meta', 'rotation-previous');
        $previousMembers = $this->raw(['SMEMBERS', $previousKey]);
        $previousTtl = $this->integer($this->raw(['TTL', $previousKey]));
        $previousExpiry = $this->integer($this->raw(['HGET', $previousMetaKey, 'expiresAt']));

        $first = $this->store->addDistinctBoundedWithSnapshotAcrossRotation('rotation-current', 'rotation-bridge', 'rotation-previous', 'new', 'not-known', 60, 2);
        self::assertSame(['old', 'new'], $first->members);
        $currentKey = $this->key('distinct', 'rotation-current');
        $bridgeKey = $this->key('distinct', 'rotation-bridge');
        $currentTtl = $this->integer($this->raw(['TTL', $currentKey]));
        $bridgeTtl = $this->integer($this->raw(['TTL', $bridgeKey]));
        self::assertLessThanOrEqual($previousExpiry, $this->integer($this->raw(['HGET', $this->key('distinct-meta', 'rotation-bridge'), 'expiresAt'])));
        self::assertSame($previousExpiry, $first->expiresAt);

        $duplicate = $this->store->addDistinctBoundedWithSnapshotAcrossRotation('rotation-current', 'rotation-bridge', 'rotation-previous', 'new', 'not-known', 60, 2);
        self::assertSame($first->members, $duplicate->members);
        self::assertLessThanOrEqual($currentTtl, $this->integer($this->raw(['TTL', $currentKey])));
        self::assertLessThanOrEqual($bridgeTtl, $this->integer($this->raw(['TTL', $bridgeKey])));
        self::assertSame($previousMembers, $this->raw(['SMEMBERS', $previousKey]));
        self::assertSame($previousExpiry, $this->integer($this->raw(['HGET', $previousMetaKey, 'expiresAt'])));
        self::assertLessThanOrEqual($previousTtl, $this->integer($this->raw(['TTL', $previousKey])));
    }

    public function testHardBlockCycleBoundariesAndPauseUnionUseRealRedis(): void
    {
        $base = time();
        $first = $this->store->blockWithCycleTracking('hard-matrix', null, 2, 60, $base, 60, 2, 30, 120);
        self::assertTrue($first->newCycle);
        self::assertSame(1, $first->cycleCount);
        self::assertFalse($first->pauseActivated);

        $active = $this->store->blockWithCycleTracking('hard-matrix', null, 3, 60, $base + 10, 60, 2, 30, 120);
        self::assertFalse($active->newCycle);
        self::assertSame(1, $active->cycleCount);

        $this->store->blockWithCycleTracking('hard-exact', null, 2, 60, $base, 60, 2, 30, 120);
        $exact = $this->store->blockWithCycleTracking('hard-exact', null, 2, 60, $base + 60, 60, 2, 30, 120);
        self::assertTrue($exact->newCycle);
        self::assertSame(2, $exact->cycleCount);

        $threshold = $this->store->blockWithCycleTracking('hard-threshold', null, 2, 60, $base, 120, 2, 30, 120);
        self::assertFalse($threshold->pauseActivated);
        $threshold = $this->store->blockWithCycleTracking('hard-threshold', null, 2, 60, $base + 61, 120, 2, 30, 120);
        self::assertTrue($threshold->pauseActivated);
        self::assertSame($base + 91, $threshold->pauseUntil);
        $renewal = $this->store->blockWithCycleTracking('hard-threshold', null, 2, 60, $base + 62, 60, 2, 30, 120);
        self::assertFalse($renewal->pauseActivated);
        self::assertSame($base + 91, $renewal->pauseUntil);

        $union = $this->store->readDecayPauseState('hard-threshold', null, $base, $base + 80);
        self::assertSame(19, $union->elapsedPausedSeconds);
        self::assertSame($base + 91, $union->activePauseUntil);
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

    public function testHealthBoundaryReturnsFalseForInvalidOrThrowingPingAndStatefulErrorsPassThrough(): void
    {
        $invalidPing = new class implements RedisCommandExecutorInterface {
            /** @param non-empty-list<int|string|float> $command */
            public function execute(array $command): mixed
            {
                return 'NOT_PONG';
            }
        };
        $throwing = new class implements RedisCommandExecutorInterface {
            /** @param non-empty-list<int|string|float> $command */
            public function execute(array $command): mixed
            {
                throw new \RuntimeException('backend unavailable');
            }
        };

        self::assertFalse((new RedisFullCapabilityStore($invalidPing, 'health-invalid'))->isHealthy());
        $throwingStore = new RedisFullCapabilityStore($throwing, 'health-throwing');
        self::assertFalse($throwingStore->isHealthy());
        $this->assertOperationFails(fn(): mixed => $throwingStore->increment('stateful', 60));
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

    private function deviceContext(string $accountId, int $index): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            '198.51.100.21',
            'Mozilla/5.0 Chrome/' . $index,
            $accountId,
            ['device' => 'device-' . $index],
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

    private function assertOperationFails(callable $operation): void
    {
        try {
            $operation();
        } catch (\Throwable) {
            self::addToAssertionCount(1);
            return;
        }

        self::fail('Malformed persisted Redis state was accepted.');
    }
}
