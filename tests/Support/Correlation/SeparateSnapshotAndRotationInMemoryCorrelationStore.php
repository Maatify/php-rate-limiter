<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Correlation;

use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;
use Maatify\RateLimiter\Repository\BoundedCorrelationRotationStoreInterface;
use Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotStoreInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Test double implementing BoundedCorrelationRotationStoreInterface and
 * BoundedCorrelationSnapshotStoreInterface as two separate capabilities,
 * while deliberately NOT implementing the combined
 * BoundedCorrelationSnapshotRotationStoreInterface (PHP `instanceof` checks
 * are nominal, not structural, so having both underlying methods does not
 * make this store an instance of the combined interface).
 *
 * Used to prove that a DISTRIBUTED_ACCOUNT-capable policy with a reachable
 * previous generation specifically requires the combined snapshot+rotation
 * capability, not merely both capabilities held separately.
 */
final class SeparateSnapshotAndRotationInMemoryCorrelationStore implements
    BoundedCorrelationRotationStoreInterface,
    BoundedCorrelationSnapshotStoreInterface
{
    private readonly StatefulInMemoryCorrelationStore $delegate;

    public function __construct(ClockInterface $clock)
    {
        $this->delegate = new StatefulInMemoryCorrelationStore($clock);
    }

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        return $this->delegate->addDistinct($key, $item, $ttlSeconds);
    }

    public function addDistinctBounded(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO {
        return $this->delegate->addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
    }

    public function addDistinctBoundedWithSnapshot(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctSnapshotDTO {
        return $this->delegate->addDistinctBoundedWithSnapshot($key, $item, $ttlSeconds, $maxDistinct);
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
        return $this->delegate->addDistinctBoundedAcrossRotation(
            $currentKey,
            $bridgeKey,
            $previousKey,
            $currentMember,
            $previousMember,
            $ttlSeconds,
            $maxDistinct,
        );
    }

    public function addDistinctAcrossRotation(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
    ): int {
        return $this->delegate->addDistinctAcrossRotation(
            $currentKey,
            $bridgeKey,
            $previousKey,
            $currentMember,
            $previousMember,
            $ttlSeconds,
        );
    }

    public function incrementWatchFlagAcrossRotation(string $currentKey, string $previousKey, int $ttlSeconds): int
    {
        return $this->delegate->incrementWatchFlagAcrossRotation($currentKey, $previousKey, $ttlSeconds);
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        return $this->delegate->incrementWatchFlag($key, $ttlSeconds);
    }

    public function getWatchFlag(string $key): int
    {
        return $this->delegate->getWatchFlag($key);
    }
}
