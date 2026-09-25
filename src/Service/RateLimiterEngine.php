<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\PolicyCapability;
use Maatify\RateLimiter\Config\PolicyCapabilityProviderInterface;
use Maatify\RateLimiter\Config\FailureFallbackProfile;
use Maatify\RateLimiter\Config\FailureFallbackProfileProviderInterface;
use Maatify\RateLimiter\Service\DeviceIdentityResolverInterface;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\Service\RateLimiterRuntimeInterface;
use Maatify\RateLimiter\Config\PostPunishmentReentryPolicyInterface;
use Maatify\RateLimiter\Exception\RateLimitConcurrencyException;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\RateLimitContextMetadataDTO;
use Maatify\RateLimiter\DTO\RateLimitMetadataDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Implements the composite RateLimiterRuntimeInterface.
 *
 * Coordinates policy registration, public evaluation, circuit breaking,
 * failure-mode fallback, and the circuit-boundary orchestration of the public
 * one-shot post-punishment lifecycle claim.
 */
class RateLimiterEngine implements RateLimiterRuntimeInterface
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
        $capabilities = $this->capabilities($policy);
        $hasApi = in_array(PolicyCapability::API_OVERUSE, $capabilities, true);
        $hasAuth = $this->isAuthRelated($policy, $capabilities);
        $profile = $policy instanceof FailureFallbackProfileProviderInterface
            ? $policy->getFailureFallbackProfile()
            : null;

        if ($hasAuth && $hasApi) {
            throw new RateLimiterException("Policy {$policy->getName()} invalid: Authentication and API_OVERUSE capabilities cannot be combined.");
        }

        if ($hasAuth) {
            $thresholds = $policy->getScoreThresholds();
            if ($thresholds->k4 === null
                || $thresholds->k4->l1 <= 0
                || $thresholds->k4->l1 > $thresholds->k4->l2
                || $thresholds->k4->l2 > $thresholds->k4->l3) {
                throw new RateLimiterException("Policy {$policy->getName()} invalid: Must enforce Account (K4) thresholds; authentication K4 thresholds must be positive and monotonic.");
            }
            $deltas = $policy->getScoreDeltas();
            if ($deltas->k4_failure <= 0) {
                throw new RateLimiterException("Policy {$policy->getName()} invalid: Authentication policies require a positive K4 failure delta.");
            }
            if ($policy->getBudgetConfig() === null) {
                throw new RateLimiterException("Policy {$policy->getName()} invalid: Authentication policies require BudgetConfig.");
            }
            if ($deltas->k2_missing_fp <= 0
                && $deltas->k4_repeated_missing_fp <= 0
                && $deltas->k5_failure <= 0) {
                throw new RateLimiterException("Policy {$policy->getName()} invalid: Authentication policies require a device-aware positive signal.");
            }
            if ($policy->getFailureMode() !== 'FAIL_CLOSED') {
                throw new RateLimiterException("Policy {$policy->getName()} invalid: FAIL_OPEN is not allowed; authentication policies must use FAIL_CLOSED.");
            }
            if (!in_array($profile, [FailureFallbackProfile::AUTHENTICATION_PRIMARY, FailureFallbackProfile::AUTHENTICATION_STEP_UP], true)) {
                throw new RateLimiterException("Policy {$policy->getName()} invalid: Authentication policies require an authentication fallback profile.");
            }
        }

        if ($hasApi && $profile !== FailureFallbackProfile::API_OVERUSE) {
            throw new RateLimiterException("Policy {$policy->getName()} invalid: API_OVERUSE requires the API_OVERUSE fallback profile.");
        }
        if ($profile === FailureFallbackProfile::API_OVERUSE && ! $hasApi) {
            throw new RateLimiterException("Policy {$policy->getName()} invalid: API_OVERUSE fallback profile requires API_OVERUSE capability.");
        }
        if ($profile !== null && ! $hasAuth && ! $hasApi) {
            throw new RateLimiterException("Policy {$policy->getName()} invalid: Fallback profile requires a compatible capability or DEC-007 lifecycle.");
        }
        if ($policy->getFailureMode() === 'FAIL_OPEN' && ! $hasApi) {
            throw new RateLimiterException("Policy {$policy->getName()} invalid: FAIL_OPEN requires API_OVERUSE capability and fallback profile.");
        }

        if ($policy instanceof PostPunishmentReentryPolicyInterface) {
            $thresholds = $policy->getScoreThresholds();
            if ($thresholds->k4 === null) {
                throw new RateLimiterException("Policy {$policy->getName()} invalid: Must enforce Account (K4) thresholds.");
            }
            if ($thresholds->k4->l1 <= 0 || $thresholds->k4->l1 > $thresholds->k4->l2 || $thresholds->k4->l2 > $thresholds->k4->l3) {
                throw new RateLimiterException("Policy {$policy->getName()} invalid: K4 thresholds must be positive and monotonic.");
            }
            if ($policy->getBudgetConfig() === null) {
                throw new RateLimiterException("Policy {$policy->getName()} invalid: Missing required BudgetConfig.");
            }
        }

        $mode = $policy->getFailureMode();
        if (!in_array($mode, ['FAIL_CLOSED', 'FAIL_OPEN'])) {
            throw new RateLimiterException("Policy {$policy->getName()} invalid: Unknown failure mode '$mode'.");
        }
        if ($policy instanceof PostPunishmentReentryPolicyInterface && $mode === 'FAIL_OPEN') {
            throw new RateLimiterException("Policy {$policy->getName()} invalid: post-punishment re-entry cannot use FAIL_OPEN.");
        }

        if ($hasApi) {
            $thresholds = $policy->getScoreThresholds();
            if ($thresholds->k1 === null || $thresholds->k2 === null || $thresholds->k3 === null) {
                throw new RateLimiterException("Policy {$policy->getName()} invalid: Must enforce K1, K2, and K3.");
            }
        }

        if (in_array(PolicyCapability::DISTRIBUTED_ACCOUNT, $capabilities, true)
            && ($policy->getScoreDeltas()->k4_failure <= 0 || $policy->getScoreThresholds()->k4 === null)) {
            throw new RateLimiterException("Policy {$policy->getName()} invalid: Distributed-account behavior requires a positive K4 failure delta and K4 thresholds.");
        }
        $this->policies[$policy->getName()] = $policy;
    }

    /** @return list<PolicyCapability> */
    private function capabilities(BlockPolicyInterface $policy): array
    {
        if (! $policy instanceof PolicyCapabilityProviderInterface) {
            return [];
        }

        $capabilities = $policy->getCapabilities();
        foreach ($capabilities as $capability) {
            // Runtime validation intentionally protects the public boundary
            // even when a deliberately invalid fixture lies to static analysis.
            // @phpstan-ignore-next-line instanceof.alwaysTrue
            if (! $capability instanceof PolicyCapability) {
                throw new RateLimiterException("Policy {$policy->getName()} invalid: Capabilities must be PolicyCapability values.");
            }
        }

        return $capabilities;
    }

    /** @param list<PolicyCapability> $capabilities */
    private function isAuthRelated(BlockPolicyInterface $policy, array $capabilities): bool
    {
        return $policy instanceof PostPunishmentReentryPolicyInterface
            || in_array(PolicyCapability::CREDENTIAL_SPRAY, $capabilities, true)
            || in_array(PolicyCapability::DISTRIBUTED_ACCOUNT, $capabilities, true)
            || in_array(PolicyCapability::TRUSTED_AUTHENTICATION, $capabilities, true);
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
        } catch (RateLimitConcurrencyException) {
            return new RateLimitResultDTO(
                RateLimitResultDTO::DECISION_HARD_BLOCK,
                2,
                1,
                'NORMAL',
                new RateLimitMetadataDTO(
                    null,
                    'k4_concurrency_conflict',
                    new RateLimitContextMetadataDTO('k4_concurrency_conflict', 'k4'),
                ),
            );
        } catch (\Throwable $e) {
            $this->circuitBreaker->reportFailure($policyName);

            $mode = $this->failureResolver->resolve($policy, $this->circuitBreaker);

            $signal = null;
            $contextMeta = null;

            // Local Fallback Check
            if ($mode !== 'FAIL_CLOSED') {
                if (!LocalFallbackLimiter::check($this->clock, $policy, $mode, $context->ip, $context->accountId, $context->ua)) {
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
     * Atomically consume the one-shot application handoff marker for a valid
     * post-punishment re-entry claim.
     *
     * An account-less context returns false. An ineligible policy, active
     * re-entry guard, or non-closed circuit is rejected. Valid evidence returns
     * true only once; stale, expired, generation-mismatched, absent, or replayed
     * evidence returns false. Backend failure or corruption is reported to the
     * circuit breaker and propagated. The claim does not mutate punishment
     * satisfaction, evidence, or score lifecycle state.
     *
     * @throws RateLimiterException when the policy or circuit state disallows
     *     the claim.
     */
    public function claimPostPunishmentReentry(
        RateLimitContextDTO $context,
        string $policyName,
        string $reentryId,
    ): bool {
        $policy = $this->policies[$policyName] ?? null;
        if (! $policy || ! $policy instanceof PostPunishmentReentryPolicyInterface) {
            throw new RateLimiterException("Policy is not eligible for post-punishment re-entry: {$policyName}");
        }
        if ($context->accountId === null) {
            return false;
        }
        if ($this->circuitBreaker->isReEntryGuardViolated($policyName)) {
            throw new RateLimiterException('Post-punishment re-entry claim is unavailable while the circuit guard is active.');
        }
        $state = $this->circuitBreaker->getState($policyName);
        if ($state->state !== \Maatify\RateLimiter\DTO\FailureStateDTO::STATE_CLOSED) {
            throw new RateLimiterException('Post-punishment re-entry claim is unavailable while the circuit is not closed.');
        }
        $device = $this->deviceResolver->resolve($context);
        try {
            $claimed = $this->pipeline->claimPostPunishmentReentry($context, $device, $policyName, $reentryId);
            $this->circuitBreaker->reportSuccess($policyName);
            return $claimed;
        } catch (\Throwable $exception) {
            $this->circuitBreaker->reportFailure($policyName);
            throw $exception;
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
                $policy,
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
