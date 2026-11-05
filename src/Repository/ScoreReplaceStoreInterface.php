<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\RateLimitStateDTO;

/**
 * Write a score and return the one it replaced, atomically (#185, F-185-17, DEC-031).
 *
 * The runtime keeps auxiliary markers (the last failure without a fingerprint) whose previous value decides the request's
 * delta. Reading the marker and writing it in two calls let two concurrent requests both read "no marker". This method
 * reads and writes in one atomic step; the TTL is set on every write, as `set()` sets it.
 *
 * `build()` requires it whenever a registered policy has `k4_repeated_missing_fp > 0`.
 */
interface ScoreReplaceStoreInterface extends RateLimitStoreInterface
{
    /**
     * Sets the score to `$value` (with `updatedAt` = the store's current time and a fresh TTL of `$ttlSeconds`) and
     * returns the state it replaced, or null when there was none. Malformed persisted state is an explicit failure and is
     * not overwritten.
     */
    public function replaceScore(string $key, int $value, int $ttlSeconds): ?RateLimitStateDTO;
}
