<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Service\DeviceIdentityResolverInterface;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\RateLimitContextMetadataDTO;
use Maatify\RateLimiter\DTO\RateLimitMetadataDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Coordinates policy registration, evaluation, circuit breaking, and fallback.
 */
class RateLimiterEngine implements RateLimiterInterface
{
    /** @var array<string, BlockPolicyInterface> */
    private array $policies = [];

    /**
     * @param DeviceIdentityResolverInterface $deviceResolver Request identity resolver.
     * @param EvaluationPipeline $pipeline Normal evaluation pipeline.
     * @param CircuitBreaker $circuitBreaker Backend failure state machine.
     * @param FailureModeResolver $failureResolver Failure-mode selector.
     * @param FailureSignalEmitterInterface $emitter Failure transition sink.
     * @param ClockInterface $clock Source of fallback timestamps.
     * @param BlockPolicyInterface[] $policies Policies available by name.
     */
    public function __construct(
        private readonly DeviceIdentityResolverInterface $deviceResolver,
        private readonly EvaluationPipeline $pipeline,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly FailureModeResolver $failureResolver,
        FailureSignalEmitterInterface $emitter,
        private readonly ClockInterface $clock,
        array $policies,
    ) {
        // Retain the historical constructor boundary; transition signals are
        // emitted by CircuitBreaker at the actual state transition.
        unset($emitter);

        foreach ($policies as $policy) {
            $this->registerPolicy($policy);
        }
    }

    private function registerPolicy(BlockPolicyInterface $policy): void
    {
        if (in_array($policy->getName(), ['login_protection', 'otp_protection'])) {
            $thresholds = $policy->getScoreThresholds();
            if ($thresholds->k4 === null) {
                throw new RateLimiterException("Policy {$policy->getName()} invalid: Must enforce Account (K4) thresholds.");
            }
            if ($policy->getBudgetConfig() === null) {
                throw new RateLimiterException("Policy {$policy->getName()} invalid: Missing required BudgetConfig.");
            }
        }

        $mode = $policy->getFailureMode();
        if (!in_array($mode, ['FAIL_CLOSED', 'FAIL_OPEN'])) {
            throw new RateLimiterException("Policy {$policy->getName()} invalid: Unknown failure mode '$mode'.");
        }

        if ($policy->getName() === 'api_heavy_protection') {
            $thresholds = $policy->getScoreThresholds();
            if ($thresholds->k1 === null || $thresholds->k2 === null || $thresholds->k3 === null) {
                throw new RateLimiterException("Policy {$policy->getName()} invalid: Must enforce K1, K2, and K3.");
            }
        }

        $this->policies[$policy->getName()] = $policy;
    }

    /**
     * Evaluate a request using the policy named by its command.
     *
     * Backend failures are converted to the policy's configured failure mode
     * and may use the bounded local fallback limiter.
     */
    public function limit(RateLimitContextDTO $context, RateLimitCommand $request): RateLimitResultDTO
    {
        $policy = $this->policies[$request->policyName] ?? null;
        if (!$policy) {
            throw new RateLimiterException("Policy not found: {$request->policyName}");
        }

        $policyName = $policy->getName();

        // Circuit-breaker preflight must happen before identity resolution and
        // normal evaluation so OPEN/HALF_OPEN requests cannot touch the shared
        // backend. An eligible probe without the additive lease capability is a
        // configuration failure and intentionally escapes generic failure logic.
        if ($this->circuitBreaker->isReEntryGuardViolated($policyName)) {
            return $this->guardResult($policyName);
        }

        $state = $this->circuitBreaker->getState($policyName);
        if ($state->state !== \Maatify\RateLimiter\DTO\FailureStateDTO::STATE_CLOSED) {
            $mayProcessNormally = $this->circuitBreaker->attemptRecoveryProbe(
                $policyName,
                fn(): bool => $this->pipeline->isBackendHealthy(),
            );

            if ($this->circuitBreaker->getReEntryGuardRemaining($policyName) > 0) {
                return $this->guardResult($policyName);
            }

            if (! $mayProcessNormally) {
                return $this->degradedResult($policy, $context);
            }
        }

        try {
            $device = $this->deviceResolver->resolve($context);

            $result = $this->pipeline->process($policy, $context, $request, $device);

            $this->circuitBreaker->reportSuccess($policyName);

            return $result;
        } catch (\Throwable $e) {
            $this->circuitBreaker->reportFailure($policyName);

            $mode = $this->failureResolver->resolve($policy, $this->circuitBreaker);

            $signal = null;
            $contextMeta = null;

            // Local Fallback Check
            if ($mode !== 'FAIL_CLOSED') {
                if (!LocalFallbackLimiter::check($this->clock, $policyName, $mode, $context->ip, $context->accountId, $context->ua)) {
                    $contextMeta = new RateLimitContextMetadataDTO('fallback_limit_exceeded');
                    $meta = new RateLimitMetadataDTO($signal, 'fallback_limit_exceeded', $contextMeta);
                    return new RateLimitResultDTO(RateLimitResultDTO::DECISION_HARD_BLOCK, 2, 60, $mode, $meta);
                }
            }

            $meta = new RateLimitMetadataDTO($signal, null, $contextMeta);

            if ($mode === 'FAIL_OPEN') {
                return new RateLimitResultDTO(RateLimitResultDTO::DECISION_ALLOW, 0, 0, $mode, $meta);
            }

            if ($mode === 'DEGRADED_MODE') {
                return new RateLimitResultDTO(RateLimitResultDTO::DECISION_ALLOW, 0, 0, $mode, $meta);
            }

            // FAIL_CLOSED
            $guardActive = $this->circuitBreaker->isReEntryGuardViolated($policyName);
            $retryAfter = $guardActive
                ? max(1, $this->circuitBreaker->getReEntryGuardRemaining($policyName))
                : 600;

            if ($guardActive) {
                $signal = 'CRITICAL_RE_ENTRY_VIOLATION';
                $contextMeta = new RateLimitContextMetadataDTO('re_entry_violation');
                $meta = new RateLimitMetadataDTO($signal, 're_entry_violation', $contextMeta);
            }

            return new RateLimitResultDTO(RateLimitResultDTO::DECISION_HARD_BLOCK, 2, $retryAfter, $mode, $meta);
        }
    }

    /**
     * Return the authoritative active-guard denial without probing or fallback.
     */
    private function guardResult(string $policyName): RateLimitResultDTO
    {
        $contextMeta = new RateLimitContextMetadataDTO('re_entry_violation');
        $meta = new RateLimitMetadataDTO('CRITICAL_RE_ENTRY_VIOLATION', 're_entry_violation', $contextMeta);

        return new RateLimitResultDTO(
            RateLimitResultDTO::DECISION_HARD_BLOCK,
            2,
            max(1, $this->circuitBreaker->getReEntryGuardRemaining($policyName)),
            'FAIL_CLOSED',
            $meta,
        );
    }

    /**
     * Serve an OPEN/HALF_OPEN request through the existing bounded fallback.
     */
    private function degradedResult(BlockPolicyInterface $policy, RateLimitContextDTO $context): RateLimitResultDTO
    {
        $mode = $this->failureResolver->resolve($policy, $this->circuitBreaker);
        if ($mode === 'FAIL_CLOSED' && $this->circuitBreaker->isReEntryGuardViolated($policy->getName())) {
            return $this->guardResult($policy->getName());
        }

        if ($mode !== 'FAIL_CLOSED'
            && ! LocalFallbackLimiter::check(
                $this->clock,
                $policy->getName(),
                $mode,
                $context->ip,
                $context->accountId,
                $context->ua,
            )) {
            $contextMeta = new RateLimitContextMetadataDTO('fallback_limit_exceeded');
            $meta = new RateLimitMetadataDTO(null, 'fallback_limit_exceeded', $contextMeta);

            return new RateLimitResultDTO(RateLimitResultDTO::DECISION_HARD_BLOCK, 2, 60, $mode, $meta);
        }

        if ($mode === 'FAIL_OPEN' || $mode === 'DEGRADED_MODE') {
            return new RateLimitResultDTO(RateLimitResultDTO::DECISION_ALLOW, 0, 0, $mode);
        }

        return new RateLimitResultDTO(RateLimitResultDTO::DECISION_HARD_BLOCK, 2, 600, $mode);
    }
}
