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

        $clock->setNow($clock->now()->modify('+601 seconds'));
        $served = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $served->decision);
        self::assertNotNull($served->metadata?->postPunishmentReentry);

        $id = $served->metadata->postPunishmentReentry->id;
        self::assertTrue($engine->claimPostPunishmentReentry($context, 'login_protection', $id));
        self::assertFalse($engine->claimPostPunishmentReentry($context, 'login_protection', $id));
        self::assertNull($engine->limit($context, RateLimitCommand::recordSuccess('login_protection'))->metadata?->postPunishmentReentry);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $engine->limit($context, RateLimitCommand::checkOnly('login_protection'))->decision);
    }

    public function testOtpUsesSameUnverifiedDeviceForK4LifecycleAndPreservesRecoveryGuard(): void
    {
        $clock = new FixedClock();
        $engine = $this->engine($clock);
        $context = $this->context('otp-account', 'exact-unverified-device');

        $first = $engine->limit($context, RateLimitCommand::recordFailure('otp_protection'));
        $hard = $engine->limit($context, RateLimitCommand::recordFailure('otp_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $first->decision);
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $hard->decision);

        $clock->setNow($clock->now()->modify('+601 seconds'));
        $served = $engine->limit($context, RateLimitCommand::checkOnly('otp_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $served->decision);
        self::assertNotNull($served->metadata?->postPunishmentReentry);
        self::assertTrue($engine->claimPostPunishmentReentry($context, 'otp_protection', $served->metadata->postPunishmentReentry->id));
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

            public function mutateGenerationBoundScore(string $currentKey, ?string $previousKey, ?GenerationBoundScoreStateDTO $expectedState, int $ttlSeconds, int $newValue): GenerationBoundScoreMutationDTO
            {
                if ($this->mutationCalls++ === 0) {
                    return new GenerationBoundScoreMutationDTO(false, null);
                }

                return parent::mutateGenerationBoundScore($currentKey, $previousKey, $expectedState, $ttlSeconds, $newValue);
            }

            public function blockWithPunishmentLifecycleTracking(string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): PunishmentLifecycleTransitionDTO
            {
                if ($this->publicationCalls++ === 0) {
                    return new PunishmentLifecycleTransitionDTO(false, null, null, null);
                }

                return parent::blockWithPunishmentLifecycleTracking($currentKey, $previousKey, $expectedGeneration, $proposedLifecycleId, $level, $durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds);
            }
        };
        $engine = $this->engine($clock, [], $store);
        $context = $this->context('conflict-account', 'conflict-device');

        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $store->mutationCalls = 0;
        $store->publicationCalls = 0;
        $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $store->mutationCalls);
        self::assertSame(2, $store->publicationCalls);
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

    /** @param list<\Maatify\RateLimiter\Config\BlockPolicyInterface> $extraPolicies */
    private function engine(FixedClock $clock, array $extraPolicies = [], ?InMemoryRateLimitStore $store = null, ?InMemoryCircuitBreakerStore $circuitStore = null): RateLimiterEngine
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
}
