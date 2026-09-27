<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\DTO\FailureStateDTO;

/**
 * Maps circuit-breaker state and policy configuration to an execution mode.
 */
class FailureModeResolver
{
    /**
     * Return FAIL_CLOSED, DEGRADED_MODE, or the policy's configured mode.
     */
    public function resolve(BlockPolicyInterface $policy, CircuitBreaker $cb): string
    {
        if ($cb->isReEntryGuardViolated($policy->getName())) {
            return 'FAIL_CLOSED';
        }

        $state = $cb->getState($policy->getName());

        if ($state->state === FailureStateDTO::STATE_OPEN
            || $state->state === FailureStateDTO::STATE_HALF_OPEN) {
            return 'DEGRADED_MODE';
        }

        return $policy->getFailureMode();
    }
}
