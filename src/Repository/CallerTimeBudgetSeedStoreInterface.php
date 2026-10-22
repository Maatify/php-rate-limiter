<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\BudgetStateDTO;

/**
 * Caller-time mirror of `BudgetSeedStoreInterface`.
 *
 * Additive caller-time capability (#153 phase 2, DEC-016 rule 1): the same
 * operations as `BudgetSeedStoreInterface`, with the caller's semantic time `$now` (a
 * non-negative Unix timestamp in seconds, DEC-017) as the only time authority
 * for every stored and compared timestamp. A store that does not implement it
 * keeps its existing behaviour; the runtime then calls the existing methods.
 */
interface CallerTimeBudgetSeedStoreInterface extends BudgetSeedStoreInterface, CallerTimeRateLimitStoreInterface
{
    /**
     * The seeded increment of `incrementBudgetWithSeed()`, judged at `$now`.
     */
    public function incrementBudgetWithSeedAt(string $key, int $epochDurationSeconds, BudgetStateDTO $seed, int $amount, int $now): BudgetStateDTO;
}
