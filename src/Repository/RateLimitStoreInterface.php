<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;

/**
 * Persistence contract for scores, blocks, budgets, and backend health.
 */
interface RateLimitStoreInterface
{
    /**
     * Increment a counter atomically.
     * If the key does not exist, it is created with the given TTL.
     * If it exists, the TTL is NOT updated.
     *
     * @param string $key
     * @param int $ttlSeconds
     * @param int $amount
     * @return int The new value
     */
    public function increment(string $key, int $ttlSeconds, int $amount = 1): int;

    /**
     * Get current counter value and metadata.
     *
     * @param string $key
     * @return RateLimitStateDTO|null
     */
    public function get(string $key): ?RateLimitStateDTO;

    /**
     * Set a value (overwrite).
     *
     * @param string $key
     * @param int $value
     * @param int $ttlSeconds
     * @return void
     */
    public function set(string $key, int $value, int $ttlSeconds): void;

    /**
     * Set a block on a key.
     *
     * @param string $key
     * @param int $level Block level (L1-L6)
     * @param int $durationSeconds
     * @return void
     */
    public function block(string $key, int $level, int $durationSeconds): void;

    /**
     * Check if a key is blocked.
     *
     * @param string $key
     * @return BlockStateDTO|null
     */
    public function checkBlock(string $key): ?BlockStateDTO;

    /**
     * Return the active persisted budget epoch for the key.
     *
     * Absent or expired state is returned as null and is not exposed as an
     * active budget. The returned count and epochStart describe the effective
     * persisted state. Reading never creates, refreshes, or renews an epoch.
     * @param string $key
     * @return BudgetStateDTO|null
     */
    public function getBudget(string $key): ?BudgetStateDTO;

    /**
     * Atomically initialize or increment a fixed budget epoch.
     *
     * Absent or expired state starts a fresh epoch with count equal to the
     * complete amount. Active state increments by the complete amount and
     * preserves its original epochStart; active mutation does not renew or
     * extend the fixed epoch end or TTL. The returned DTO is the resulting
     * persisted state. Implementations must preserve these fixed-window
     * semantics for package services and consumers, and must reject a
     * backend-impossible expiry before its first state-changing command.
     *
     * @param string $key
     * @param int $epochDurationSeconds
     * @param int $amount
     * @return BudgetStateDTO
     */
    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO;

    /**
     * Check backend health.
     *
     * @return bool
     */
    public function isHealthy(): bool;
}
