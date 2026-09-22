<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;

/**
 * Additive capability for atomic, bounded distinct correlation observations.
 */
interface BoundedCorrelationStoreInterface extends CorrelationStoreInterface
{
    /**
     * Add a logical member only while the fixed-capacity set has room.
     *
     * Membership, cardinality, capacity, insertion, and first-TTL setup are
     * one atomic backend operation. A known member is accepted at capacity;
     * a new member at capacity is rejected without mutation.
     */
    public function addDistinctBounded(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO;
}
