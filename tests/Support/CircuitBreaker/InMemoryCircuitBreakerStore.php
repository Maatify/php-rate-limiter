<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\CircuitBreaker;

use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\Repository\CircuitBreakerProbeStoreInterface;
use Maatify\RateLimiter\Exception\BackendFailureException;

class InMemoryCircuitBreakerStore implements CircuitBreakerProbeStoreInterface
{
    public function __construct(public bool $available = true) {}

    /** @var array<string, CircuitBreakerStateDTO> */
    private array $store = [];

    /** @var array<string, int> */
    private array $probeLeases = [];

    private int $probeAcquisitions = 0;

    private int $saveCount = 0;

    private int $loadCount = 0;

    public function load(string $policyName): ?CircuitBreakerStateDTO
    {
        $this->loadCount++;
        $this->assertAvailable();

        return $this->store[$policyName] ?? null;
    }

    public function save(string $policyName, CircuitBreakerStateDTO $state): void
    {
        $this->assertAvailable();

        $this->store[$policyName] = $state;
        $this->saveCount++;
    }

    public function acquireProbeLease(string $policyName, int $now, int $leaseSeconds): bool
    {
        $this->assertAvailable();

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

    public function saveCount(): int
    {
        return $this->saveCount;
    }

    public function loadCount(): int
    {
        return $this->loadCount;
    }

    private function assertAvailable(): void
    {
        if (! $this->available) {
            throw new BackendFailureException('Circuit store unavailable');
        }
    }
}
