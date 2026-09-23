<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\FullCapability;

use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\Repository\FullCapabilityStoreInterface;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Delegating aggregate fixture that exposes all storage capabilities through one object.
 */
class FullCapabilityInMemoryStore extends InMemoryRateLimitStore implements FullCapabilityStoreInterface
{
    private StatefulInMemoryCorrelationStore $correlationStore;

    private InMemoryCircuitBreakerStore $circuitBreakerStore;

    public function __construct(ClockInterface $clock)
    {
        parent::__construct($clock);
        $this->correlationStore = new StatefulInMemoryCorrelationStore($clock);
        $this->circuitBreakerStore = new InMemoryCircuitBreakerStore();
    }

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        return $this->correlationStore->addDistinct($key, $item, $ttlSeconds);
    }

    public function addDistinctBounded(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO {
        return $this->correlationStore->addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
    }

    public function addDistinctBoundedWithSnapshot(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctSnapshotDTO {
        return $this->correlationStore->addDistinctBoundedWithSnapshot(
            $key,
            $item,
            $ttlSeconds,
            $maxDistinct,
        );
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        return $this->correlationStore->incrementWatchFlag($key, $ttlSeconds);
    }

    public function getWatchFlag(string $key): int
    {
        return $this->correlationStore->getWatchFlag($key);
    }

    public function addDistinctAcrossRotation(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
    ): int {
        return $this->correlationStore->addDistinctAcrossRotation(
            $currentKey,
            $bridgeKey,
            $previousKey,
            $currentMember,
            $previousMember,
            $ttlSeconds,
        );
    }

    public function addDistinctBoundedAcrossRotation(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO {
        return $this->correlationStore->addDistinctBoundedAcrossRotation(
            $currentKey,
            $bridgeKey,
            $previousKey,
            $currentMember,
            $previousMember,
            $ttlSeconds,
            $maxDistinct,
        );
    }

    public function addDistinctBoundedWithSnapshotAcrossRotation(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctSnapshotDTO {
        return $this->correlationStore->addDistinctBoundedWithSnapshotAcrossRotation(
            $currentKey,
            $bridgeKey,
            $previousKey,
            $currentMember,
            $previousMember,
            $ttlSeconds,
            $maxDistinct,
        );
    }

    public function incrementWatchFlagAcrossRotation(
        string $currentKey,
        string $previousKey,
        int $ttlSeconds,
    ): int {
        return $this->correlationStore->incrementWatchFlagAcrossRotation(
            $currentKey,
            $previousKey,
            $ttlSeconds,
        );
    }

    public function load(string $policyName): ?CircuitBreakerStateDTO
    {
        return $this->circuitBreakerStore->load($policyName);
    }

    public function save(string $policyName, CircuitBreakerStateDTO $state): void
    {
        $this->circuitBreakerStore->save($policyName, $state);
    }

    public function acquireProbeLease(string $policyName, int $now, int $leaseSeconds): bool
    {
        return $this->circuitBreakerStore->acquireProbeLease($policyName, $now, $leaseSeconds);
    }

    public function correlationStore(): StatefulInMemoryCorrelationStore
    {
        return $this->correlationStore;
    }

    public function circuitBreakerStore(): InMemoryCircuitBreakerStore
    {
        return $this->circuitBreakerStore;
    }
}
