<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\CircuitBreaker;

use Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;

class InMemoryCircuitBreakerStore implements CircuitBreakerStoreInterface
{
    /** @var array<string, CircuitBreakerStateDTO> */
    private array $store = [];

    public function load(string $policyName): ?CircuitBreakerStateDTO
    {
        return $this->store[$policyName] ?? null;
    }

    public function save(string $policyName, CircuitBreakerStateDTO $state): void
    {
        $this->store[$policyName] = $state;
    }
}
