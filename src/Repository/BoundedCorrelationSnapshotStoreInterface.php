<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;

/**
 * Additive capability for atomic bounded correlation snapshots.
 */
interface BoundedCorrelationSnapshotStoreInterface extends BoundedCorrelationStoreInterface
{
    /**
     * Observe one member and return the complete bounded logical snapshot.
     *
     * Membership, capacity, insertion, initial TTL, and snapshot creation are
     * one atomic backend operation. A duplicate is accepted without mutation;
     * a new member at capacity is rejected without set growth.
     */
    public function addDistinctBoundedWithSnapshot(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctSnapshotDTO;
}
