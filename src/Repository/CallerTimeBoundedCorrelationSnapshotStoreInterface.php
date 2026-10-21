<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;

/**
 * Caller-time mirror of `BoundedCorrelationSnapshotStoreInterface`.
 *
 * Additive caller-time capability (#153 phase 2, DEC-016 rule 1): the same
 * operations as `BoundedCorrelationSnapshotStoreInterface`, with the caller's semantic time `$now` (a
 * non-negative Unix timestamp in seconds, DEC-017) as the only time authority
 * for every stored and compared timestamp. A store that does not implement it
 * keeps its existing behaviour; the runtime then calls the existing methods.
 */
interface CallerTimeBoundedCorrelationSnapshotStoreInterface extends BoundedCorrelationSnapshotStoreInterface, CallerTimeBoundedCorrelationStoreInterface
{
    /**
     * `addDistinctBoundedWithSnapshot()`, judged at `$now`; a created window
     * returns `expiresAt` = `$now + $ttlSeconds`.
     */
    public function addDistinctBoundedWithSnapshotAt(string $key, string $item, int $ttlSeconds, int $maxDistinct, int $now): BoundedDistinctSnapshotDTO;
}
