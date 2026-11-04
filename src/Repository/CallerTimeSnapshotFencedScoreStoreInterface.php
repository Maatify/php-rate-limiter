<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

/**
 * Caller-time mirror of `SnapshotFencedScoreStoreInterface` (#153 phase 2, DEC-016 rule 1; #185, F-185-08): the caller's
 * semantic time (a non-negative Unix timestamp, DEC-017) is the `updatedAt` written. A store that does not implement it
 * keeps the store-clock method; the runtime then calls `incrementIfSnapshot()`.
 */
interface CallerTimeSnapshotFencedScoreStoreInterface extends SnapshotFencedScoreStoreInterface, CallerTimeRateLimitStoreInterface
{
    /** `incrementIfSnapshot()` with `$now` as the written `updatedAt`. */
    public function incrementIfSnapshotAt(string $key, int $ttlSeconds, ?int $expectedValue, ?int $expectedUpdatedAt, int $newValue, int $now): ?int;
}
