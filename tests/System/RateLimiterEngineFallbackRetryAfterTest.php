<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\FailureFallbackConfigurationProviderInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\PolicyCapabilityProviderInterface;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\FailureFallbackConfigurationDTO;
use Maatify\RateLimiter\DTO\FailureFallbackRuleDTO;
use Maatify\RateLimiter\DTO\FailureStateDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Enum\FailureFallbackDimensionEnum;
use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\LocalFallbackLimiter;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\NullCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\ThrowingRateLimitStore;
use PHPUnit\Framework\TestCase;

/**
 * Proves the fallback denial Retry-After is derived from the real remaining
 * fixed window of every denying dimension, on both Engine fallback paths:
 * the typed backend-failure path (circuit still CLOSED) and the OPEN circuit
 * degraded path.
 */
final class RateLimiterEngineFallbackRetryAfterTest extends TestCase
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';
    private const IP = '198.51.100.90';

    private FixedClock $clock;
    private CircuitBreaker $breaker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2025-01-01 12:00:10');
        $this->resetLocalFallbackState();
    }

    public function testBackendFailurePathReportsRemainingWindowLongerThanSixtySeconds(): void
    {
        $engine = $this->engine($this->policy([[FailureFallbackDimensionEnum::IP_PREFIX, 1, 300]], 'FAIL_OPEN'));

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->limit($engine)->decision);
        $this->assertSame(FailureStateDTO::STATE_CLOSED, $this->breaker->getState('retry_after_policy')->state);

        $blocked = $this->limit($engine);

        $this->assertFallbackDenial($blocked, 'FAIL_OPEN', 290);
    }

    public function testBackendFailurePathReportsRemainingWindowShorterThanSixtySeconds(): void
    {
        $engine = $this->engine($this->policy([[FailureFallbackDimensionEnum::IP_PREFIX, 1, 45]], 'FAIL_OPEN'));

        $this->limit($engine);
        $blocked = $this->limit($engine);

        $this->assertSame(FailureStateDTO::STATE_CLOSED, $this->breaker->getState('retry_after_policy')->state);
        // 12:00:10 is 10s into the 45s bucket that began at the epoch-aligned boundary.
        $this->assertFallbackDenial($blocked, 'FAIL_OPEN', $this->remaining(45));
    }

    public function testOpenCircuitPathReportsRemainingWindowLongerThanSixtySeconds(): void
    {
        $engine = $this->engine($this->policy([[FailureFallbackDimensionEnum::IP_PREFIX, 1, 300]], 'FAIL_OPEN'));
        $this->openCircuit($engine);

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->limit($engine)->decision);
        $blocked = $this->limit($engine);

        $this->assertNotSame(FailureStateDTO::STATE_CLOSED, $this->breaker->getState('retry_after_policy')->state);
        $this->assertFallbackDenial($blocked, 'DEGRADED_MODE', 290);
    }

    public function testOpenCircuitPathReportsRemainingWindowShorterThanSixtySeconds(): void
    {
        $engine = $this->engine($this->policy([[FailureFallbackDimensionEnum::IP_PREFIX, 1, 45]], 'FAIL_OPEN'));
        $this->openCircuit($engine);

        $this->limit($engine);
        $blocked = $this->limit($engine);

        $this->assertNotSame(FailureStateDTO::STATE_CLOSED, $this->breaker->getState('retry_after_policy')->state);
        $this->assertFallbackDenial($blocked, 'DEGRADED_MODE', $this->remaining(45));
    }

    public function testRetryAfterIsOneSecondBeforeBoundaryAndFullWindowAtBoundary(): void
    {
        $engine = $this->engine($this->policy([[FailureFallbackDimensionEnum::IP_PREFIX, 1, 120]], 'FAIL_OPEN'));
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:20:00'));
        $this->openCircuit($engine);
        $this->limit($engine);
        $this->assertFallbackDenial($this->limit($engine), 'DEGRADED_MODE', 120);

        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:21:59'));
        $this->assertFallbackDenial($this->limit($engine), 'DEGRADED_MODE', 1);

        // The window rolled over: the denial hint was truthful and the quota is available again.
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:22:00'));
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->limit($engine)->decision);
    }

    public function testMultipleDenyingDimensionsReportTheLongestRemainingWindowOnBothPaths(): void
    {
        $policy = $this->policy([
            [FailureFallbackDimensionEnum::IP_PREFIX, 1, 300],
            [FailureFallbackDimensionEnum::IP_PREFIX_NORMALIZED_USER_AGENT, 1, 45],
        ], 'FAIL_OPEN');
        $engine = $this->engine($policy);

        // Backend failure path: both dimensions deny the second request.
        $this->limit($engine);
        $this->assertFallbackDenial($this->limit($engine), 'FAIL_OPEN', 290);

        // Open circuit path: the longer window still decides.
        $this->openCircuit($engine);
        $this->limit($engine);
        $this->assertFallbackDenial($this->limit($engine), 'DEGRADED_MODE', 290);
    }

    public function testOnlyTheShorterDimensionDenyingReportsItsOwnRemainingWindow(): void
    {
        $engine = $this->engine($this->policy([
            [FailureFallbackDimensionEnum::IP_PREFIX, 5, 300],
            [FailureFallbackDimensionEnum::IP_PREFIX_NORMALIZED_USER_AGENT, 1, 45],
        ], 'FAIL_OPEN'));

        $this->limit($engine);
        $blocked = $this->limit($engine);

        $this->assertFallbackDenial($blocked, 'FAIL_OPEN', $this->remaining(45));
    }

    public function testOnlyTheLongerDimensionDenyingReportsItsOwnRemainingWindow(): void
    {
        $engine = $this->engine($this->policy([
            [FailureFallbackDimensionEnum::IP_PREFIX, 1, 300],
            [FailureFallbackDimensionEnum::IP_PREFIX_NORMALIZED_USER_AGENT, 5, 45],
        ], 'FAIL_OPEN'));

        $this->limit($engine);
        $blocked = $this->limit($engine);

        $this->assertFallbackDenial($blocked, 'FAIL_OPEN', 290);
    }

    public function testHardBlockL2AndPositiveRetryAfterForOfficialPolicies(): void
    {
        $engine = $this->engine(new LoginProtectionPolicy());
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 13:00:00'));
        $this->openCircuit($engine, 'login_protection');

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->limit($engine, 'login_protection', 'account-1')->decision);
        }
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 13:04:00'));
        $blocked = $this->limit($engine, 'login_protection', 'account-1');

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $blocked->decision);
        $this->assertSame(2, $blocked->blockLevel);
        $this->assertGreaterThan(0, $blocked->retryAfter);
        // Login fallback window is 600s (13:00-13:10); 360s remain at 13:04:00.
        $this->assertSame(360, $blocked->retryAfter);
        $this->assertSame('fallback_limit_exceeded', $blocked->metadata?->context?->reason);
    }

    public function testOneEvaluationConsumesEachFallbackCounterExactlyOnce(): void
    {
        $engine = $this->engine($this->policy([
            [FailureFallbackDimensionEnum::IP_PREFIX, 1, 300],
            [FailureFallbackDimensionEnum::IP_PREFIX_NORMALIZED_USER_AGENT, 1, 45],
        ], 'FAIL_OPEN'));

        $this->limit($engine);
        $this->assertSame([1, 1], $this->counterValues());

        // The denied evaluation consumes one unit per dimension, not two.
        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $this->limit($engine)->decision);
        $this->assertSame([2, 2], $this->counterValues());

        // The same holds when the open circuit path produces the denial.
        $this->openCircuit($engine);
        $this->limit($engine);
        $this->assertSame([1, 1], $this->counterValues());
        $this->limit($engine);
        $this->assertSame([2, 2], $this->counterValues());
    }

    public function testAllowedAndOverflowAndWindowRolloverRegressions(): void
    {
        $limit = 3;
        $engine = $this->engine($this->policy([[FailureFallbackDimensionEnum::IP_PREFIX, $limit, 60]], 'FAIL_OPEN'));
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 14:00:00'));
        $this->openCircuit($engine);

        for ($i = 0; $i < $limit; $i++) {
            $allowed = $this->limit($engine);
            $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $allowed->decision);
            $this->assertSame(0, $allowed->retryAfter);
        }
        $this->assertFallbackDenial($this->limit($engine), 'DEGRADED_MODE', 60);

        // Subjects beyond the tracked-subject bound share the overflow bucket
        // and still receive a truthful remaining-window hint.
        $overflow = $this->engine($this->policy([[FailureFallbackDimensionEnum::IP_PREFIX, 1, 90]], 'FAIL_OPEN', 'overflow_policy'));
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 15:00:30'));
        $this->openCircuit($overflow, 'overflow_policy');
        for ($i = 0; $i < 4096; $i++) {
            $this->assertSame(
                RateLimitResultDTO::DECISION_ALLOW,
                $this->limit($overflow, 'overflow_policy', null, '10.' . intdiv($i, 65536) . '.' . intdiv($i % 65536, 256) . '.' . ($i % 256))->decision,
            );
        }
        $this->limit($overflow, 'overflow_policy', null, '203.0.113.200');
        $this->assertFallbackDenial(
            $this->limit($overflow, 'overflow_policy', null, '203.0.113.201'),
            'DEGRADED_MODE',
            60,
        );

        // After the window ends, the sequence is allowed again.
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 15:02:00'));
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->limit($overflow, 'overflow_policy', null, '203.0.113.201')->decision);
    }

    public function testFailClosedAndPolicyWithoutFallbackKeepTheirExistingBehavior(): void
    {
        $engine = $this->engine(new LoginProtectionPolicy(), new ApiHeavyProtectionPolicy());

        // FAIL_CLOSED while the circuit is still closed keeps its 600s hint.
        $first = $this->limit($engine, 'login_protection', 'closed-account');
        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $first->decision);
        $this->assertSame('FAIL_CLOSED', $first->failureMode);
        $this->assertSame(600, $first->retryAfter);

        $unprofiled = $this->engine($this->unprofiledPolicy());
        for ($i = 0; $i < 4; $i++) {
            $result = $this->limit($unprofiled, 'unprofiled_retry_policy');
        }
        // No bounded configuration exists, so there is no window to derive a
        // reset from: the denial is preserved with its historical hint.
        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertSame('DEGRADED_MODE', $result->failureMode);
        $this->assertSame(60, $result->retryAfter);
    }

    public function testLimiterCheckAndEvaluateAgreeOnAllowedState(): void
    {
        $policy = $this->policy([[FailureFallbackDimensionEnum::IP_PREFIX, 1, 60]], 'FAIL_OPEN');

        $this->assertTrue(LocalFallbackLimiter::check($this->clock, $policy, 'DEGRADED_MODE', self::IP));
        $this->assertFalse(LocalFallbackLimiter::check($this->clock, $policy, 'DEGRADED_MODE', self::IP));
        $this->assertSame($this->remaining(60), LocalFallbackLimiter::evaluate($this->clock, $policy, 'DEGRADED_MODE', self::IP));
        $this->assertNull(LocalFallbackLimiter::evaluate($this->clock, $policy, 'FAIL_CLOSED', self::IP));
    }

    private function assertFallbackDenial(RateLimitResultDTO $result, string $mode, int $retryAfter): void
    {
        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertSame(2, $result->blockLevel);
        $this->assertSame($mode, $result->failureMode);
        $this->assertSame($retryAfter, $result->retryAfter);
        $this->assertSame('fallback_limit_exceeded', $result->metadata?->cause);
        $this->assertSame('fallback_limit_exceeded', $result->metadata->context?->reason);
    }

    /**
     * Remaining seconds of the epoch-aligned fixed window containing "now".
     */
    private function remaining(int $window): int
    {
        $now = $this->clock->now()->getTimestamp();

        return (intdiv($now, $window) + 1) * $window - $now;
    }

    /**
     * @return list<int>
     */
    private function counterValues(): array
    {
        $counters = (new \ReflectionClass(LocalFallbackLimiter::class))->getProperty('counters')->getValue();
        $this->assertIsArray($counters);

        $values = [];
        foreach ($counters as $counter) {
            $this->assertIsArray($counter);
            $this->assertIsInt($counter['count']);
            $values[] = $counter['count'];
        }

        return $values;
    }

    private function openCircuit(RateLimiterEngine $engine, string $policyName = 'retry_after_policy'): void
    {
        $this->resetLocalFallbackState();
        for ($i = 0; $i < 3; $i++) {
            $this->limit($engine, $policyName, null, '203.0.113.250');
        }
        $this->assertNotSame(FailureStateDTO::STATE_CLOSED, $this->breaker->getState($policyName)->state);
        $this->resetLocalFallbackState();
    }

    private function limit(RateLimiterEngine $engine, string $policyName = 'retry_after_policy', ?string $accountId = null, string $ip = self::IP): RateLimitResultDTO
    {
        return $engine->limit(
            new RateLimitContextDTO($ip, self::UA, $accountId),
            RateLimitCommand::checkOnly($policyName),
        );
    }

    private function engine(BlockPolicyInterface ...$policies): RateLimiterEngine
    {
        $store = new ThrowingRateLimitStore();
        $correlationStore = new NullCorrelationStore();
        $emitter = new RecordingFailureSignalEmitter();
        $pipeline = new EvaluationPipeline(
            $store,
            $correlationStore,
            new BudgetTracker($store, $this->clock),
            new AntiEquilibriumGate($correlationStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($correlationStore),
            'test_secret',
            'prod',
            $this->clock,
        );
        $this->breaker = new CircuitBreaker(new InMemoryCircuitBreakerStore(), $emitter, $this->clock);

        return new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher('test_secret')),
            $pipeline,
            $this->breaker,
            new FailureModeResolver(),
            $emitter,
            $this->clock,
            $policies,
        );
    }

    /**
     * @param list<array{FailureFallbackDimensionEnum, int, int}> $rules
     */
    private function policy(array $rules, string $failureMode, string $name = 'retry_after_policy'): BlockPolicyInterface
    {
        $ruleDtos = array_map(
            static fn(array $rule): FailureFallbackRuleDTO => new FailureFallbackRuleDTO($rule[0], $rule[1], $rule[2]),
            $rules,
        );
        // API_OVERUSE requires both IP dimensions; pad the missing one with an unreachable cap.
        foreach ([FailureFallbackDimensionEnum::IP_PREFIX, FailureFallbackDimensionEnum::IP_PREFIX_NORMALIZED_USER_AGENT] as $required) {
            $present = false;
            foreach ($ruleDtos as $dto) {
                $present = $present || $dto->dimension === $required;
            }
            if (!$present) {
                $ruleDtos[] = new FailureFallbackRuleDTO($required, 1_000_000, 60);
            }
        }

        return new class ($name, $failureMode, new FailureFallbackConfigurationDTO($ruleDtos)) implements BlockPolicyInterface, PolicyCapabilityProviderInterface, FailureFallbackConfigurationProviderInterface {
            public function __construct(
                private readonly string $name,
                private readonly string $failureMode,
                private readonly FailureFallbackConfigurationDTO $configuration,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            /** @return list<PolicyCapabilityEnum> */
            public function getCapabilities(): array
            {
                return [PolicyCapabilityEnum::API_OVERUSE];
            }

            public function getFailureFallbackConfiguration(): FailureFallbackConfigurationDTO
            {
                return $this->configuration;
            }

            public function getScoreThresholds(): PolicyThresholdsDTO
            {
                return new PolicyThresholdsDTO(k1: new ScoreThresholdsDTO(1000, 1000, 1000), k2: new ScoreThresholdsDTO(1000, 1000, 1000), k3: new ScoreThresholdsDTO(1, 1, 1));
            }

            public function getScoreDeltas(): ScoreDeltasDTO
            {
                return new ScoreDeltasDTO(access: 1);
            }

            public function getFailureMode(): string
            {
                return $this->failureMode;
            }

            public function getBudgetConfig(): ?BudgetConfigDTO
            {
                return null;
            }
        };
    }

    private function unprofiledPolicy(): BlockPolicyInterface
    {
        return new class implements BlockPolicyInterface {
            public function getName(): string
            {
                return 'unprofiled_retry_policy';
            }

            public function getScoreThresholds(): PolicyThresholdsDTO
            {
                return new PolicyThresholdsDTO();
            }

            public function getScoreDeltas(): ScoreDeltasDTO
            {
                return new ScoreDeltasDTO();
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
    }

    private function resetLocalFallbackState(): void
    {
        $reflection = new \ReflectionClass(LocalFallbackLimiter::class);
        $reflection->getProperty('counters')->setValue(null, []);
        $reflection->getProperty('trackedSubjects')->setValue(null, []);
        $reflection->getProperty('trackedSubjectExpiries')->setValue(null, []);
    }
}
