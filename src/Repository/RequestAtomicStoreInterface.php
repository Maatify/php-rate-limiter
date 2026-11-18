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
 * - A `BackendFailureException` is not retried; it is thrown so that the engine's failure handling applies (DEC-015).
 *   What it says about the save depends on when it was raised. A failure while `$evaluate` runs, or a save the
 *   backend never received, leaves nothing saved. A failure while the implementation waits for the reply to the save
 *   can follow a save the backend did apply: the save is atomic, so it is then complete and never partial, but the
 *   caller cannot know which. Treat the outcome of that failure as unknown, not as "nothing saved".
 * - Called while one of its own requests is already being evaluated, it runs `$evaluate` inside that request and
 *   does not start another save, **if the call comes from the execution context of the call that owns the request while no
 *   Host crossing (executor, emitter, clock, resolver, provider) of that call is open** (DEC-041). Any other call, from another
 *   execution context or from inside a Host crossing, is an independent call: the implementation refuses it with
 *   `OverlappingRequestException` before it can alter or observe the request in flight, and it never joins that request. The
 *   refusal is not retried and is neither a backend failure nor a concurrency conflict.
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
     * current is thrown unchanged, with nothing saved, and is not retried. A `BackendFailureException` is not retried
     * either: raised before the save was applied it leaves nothing saved, but raised while awaiting the reply to the
     * save it leaves the outcome unknown, because the save may have been applied completely (never in part).
     *
     * @template T
     * @param callable(): T $evaluate repeatable evaluation of the whole request; it may run several times and must
     *                                not emit signals or change anything outside the store
     * @return T
     */
    public function transactRequest(callable $evaluate): mixed;
}
