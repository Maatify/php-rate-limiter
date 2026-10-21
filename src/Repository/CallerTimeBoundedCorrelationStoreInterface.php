<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;

/**
 * Caller-time mirror of `BoundedCorrelationStoreInterface`.
 *
 * Additive caller-time capability (#153 phase 2, DEC-016 rule 1): the same
 * operations as `BoundedCorrelationStoreInterface`, with the caller's semantic time `$now` (a
 * non-negative Unix timestamp in seconds, DEC-017) as the only time authority
 * for every stored and compared timestamp. A store that does not implement it
 * keeps its existing behaviour; the runtime then calls the existing methods.
 */
interface CallerTimeBoundedCorrelationStoreInterface extends BoundedCorrelationStoreInterface, CallerTimeCorrelationStoreInterface
{
    /**
     * `addDistinctBounded()`, judged at `$now`.
     */
    public function addDistinctBoundedAt(string $key, string $item, int $ttlSeconds, int $maxDistinct, int $now): BoundedDistinctResultDTO;
}
