<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
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

    public function testGenerationBoundLifecycleIsAtomicAndClaimDoesNotConsumeEvidence(): void
    {
        $mutation = $this->store->mutateGenerationBoundScore('lifecycle-current', null, null, 600, 8);
        self::assertTrue($mutation->applied);
        self::assertNotNull($mutation->state);
        self::assertSame(1, $mutation->state->generation);

        $id = str_repeat('a', 32);
        $transition = $this->store->blockWithPunishmentLifecycleTracking(
            'lifecycle-current',
            null,
            1,
            $id,
            2,
            1,
            21600,
            2,
            600,
            86400,
        );
        self::assertTrue($transition->applied);
        self::assertNotNull($transition->postPunishmentReentry);
        self::assertSame($id, $transition->postPunishmentReentry->id);

        $duringBlock = $this->store->readGenerationBoundScoreState('lifecycle-current', null);
        self::assertNotNull($duringBlock);
        self::assertNull($duringBlock->postPunishmentReentry);

        self::assertFalse($this->store->claimPostPunishmentReentry('lifecycle-current', null, $id));
        $this->executor->execute(['DEL', 'maatify:rate-limiter:v1:' . hash('sha256', $this->namespace) . ':block:' . hash('sha256', 'lifecycle-current')]);
        self::assertTrue($this->store->claimPostPunishmentReentry('lifecycle-current', null, $id));
        self::assertFalse($this->store->claimPostPunishmentReentry('lifecycle-current', null, $id));
        self::assertNotNull($this->store->readGenerationBoundScoreState('lifecycle-current', null)?->postPunishmentReentry);
    }

    public function testLifecycleCyclesUseRedisTimeExactlyOnceAndPauseIsNotRenewed(): void
    {
        $mutation = $this->store->mutateGenerationBoundScore('redis-time-cycle', null, null, 600, 8);
        self::assertTrue($mutation->applied);

        $first = $this->store->blockWithPunishmentLifecycleTracking(
            'redis-time-cycle',
            null,
            1,
            str_repeat('a', 32),
            2,
            60,
            21600,
            2,
            600,
            86400,
        );
        self::assertTrue($first->applied);
        self::assertNotNull($first->cycle);
        self::assertTrue($first->cycle->newCycle);
        self::assertSame(1, $first->cycle->cycleCount);
        self::assertFalse($first->cycle->pauseActivated);

        $refresh = $this->store->blockWithPunishmentLifecycleTracking(
            'redis-time-cycle',
            null,
            1,
            str_repeat('b', 32),
            2,
            60,
            21600,
            2,
            600,
            86400,
        );
        self::assertTrue($refresh->applied);
        self::assertNotNull($refresh->cycle);
        self::assertFalse($refresh->cycle->newCycle);
        self::assertSame(1, $refresh->cycle->cycleCount);
        self::assertFalse($refresh->cycle->pauseActivated);

        // End only the active block fixture; cycle and lifecycle evidence stay in Redis.
        $fixtureNow = $this->redisTime();
        $cycleKey = $this->key('cycle', 'redis-time-cycle');
        $this->raw(['DEL', $cycleKey]);
        $this->raw(['ZADD', $cycleKey, $fixtureNow - 1, (string) ($fixtureNow - 1)]);
        $this->raw(['EXPIRE', $cycleKey, 21600]);
        $this->raw(['DEL', $this->key('block', 'redis-time-cycle')]);
        $before = $this->redisTime();
        $second = $this->store->blockWithPunishmentLifecycleTracking(
            'redis-time-cycle',
            null,
            1,
            str_repeat('c', 32),
            2,
            60,
            21600,
            2,
            600,
            86400,
        );
        $after = $this->redisTime();

        self::assertTrue($second->applied);
        self::assertNotNull($second->cycle);
        self::assertTrue($second->cycle->newCycle);
        self::assertSame(2, $second->cycle->cycleCount);
        self::assertTrue($second->cycle->pauseActivated);
        self::assertGreaterThanOrEqual($before + 600, $second->cycle->pauseUntil);
        self::assertLessThanOrEqual($after + 600, $second->cycle->pauseUntil);

        $duplicate = $this->store->blockWithPunishmentLifecycleTracking(
            'redis-time-cycle',
            null,
            1,
            str_repeat('d', 32),
            2,
            60,
            21600,
            2,
            600,
            86400,
        );
        self::assertTrue($duplicate->applied);
        self::assertNotNull($duplicate->cycle);
        self::assertFalse($duplicate->cycle->newCycle);
        self::assertSame(2, $duplicate->cycle->cycleCount);
        self::assertFalse($duplicate->cycle->pauseActivated);
        self::assertSame($second->cycle->pauseUntil, $duplicate->cycle->pauseUntil);
    }

    public function testGenerationMutationInvalidatesOldLifecycleIdentityAndFreshPublicationUsesNewIdentity(): void
    {
        $first = $this->store->mutateGenerationBoundScore('lifecycle-fence', null, null, 600, 8);
        self::assertTrue($first->applied);
        self::assertSame(1, $first->state?->generation);
        $idA = str_repeat('a', 32);
        $firstTransition = $this->store->blockWithPunishmentLifecycleTracking('lifecycle-fence', null, 1, $idA, 2, 60, 21600, 2, 600, 86400);
        self::assertTrue($firstTransition->applied);

        $second = $this->store->mutateGenerationBoundScore('lifecycle-fence', null, $this->store->readGenerationBoundScoreState('lifecycle-fence', null), 600, 10);
        self::assertTrue($second->applied);
        self::assertSame(2, $second->state?->generation);
        self::assertNull($this->store->readGenerationBoundScoreState('lifecycle-fence', null)?->postPunishmentReentry);
        self::assertFalse($this->store->claimPostPunishmentReentry('lifecycle-fence', null, $idA));

        $idB = str_repeat('b', 32);
        $secondTransition = $this->store->blockWithPunishmentLifecycleTracking('lifecycle-fence', null, 2, $idB, 2, 60, 21600, 2, 600, 86400);
        self::assertTrue($secondTransition->applied);
        self::assertSame($idB, $secondTransition->postPunishmentReentry?->id);
        $this->executor->execute(['DEL', $this->key('block', 'lifecycle-fence')]);
        self::assertFalse($this->store->claimPostPunishmentReentry('lifecycle-fence', null, $idA));
        self::assertTrue($this->store->claimPostPunishmentReentry('lifecycle-fence', null, $idB));
        self::assertFalse($this->store->claimPostPunishmentReentry('lifecycle-fence', null, $idB));
    }

    public function testLegacyGenerationlessMutationConflictsWhenWriterAddsGeneration(): void
    {
        $key = $this->key('score', 'legacy-cas');
        $this->executor->execute(['HSET', $key, 'value', '4', 'updatedAt', (string) time()]);
        $this->executor->execute(['EXPIRE', $key, '600']);
        $legacy = $this->store->readGenerationBoundScoreState('legacy-cas', null);
        self::assertNotNull($legacy);
        self::assertNull($legacy->generation);

        $this->executor->execute(['HSET', $key, 'generation', '1', 'expiresAt', (string) (time() + 600)]);
        $stale = $this->store->mutateGenerationBoundScore('legacy-cas', null, $legacy, 600, 9);
        self::assertFalse($stale->applied);
    }

    public function testClaimUsesCurrentAsAuthoritativeOverPrevious(): void
    {
        $previousKey = $this->key('score', 'claim-previous');
        $currentKey = $this->key('score', 'claim-current');
        $now = time();
        $this->executor->execute(['HSET', $previousKey, 'value', '8', 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 600), 'reentryId', str_repeat('a', 32), 'reentryValidUntil', (string) ($now + 600), 'reentryGeneration', '1']);
        $this->executor->execute(['EXPIRE', $previousKey, '600']);
        $this->executor->execute(['HSET', $currentKey, 'value', '9', 'updatedAt', (string) $now, 'generation', '2', 'expiresAt', (string) ($now + 600)]);
        $this->executor->execute(['EXPIRE', $currentKey, '600']);

        self::assertFalse($this->store->claimPostPunishmentReentry('claim-current', 'claim-previous', str_repeat('a', 32)));
    }

    public function testMalformedLifecycleClaimStateRaisesExplicitFailure(): void
    {
        $now = $this->redisNow();
        $partial = $this->key('score', 'claim-malformed-partial');
        $this->raw(['HSET', $partial, 'value', '8', 'updatedAt', (string) $now, 'expiresAt', (string) ($now + 600), 'generation', '1', 'reentryId', str_repeat('a', 32)]);
        $this->raw(['EXPIRE', $partial, '600']);
        $this->assertOperationFails(fn(): mixed => $this->store->claimPostPunishmentReentry('claim-malformed-partial', null, str_repeat('a', 32)));

        $invalidId = $this->key('score', 'claim-malformed-id');
        $this->raw(['HSET', $invalidId, 'value', '8', 'updatedAt', (string) $now, 'expiresAt', (string) ($now + 600), 'generation', '1', 'reentryId', str_repeat('z', 32), 'reentryValidUntil', (string) ($now + 600), 'reentryGeneration', '1']);
        $this->raw(['EXPIRE', $invalidId, '600']);
        $this->assertOperationFails(fn(): mixed => $this->store->claimPostPunishmentReentry('claim-malformed-id', null, str_repeat('z', 32)));

        $malformedBlockScore = $this->key('score', 'claim-malformed-block');
        $malformedBlock = $this->key('block', 'claim-malformed-block');
        $this->raw(['HSET', $malformedBlockScore, 'value', '8', 'updatedAt', (string) $now, 'expiresAt', (string) ($now + 600), 'generation', '1', 'reentryId', str_repeat('a', 32), 'reentryValidUntil', (string) ($now + 600), 'reentryGeneration', '1']);
        $this->raw(['EXPIRE', $malformedBlockScore, '600']);
        $this->raw(['HSET', $malformedBlock, 'level', '2']);
        $this->raw(['EXPIRE', $malformedBlock, '600']);
        $this->assertOperationFails(fn(): mixed => $this->store->claimPostPunishmentReentry('claim-malformed-block', null, str_repeat('a', 32)));
    }

    public function testGeneratedStateWithoutExpiresAtFailsReadClaimAndMutationWhileLegacyStateRemainsSupported(): void
    {
        $now = $this->redisNow();
        $generatedRead = $this->key('score', 'generated-missing-expiry-read');
        $this->raw(['HSET', $generatedRead, 'value', '8', 'updatedAt', (string) $now, 'generation', '1']);
        $this->raw(['EXPIRE', $generatedRead, '600']);
        $this->assertOperationFails(fn(): mixed => $this->store->readGenerationBoundScoreState('generated-missing-expiry-read', null));

        $generatedClaim = $this->key('score', 'generated-missing-expiry-claim');
        $this->raw(['HSET', $generatedClaim, 'value', '8', 'updatedAt', (string) $now, 'generation', '1', 'reentryId', str_repeat('a', 32), 'reentryValidUntil', (string) ($now + 600), 'reentryGeneration', '1']);
        $this->raw(['EXPIRE', $generatedClaim, '600']);
        $this->assertOperationFails(fn(): mixed => $this->store->claimPostPunishmentReentry('generated-missing-expiry-claim', null, str_repeat('a', 32)));

        $generatedMutation = $this->key('score', 'generated-missing-expiry-mutation');
        $this->raw(['HSET', $generatedMutation, 'value', '8', 'updatedAt', (string) $now, 'generation', '1']);
        $this->raw(['EXPIRE', $generatedMutation, '600']);
        $expected = new GenerationBoundScoreStateDTO(
            GenerationBoundScoreStateDTO::SOURCE_CURRENT,
            8,
            $now,
            $now + 600,
            1,
        );
        $this->assertOperationFails(fn(): mixed => $this->store->mutateGenerationBoundScore('generated-missing-expiry-mutation', null, $expected, 600, 9));

        $legacy = $this->key('score', 'legacy-missing-expiry-compatible');
        $this->raw(['HSET', $legacy, 'value', '4', 'updatedAt', (string) $now]);
        $this->raw(['EXPIRE', $legacy, '600']);
        $legacyState = $this->store->readGenerationBoundScoreState('legacy-missing-expiry-compatible', null);
        self::assertNotNull($legacyState);
        self::assertNull($legacyState->generation);
    }

    public function testConcurrentRedisClaimsHaveExactlyOneWinner(): void
    {
        $now = $this->redisNow();
        $id = str_repeat('c', 32);
        $score = $this->key('score', 'claim-concurrent');
        $this->raw(['HSET', $score, 'value', '8', 'updatedAt', (string) $now, 'expiresAt', (string) ($now + 600), 'generation', '1', 'reentryId', $id, 'reentryValidUntil', (string) ($now + 600), 'reentryGeneration', '1']);
        $this->raw(['EXPIRE', $score, '600']);

        $workers = 8;
        $this->runConcurrentWorkers($workers, function (int $index) use ($id): void {
            $claimed = $this->workerStore()->claimPostPunishmentReentry('claim-concurrent', null, $id);
            $host = getenv('REDIS_INTEGRATION_HOST');
            $port = getenv('REDIS_INTEGRATION_PORT');
            if ($host === false || $port === false) {
                exit(1);
            }
            (new RespRedisCommandExecutor($host, (int) $port))->execute([
                'SET',
                $this->key('claim-result', 'concurrent-' . $index),
                $claimed ? '1' : '0',
                'EX',
                '60',
            ]);
        });

        $winners = 0;
        for ($index = 0; $index < $workers; $index++) {
            $winners += $this->integer($this->raw(['GET', $this->key('claim-result', 'concurrent-' . $index)]));
        }
        self::assertSame(1, $winners);
    }

    public function testPreviousClaimIsReadOnlyAndWritesOnlyCurrentMarker(): void
    {
        $now = $this->redisNow();
        $id = str_repeat('d', 32);
        $previous = $this->key('score', 'claim-previous-only');
        $this->raw(['HSET', $previous, 'value', '8', 'updatedAt', (string) $now, 'expiresAt', (string) ($now + 600), 'generation', '1', 'reentryId', $id, 'reentryValidUntil', (string) ($now + 600), 'reentryGeneration', '1']);
        $this->raw(['EXPIRE', $previous, '600']);
        $before = $this->hashMap($previous);

        self::assertTrue($this->store->claimPostPunishmentReentry('claim-previous-only-current', 'claim-previous-only', $id));

        self::assertSame($before, $this->hashMap($previous));
        self::assertSame($id, $this->raw(['GET', $this->key('reentry-claim', 'claim-previous-only-current')]));
        $markerTtl = $this->integer($this->raw(['TTL', $this->key('reentry-claim', 'claim-previous-only-current')]));
        self::assertGreaterThan(0, $markerTtl);
        self::assertLessThanOrEqual(600, $markerTtl);
        self::assertFalse($this->store->claimPostPunishmentReentry('claim-previous-only-current', 'claim-previous-only', $id));
    }

    public function testLifecycleClaimDoesNotMutateScoreBlockCycleBudgetOrGenerationEvidence(): void
    {
        $mutation = $this->store->mutateGenerationBoundScore('claim-nonmutation', null, null, 600, 8);
        self::assertTrue($mutation->applied);
        $id = str_repeat('e', 32);
        $transition = $this->store->blockWithPunishmentLifecycleTracking('claim-nonmutation', null, 1, $id, 2, 1, 21600, 2, 600, 86400);
        self::assertTrue($transition->applied);
        $this->raw(['DEL', $this->key('block', 'claim-nonmutation')]);
        $this->store->incrementBudget('claim-nonmutation-budget', 600, 3);

        $scoreBefore = $this->hashMap($this->key('score', 'claim-nonmutation'));
        $cycleBefore = $this->raw(['ZRANGE', $this->key('cycle', 'claim-nonmutation'), '0', '-1', 'WITHSCORES']);
        $budgetBefore = $this->hashMap($this->key('budget', 'claim-nonmutation-budget'));
        self::assertTrue($this->store->claimPostPunishmentReentry('claim-nonmutation', null, $id));
        self::assertSame($scoreBefore, $this->hashMap($this->key('score', 'claim-nonmutation')));
        self::assertSame($cycleBefore, $this->raw(['ZRANGE', $this->key('cycle', 'claim-nonmutation'), '0', '-1', 'WITHSCORES']));
        self::assertSame($budgetBefore, $this->hashMap($this->key('budget', 'claim-nonmutation-budget')));
        self::assertSame($id, $this->raw(['GET', $this->key('reentry-claim', 'claim-nonmutation')]));
    }

    public function testLegacyHandoffAndSameValueMutationAdvanceGenerationExactlyOnce(): void
    {
        $now = $this->redisNow();
        $legacyCurrent = $this->key('score', 'legacy-current-boundary');
        $this->raw(['HSET', $legacyCurrent, 'value', '4', 'updatedAt', (string) $now]);
        $this->raw(['EXPIRE', $legacyCurrent, '600']);
        $legacyTtlBefore = $this->integer($this->raw(['TTL', $legacyCurrent]));
        $legacyState = $this->store->readGenerationBoundScoreState('legacy-current-boundary', null);
        self::assertNotNull($legacyState);
        self::assertNull($legacyState->generation);
        $sameValue = $this->store->mutateGenerationBoundScore('legacy-current-boundary', null, $legacyState, 600, 4);
        self::assertTrue($sameValue->applied);
        self::assertSame(1, $sameValue->state?->generation);
        $legacyTtlAfter = $this->integer($this->raw(['TTL', $legacyCurrent]));
        self::assertLessThanOrEqual($legacyTtlAfter, $legacyTtlBefore);
        self::assertGreaterThanOrEqual($legacyTtlBefore - 2, $legacyTtlAfter);

        $previous = $this->key('score', 'legacy-previous-boundary');
        $this->raw(['HSET', $previous, 'value', '6', 'updatedAt', (string) $now]);
        $this->raw(['EXPIRE', $previous, '600']);
        $previousBefore = $this->hashMap($previous);
        $previousState = $this->store->readGenerationBoundScoreState('legacy-handoff-current', 'legacy-previous-boundary');
        self::assertNotNull($previousState);
        self::assertNull($previousState->generation);
        $handoff = $this->store->mutateGenerationBoundScore('legacy-handoff-current', 'legacy-previous-boundary', $previousState, 600, 7);
        self::assertTrue($handoff->applied);
        self::assertSame(1, $handoff->state?->generation);
        self::assertSame($previousBefore, $this->hashMap($previous));
        self::assertFalse($this->store->mutateGenerationBoundScore('legacy-handoff-current', 'legacy-previous-boundary', $previousState, 600, 8)->applied);

        $generationOne = $this->store->mutateGenerationBoundScore('same-second-value', null, null, 600, 8);
        self::assertTrue($generationOne->applied);
        $generationTwo = $this->store->mutateGenerationBoundScore('same-second-value', null, $generationOne->state, 600, 8);
        self::assertTrue($generationTwo->applied);
        self::assertSame(2, $generationTwo->state?->generation);
        self::assertNull($this->store->readGenerationBoundScoreState('same-second-value', null)?->postPunishmentReentry);
    }

    public function testConcurrentPreviousHandoffHasNoDoubleSeedAndGenerationMutationsHaveNoLostUpdates(): void
    {
        $now = $this->redisNow();
        $previous = $this->key('score', 'concurrent-previous');
        $this->raw(['HSET', $previous, 'value', '5', 'updatedAt', (string) $now]);
        $this->raw(['EXPIRE', $previous, '600']);
        $observedPrevious = $this->store->readGenerationBoundScoreState('concurrent-current', 'concurrent-previous');
        self::assertNotNull($observedPrevious);

        $workers = 6;
        $this->runConcurrentWorkers($workers, function (int $index) use ($observedPrevious): void {
            $mutation = $this->workerStore()->mutateGenerationBoundScore('concurrent-current', 'concurrent-previous', $observedPrevious, 600, 6);
            $host = getenv('REDIS_INTEGRATION_HOST');
            $port = getenv('REDIS_INTEGRATION_PORT');
            if ($host === false || $port === false) {
                exit(1);
            }
            (new RespRedisCommandExecutor($host, (int) $port))->execute([
                'SET',
                $this->key('handoff-result', (string) $index),
                $mutation->applied ? '1' : '0',
                'EX',
                '60',
            ]);
        });
        $handoffWinners = 0;
        for ($index = 0; $index < $workers; $index++) {
            $handoffWinners += $this->integer($this->raw(['GET', $this->key('handoff-result', (string) $index)]));
        }
        self::assertSame(1, $handoffWinners);
        self::assertSame($observedPrevious->value, $this->store->readGenerationBoundScoreState('concurrent-previous', null)?->value);
        $handoffState = $this->store->readGenerationBoundScoreState('concurrent-current', 'concurrent-previous');
        self::assertNotNull($handoffState);
        self::assertSame(6, $handoffState->value);
        self::assertSame(1, $handoffState->generation);

        $this->store->mutateGenerationBoundScore('concurrent-mutations', null, null, 600, 0);
        $this->runConcurrentWorkers($workers, function (): void {
            for ($attempt = 0; $attempt < 8; $attempt++) {
                $store = $this->workerStore();
                $state = $store->readGenerationBoundScoreState('concurrent-mutations', null);
                if ($state !== null && $store->mutateGenerationBoundScore('concurrent-mutations', null, $state, 600, $state->value + 1)->applied) {
                    return;
                }
                usleep(1000);
            }
            exit(1);
        });
        $final = $this->store->readGenerationBoundScoreState('concurrent-mutations', null);
        self::assertNotNull($final);
        self::assertSame($workers, $final->value);
        self::assertSame($workers + 1, $final->generation);
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

    public function testPublicBuilderRotationWritesCurrentStateAndLeavesPreviousRedisStateReadOnly(): void
    {
        $account = 'redis-public-read-only-rotation';
        $oldLimiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('public-old-outer', 'public-stable-fingerprint', 'prod'),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->build();
        for ($device = 1; $device <= 3; $device++) {
            $oldLimiter->limit($this->deviceContext($account, $device), RateLimitCommand::checkOnly('login_protection'));
        }

        $previousKeys = $this->redisKeys('distinct');
        self::assertNotEmpty($previousKeys);
        $previousSnapshot = [];
        foreach ($previousKeys as $key) {
            $previousSnapshot[$key] = [
                $this->raw(['SMEMBERS', $key]),
                $this->integer($this->raw(['PTTL', $key])),
                $this->hashMap(str_replace(':distinct:', ':distinct-meta:', $key)),
                $this->integer($this->raw(['PTTL', str_replace(':distinct:', ':distinct-meta:', $key)])),
            ];
        }

        $currentLimiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig(
                'public-new-outer',
                'public-stable-fingerprint',
                'prod',
                'public-old-outer',
                null,
            ),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->build();
        $rotated = $currentLimiter->limit(
            $this->deviceContext($account, 4),
            RateLimitCommand::checkOnly('login_protection'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $rotated->decision);
        $currentKeys = $this->redisKeys('distinct');
        self::assertNotEmpty(array_diff($currentKeys, $previousKeys));
        foreach ($previousSnapshot as $key => [$members, $pttl, $metadata, $metadataPttl]) {
            self::assertSame($members, $this->raw(['SMEMBERS', $key]));
            self::assertLessThanOrEqual($pttl, $this->integer($this->raw(['PTTL', $key])));
            $metadataKey = str_replace(':distinct:', ':distinct-meta:', $key);
            self::assertSame($metadata, $this->hashMap($metadataKey));
            self::assertLessThanOrEqual($metadataPttl, $this->integer($this->raw(['PTTL', $metadataKey])));
        }
    }

    public function testPublicBuilderMigratesPreviousBudgetIntoCurrentGeneration(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $context = new RateLimitContextDTO(
            '198.51.100.40',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'redis-public-budget-migration',
            ['device' => 'migration'],
        );
        $oldLimiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('budget-old-outer', 'budget-old-fingerprint', 'prod'),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->build();
        $oldLimiter->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $previousKeys = $this->redisKeys('budget');
        self::assertNotEmpty($previousKeys);
        $previousKey = $previousKeys[0];
        $previousState = $this->hashMap($previousKey);
        self::assertArrayHasKey('count', $previousState);
        self::assertArrayHasKey('epochStart', $previousState);
        $previousPttl = $this->integer($this->raw(['PTTL', $previousKey]));

        $currentLimiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig(
                'budget-new-outer',
                'budget-new-fingerprint',
                'prod',
                'budget-old-outer',
                'budget-old-fingerprint',
            ),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->build();
        $currentLimiter->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $currentKeys = array_values(array_diff($this->redisKeys('budget'), $previousKeys));
        self::assertNotEmpty($currentKeys);
        $currentState = $this->hashMap($currentKeys[0]);
        self::assertSame((int) $previousState['count'] + 1, (int) $currentState['count']);
        self::assertSame($previousState['epochStart'], $currentState['epochStart']);
        self::assertSame($previousState, $this->hashMap($previousKey));
        self::assertLessThanOrEqual($previousPttl, $this->integer($this->raw(['PTTL', $previousKey])));
    }

    public function testPublicBuilderPersistsHardBlockCycleAndPauseThroughLimitWorkflow(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $policy = new class implements BlockPolicyInterface {
            public function getName(): string
            {
                return 'public_hard_cycle';
            }

            public function getScoreThresholds(): PolicyThresholdsDTO
            {
                return new PolicyThresholdsDTO(k4: new ScoreThresholdsDTO(1, 1, 1));
            }

            public function getScoreDeltas(): ScoreDeltasDTO
            {
                return new ScoreDeltasDTO(k4_failure: 1);
            }

            public function getFailureMode(): string
            {
                return 'FAIL_CLOSED';
            }

            public function getBudgetConfig(): ?BudgetConfigDTO
            {
                return null;
            }
        };
        $limiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('public-hard-cycle-key', 'public-hard-cycle-fingerprint', 'prod'),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->withPolicy($policy)->build();
        $context = new RateLimitContextDTO(
            '198.51.100.41',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'redis-public-hard-cycle',
            ['device' => 'hard-cycle'],
        );

        $first = $limiter->limit($context, RateLimitCommand::recordFailure('public_hard_cycle'));
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $first->decision);
        $clock->setNow($clock->now()->modify('+61 seconds'));
        $second = $limiter->limit($context, RateLimitCommand::recordFailure('public_hard_cycle'));
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $second->decision);

        $pauseKeys = $this->redisKeys('pause');
        self::assertNotEmpty($pauseKeys);
        self::assertGreaterThan(0, $this->integer($this->raw(['PTTL', $pauseKeys[0]])));
        self::assertNotEmpty($this->redisKeys('cycle'));
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
        foreach (['', ' ', '{invalid}', 'invalid/slash', str_repeat('a', 129)] as $invalidNamespace) {
            $this->assertOperationFails(fn(): RedisFullCapabilityStore => new RedisFullCapabilityStore($this->executor, $invalidNamespace));
        }

        $validNamespace = new RedisFullCapabilityStore($this->executor, 'integration.valid:namespace-1');
        self::assertNull($validNamespace->get('same-logical-key'));
        $otherNamespace = new RedisFullCapabilityStore($this->executor, 'integration:other');

        $logical = 'same-logical-key';
        self::assertSame(1, $this->store->increment($logical, 60));
        self::assertNull($otherNamespace->get('same-logical-key'));

        $this->store->block($logical, 2, 1);
        $budget = $this->store->incrementBudget($logical, 60);
        self::assertSame(1, $budget->count);
        self::assertSame(1, $this->store->addDistinct($logical, 'member', 60));
        self::assertSame(1, $this->store->incrementWatchFlag($logical, 60));
        $this->store->save($logical, new CircuitBreakerStateDTO('CLOSED', [], 0, 0, 0, [], 0));
        self::assertTrue($this->store->acquireProbeLease($logical, time(), 60));

        $base = time();
        $this->store->blockWithCycleTracking($logical, null, 2, 1, $base + 2, 21600, 2, 600, 86400);
        $this->store->blockWithCycleTracking($logical, null, 2, 1, $base + 4, 21600, 2, 600, 86400);
        $this->store->block($logical, 2, 60);

        self::assertSame(1, $this->store->get($logical)?->value);
        self::assertSame(2, $this->store->checkBlock($logical)?->level);
        self::assertSame('CLOSED', $this->store->load($logical)?->status);
        foreach (['score', 'block', 'budget', 'distinct', 'distinct-meta', 'watch', 'watch-meta', 'circuit', 'probe', 'cycle', 'pause'] as $family) {
            self::assertSame(1, $this->integer($this->raw(['EXISTS', $this->key($family, $logical)])), $family);
        }
    }

    public function testScoreUpdatedAtTracksPersistedRedisStateAcrossWrites(): void
    {
        self::assertSame(1, $this->store->increment('updated-at', 60));
        $first = $this->store->get('updated-at');
        self::assertNotNull($first);
        self::assertSame($first->value, $this->integer($this->raw(['HGET', $this->key('score', 'updated-at'), 'value'])));
        self::assertSame($first->updatedAt, $this->integer($this->raw(['HGET', $this->key('score', 'updated-at'), 'updatedAt'])));

        usleep(1_100_000);
        self::assertSame(2, $this->store->increment('updated-at', 60));
        $second = $this->store->get('updated-at');
        self::assertNotNull($second);
        self::assertGreaterThan($first->updatedAt, $second->updatedAt);
        self::assertSame($second->value, $this->integer($this->raw(['HGET', $this->key('score', 'updated-at'), 'value'])));
        self::assertSame($second->updatedAt, $this->integer($this->raw(['HGET', $this->key('score', 'updated-at'), 'updatedAt'])));
    }

    public function testFiniteSubsecondTtlRemainsValidForScoreBudgetAndProbeLease(): void
    {
        self::assertSame(1, $this->store->increment('subsecond-score', 60));
        $scoreKey = $this->key('score', 'subsecond-score');
        $this->raw(['PEXPIRE', $scoreKey, 50]);
        self::assertSame(0, $this->integer($this->raw(['TTL', $scoreKey])));
        self::assertSame(2, $this->store->increment('subsecond-score', 60));

        $budget = $this->store->incrementBudget('subsecond-budget', 60);
        $budgetKey = $this->key('budget', 'subsecond-budget');
        $this->raw(['PEXPIRE', $budgetKey, 50]);
        self::assertSame(0, $this->integer($this->raw(['TTL', $budgetKey])));
        self::assertSame($budget->epochStart, $this->store->incrementBudget('subsecond-budget', 60)->epochStart);

        $now = time();
        self::assertTrue($this->store->acquireProbeLease('subsecond-probe', $now, 60));
        $probeKey = $this->key('probe', 'subsecond-probe');
        $this->raw(['PEXPIRE', $probeKey, 50]);
        self::assertSame(0, $this->integer($this->raw(['TTL', $probeKey])));
        self::assertFalse($this->store->acquireProbeLease('subsecond-probe', $now + 1, 60));
    }

    public function testScoreAndBlockWindowsKeepFixedTtlAndExpireAtExactBoundary(): void
    {
        self::assertSame(2, $this->store->increment('score-window', 60, 2));
        $scoreKey = $this->key('score', 'score-window');
        $this->raw(['PEXPIRE', $scoreKey, 5_000]);
        $scorePttl = $this->integer($this->raw(['PTTL', $scoreKey]));
        self::assertGreaterThan(0, $scorePttl);
        self::assertSame(5, $this->store->increment('score-window', 60, 3));
        $scoreAfterPttl = $this->integer($this->raw(['PTTL', $scoreKey]));
        self::assertLessThanOrEqual($scorePttl, $scoreAfterPttl);
        self::assertLessThan(60_000, $scoreAfterPttl);
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

        $this->store->block('block-logical-boundary', 2, 60);
        $logicalBoundaryKey = $this->key('block', 'block-logical-boundary');
        $this->raw(['HSET', $logicalBoundaryKey, 'expiresAt', $this->redisNow()]);
        $this->raw(['PEXPIRE', $logicalBoundaryKey, 60_000]);
        self::assertSame(1, $this->integer($this->raw(['EXISTS', $logicalBoundaryKey])));
        self::assertNull($this->store->checkBlock('block-logical-boundary'));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $logicalBoundaryKey])));
    }

    public function testBudgetEpochAndSeedSemanticsAreFixedAndExplicit(): void
    {
        $first = $this->store->incrementBudget('budget-matrix', 60, 2);
        $budgetKey = $this->key('budget', 'budget-matrix');
        $this->raw(['PEXPIRE', $budgetKey, 5_000]);
        $pttl = $this->integer($this->raw(['PTTL', $budgetKey]));
        $second = $this->store->incrementBudget('budget-matrix', 60, 3);
        self::assertSame($first->epochStart, $second->epochStart);
        $afterPttl = $this->integer($this->raw(['PTTL', $budgetKey]));
        self::assertLessThanOrEqual($pttl, $afterPttl);
        self::assertLessThan(60_000, $afterPttl);

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
        $firstDistinctCount = $this->store->addDistinct('distinct-matrix', 'one', 60);
        self::assertSame(1, $firstDistinctCount);
        $distinctKey = $this->key('distinct', 'distinct-matrix');
        $this->raw(['PEXPIRE', $distinctKey, 5_000]);
        $this->raw(['PEXPIRE', $this->key('distinct-meta', 'distinct-matrix'), 5_000]);
        $distinctPttl = $this->integer($this->raw(['PTTL', $distinctKey]));
        $duplicateDistinctCount = $this->store->addDistinct('distinct-matrix', 'one', 60);
        self::assertSame($firstDistinctCount, $duplicateDistinctCount);
        $distinctAfterPttl = $this->integer($this->raw(['PTTL', $distinctKey]));
        self::assertLessThanOrEqual($distinctPttl, $distinctAfterPttl);
        self::assertLessThan(60_000, $distinctAfterPttl);
        self::assertSame(2, $this->store->addDistinct('distinct-matrix', 'two', 60));
        $this->raw(['PEXPIRE', $distinctKey, 50]);
        $this->raw(['PEXPIRE', $this->key('distinct-meta', 'distinct-matrix'), 50]);
        usleep(80_000);
        self::assertSame(1, $this->store->addDistinct('distinct-matrix', 'after-expiry', 60));

        self::assertSame(0, $this->store->getWatchFlag('missing-watch'));
        self::assertSame(1, $this->store->incrementWatchFlag('watch-matrix', 60));
        $watchKey = $this->key('watch', 'watch-matrix');
        $this->raw(['PEXPIRE', $watchKey, 5_000]);
        $this->raw(['PEXPIRE', $this->key('watch-meta', 'watch-matrix'), 5_000]);
        $watchPttl = $this->integer($this->raw(['PTTL', $watchKey]));
        self::assertSame(2, $this->store->incrementWatchFlag('watch-matrix', 60));
        $watchAfterPttl = $this->integer($this->raw(['PTTL', $watchKey]));
        self::assertLessThanOrEqual($watchPttl, $watchAfterPttl);
        self::assertLessThan(60_000, $watchAfterPttl);
        $this->raw(['PEXPIRE', $watchKey, 50]);
        $this->raw(['PEXPIRE', $this->key('watch-meta', 'watch-matrix'), 50]);
        usleep(80_000);
        self::assertSame(1, $this->store->incrementWatchFlag('watch-matrix', 60));

        $first = $this->store->addDistinctBoundedWithSnapshot('bounded-matrix', 'one', 60, 2);
        $boundedExpiry = $first->expiresAt;
        $boundedKey = $this->key('distinct', 'bounded-matrix');
        $this->raw(['PEXPIRE', $boundedKey, 5_000]);
        $this->raw(['PEXPIRE', $this->key('distinct-meta', 'bounded-matrix'), 5_000]);
        $boundedPttl = $this->integer($this->raw(['PTTL', $boundedKey]));
        $second = $this->store->addDistinctBoundedWithSnapshot('bounded-matrix', 'one', 60, 2);
        self::assertSame($boundedExpiry, $second->expiresAt);
        $boundedAfterPttl = $this->integer($this->raw(['PTTL', $boundedKey]));
        self::assertLessThanOrEqual($boundedPttl, $boundedAfterPttl);
        self::assertLessThan(60_000, $boundedAfterPttl);
        self::assertTrue($this->store->addDistinctBoundedWithSnapshot('bounded-matrix', 'two', 60, 2)->accepted);
        self::assertFalse($this->store->addDistinctBoundedWithSnapshot('bounded-matrix', 'three', 60, 2)->accepted);
    }

    public function testRotationKeepsPreviousReadOnlyAndBoundsBridgeTtlWithoutRefresh(): void
    {
        self::assertSame(1, $this->store->addDistinct('rotation-previous', 'old', 60));
        $previousKey = $this->key('distinct', 'rotation-previous');
        $previousMetaKey = $this->key('distinct-meta', 'rotation-previous');
        $previousMembers = $this->raw(['SMEMBERS', $previousKey]);
        $this->raw(['PEXPIRE', $previousKey, 10_000]);
        $this->raw(['PEXPIRE', $previousMetaKey, 10_000]);
        $previousPttl = $this->integer($this->raw(['PTTL', $previousKey]));
        $previousExpiry = $this->integer($this->raw(['HGET', $previousMetaKey, 'expiresAt']));

        $first = $this->store->addDistinctBoundedWithSnapshotAcrossRotation('rotation-current', 'rotation-bridge', 'rotation-previous', 'new', 'not-known', 60, 3);
        self::assertSame(['old', 'new'], $first->members);
        $currentKey = $this->key('distinct', 'rotation-current');
        $currentMetaKey = $this->key('distinct-meta', 'rotation-current');
        $bridgeKey = $this->key('distinct', 'rotation-bridge');
        $bridgeMetaKey = $this->key('distinct-meta', 'rotation-bridge');
        self::assertLessThanOrEqual($previousExpiry, $this->integer($this->raw(['HGET', $this->key('distinct-meta', 'rotation-bridge'), 'expiresAt'])));
        self::assertSame($previousExpiry, $first->expiresAt);

        $this->raw(['PEXPIRE', $currentKey, 5_000]);
        $this->raw(['PEXPIRE', $currentMetaKey, 5_000]);
        $this->raw(['PEXPIRE', $bridgeKey, 5_000]);
        $this->raw(['PEXPIRE', $bridgeMetaKey, 5_000]);
        $currentPttl = $this->integer($this->raw(['PTTL', $currentKey]));
        $bridgePttl = $this->integer($this->raw(['PTTL', $bridgeKey]));

        $duplicate = $this->store->addDistinctBoundedWithSnapshotAcrossRotation('rotation-current', 'rotation-bridge', 'rotation-previous', 'new', 'not-known', 60, 3);
        self::assertSame($first->members, $duplicate->members);
        self::assertFalse($duplicate->added);
        $currentAfterDuplicate = $this->integer($this->raw(['PTTL', $currentKey]));
        $bridgeAfterDuplicate = $this->integer($this->raw(['PTTL', $bridgeKey]));
        self::assertLessThanOrEqual($currentPttl, $currentAfterDuplicate);
        self::assertLessThan(60_000, $currentAfterDuplicate);
        self::assertLessThanOrEqual($bridgePttl, $bridgeAfterDuplicate);
        self::assertLessThan(60_000, $bridgeAfterDuplicate);

        $secondNew = $this->store->addDistinctBoundedWithSnapshotAcrossRotation('rotation-current', 'rotation-bridge', 'rotation-previous', 'second-new', 'not-known', 60, 3);
        self::assertSame(['old', 'new', 'second-new'], $secondNew->members);
        self::assertTrue($secondNew->added);
        self::assertLessThanOrEqual($currentAfterDuplicate, $this->integer($this->raw(['PTTL', $currentKey])));
        self::assertLessThanOrEqual($bridgeAfterDuplicate, $this->integer($this->raw(['PTTL', $bridgeKey])));
        self::assertSame($previousMembers, $this->raw(['SMEMBERS', $previousKey]));
        self::assertSame($previousExpiry, $this->integer($this->raw(['HGET', $previousMetaKey, 'expiresAt'])));
        self::assertLessThanOrEqual($previousPttl, $this->integer($this->raw(['PTTL', $previousKey])));

        self::assertSame(1, $this->store->addDistinct('known-previous', 'old-known', 60));
        $known = $this->store->addDistinctBoundedWithSnapshotAcrossRotation(
            'known-current',
            'known-bridge',
            'known-previous',
            'current-known',
            'old-known',
            60,
            3,
        );
        self::assertTrue($known->accepted);
        self::assertFalse($known->added);
        self::assertSame(1, $known->count);
        self::assertSame(['old-known'], $known->members);
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('distinct', 'known-bridge')])));
        self::assertSame($this->integer($this->raw(['HGET', $this->key('distinct-meta', 'known-previous'), 'expiresAt'])), $known->expiresAt);
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

    public function testHardBlockUsesCanonicalInclusiveBoundaryAndPrunesOlderCycles(): void
    {
        $base = time();
        $this->store->blockWithCycleTracking('hard-canonical-exact', null, 2, 1, $base, 21600, 2, 600, 86400);
        $exact = $this->store->blockWithCycleTracking('hard-canonical-exact', null, 2, 1, $base + 21600, 21600, 2, 600, 86400);
        self::assertTrue($exact->newCycle);
        self::assertSame(2, $exact->cycleCount);
        self::assertTrue($exact->pauseActivated);
        self::assertSame($base + 22200, $exact->pauseUntil);

        $this->store->blockWithCycleTracking('hard-canonical-outside', null, 2, 1, $base, 21600, 2, 600, 86400);
        $outside = $this->store->blockWithCycleTracking('hard-canonical-outside', null, 2, 1, $base + 21601, 21600, 2, 600, 86400);
        self::assertTrue($outside->newCycle);
        self::assertSame(1, $outside->cycleCount);
        self::assertFalse($outside->pauseActivated);
    }

    public function testHardBlockAdoptsPreviousHistoryWithoutMutatingPreviousPhysicalState(): void
    {
        $base = time();
        $this->store->blockWithCycleTracking('hard-previous-read-only', null, 2, 600, $base, 21600, 2, 600, 86400);
        $previousCycleKey = $this->key('cycle', 'hard-previous-read-only');
        $previousBlockKey = $this->key('block', 'hard-previous-read-only');
        $this->raw(['PEXPIRE', $previousCycleKey, 5_000]);
        $this->raw(['PEXPIRE', $previousBlockKey, 5_000]);
        $previousMembers = $this->raw(['ZRANGE', $previousCycleKey, 0, -1]);
        $previousBlockExpires = $this->raw(['HGET', $previousBlockKey, 'expiresAt']);
        $previousCyclePttl = $this->integer($this->raw(['PTTL', $previousCycleKey]));
        $previousBlockPttl = $this->integer($this->raw(['PTTL', $previousBlockKey]));

        $current = $this->store->blockWithCycleTracking('hard-current-read-only', 'hard-previous-read-only', 2, 1, $base + 601, 21600, 2, 600, 86400);
        self::assertTrue($current->newCycle);
        self::assertSame(2, $current->cycleCount);
        $repeat = $this->store->blockWithCycleTracking('hard-current-read-only', 'hard-previous-read-only', 2, 1, $base + 601, 21600, 2, 600, 86400);
        self::assertFalse($repeat->newCycle);
        self::assertSame(2, $repeat->cycleCount);
        self::assertSame($previousMembers, $this->raw(['ZRANGE', $previousCycleKey, 0, -1]));
        self::assertSame($previousBlockExpires, $this->raw(['HGET', $previousBlockKey, 'expiresAt']));
        self::assertLessThanOrEqual($previousCyclePttl, $this->integer($this->raw(['PTTL', $previousCycleKey])));
        self::assertLessThanOrEqual($previousBlockPttl, $this->integer($this->raw(['PTTL', $previousBlockKey])));
    }

    public function testHardBlockStartsAnotherCanonicalPauseAfterThePreviousPauseCompletes(): void
    {
        $base = time();
        $this->store->blockWithCycleTracking('hard-later-pause', null, 2, 1, $base, 21600, 2, 600, 86400);
        $firstPause = $this->store->blockWithCycleTracking('hard-later-pause', null, 2, 1, $base + 2, 21600, 2, 600, 86400);
        self::assertTrue($firstPause->pauseActivated);
        self::assertSame($base + 602, $firstPause->pauseUntil);

        $secondPause = $this->store->blockWithCycleTracking('hard-later-pause', null, 2, 1, $base + 603, 21600, 2, 600, 86400);
        self::assertTrue($secondPause->newCycle);
        self::assertTrue($secondPause->pauseActivated);
        self::assertSame($base + 1203, $secondPause->pauseUntil);

        $unknown = $this->store->readDecayPauseState('hard-unknown-current', 'hard-unknown-previous', $base, $base + 1);
        self::assertSame(0, $unknown->elapsedPausedSeconds);
        self::assertSame(0, $unknown->activePauseUntil);
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

    private function redisTime(): int
    {
        $time = $this->raw(['TIME']);

        self::assertIsArray($time);

        $seconds = $time[0] ?? 0;

        return is_int($seconds) || is_string($seconds) ? (int) $seconds : 0;
    }

    private function redisNow(): int
    {
        $time = $this->raw(['TIME']);
        self::assertIsArray($time);
        self::assertArrayHasKey(0, $time);

        return $this->integer($time[0]);
    }

    /**
     * @return list<string>
     */
    private function redisKeys(string $family): array
    {
        $keys = $this->raw(['KEYS', $this->familyPrefix($family) . ':*']);
        self::assertIsArray($keys);
        $result = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $result[] = $key;
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function hashMap(string $key): array
    {
        $raw = $this->raw(['HGETALL', $key]);
        self::assertIsArray($raw);
        $values = array_values($raw);
        self::assertSame(0, count($values) % 2);
        $result = [];
        for ($index = 0; $index < count($values); $index += 2) {
            self::assertIsString($values[$index]);
            self::assertIsString($values[$index + 1]);
            $result[$values[$index]] = $values[$index + 1];
        }

        return $result;
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
        return $this->familyPrefix($family) . ':' . hash('sha256', $logical);
    }

    private function familyPrefix(string $family): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', $this->namespace) . ':' . $family;
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
