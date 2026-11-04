<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

/**
 * A score write fenced by the snapshot it was computed from (#185, F-185-08, DEC-030).
 *
 * The runtime reads a score, applies its decay, and writes back. A write that carries a decay credit (the new value is
 * below the stored value plus the request's delta) is only correct against the snapshot it was computed from: two
 * concurrent requests on the same snapshot would both apply the same credit. Such a write goes through this method; every
 * other score write stays an additive `increment()`.
 *
 * `build()` requires this capability whenever a registered policy writes a score (any positive score delta).
 */
interface SnapshotFencedScoreStoreInterface extends RateLimitStoreInterface
{
    /**
     * Atomically sets the score to `$newValue` only while the stored `(value, updatedAt)` still equals the expected
     * snapshot; a null expected snapshot means the score must be absent. `updatedAt` becomes the store's current time;
     * the TTL applies only when the score is created, as in `increment()`.
     *
     * Both expected fields are null or both are integers. A miss (the stored state differs) is an ordinary outcome and
     * returns null; malformed persisted state is an explicit failure, never a miss.
     *
     * @return ?int The stored value after the write, or null on a miss.
     */
    public function incrementIfSnapshot(string $key, int $ttlSeconds, ?int $expectedValue, ?int $expectedUpdatedAt, int $newValue): ?int;
}
