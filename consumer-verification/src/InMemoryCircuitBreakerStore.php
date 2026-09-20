<?php

declare(strict_types=1);

namespace ConsumerVerification;

use Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;

final class InMemoryCircuitBreakerStore implements CircuitBreakerStoreInterface
{
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
}
