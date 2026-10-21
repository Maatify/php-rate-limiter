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
use Maatify\RateLimiter\Repository\CallerTimeBoundedCorrelationSnapshotRotationStoreInterface;

/**
 * Delegating aggregate fixture that exposes all storage capabilities through one object.
 */
class FullCapabilityInMemoryStore extends InMemoryRateLimitStore implements FullCapabilityStoreInterface, CallerTimeBoundedCorrelationSnapshotRotationStoreInterface
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

    public function addDistinctAt(string $key, string $item, int $ttlSeconds, int $now): int
    {
        // Held on the delegate, through this instance's own method, so a subclass override applies.
        return $this->correlationStore->atCallerTime($now, fn(): int => $this->addDistinct($key, $item, $ttlSeconds));
    }

    public function incrementWatchFlagAt(string $key, int $ttlSeconds, int $now): int
    {
        // Held on the delegate, through this instance's own method, so a subclass override applies.
        return $this->correlationStore->atCallerTime($now, fn(): int => $this->incrementWatchFlag($key, $ttlSeconds));
    }

    public function addDistinctBoundedAt(string $key, string $item, int $ttlSeconds, int $maxDistinct, int $now): BoundedDistinctResultDTO
    {
        // Held on the delegate, through this instance's own method, so a subclass override applies.
        return $this->correlationStore->atCallerTime($now, fn(): BoundedDistinctResultDTO => $this->addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct));
    }

    public function addDistinctBoundedWithSnapshotAt(string $key, string $item, int $ttlSeconds, int $maxDistinct, int $now): BoundedDistinctSnapshotDTO
    {
        // Held on the delegate, through this instance's own method, so a subclass override applies.
        return $this->correlationStore->atCallerTime($now, fn(): BoundedDistinctSnapshotDTO => $this->addDistinctBoundedWithSnapshot($key, $item, $ttlSeconds, $maxDistinct));
    }

    public function addDistinctAcrossRotationAt(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $nowMilliseconds): int
    {
        // Held on the delegate, through this instance's own method, so a subclass override applies.
        return $this->correlationStore->atCallerTime(intdiv($nowMilliseconds, 1000), fn(): int => $this->addDistinctAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds));
    }

    public function incrementWatchFlagAcrossRotationAt(string $currentKey, string $previousKey, int $ttlSeconds, int $now): int
    {
        // Held on the delegate, through this instance's own method, so a subclass override applies.
        return $this->correlationStore->atCallerTime($now, fn(): int => $this->incrementWatchFlagAcrossRotation($currentKey, $previousKey, $ttlSeconds));
    }

    public function addDistinctBoundedAcrossRotationAt(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct, int $nowMilliseconds): BoundedDistinctResultDTO
    {
        // Held on the delegate, through this instance's own method, so a subclass override applies.
        return $this->correlationStore->atCallerTime(intdiv($nowMilliseconds, 1000), fn(): BoundedDistinctResultDTO => $this->addDistinctBoundedAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct));
    }

    public function addDistinctBoundedWithSnapshotAcrossRotationAt(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct, int $nowMilliseconds): BoundedDistinctSnapshotDTO
    {
        // Held on the delegate, through this instance's own method, so a subclass override applies.
        return $this->correlationStore->atCallerTime(intdiv($nowMilliseconds, 1000), fn(): BoundedDistinctSnapshotDTO => $this->addDistinctBoundedWithSnapshotAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct));
    }
}
