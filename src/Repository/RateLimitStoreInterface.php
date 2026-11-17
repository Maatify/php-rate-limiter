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
     * The new value is returned as a native `int`, so it must be representable by the PHP build of the caller (on a build
     * with `PHP_INT_SIZE` 4 that ends at 2147483647 and -2147483648, on 64-bit builds at the 64-bit limits). When the
     * resulting value is outside that range, or a stored value already is, the call fails explicitly before any stored
     * value, timestamp or expiry changes; an implementation must not clamp, wrap or write a value its caller cannot read
     * back, and the check and the change are one atomic operation. It is a failure of the score calculation, not a backend
     * outage: it is not classified as a `BackendFailureException` and starts no fallback. A direct call is not retried by the
     * package, and a refusal over state that is still current inside `transactRequest()` is thrown unchanged and not retried
     * (DEC-037). Inside `transactRequest()` the evaluation runs again, within its bound, when the state it read changed
     * before the save, so a refusal raised over state that has since changed is not final: the recalculation can succeed or
     * refuse again on the newer state, and nothing of a refused evaluation is saved.
     *
     * The type that carries the failure belongs to the component that detects it. A calculation made by the implementation
     * itself (a custom store, the runnable example stores) fails with `RateLimiterException`. An error reply of a backend
     * script reaches the caller as the Host's `RedisCommandExecutorInterface` surfaces it and is not normalized (DEC-018):
     * the official Redis store rejects the result inside its script with the message `score integer overflow`, and through
     * a native client bridge that error can arrive as the client's own exception (for example phpredis `RedisException`)
     * rather than as `RateLimiterException`. A caller must therefore not rely on one exception class for every backend.
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
