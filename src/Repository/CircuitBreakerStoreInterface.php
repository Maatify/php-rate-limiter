<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;

/**
 * Persistence boundary for per-policy circuit-breaker state.
 */
interface CircuitBreakerStoreInterface
{
    /**
     * Load circuit-breaker state for a policy.
     *
     * A null result means that the policy has no persisted state yet.
     */
    public function load(string $policyName): ?CircuitBreakerStateDTO;

    /**
     * Replace the persisted circuit-breaker state for a policy.
     */
    public function save(string $policyName, CircuitBreakerStateDTO $state): void;
}
