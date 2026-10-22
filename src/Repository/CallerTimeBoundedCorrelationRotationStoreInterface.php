<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;

/**
 * Caller-time mirror of `BoundedCorrelationRotationStoreInterface`.
 *
 * Additive caller-time capability (#153 phase 2, DEC-016 rule 1): the same
 * operations as `BoundedCorrelationRotationStoreInterface`, with the caller's semantic time (a non-negative Unix
 * timestamp, DEC-017; `$now` in seconds, `$nowMilliseconds` in milliseconds
 * where the operation derives a millisecond lifetime) as the only time authority
 * for every stored and compared timestamp. A store that does not implement it
 * keeps its existing behaviour; the runtime then calls the existing methods.
 */
interface CallerTimeBoundedCorrelationRotationStoreInterface extends BoundedCorrelationRotationStoreInterface, CallerTimeBoundedCorrelationStoreInterface, CallerTimeCorrelationRotationStoreInterface
{
    /**
     * `addDistinctBoundedAcrossRotation()`, judged at the caller's time in milliseconds.
     */
    public function addDistinctBoundedAcrossRotationAt(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
        int $maxDistinct,
        int $nowMilliseconds,
    ): BoundedDistinctResultDTO;
}
