<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;

interface CircuitBreakerStoreInterface
{
    /**
     * Load circuit breaker state for a policy.
     * @param string $policyName
     * @return CircuitBreakerStateDTO|null
     */
    public function load(string $policyName): ?CircuitBreakerStateDTO;

    /**
     * Save circuit breaker state.
     * @param string $policyName
     * @param CircuitBreakerStateDTO $state
     * @return void
     */
    public function save(string $policyName, CircuitBreakerStateDTO $state): void;
}
