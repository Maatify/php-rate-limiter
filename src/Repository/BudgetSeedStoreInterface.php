<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\BudgetStateDTO;

/**
 * Additive capability over RateLimitStoreInterface for atomic budget-epoch
 * hand-off across key rotation (KEY_STRATEGY.md §4.3.2).
 *
 * RateLimitStoreInterface itself is intentionally unchanged, so every store
 * that implements the base contract remains source-compatible.
 */
interface BudgetSeedStoreInterface extends RateLimitStoreInterface
{
    /**
     * Atomically seed (or not) and increment a budget counter on the target key.
     *
     * The operation is atomic with respect to the target key: a concurrent
     * initialization MUST NOT duplicate the seed or lose increments.
     *
     * Locked semantics (KEY_STRATEGY.md §4.3.2):
     * - If a valid V2 budget already exists, the seed is ignored and the
     *   existing V2 count is incremented by $amount while the existing V2
     *   epochStart is preserved.
     * - If no valid V2 budget exists and the seed is still inside its epoch
     *   (now < seed.epochStart + epochDurationSeconds), V2 is initialized with
     *   count = seed.count + $amount and epochStart = seed.epochStart; the
     *   epoch end stays fixed at seed.epochStart + epochDurationSeconds and is
     *   never reset to now.
     * - If the seed is expired
     *   (now >= seed.epochStart + epochDurationSeconds), a normal new epoch
     *   starts: count = $amount, epochStart = now.
     *
     * @param string $key
     * @param int $epochDurationSeconds
     * @param BudgetStateDTO $seed
     * @param int $amount
     * @return BudgetStateDTO
     */
    public function incrementBudgetWithSeed(
        string $key,
        int $epochDurationSeconds,
        BudgetStateDTO $seed,
        int $amount = 1
    ): BudgetStateDTO;
}
