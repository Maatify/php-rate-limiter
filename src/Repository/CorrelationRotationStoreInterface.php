<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

/**
 * Additive capability for preserving credential-spray correlation across key rotation.
 *
 * Implementations must perform each operation atomically. The current generation
 * is writable; the previous generation is read-only and must never have its TTL
 * refreshed or its members changed.
 */
interface CorrelationRotationStoreInterface extends CorrelationStoreInterface
{
    /**
     * Add a current-generation member while carrying forward active previous state.
     *
     * The returned value is the effective distinct count: previous cardinality plus
     * current-generation bridge cardinality while the previous window is active.
     *
     * @throws \Throwable When previous state is corrupt or atomicity cannot be provided.
     */
    public function addDistinctAcrossRotation(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
    ): int;

    /**
     * Increment only the current watch flag and include active previous state.
     *
     * The returned value is the current count plus the read-only previous count.
     *
     * @throws \Throwable When previous state is corrupt or atomicity cannot be provided.
     */
    public function incrementWatchFlagAcrossRotation(
        string $currentKey,
        string $previousKey,
        int $ttlSeconds,
    ): int;
}
