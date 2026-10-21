<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

/**
 * Caller-time mirror of `CorrelationStoreInterface`.
 *
 * Additive caller-time capability (#153 phase 2, DEC-016 rule 1): the same
 * operations as `CorrelationStoreInterface`, with the caller's semantic time `$now` (a
 * non-negative Unix timestamp in seconds, DEC-017) as the only time authority
 * for every stored and compared timestamp. A store that does not implement it
 * keeps its existing behaviour; the runtime then calls the existing methods.
 */
interface CallerTimeCorrelationStoreInterface extends CorrelationStoreInterface
{
    /**
     * Add a distinct member; a window whose logical expiry is not after `$now` restarts.
     */
    public function addDistinctAt(string $key, string $item, int $ttlSeconds, int $now): int;

    /**
     * Increment a watch flag; a flag whose logical expiry is not after `$now` restarts.
     */
    public function incrementWatchFlagAt(string $key, int $ttlSeconds, int $now): int;
}
