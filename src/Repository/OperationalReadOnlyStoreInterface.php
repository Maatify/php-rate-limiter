<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;

/**
 * Read-only block and budget reads for Operational Read (#185, F-185-26, DEC-032).
 *
 * `checkBlock()` and `getBudget()` delete a logically expired key as they read it (LT-P2-03): right for the runtime,
 * which writes anyway, and wrong for the operational readers, which are documented as read-only. These two methods read
 * with the same validation (malformed state is an explicit failure) and answer null for an expired block or a budget
 * past its epoch, but they write nothing: no delete, no TTL change.
 *
 * `buildOperationalReader()` requires it and never falls back to the deleting reads. A reader built through its own
 * constructor over a store without it keeps `checkBlock()` / `getBudget()` (DEC-012 §4).
 */
interface OperationalReadOnlyStoreInterface extends RateLimitStoreInterface
{
    /** `checkBlock()` without a write: an expired block is null and stays where it is. */
    public function peekBlock(string $key): ?BlockStateDTO;

    /** `getBudget()` without a write: a budget past its epoch is null and stays where it is. */
    public function peekBudget(string $key): ?BudgetStateDTO;
}
