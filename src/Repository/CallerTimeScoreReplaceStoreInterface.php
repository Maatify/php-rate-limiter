<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\RateLimitStateDTO;

/**
 * Caller-time mirror of `ScoreReplaceStoreInterface` (#153 phase 2, DEC-016 rule 1; #185, F-185-17): the caller's
 * semantic time (a non-negative Unix timestamp, DEC-017) is the `updatedAt` written. A store that does not implement it
 * keeps the store-clock method; the runtime then calls `replaceScore()`.
 */
interface CallerTimeScoreReplaceStoreInterface extends ScoreReplaceStoreInterface, CallerTimeRateLimitStoreInterface
{
    /** `replaceScore()` with `$now` as the written `updatedAt`. */
    public function replaceScoreAt(string $key, int $value, int $ttlSeconds, int $now): ?RateLimitStateDTO;
}
