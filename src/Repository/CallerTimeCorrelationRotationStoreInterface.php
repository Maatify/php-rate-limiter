<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

/**
 * Caller-time mirror of `CorrelationRotationStoreInterface`.
 *
 * Additive caller-time capability (#153 phase 2, DEC-016 rule 1): the same
 * operations as `CorrelationRotationStoreInterface`, with the caller's semantic time (a non-negative Unix
 * timestamp, DEC-017; `$now` in seconds, `$nowMilliseconds` in milliseconds
 * where the operation derives a millisecond lifetime) as the only time authority
 * for every stored and compared timestamp. A store that does not implement it
 * keeps its existing behaviour; the runtime then calls the existing methods.
 */
interface CallerTimeCorrelationRotationStoreInterface extends CorrelationRotationStoreInterface, CallerTimeCorrelationStoreInterface
{
    /**
     * `addDistinctAcrossRotation()`, judged at the caller's time in
     * milliseconds (the bridge lifetime is derived in milliseconds).
     */
    public function addDistinctAcrossRotationAt(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
        int $nowMilliseconds,
    ): int;

    /**
     * `incrementWatchFlagAcrossRotation()`, judged at `$now`.
     */
    public function incrementWatchFlagAcrossRotationAt(string $currentKey, string $previousKey, int $ttlSeconds, int $now): int;
}
