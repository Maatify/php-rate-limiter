<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;

/**
 * Caller-time mirror of `RateLimitStoreInterface`.
 *
 * Additive caller-time capability (#153 phase 2, DEC-016 rule 1): the same
 * operations as `RateLimitStoreInterface`, with the caller's semantic time `$now` (a
 * non-negative Unix timestamp in seconds, DEC-017) as the only time authority
 * for every stored and compared timestamp. A store that does not implement it
 * keeps its existing behaviour; the runtime then calls the existing methods.
 */
interface CallerTimeRateLimitStoreInterface extends RateLimitStoreInterface
{
    /**
     * Increment a score; on creation `updatedAt` is `$now` and the TTL starts.
     */
    public function incrementAt(string $key, int $ttlSeconds, int $amount, int $now): int;

    /**
     * Overwrite a score with `updatedAt` = `$now` and a fresh TTL.
     */
    public function setAt(string $key, int $value, int $ttlSeconds, int $now): void;

    /**
     * Write a block expiring at `$now + $durationSeconds`.
     */
    public function blockAt(string $key, int $level, int $durationSeconds, int $now): void;

    /**
     * Read a block; a block whose expiry is not after `$now` is absent.
     */
    public function checkBlockAt(string $key, int $now): ?BlockStateDTO;

    /**
     * Read a budget epoch; an epoch whose end is not after `$now` is absent.
     */
    public function getBudgetAt(string $key, int $now): ?BudgetStateDTO;

    /**
     * Increment a budget, starting a fresh epoch at `$now` when none is active.
     */
    public function incrementBudgetAt(string $key, int $epochDurationSeconds, int $amount, int $now): BudgetStateDTO;
}
