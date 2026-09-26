<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Builder;

use DateTimeZone;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\Config\PostPunishmentReentryPolicyInterface;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Config\SimpleThrottlePolicyInterface;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\RateLimiter\Repository\FullCapabilityStoreInterface;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntime;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\DeviceIdentityResolverInterface;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\FixedWindowSimpleRateLimiter;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\SharedCommon\Infrastructure\SystemClock;

/**
 * Builds the package-owned default runtime graph around RateLimiterEngine.
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
     * Override the clock shared by every clock-dependent runtime component.
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
     * Policies implementing PostPunishmentReentryPolicyInterface require the
     * PunishmentLifecycleStoreInterface capability; the builder rejects that
     * unsupported configuration before constructing the runtime graph.
     *
     * @throws RateLimiterException when an opted-in policy lacks lifecycle
     *     storage capability.
     */
    public function build(): CompositeRateLimiterRuntimeInterface
    {
        foreach ($this->policies as $policy) {
            if ($policy instanceof PostPunishmentReentryPolicyInterface
                && ! $this->rateLimitStore instanceof \Maatify\RateLimiter\Repository\PunishmentLifecycleStoreInterface) {
                throw new RateLimiterException(
                    'Policy ' . $policy->getName() . ' requires PunishmentLifecycleStoreInterface.',
                );
            }
        }
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

    private function defaultDeviceIdentityResolver(): DeviceIdentityResolver
    {
        $previousSecret = $this->config->previousFingerprintSecret();

        return new DeviceIdentityResolver(
            new FingerprintHasher($this->config->fingerprintSecret()),
            $previousSecret === null ? null : new FingerprintHasher($previousSecret),
        );
    }
}
