<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

/**
 * A store that can make one whole evaluation request a single atomic save.
 *
 * `transactRequest()` runs `$evaluate` with every store write held back: each
 * step sees the results of the earlier ones as if they were already stored,
 * but nothing reaches the backend until `$evaluate` has returned. The held
 * changes are then saved together, and only if every state the evaluation read
 * is unchanged; otherwise nothing is saved and `$evaluate` runs again from a
 * fresh read. When `$evaluate` throws, nothing is saved. Because it may run
 * more than once it must be repeatable and must not emit signals.
 */
interface RequestAtomicStoreInterface
{
    /**
     * @template T
     * @param callable(): T $evaluate
     * @return T
     */
    public function transactRequest(callable $evaluate): mixed;
}
