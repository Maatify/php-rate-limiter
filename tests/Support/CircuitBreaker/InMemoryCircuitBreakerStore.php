<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\CircuitBreaker;

use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\Repository\CircuitBreakerProbeStoreInterface;

class InMemoryCircuitBreakerStore implements CircuitBreakerProbeStoreInterface
{
    /** @var array<string, CircuitBreakerStateDTO> */
    private array $store = [];

    /** @var array<string, int> */
    private array $probeLeases = [];

    private int $probeAcquisitions = 0;

    public function load(string $policyName): ?CircuitBreakerStateDTO
    {
        return $this->store[$policyName] ?? null;
    }

    public function save(string $policyName, CircuitBreakerStateDTO $state): void
    {
        $this->store[$policyName] = $state;
    }

    public function acquireProbeLease(string $policyName, int $now, int $leaseSeconds): bool
    {
        $expiresAt = $this->probeLeases[$policyName] ?? 0;
        if ($expiresAt > $now) {
            return false;
        }

        $this->probeLeases[$policyName] = $now + $leaseSeconds;
        $this->probeAcquisitions++;

        return true;
    }

    public function probeLeaseExpiresAt(string $policyName): ?int
    {
        return $this->probeLeases[$policyName] ?? null;
    }

    public function probeAcquisitionCount(): int
    {
        return $this->probeAcquisitions;
    }
}
