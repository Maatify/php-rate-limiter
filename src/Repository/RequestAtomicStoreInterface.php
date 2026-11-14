<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

/**
 * A store that can make one whole evaluation request a single atomic save (DEC-037).
 *
 * The contract has two parts: what every implementation owes the engine (below), and the attempt and
 * time limits, which an implementation chooses for itself. `RedisFullCapabilityStore` documents the limits it
 * applies at `transactRequest()`.
 *
 * Guarantees of every implementation:
 *
 * - `$evaluate` runs with every store write held back: each step sees the results of the earlier ones as if they
 *   were already stored, but nothing reaches the backend until `$evaluate` has returned.
 * - The held changes are saved together, all or none, and only if every state the evaluation read is still
 *   unchanged. A request is never saved in part.
 * - When the state changed, nothing is saved and `$evaluate` runs again from a fresh read, so it may run more than
 *   once: it must be repeatable and must not emit signals.
 * - The recalculation is bounded. When the implementation stops, it saves nothing and throws
 *   `RateLimitConcurrencyException`, which the engine answers with its concurrency block (DEC-029). A call never
 *   retries without a limit.
 * - When `$evaluate` throws, its changes are never saved. The error is classified by the state it was raised over. An
 *   error over state that is still current is the request's answer: it is thrown unchanged and is not retried.
 *   An error over state that changed since it was read may come from a combination of reads that no single moment
 *   held, so an implementation may treat it as a conflict and run `$evaluate` again within its bound; the bundled
 *   Redis store does.
 * - A `BackendFailureException` is not retried: it is thrown with nothing saved, so that the engine's failure
 *   handling applies (DEC-015).
 * - Called while one of its own requests is already being evaluated, it runs `$evaluate` inside that request and
 *   does not start another save.
 */
interface RequestAtomicStoreInterface
{
    /**
     * Run `$evaluate` as one request and save everything it changed atomically.
     *
     * Returns what `$evaluate` returned on the evaluation that was saved. That evaluation is the last one that ran:
     * the results of an earlier, discarded evaluation are never returned.
     *
     * Failure: when the state kept changing until the implementation's recalculation bound was reached, it throws
     * `RateLimitConcurrencyException` with nothing saved. An error that `$evaluate` throws over state that was still
     * current, and a `BackendFailureException`, are thrown with nothing saved and are not retried.
     *
     * @template T
     * @param callable(): T $evaluate repeatable evaluation of the whole request; it may run several times and must
     *                                not emit signals or change anything outside the store
     * @return T
     */
    public function transactRequest(callable $evaluate): mixed;
}
