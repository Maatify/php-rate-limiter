<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Builder;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\PolicyCapabilityProviderInterface;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\BoundedOnlyInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\Correlation\SeparateSnapshotAndRotationInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\BaseOnlyInMemoryRateLimitStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

/**
 * Proves RateLimiterBuilder::build() fails fast (WU-S4-03D-F01 / DEC-010)
 * whenever the configured stores lack a storage/runtime capability that the
 * registered policy graph or configured generations semantically require,
 * instead of discovering the gap only at a later request-time code path.
 */
final class RateLimiterBuilderCapabilityPreflightTest extends TestCase
{
    private RateLimiterConfig $config;
    private FixedClock $clock;
    private FailureSignalEmitterInterface $failureSignalEmitter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->config = new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod');
        $this->failureSignalEmitter = new RecordingFailureSignalEmitter();
    }

    public function testMissingBoundedCorrelationCapabilityIsRejected(): void
    {
        $bareCorrelationStore = new class implements CorrelationStoreInterface {
            public function addDistinct(string $key, string $item, int $ttlSeconds): int
            {
                return 0;
            }

            public function incrementWatchFlag(string $key, int $ttlSeconds): int
            {
                return 0;
            }

            public function getWatchFlag(string $key): int
            {
                return 0;
            }
        };

        $builder = new RateLimiterBuilder(
            $this->config,
            new InMemoryRateLimitStore($this->clock),
            $bareCorrelationStore,
            new InMemoryCircuitBreakerStore(),
            $this->failureSignalEmitter,
        );

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('BoundedCorrelationStoreInterface');
        $builder->build();
    }

    public function testCompleteNonRotationBoundedCorrelationIsAccepted(): void
    {
        $builder = $this->neutralizedBuilder(
            new BaseOnlyInMemoryRateLimitStore($this->clock),
            new BoundedOnlyInMemoryCorrelationStore($this->clock),
            new InMemoryCircuitBreakerStore(),
        );

        $runtime = $builder->build();

        self::assertInstanceOf(CompositeRateLimiterRuntimeInterface::class, $runtime);
    }

    public function testPreviousGenerationRejectsCorrelationStoreLackingRotationCapability(): void
    {
        $config = new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod', 'previous-key-secret');
        $builder = new RateLimiterBuilder(
            $config,
            new InMemoryRateLimitStore($this->clock),
            new BoundedOnlyInMemoryCorrelationStore($this->clock),
            new InMemoryCircuitBreakerStore(),
            $this->failureSignalEmitter,
        );

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('BoundedCorrelationRotationStoreInterface');
        $builder->build();
    }

    public function testDistributedAccountCapabilityRejectsBoundedStoreWithoutSnapshotSupport(): void
    {
        $builder = new RateLimiterBuilder(
            $this->config,
            new InMemoryRateLimitStore($this->clock),
            new BoundedOnlyInMemoryCorrelationStore($this->clock),
            new InMemoryCircuitBreakerStore(),
            $this->failureSignalEmitter,
        );

        try {
            $builder->build();
            self::fail('Expected DISTRIBUTED_ACCOUNT to require BoundedCorrelationSnapshotStoreInterface.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('login_protection', $exception->getMessage());
            self::assertStringContainsString('BoundedCorrelationSnapshotStoreInterface', $exception->getMessage());
        }
    }

    public function testDistributedAccountRotationRejectsSnapshotWithoutRotationSupport(): void
    {
        $config = new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod', 'previous-key-secret');
        $builder = new RateLimiterBuilder(
            $config,
            new InMemoryRateLimitStore($this->clock),
            new SeparateSnapshotAndRotationInMemoryCorrelationStore($this->clock),
            new InMemoryCircuitBreakerStore(),
            $this->failureSignalEmitter,
        );

        try {
            $builder->build();
            self::fail('Expected DISTRIBUTED_ACCOUNT with a previous generation to require the combined snapshot-rotation capability.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('login_protection', $exception->getMessage());
            self::assertStringContainsString('BoundedCorrelationSnapshotRotationStoreInterface', $exception->getMessage());
        }
    }

    public function testCustomDifferentlyNamedDistributedAccountPolicyGetsTheSameValidationAsAnOfficialOne(): void
    {
        $builder = new RateLimiterBuilder(
            $this->config,
            new InMemoryRateLimitStore($this->clock),
            new BoundedOnlyInMemoryCorrelationStore($this->clock),
            new InMemoryCircuitBreakerStore(),
            $this->failureSignalEmitter,
        );
        $builder
            ->withPolicy(new NeutralPolicy('login_protection'))
            ->withPolicy(new NeutralPolicy('otp_protection'))
            ->withPolicy(new DistributedAccountPolicy('custom_distributed_policy'));

        try {
            $builder->build();
            self::fail('Expected the custom-named DISTRIBUTED_ACCOUNT policy to be validated identically to an official preset.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('custom_distributed_policy', $exception->getMessage());
            self::assertStringContainsString('BoundedCorrelationSnapshotStoreInterface', $exception->getMessage());
        }
    }

    public function testL2PlusCapableGraphRejectsRateLimitStoreLackingHardBlockCycleCapability(): void
    {
        $builder = $this->neutralizedBuilder(
            new BaseOnlyInMemoryRateLimitStore($this->clock),
            new StatefulInMemoryCorrelationStore($this->clock),
            new InMemoryCircuitBreakerStore(),
        );
        $builder->withPolicy(new NeutralPolicy('l2_capable_policy', l2: 2, l3: 3));

        try {
            $builder->build();
            self::fail('Expected an L2+-capable non-opt-in policy to require HardBlockCycleStoreInterface.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('l2_capable_policy', $exception->getMessage());
            self::assertStringContainsString('HardBlockCycleStoreInterface', $exception->getMessage());
        }
    }

    public function testAccountBudgetWithPreviousOuterKeyRejectsMissingBudgetSeedCapability(): void
    {
        $config = new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod', 'previous-key-secret');
        $builder = $this->neutralizedBuilder(
            new BaseOnlyInMemoryRateLimitStore($this->clock),
            new StatefulInMemoryCorrelationStore($this->clock),
            new InMemoryCircuitBreakerStore(),
            $config,
        );
        $builder->withPolicy(new NeutralPolicy(
            'account_budget_policy',
            budget: new BudgetConfigDTO(threshold: 5, block_level: 1, known_device_micro_cap: null),
        ));

        try {
            $builder->build();
            self::fail('Expected an account-budget policy with previous outer-key rotation to require BudgetSeedStoreInterface.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('BudgetSeedStoreInterface', $exception->getMessage());
        }
    }

    public function testKnownDeviceMicroCapWithPreviousFingerprintRejectsMissingBudgetSeedCapability(): void
    {
        $config = new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod', null, 'previous-fingerprint-secret');
        $builder = $this->neutralizedBuilder(
            new BaseOnlyInMemoryRateLimitStore($this->clock),
            new StatefulInMemoryCorrelationStore($this->clock),
            new InMemoryCircuitBreakerStore(),
            $config,
        );
        $builder->withPolicy(new NeutralPolicy(
            'micro_cap_policy',
            budget: new BudgetConfigDTO(threshold: 5, block_level: 1, known_device_micro_cap: 3),
        ));

        try {
            $builder->build();
            self::fail('Expected a known-device micro-cap policy with previous fingerprint rotation to require BudgetSeedStoreInterface.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('BudgetSeedStoreInterface', $exception->getMessage());
        }
    }

    public function testSimpleThrottleWithPreviousOuterKeyRejectsMissingBudgetSeedCapability(): void
    {
        $config = new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod', 'previous-key-secret');
        $builder = $this->neutralizedBuilder(
            new BaseOnlyInMemoryRateLimitStore($this->clock),
            new StatefulInMemoryCorrelationStore($this->clock),
            new InMemoryCircuitBreakerStore(),
            $config,
        );
        $builder->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('checkout_attempts', 5, 60));

        try {
            $builder->build();
            self::fail('Expected a registered simple throttle policy with previous outer-key rotation to require BudgetSeedStoreInterface.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('BudgetSeedStoreInterface', $exception->getMessage());
        }
    }

    public function testNoFalsePositiveBudgetSeedRequirementWithoutAnApplicablePreviousGeneration(): void
    {
        $builder = $this->neutralizedBuilder(
            new BaseOnlyInMemoryRateLimitStore($this->clock),
            new StatefulInMemoryCorrelationStore($this->clock),
            new InMemoryCircuitBreakerStore(),
        );
        $builder->withPolicy(new NeutralPolicy(
            'unrotated_budget_policy',
            budget: new BudgetConfigDTO(threshold: 5, block_level: 1, known_device_micro_cap: 3),
        ));

        $runtime = $builder->build();

        self::assertInstanceOf(CompositeRateLimiterRuntimeInterface::class, $runtime);
    }

    public function testCircuitBreakerStoreLackingProbeCapabilityIsRejected(): void
    {
        $baseCircuitBreakerStore = new class implements CircuitBreakerStoreInterface {
            /** @var array<string, CircuitBreakerStateDTO> */
            private array $states = [];

            public function load(string $policyName): ?CircuitBreakerStateDTO
            {
                return $this->states[$policyName] ?? null;
            }

            public function save(string $policyName, CircuitBreakerStateDTO $state): void
            {
                $this->states[$policyName] = $state;
            }
        };

        $builder = new RateLimiterBuilder(
            $this->config,
            new InMemoryRateLimitStore($this->clock),
            new StatefulInMemoryCorrelationStore($this->clock),
            $baseCircuitBreakerStore,
            $this->failureSignalEmitter,
        );

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('CircuitBreakerProbeStoreInterface');
        $builder->build();
    }

    public function testFullCapabilityStorePathStillBuildsSuccessfully(): void
    {
        $runtime = RateLimiterBuilder::fromFullCapabilityStore(
            $this->config,
            new FullCapabilityInMemoryStore($this->clock),
            $this->failureSignalEmitter,
        )->build();

        self::assertInstanceOf(CompositeRateLimiterRuntimeInterface::class, $runtime);
    }

    public function testValidSeparateStoreCompositionStillBuildsSuccessfully(): void
    {
        $builder = new RateLimiterBuilder(
            $this->config,
            new InMemoryRateLimitStore($this->clock),
            new StatefulInMemoryCorrelationStore($this->clock),
            new InMemoryCircuitBreakerStore(),
            $this->failureSignalEmitter,
        );

        self::assertInstanceOf(CompositeRateLimiterRuntimeInterface::class, $builder->build());
    }

    /**
     * Replaces the three seeded default policies with a capability-neutral,
     * L1-only, budget-free stand-in so a scenario under test is isolated to
     * exactly the capability it configures via withPolicy()/withSimpleThrottlePolicy().
     */
    private function neutralizedBuilder(
        RateLimitStoreInterface $rateLimitStore,
        CorrelationStoreInterface $correlationStore,
        CircuitBreakerStoreInterface $circuitBreakerStore,
        ?RateLimiterConfig $config = null,
    ): RateLimiterBuilder {
        $builder = new RateLimiterBuilder(
            $config ?? $this->config,
            $rateLimitStore,
            $correlationStore,
            $circuitBreakerStore,
            $this->failureSignalEmitter,
        );

        return $builder
            ->withPolicy(new NeutralPolicy('login_protection'))
            ->withPolicy(new NeutralPolicy('otp_protection'))
            ->withPolicy(new NeutralPolicy('api_heavy_protection'));
    }
}

/**
 * Capability-neutral BlockPolicyInterface stand-in: L1-only by default
 * (PHP_INT_MAX-disabled L2/L3, matching the package's own disabled-threshold
 * sentinel convention) and budget-free unless a budget is supplied, so a
 * test can attach exactly one capability-triggering condition at a time.
 */
final class NeutralPolicy implements BlockPolicyInterface
{
    public function __construct(
        private readonly string $name,
        private readonly ?BudgetConfigDTO $budget = null,
        private readonly int $l2 = PHP_INT_MAX,
        private readonly int $l3 = PHP_INT_MAX,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getScoreThresholds(): PolicyThresholdsDTO
    {
        return new PolicyThresholdsDTO(k1: new ScoreThresholdsDTO(1, $this->l2, $this->l3));
    }

    public function getScoreDeltas(): ScoreDeltasDTO
    {
        return new ScoreDeltasDTO(k1_spray: 1);
    }

    public function getFailureMode(): string
    {
        return 'FAIL_CLOSED';
    }

    public function getBudgetConfig(): ?BudgetConfigDTO
    {
        return $this->budget;
    }
}

/**
 * Minimal DISTRIBUTED_ACCOUNT-capable policy usable under any policy name, to
 * prove capability validation is name-agnostic (never keyed by preset name).
 */
final class DistributedAccountPolicy implements BlockPolicyInterface, PolicyCapabilityProviderInterface
{
    public function __construct(private readonly string $name) {}

    /** @return list<PolicyCapabilityEnum> */
    public function getCapabilities(): array
    {
        return [PolicyCapabilityEnum::DISTRIBUTED_ACCOUNT];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getScoreThresholds(): PolicyThresholdsDTO
    {
        return new PolicyThresholdsDTO(k4: new ScoreThresholdsDTO(1, 2, 3));
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
}
