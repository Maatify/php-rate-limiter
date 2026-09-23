<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Builder;

use DateTimeZone;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\DeviceIdentityResolverInterface;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Service\RateLimiterInterface;
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
     * Build one coherent RateLimiterEngine graph and return its public API.
     */
    public function build(): RateLimiterInterface
    {
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

        return new RateLimiterEngine(
            $deviceIdentityResolver,
            $evaluationPipeline,
            $circuitBreaker,
            new FailureModeResolver(),
            $this->failureSignalEmitter,
            $clock,
            array_values($this->policies),
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
}
