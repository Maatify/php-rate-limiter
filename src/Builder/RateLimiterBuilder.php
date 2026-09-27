<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Builder;

use DateTimeZone;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\Config\PolicyCapabilityProviderInterface;
use Maatify\RateLimiter\Config\PostPunishmentReentryPolicyInterface;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Config\SimpleThrottlePolicyInterface;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;
use Maatify\RateLimiter\Repository\BoundedCorrelationRotationStoreInterface;
use Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotRotationStoreInterface;
use Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotStoreInterface;
use Maatify\RateLimiter\Repository\BoundedCorrelationStoreInterface;
use Maatify\RateLimiter\Repository\BudgetSeedStoreInterface;
use Maatify\RateLimiter\Repository\CircuitBreakerProbeStoreInterface;
use Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\RateLimiter\Repository\FullCapabilityStoreInterface;
use Maatify\RateLimiter\Repository\HardBlockCycleStoreInterface;
use Maatify\RateLimiter\Repository\PunishmentLifecycleStoreInterface;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntime;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Service\CompositeRateLimitOperationalReader;
use Maatify\RateLimiter\Service\CompositeRateLimitOperationalReaderInterface;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\DeviceIdentityResolverInterface;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\FixedWindowSimpleRateLimiter;
use Maatify\RateLimiter\Service\RateLimitOperationalReader;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\Service\SimpleRateLimitOperationalReader;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\SharedCommon\Infrastructure\SystemClock;

/**
 * Builds the package-owned default enforcement/runtime graph around
 * RateLimiterEngine and the package-owned default Operational Read graph.
 *
 * Host stores and the failure signal emitter are always supplied explicitly.
 * The builder owns only package orchestration defaults and exposes targeted
 * overrides for the clock, identity resolver, and policy registry.
 */
final class RateLimiterBuilder
{
    private ?ClockInterface $clock = null;

    private ?DeviceIdentityResolverInterface $deviceIdentityResolver = null;

    /** @var array<string, BlockPolicyInterface> */
    private array $policies;

    /** @var array<string, SimpleThrottlePolicyInterface> */
    private array $simpleThrottlePolicies = [];

    /**
     * Create a builder with explicit host-owned integration boundaries.
     *
     * The supplied stores and emitter are used directly; this builder never
     * creates production substitutes for them.
     */
    public function __construct(
        private readonly RateLimiterConfig $config,
        private readonly RateLimitStoreInterface $rateLimitStore,
        private readonly CorrelationStoreInterface $correlationStore,
        private readonly CircuitBreakerStoreInterface $circuitBreakerStore,
        private readonly FailureSignalEmitterInterface $failureSignalEmitter,
    ) {
        $this->policies = [
            'login_protection' => new LoginProtectionPolicy(),
            'otp_protection' => new OtpProtectionPolicy(),
            'api_heavy_protection' => new ApiHeavyProtectionPolicy(),
        ];
    }

    /**
     * Create a builder that uses one aggregate store for every storage boundary.
     *
     * The aggregate object is passed unchanged to the rate-limit, correlation,
     * and circuit-breaker boundaries; the failure signal emitter remains separate.
     */
    public static function fromFullCapabilityStore(
        RateLimiterConfig $config,
        FullCapabilityStoreInterface $store,
        FailureSignalEmitterInterface $failureSignalEmitter,
    ): self {
        return new self(
            $config,
            $store,
            $store,
            $store,
            $failureSignalEmitter,
        );
    }

    /**
     * Override the clock shared by the Builder's clock-dependent runtime and
     * operational-read components.
     */
    public function withClock(ClockInterface $clock): self
    {
        $this->clock = $clock;

        return $this;
    }

    /**
     * Override the package default device identity resolver.
     */
    public function withDeviceIdentityResolver(
        DeviceIdentityResolverInterface $deviceIdentityResolver,
    ): self {
        $this->deviceIdentityResolver = $deviceIdentityResolver;

        return $this;
    }

    /**
     * Replace a policy with the same name or append a policy with a new name.
     */
    public function withPolicy(BlockPolicyInterface $policy): self
    {
        $this->policies[$policy->getName()] = $policy;

        return $this;
    }

    /**
     * Replace a simple throttle policy with the same name or append a policy
     * with a new name (DEC-010). The simple-policy registry starts empty:
     * there are no package-default simple policies.
     */
    public function withSimpleThrottlePolicy(SimpleThrottlePolicyInterface $policy): self
    {
        $this->simpleThrottlePolicies[$policy->getName()] = $policy;

        return $this;
    }

    /**
     * Build one coherent runtime graph and return its composite public API.
     *
     * The registered policy graph and configured generations are validated
     * against the storage/runtime capabilities they semantically require
     * before any runtime object is constructed (DEC-010 fail-fast).
     *
     * @throws RateLimiterException when the configured stores lack a
     *     capability the registered policy graph requires.
     */
    public function build(): CompositeRateLimiterRuntimeInterface
    {
        $this->assertCapabilitiesSatisfied();

        $clock = $this->clock ?? new SystemClock(new DateTimeZone('UTC'));
        $deviceIdentityResolver = $this->deviceIdentityResolver ?? $this->defaultDeviceIdentityResolver();

        $budgetTracker = new BudgetTracker($this->rateLimitStore, $clock);
        $antiEquilibriumGate = new AntiEquilibriumGate($this->correlationStore);
        $decayCalculator = new DecayCalculator($clock);
        $ephemeralBucket = new EphemeralBucket($this->correlationStore);
        $evaluationPipeline = new EvaluationPipeline(
            $this->rateLimitStore,
            $this->correlationStore,
            $budgetTracker,
            $antiEquilibriumGate,
            $decayCalculator,
            $ephemeralBucket,
            $this->config->keySecret(),
            $this->config->environmentScope(),
            $clock,
            $this->config->previousKeySecret(),
        );
        $circuitBreaker = new CircuitBreaker(
            $this->circuitBreakerStore,
            $this->failureSignalEmitter,
            $clock,
        );

        $scoreRuntime = new RateLimiterEngine(
            $deviceIdentityResolver,
            $evaluationPipeline,
            $circuitBreaker,
            new FailureModeResolver(),
            $this->failureSignalEmitter,
            $clock,
            array_values($this->policies),
        );

        $simpleRuntime = new FixedWindowSimpleRateLimiter(
            array_values($this->simpleThrottlePolicies),
            $this->rateLimitStore,
            $clock,
            $this->config->keySecret(),
            $this->config->environmentScope(),
            $this->config->previousKeySecret(),
        );

        return new CompositeRateLimiterRuntime($scoreRuntime, $simpleRuntime);
    }

    /**
     * Build the package-owned default Operational Read composition from this
     * builder's current registered state (DEC-012): the same effective
     * clock, device identity resolver, key configuration, environment scope,
     * rate-limit store, circuit-breaker store, score-policy registry, and
     * simple-policy registry used by build().
     *
     * This is a read-only composition method. It intentionally does not run
     * build()'s mutation-only capability preflight (assertCapabilitiesSatisfied()):
     * Operational Read never calls a mutation-only primitive — bounded
     * correlation, snapshot mutation, circuit probe acquisition, or
     * punishment mutation — so construction must not fail on capabilities it
     * never uses. It also does not weaken or change build() or any of its
     * fail-fast behavior.
     */
    public function buildOperationalReader(): CompositeRateLimitOperationalReaderInterface
    {
        $clock = $this->clock ?? new SystemClock(new DateTimeZone('UTC'));
        $deviceIdentityResolver = $this->deviceIdentityResolver ?? $this->defaultDeviceIdentityResolver();

        $scoreReader = new RateLimitOperationalReader(
            $deviceIdentityResolver,
            $this->rateLimitStore,
            $this->circuitBreakerStore,
            new DecayCalculator($clock),
            $clock,
            $this->config->keySecret(),
            $this->config->environmentScope(),
            $this->config->previousKeySecret(),
        );

        $simpleReader = new SimpleRateLimitOperationalReader(
            array_values($this->simpleThrottlePolicies),
            $this->rateLimitStore,
            $clock,
            $this->config->keySecret(),
            $this->config->environmentScope(),
            $this->config->previousKeySecret(),
        );

        return new CompositeRateLimitOperationalReader(
            $scoreReader,
            array_values($this->policies),
            $simpleReader,
        );
    }

    private function defaultDeviceIdentityResolver(): DeviceIdentityResolver
    {
        $previousSecret = $this->config->previousFingerprintSecret();

        return new DeviceIdentityResolver(
            new FingerprintHasher($this->config->fingerprintSecret()),
            $previousSecret === null ? null : new FingerprintHasher($previousSecret),
        );
    }

    /**
     * Reject an incompatible production graph before any runtime object is
     * constructed (DEC-010). Each check is a semantic requirement derived
     * from the registered policies and configured generations, never from a
     * preset name or backend identity.
     *
     * @throws RateLimiterException when a required typed capability is absent.
     */
    private function assertCapabilitiesSatisfied(): void
    {
        $this->assertPunishmentLifecycleCapability();
        $this->assertBoundedCorrelationCapability();
        $this->assertDistributedAccountCapability();
        $this->assertHardBlockCycleCapability();
        $this->assertBudgetSeedCapability();
        $this->assertCircuitBreakerProbeCapability();
    }

    private function assertPunishmentLifecycleCapability(): void
    {
        foreach ($this->policies as $policy) {
            if ($policy instanceof PostPunishmentReentryPolicyInterface
                && ! $this->rateLimitStore instanceof PunishmentLifecycleStoreInterface) {
                throw new RateLimiterException(
                    'Policy ' . $policy->getName() . ' requires PunishmentLifecycleStoreInterface.',
                );
            }
        }
    }

    /**
     * The score runtime always evaluates bounded device-cap/churn/dilution
     * semantics, independently of which policies are registered, so the
     * correlation store must support BoundedCorrelationStoreInterface
     * unconditionally; a configured previous generation additionally
     * requires BoundedCorrelationRotationStoreInterface.
     */
    private function assertBoundedCorrelationCapability(): void
    {
        if (! $this->correlationStore instanceof BoundedCorrelationStoreInterface) {
            throw new RateLimiterException(
                'The correlation store requires BoundedCorrelationStoreInterface.',
            );
        }

        if ($this->hasReachablePreviousGeneration()
            && ! $this->correlationStore instanceof BoundedCorrelationRotationStoreInterface) {
            throw new RateLimiterException(
                'The correlation store requires BoundedCorrelationRotationStoreInterface '
                . 'because a previous generation is configured.',
            );
        }
    }

    /**
     * A policy declaring PolicyCapabilityEnum::DISTRIBUTED_ACCOUNT needs a
     * correlation store that can produce a bounded snapshot; this is driven
     * by the declared capability, never by an official preset name.
     */
    private function assertDistributedAccountCapability(): void
    {
        foreach ($this->policies as $policy) {
            if (! $policy instanceof PolicyCapabilityProviderInterface
                || ! in_array(PolicyCapabilityEnum::DISTRIBUTED_ACCOUNT, $policy->getCapabilities(), true)) {
                continue;
            }

            if ($this->hasReachablePreviousGeneration()) {
                if (! $this->correlationStore instanceof BoundedCorrelationSnapshotRotationStoreInterface) {
                    throw new RateLimiterException(
                        'Policy ' . $policy->getName() . ' declares DISTRIBUTED_ACCOUNT and requires '
                        . 'BoundedCorrelationSnapshotRotationStoreInterface because a previous '
                        . 'generation is configured.',
                    );
                }

                continue;
            }

            if (! $this->correlationStore instanceof BoundedCorrelationSnapshotStoreInterface) {
                throw new RateLimiterException(
                    'Policy ' . $policy->getName() . ' declares DISTRIBUTED_ACCOUNT and requires '
                    . 'BoundedCorrelationSnapshotStoreInterface.',
                );
            }
        }
    }

    /**
     * DEC-003: a persisted L2+ block must never fall back to
     * RateLimitStoreInterface::block(). EvaluationPipeline's generic bounded
     * correlation enforcement (churn, dilution, and related
     * checkCorrelationRules() paths) can produce a persisted L2+ candidate
     * independently of any policy's own score thresholds or budget
     * configuration, so this is a Production Default Path requirement for
     * the score runtime, not something derivable from the registered policy
     * graph. PunishmentLifecycleStoreInterface already satisfies this
     * transitively.
     */
    private function assertHardBlockCycleCapability(): void
    {
        if (! $this->rateLimitStore instanceof HardBlockCycleStoreInterface) {
            throw new RateLimiterException(
                'The rate-limit store requires HardBlockCycleStoreInterface.',
            );
        }
    }

    /**
     * KEY_STRATEGY.md §4.3.2 / DEC-013: BudgetSeedStoreInterface is only
     * genuinely required when the configured graph can produce a real
     * previous-generation budget (K4 account budget, K5 known-device
     * micro-cap) or simple-window migration; a previous generation that
     * cannot reach any of those is not a false-positive trigger.
     */
    private function assertBudgetSeedCapability(): void
    {
        $hasAccountBudgetPolicy = false;
        $hasKnownDeviceMicroCapPolicy = false;

        foreach ($this->policies as $policy) {
            $budget = $policy->getBudgetConfig();

            if ($budget === null) {
                continue;
            }

            $hasAccountBudgetPolicy = true;

            if ($budget->known_device_micro_cap !== null) {
                $hasKnownDeviceMicroCapPolicy = true;
            }
        }

        $needsBudgetSeed
            = ($this->hasReachablePreviousOuterGeneration() && $hasAccountBudgetPolicy)
            || ($this->hasReachablePreviousFingerprintGeneration() && $hasKnownDeviceMicroCapPolicy)
            || ($this->hasReachablePreviousOuterGeneration() && $this->simpleThrottlePolicies !== []);

        if ($needsBudgetSeed && ! $this->rateLimitStore instanceof BudgetSeedStoreInterface) {
            throw new RateLimiterException(
                'The configured graph requires a previous-generation budget migration and '
                . 'requires BudgetSeedStoreInterface.',
            );
        }
    }

    /**
     * The package runtime owns recovery probing with an atomic per-policy
     * probe lease; discovering the absence of that capability must not wait
     * until the circuit has already entered recovery.
     */
    private function assertCircuitBreakerProbeCapability(): void
    {
        if (! $this->circuitBreakerStore instanceof CircuitBreakerProbeStoreInterface) {
            throw new RateLimiterException(
                'The circuit-breaker store requires CircuitBreakerProbeStoreInterface.',
            );
        }
    }

    /**
     * A previous outer key-generation secret is always reachable when
     * configured. A previous fingerprint generation is reachable when
     * RateLimiterConfig::previousFingerprintSecret() is configured (consumed
     * by the package default resolver), OR when a Host-supplied custom
     * DeviceIdentityResolverInterface is configured: DeviceIdentityDTO
     * publicly permits a non-null previousFingerprintHash, and the runtime
     * already treats that as an active previous generation regardless of
     * which resolver produced it. The Builder cannot prove a custom resolver
     * will never do so, so the Production Default Path conservatively
     * treats a custom resolver as capable of producing one.
     */
    private function hasReachablePreviousOuterGeneration(): bool
    {
        return $this->config->previousKeySecret() !== null;
    }

    private function hasReachablePreviousFingerprintGeneration(): bool
    {
        return $this->deviceIdentityResolver !== null
            || $this->config->previousFingerprintSecret() !== null;
    }

    private function hasReachablePreviousGeneration(): bool
    {
        return $this->hasReachablePreviousOuterGeneration()
            || $this->hasReachablePreviousFingerprintGeneration();
    }
}
