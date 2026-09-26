<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

/**
 * Persistence boundary for bounded distinct sets and watch flags.
 *
 * Concrete implementations must establish the initial TTL atomically with the
 * first mutation and must not refresh that TTL on later mutations. A malformed
 * backend result or an operation that cannot provide these guarantees is a
 * failure; it must not be converted into a silent zero.
 */
interface CorrelationStoreInterface
{
    /**
     * Add an item to a fixed-TTL set and return its current cardinality.
     *
     * @param string $key
     * @param string $item
     * @param int $ttlSeconds
     * @return int Current distinct count.
     * @throws \Throwable When the backend result is malformed or atomicity fails.
     */
    public function addDistinct(string $key, string $item, int $ttlSeconds): int;

    /**
     * Increment a fixed-TTL watch flag counter used for Anti-N-1 protection.
     *
     * @param string $key
     * @param int $ttlSeconds
     * @return int Current value of the flag.
     * @throws \Throwable When the backend result is malformed or atomicity fails.
     */
    public function incrementWatchFlag(string $key, int $ttlSeconds): int;

    /**
     * Get the value of a watch flag.
     *
     * @param string $key
     * @return int Current value, or zero when the flag is absent or expired.
     * @throws \Throwable When the backend result is malformed.
     */
    public function getWatchFlag(string $key): int;
}
