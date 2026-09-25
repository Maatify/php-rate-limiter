<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\Config\PostPunishmentReentryPolicyInterface;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class PostPunishmentReentryLifecycleTest extends TestCase
{
    public function testLoginLifecycleAllowsResidualGenerationAndClaimsOnce(): void
    {
        $clock = new FixedClock();
        $engine = $this->engine($clock);
        $context = $this->context('login-account', 'login-device');

        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $hard = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $hard->decision);
        self::assertSame(2, $hard->blockLevel);
        self::assertSame(60, $hard->retryAfter);
        self::assertNull($hard->metadata?->postPunishmentReentry);

        $clock->setNow($clock->now()->modify('+601 seconds'));
        $served = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $served->decision);
        self::assertNotNull($served->metadata?->postPunishmentReentry);

        $id = $served->metadata->postPunishmentReentry->id;
        $beforeSuccess = $this->lifecycleState($engine, 'login_protection', 'login-account');
        self::assertSame($id, $beforeSuccess?->postPunishmentReentry?->id);
        self::assertTrue($engine->claimPostPunishmentReentry($context, 'login_protection', $id));
        self::assertFalse($engine->claimPostPunishmentReentry($context, 'login_protection', $id));
        $success = $engine->limit($context, RateLimitCommand::recordSuccess('login_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $success->decision);
        self::assertNull($success->metadata?->postPunishmentReentry);
        $afterSuccess = $this->lifecycleState($engine, 'login_protection', 'login-account');
        self::assertNotNull($afterSuccess);
        self::assertNotNull($beforeSuccess->postPunishmentReentry);
        self::assertNotNull($afterSuccess->postPunishmentReentry);
        self::assertSame($beforeSuccess->generation, $afterSuccess->generation);
        self::assertSame($beforeSuccess->postPunishmentReentry->id, $afterSuccess->postPunishmentReentry->id);
        $servedAgain = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $servedAgain->decision);
        $servedAgainMetadata = $servedAgain->metadata?->postPunishmentReentry;
        self::assertNotNull($servedAgainMetadata);

        $soft = $engine->limit(
            new RateLimitContextDTO('203.0.113.99', 'Mozilla/5.0 metadata-soft', 'metadata-soft', ['device' => 'metadata-soft-device']),
            RateLimitCommand::recordFailure('otp_protection'),
        );
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $soft->decision);
        self::assertNull($soft->metadata?->postPunishmentReentry);
    }

    public function testOtpUsesSameUnverifiedDeviceForK4Lifecycle(): void
    {
        $clock = new FixedClock();
        $engine = $this->engine($clock);
        $context = $this->context('otp-account', 'exact-unverified-device');

        $first = $engine->limit($context, RateLimitCommand::recordFailure('otp_protection'));
        $hard = $engine->limit($context, RateLimitCommand::recordFailure('otp_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $first->decision);
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $hard->decision);
        self::assertSame(300, $hard->retryAfter);

        $clock->setNow($clock->now()->modify('+601 seconds'));
        $served = $engine->limit($context, RateLimitCommand::checkOnly('otp_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $served->decision);
        self::assertNotNull($served->metadata?->postPunishmentReentry);
        self::assertTrue($engine->claimPostPunishmentReentry($context, 'otp_protection', $served->metadata->postPunishmentReentry->id));
    }

    public function testLifecycleClaimIdentityBoundariesRejectCrossAccountPolicyTamperedAndAnonymousClaims(): void
    {
        $clock = new FixedClock();
        $engine = $this->engine($clock);
        $context = $this->context('claim-account-a', 'claim-device');
        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $clock->setNow($clock->now()->modify('+601 seconds'));
        $served = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));
        $id = $served->metadata?->postPunishmentReentry?->id;
        self::assertNotNull($id);

        self::assertFalse($engine->claimPostPunishmentReentry($this->context('claim-account-b', 'claim-device'), 'login_protection', $id));
        self::assertFalse($engine->claimPostPunishmentReentry($context, 'otp_protection', $id));
        self::assertFalse($engine->claimPostPunishmentReentry($context, 'login_protection', str_repeat('f', 32)));
        self::assertFalse($engine->claimPostPunishmentReentry(new RateLimitContextDTO('203.0.113.10', 'Mozilla/5.0 claim-device', null, ['device' => 'claim-device']), 'login_protection', $id));
        self::assertTrue($engine->claimPostPunishmentReentry($context, 'login_protection', $id));
    }

    public function testActiveK4BlockUsesRemainingPunishmentTtl(): void
    {
        $clock = new FixedClock();
        $engine = $this->engine($clock);
        $context = $this->context('active-ttl-account', 'active-ttl-device');
        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $issued = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        self::assertSame(60, $issued->retryAfter);

        $clock->setNow($clock->now()->modify('+10 seconds'));
        $active = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $active->decision);
        self::assertSame(50, $active->retryAfter);
    }

    public function testFirstGenerationHardFromEmptyStatePublishesLifecycle(): void
    {
        $clock = new FixedClock();
        $policy = new class extends LoginProtectionPolicy implements PostPunishmentReentryPolicyInterface {
            public function getName(): string
            {
                return 'first_generation_hard';
            }

            public function getScoreDeltas(): ScoreDeltasDTO
            {
                return new ScoreDeltasDTO(k4_failure: 8);
            }
        };
        $engine = $this->engine($clock, [$policy]);
        $context = $this->context('first-generation-account', 'first-generation-device');
        $hard = $engine->limit($context, RateLimitCommand::recordFailure('first_generation_hard'));

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $hard->decision);
        self::assertSame(2, $hard->blockLevel);
        self::assertSame(60, $hard->retryAfter);
        $clock->setNow($clock->now()->modify('+61 seconds'));
        self::assertNotNull($this->lifecycleState($engine, 'first_generation_hard', 'first-generation-account')?->postPunishmentReentry);
    }

    public function testLegacyCurrentFirstHardMutationPublishesFreshLifecycle(): void
    {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $key = hash_hmac('sha256', 'login_protection:rate_limiter:k4:v2:prod:legacy-current-hard', 'test_secret');
        $store->set($key, 5, 86400);
        $engine = $this->engine($clock, [], $store);
        $hard = $engine->limit($this->context('legacy-current-hard', 'legacy-current-device'), RateLimitCommand::recordFailure('login_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $hard->decision);
        self::assertSame(60, $hard->retryAfter);
        $clock->setNow($clock->now()->modify('+61 seconds'));
        $state = $this->lifecycleState($engine, 'login_protection', 'legacy-current-hard');
        self::assertNotNull($state);
        self::assertSame(1, $state->generation);
        self::assertNotNull($state->postPunishmentReentry);
    }

    public function testLegacyPreviousFirstHardMutationHandsOffAndPublishesLifecycle(): void
    {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $account = 'legacy-previous-hard';
        $currentKey = hash_hmac('sha256', 'login_protection:rate_limiter:k4:v2:prod:' . $account, 'test_secret');
        $previousKey = hash_hmac('sha256', 'login_protection:rate_limiter:k4:v2:prod:' . $account, 'previous_secret');
        $store->set($previousKey, 5, 86400);
        $previousBefore = $store->get($previousKey);
        $engine = $this->engine($clock, [], $store, null, 'previous_secret');
        $hard = $engine->limit($this->context($account, 'legacy-previous-device'), RateLimitCommand::recordFailure('login_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $hard->decision);
        self::assertSame(60, $hard->retryAfter);
        $clock->setNow($clock->now()->modify('+61 seconds'));
        $state = $store->readGenerationBoundScoreState($currentKey, $previousKey);
        self::assertNotNull($state);
        self::assertSame(GenerationBoundScoreStateDTO::SOURCE_CURRENT, $state->source);
        self::assertSame(1, $state->generation);
        self::assertNotNull($state->postPunishmentReentry);
        self::assertSame($previousBefore?->value, $store->get($previousKey)?->value);
        self::assertSame($previousBefore?->updatedAt, $store->get($previousKey)?->updatedAt);
    }

    public function testKnownDeviceK5OnlyFailureDoesNotAdvanceServedK4Generation(): void
    {
        $clock = new FixedClock();
        $engine = $this->engine($clock);
        $unverified = $this->context('k5-only-account', 'unverified-device');

        $engine->limit($unverified, RateLimitCommand::recordFailure('login_protection'));
        $engine->limit($unverified, RateLimitCommand::recordFailure('login_protection'));
        $engine->limit($unverified, RateLimitCommand::recordFailure('login_protection'));
        $clock->setNow($clock->now()->modify('+601 seconds'));
        $served = $engine->limit($unverified, RateLimitCommand::checkOnly('login_protection'));
        self::assertNotNull($served->metadata?->postPunishmentReentry);
        $before = $this->lifecycleState($engine, 'login_protection', 'k5-only-account');

        $known = new RateLimitContextDTO(
            '198.51.100.10',
            'Mozilla/5.0 lifecycle',
            'k5-only-account',
            ['device' => 'known-device'],
            null,
            false,
            [],
            true,
        );
        $k5Failure = $engine->limit($known, RateLimitCommand::recordFailure('login_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $k5Failure->decision);
        self::assertNull($k5Failure->metadata?->postPunishmentReentry);
        $after = $this->lifecycleState($engine, 'login_protection', 'k5-only-account');
        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertNotNull($before->postPunishmentReentry);
        self::assertNotNull($after->postPunishmentReentry);
        self::assertSame($before->generation, $after->generation);
        self::assertSame($before->postPunishmentReentry->id, $after->postPunishmentReentry->id);

        $next = $engine->limit($unverified, RateLimitCommand::recordFailure('login_protection'));
        $nextState = $this->lifecycleState($engine, 'login_protection', 'k5-only-account');
        self::assertNotNull($nextState);
        self::assertSame($before->generation + 1, $nextState->generation);
        self::assertNull($next->metadata?->postPunishmentReentry);
    }

    public function testNewK4GenerationInvalidatesOldLifecycleAndPublishesFreshIdentity(): void
    {
        $clock = new FixedClock();
        $engine = $this->engine($clock);
        $context = $this->context('generation-account', 'generation-device');
        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $clock->setNow($clock->now()->modify('+601 seconds'));
        $served = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));
        $oldId = $served->metadata?->postPunishmentReentry?->id;
        self::assertNotNull($oldId);
        $oldState = $this->lifecycleState($engine, 'login_protection', 'generation-account');

        $freshFailure = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        self::assertNull($freshFailure->metadata?->postPunishmentReentry);
        for ($attempt = 0; $attempt < 3 && $freshFailure->decision !== RateLimitResultDTO::DECISION_HARD_BLOCK; $attempt++) {
            $freshFailure = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        }
        $newState = $this->lifecycleState($engine, 'login_protection', 'generation-account');
        self::assertNotNull($oldState);
        self::assertNotNull($newState);
        self::assertSame($oldState->generation + 1, $newState->generation);
        if ($newState->postPunishmentReentry !== null) {
            self::assertNotSame($oldId, $newState->postPunishmentReentry->id);
        }
        self::assertFalse($engine->claimPostPunishmentReentry($context, 'login_protection', $oldId));
    }

    public function testCustomOptInHasFullLifecycleAndNonOptInDoesNotNeedLifecycle(): void
    {
        $clock = new FixedClock();
        $policy = new class extends LoginProtectionPolicy implements PostPunishmentReentryPolicyInterface {
            public function getName(): string
            {
                return 'custom_reentry';
            }
        };
        $engine = $this->engine($clock, [$policy]);
        $context = $this->context('custom-account', 'custom-device');

        $engine->limit($context, RateLimitCommand::recordFailure('custom_reentry'));
        $engine->limit($context, RateLimitCommand::recordFailure('custom_reentry'));
        $hard = $engine->limit($context, RateLimitCommand::recordFailure('custom_reentry'));
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $hard->decision);
        $clock->setNow($clock->now()->modify('+61 seconds'));
        $served = $engine->limit($context, RateLimitCommand::checkOnly('custom_reentry'));
        self::assertNotNull($served->metadata?->postPunishmentReentry);
        self::assertTrue($engine->claimPostPunishmentReentry($context, 'custom_reentry', $served->metadata->postPunishmentReentry->id));
    }

    public function testMutationAndPublicationConflictsRefreshAndRetryWithFreshState(): void
    {
        $clock = new FixedClock();
        $store = new class ($clock) extends InMemoryRateLimitStore {
            public int $mutationCalls = 0;
            public int $publicationCalls = 0;
            public bool $injectConflict = false;
            /** @var list<int|null> */
            public array $expectedValues = [];
            /** @var list<int> */
            public array $newValues = [];
            /** @var list<int> */
            public array $publicationGenerations = [];

            public function mutateGenerationBoundScore(string $currentKey, ?string $previousKey, ?GenerationBoundScoreStateDTO $expectedState, int $ttlSeconds, int $newValue): GenerationBoundScoreMutationDTO
            {
                if ($this->injectConflict && $this->mutationCalls++ === 0) {
                    parent::mutateGenerationBoundScore($currentKey, $previousKey, null, $ttlSeconds, 8);
                    return new GenerationBoundScoreMutationDTO(false, null);
                }
                $this->expectedValues[] = $expectedState?->value;
                $this->newValues[] = $newValue;

                return parent::mutateGenerationBoundScore($currentKey, $previousKey, $expectedState, $ttlSeconds, $newValue);
            }

            public function blockWithPunishmentLifecycleTracking(string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): PunishmentLifecycleTransitionDTO
            {
                if ($this->injectConflict && $this->publicationCalls++ === 0) {
                    $latest = $this->readGenerationBoundScoreState($currentKey, $previousKey);
                    parent::mutateGenerationBoundScore($currentKey, $previousKey, $latest, 86400, 10);
                    return new PunishmentLifecycleTransitionDTO(false, null, null, null);
                }
                $this->publicationGenerations[] = $expectedGeneration;

                return parent::blockWithPunishmentLifecycleTracking($currentKey, $previousKey, $expectedGeneration, $proposedLifecycleId, $level, $durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds);
            }
        };
        $engine = $this->engine($clock, [], $store);
        $context = $this->context('conflict-account', 'conflict-device');

        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $store->injectConflict = true;
        $store->mutationCalls = 0;
        $store->publicationCalls = 0;
        $store->expectedValues = [];
        $store->newValues = [];
        $store->publicationGenerations = [];
        $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $store->mutationCalls);
        self::assertSame(2, $store->publicationCalls);
        self::assertSame([8], $store->expectedValues, json_encode(['expected' => $store->expectedValues, 'new' => $store->newValues], JSON_THROW_ON_ERROR));
        self::assertSame([11], $store->newValues);
        self::assertSame([$this->lifecycleState($engine, 'login_protection', 'conflict-account')?->generation], $store->publicationGenerations);
    }

    public function testThreeMutationConflictsReturnTransientHardWithoutCircuitFailure(): void
    {
        $clock = new FixedClock();
        $store = new class ($clock) extends InMemoryRateLimitStore {
            public int $mutationCalls = 0;

            public function mutateGenerationBoundScore(string $currentKey, ?string $previousKey, ?GenerationBoundScoreStateDTO $expectedState, int $ttlSeconds, int $newValue): GenerationBoundScoreMutationDTO
            {
                $this->mutationCalls++;
                return new GenerationBoundScoreMutationDTO(false, null);
            }
        };
        $circuitStore = new InMemoryCircuitBreakerStore();
        $engine = $this->engine($clock, [], $store, $circuitStore);
        $context = $this->context('exhaustion-account', 'exhaustion-device');

        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $store->mutationCalls = 0;
        $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $result->blockLevel);
        self::assertSame(1, $result->retryAfter);
        self::assertSame('NORMAL', $result->failureMode);
        self::assertSame('k4_concurrency_conflict', $result->metadata?->cause);
        self::assertSame(3, $store->mutationCalls);
        self::assertSame(0, $circuitStore->saveCount());
    }

    public function testThreePublicationConflictsReturnTransientHardWithoutCircuitFailure(): void
    {
        $clock = new FixedClock();
        $store = new class ($clock) extends InMemoryRateLimitStore {
            public int $publicationCalls = 0;

            public function blockWithPunishmentLifecycleTracking(string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): PunishmentLifecycleTransitionDTO
            {
                $this->publicationCalls++;

                return new PunishmentLifecycleTransitionDTO(false, null, null, null);
            }
        };
        $circuitStore = new InMemoryCircuitBreakerStore();
        $engine = $this->engine($clock, [], $store, $circuitStore);
        $context = $this->context('publication-exhaustion-account', 'publication-exhaustion-device');

        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $store->publicationCalls = 0;
        $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $result->blockLevel);
        self::assertSame(1, $result->retryAfter);
        self::assertSame('NORMAL', $result->failureMode);
        self::assertSame('k4_concurrency_conflict', $result->metadata?->cause);
        self::assertSame(3, $store->publicationCalls);
        self::assertSame(0, $circuitStore->saveCount());
    }

    /** @param list<\Maatify\RateLimiter\Config\BlockPolicyInterface> $extraPolicies */
    private function engine(FixedClock $clock, array $extraPolicies = [], ?InMemoryRateLimitStore $store = null, ?InMemoryCircuitBreakerStore $circuitStore = null, ?string $previousSecret = null): RateLimiterEngine
    {
        $store ??= new InMemoryRateLimitStore($clock);
        $correlation = new StatefulInMemoryCorrelationStore($clock);
        $signals = new RecordingFailureSignalEmitter();
        $pipeline = new EvaluationPipeline(
            $store,
            $correlation,
            new BudgetTracker($store, $clock),
            new AntiEquilibriumGate($correlation),
            new DecayCalculator($clock),
            new EphemeralBucket($correlation),
            'test_secret',
            'prod',
            $clock,
            $previousSecret,
        );

        return new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher('test_secret')),
            $pipeline,
            new CircuitBreaker($circuitStore ?? new InMemoryCircuitBreakerStore(), $signals, $clock),
            new FailureModeResolver(),
            $signals,
            $clock,
            array_merge([new LoginProtectionPolicy(), new OtpProtectionPolicy()], $extraPolicies),
        );
    }

    private function context(string $account, string $device): RateLimitContextDTO
    {
        return new RateLimitContextDTO('198.51.100.10', 'Mozilla/5.0 lifecycle', $account, ['device' => $device]);
    }

    private function lifecycleState(RateLimiterEngine $engine, string $policy, string $account): ?GenerationBoundScoreStateDTO
    {
        $pipelineReflection = new \ReflectionProperty($engine, 'pipeline');
        $pipelineReflection->setAccessible(true);
        /** @var EvaluationPipeline $pipeline */
        $pipeline = $pipelineReflection->getValue($engine);
        $storeReflection = new \ReflectionProperty($pipeline, 'store');
        $storeReflection->setAccessible(true);
        /** @var InMemoryRateLimitStore $store */
        $store = $storeReflection->getValue($pipeline);
        $key = hash_hmac('sha256', $policy . ':rate_limiter:k4:v2:prod:' . $account, 'test_secret');

        return $store->readGenerationBoundScoreState($key, null);
    }
}
