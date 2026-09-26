<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;

/**
 * Additive snapshot capability preserving one coordinated previous generation.
 */
interface BoundedCorrelationSnapshotRotationStoreInterface extends
    BoundedCorrelationSnapshotStoreInterface,
    BoundedCorrelationRotationStoreInterface
{
    /**
     * Observe one member across current, bridge, and read-only previous state.
     */
    public function addDistinctBoundedWithSnapshotAcrossRotation(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctSnapshotDTO;
}
