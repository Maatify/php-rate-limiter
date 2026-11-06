<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;

/**
 * Caller-time mirror of `OperationalReadOnlyStoreInterface` (#153 phase 2, DEC-016 rule 1; #185, F-185-26, DEC-032):
 * the caller's semantic time `$now` (a non-negative Unix timestamp, DEC-017) decides expiry. A store that does not
 * implement it keeps the store-clock methods; the readers then call `peekBlock()` / `peekBudget()`.
 */
interface CallerTimeOperationalReadOnlyStoreInterface extends OperationalReadOnlyStoreInterface, CallerTimeRateLimitStoreInterface
{
    /** `checkBlockAt()` without a write: a block whose expiry is not after `$now` is null and stays where it is. */
    public function peekBlockAt(string $key, int $now): ?BlockStateDTO;

    /** `getBudgetAt()` without a write: a budget whose epoch has ended at `$now` is null and stays where it is. */
    public function peekBudgetAt(string $key, int $now): ?BudgetStateDTO;
}
