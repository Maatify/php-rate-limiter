<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO;

/**
 * Atomic storage capability for the generation-bound authentication K4 lifecycle.
 *
 * Implementations resolve the current score first and use the previous score
 * only as a read-only rotation fallback. Legacy generation-less state remains
 * readable and is upgraded on its first real mutation without resetting its
 * score or remaining TTL. Generated state must carry authoritative expiry.
 * Mutation methods use optimistic state identity: a stale snapshot returns an
 * unapplied result, while malformed generated state raises the backend failure
 * path. Lifecycle publication atomically couples the hard block, cycle/pause
 * accounting, and generation evidence; the later public claim creates and
 * consumes an independent Current-only one-shot marker.
 */
interface PunishmentLifecycleStoreInterface extends HardBlockCycleStoreInterface
{
    /**
     * Reads one coherent current/previous K4 snapshot without mutating state.
     *
     * Resolution is Current-first: Current is read when it has live state,
     * and Previous is consulted only as a read-only fallback when Current
     * does not. The result is `null` only when neither logical source has
     * live state; it is never used to signal a storage problem.
     *
     * A malformed core score field (an unparsable or non-integer `value` or
     * `updatedAt`) is an explicit failure. A stored generation that is
     * present but not a positive integer is an explicit failure; a
     * generated score additionally requires an authoritative `expiresAt`,
     * and a missing, non-integer, non-positive, or physically inconsistent
     * expiry is an explicit failure. Lifecycle evidence must be either
     * wholly absent or a complete, structurally valid tuple; a partial or
     * otherwise malformed tuple is an explicit failure, and so is complete,
     * structurally valid evidence attached to a generation-less (legacy)
     * score — that combination is impossible persisted state, not hidden
     * evidence. None of this is confused with an ordinary, structurally
     * valid but stale, expired, mismatched, or block-suppressed evidence
     * tuple, which is simply omitted from the returned snapshot rather than
     * raising a failure.
     *
     * @return ?GenerationBoundScoreStateDTO Selecting current over previous,
     * or null when neither logical source has live state.
     */
    public function readGenerationBoundScoreState(string $currentKey, ?string $previousKey): ?GenerationBoundScoreStateDTO;

    /**
     * Applies one score mutation fenced by the supplied snapshot.
     *
     * A null expected state means create-from-empty and conflicts are reported
     * by an unapplied DTO. Applied mutations write only current state, advance
     * the generation, preserve legacy remaining TTL, and return the new state.
     *
     * An ordinary stale optimistic snapshot — the observed state no longer
     * matches `$expectedState` — is reported as `applied=false`, never an
     * exception. That is distinct from structurally malformed persisted
     * state, which always raises an exception regardless of whether it
     * happens to also be stale: a malformed core field (`value`/`updatedAt`),
     * a stored generation that is present but not a positive integer, a
     * malformed, missing, or physically inconsistent generated-score expiry,
     * a malformed lifecycle evidence tuple, and a generation-less (legacy)
     * score carrying complete lifecycle evidence are all explicit failures,
     * not conflicts.
     *
     * @return GenerationBoundScoreMutationDTO Applied state or an optimistic
     * concurrency miss; structurally malformed persisted state raises an
     * exception instead.
     */
    public function mutateGenerationBoundScore(
        string $currentKey,
        ?string $previousKey,
        ?GenerationBoundScoreStateDTO $expectedState,
        int $ttlSeconds,
        int $newValue,
    ): GenerationBoundScoreMutationDTO;

    /**
     * Atomically publishes a hard block with DEC-003 cycle/pause accounting
     * and generation-bound post-punishment evidence.
     *
     * Publication requires a Current generated score as its source; Previous
     * is historical, read-only input and is never itself a publishable
     * source. `$expectedGeneration` must be a positive integer, `$level`
     * must be L2 or higher, and `$durationSeconds`, `$cycleWindowSeconds`,
     * `$cycleThreshold`, `$pauseSeconds`, and `$pauseHistoryRetentionSeconds`
     * must all be positive; any violation is an explicit contract-precondition
     * failure before any storage access.
     *
     * Source-race semantics: structural validation of the persisted state
     * always runs before any optimistic comparison. When Current is present
     * and structurally valid, a stored generation that simply does not match
     * `$expectedGeneration` is an ordinary, non-exceptional unapplied
     * conflict — never a backend-health or corruption failure — and writes
     * no partial state. When Current is absent, the absence of any live
     * source, and a structurally valid Previous (generated or legacy) alike,
     * are the same ordinary unapplied conflict: Previous being read-only
     * history rather than a publishable source is not itself a programming
     * failure, and Previous is left untouched either way. Only structurally
     * malformed persisted state is an explicit failure rather than a
     * conflict: a stored Current generation that is missing, non-integer, or
     * not a positive integer; a malformed core score field; a malformed or
     * physically inconsistent expiry; partial lifecycle evidence; a
     * generation-less (legacy) score carrying complete lifecycle evidence;
     * and, when Current is absent, a Previous that is itself persisted
     * without a physical deadline. When Current is absent, Previous is
     * classified by running this exact same structural validation —
     * core fields, generation, authoritative expiry, physical-versus-
     * authoritative expiry consistency, and lifecycle evidence structure
     * and legacy-impossibility — before it can be treated as an ordinary
     * conflict; a Previous that fails any of those checks is the same
     * explicit failure as it would be anywhere else in the lifecycle
     * contract, never silently downgraded to an ordinary conflict.
     *
     * Stable lifecycle identity: when the resolved Current source already
     * carries valid lifecycle evidence for this same exact generation (its
     * `validUntil` still equals the score's authoritative expiry), a
     * republication for that generation preserves the existing lifecycle
     * identity and does not adopt `$proposedLifecycleId`. A publication for
     * a different (newer) generation always establishes a new identity.
     */
    public function blockWithPunishmentLifecycleTracking(
        string $currentKey,
        ?string $previousKey,
        int $expectedGeneration,
        string $proposedLifecycleId,
        int $level,
        int $durationSeconds,
        int $cycleWindowSeconds,
        int $cycleThreshold,
        int $pauseSeconds,
        int $pauseHistoryRetentionSeconds,
    ): PunishmentLifecycleTransitionDTO;

    /**
     * Claims one published lifecycle identity exactly once.
     *
     * Stale, expired, mismatched, absent, or already-claimed evidence returns
     * false without mutating score, block, cycle, budget, or generation state.
     * Structural corruption raises the storage failure path.
     */
    public function claimPostPunishmentReentry(string $currentKey, ?string $previousKey, string $lifecycleId): bool;
}
