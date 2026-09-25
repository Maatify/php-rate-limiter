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
     * @return GenerationBoundScoreMutationDTO Applied state or an optimistic
     * concurrency miss; malformed generated state raises an exception.
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
     * Publication requires a Current generated score; Previous is historical,
     * read-only input and is never itself a publishable source. `$expectedGeneration`
     * must be a positive integer, `$level` must be L2 or higher, and
     * `$durationSeconds`, `$cycleWindowSeconds`, `$cycleThreshold`,
     * `$pauseSeconds`, and `$pauseHistoryRetentionSeconds` must all be
     * positive; any violation is an explicit contract-precondition failure
     * before any storage access.
     *
     * Structural validation of the persisted state runs before the
     * generation fence is compared. A stored generation that is missing,
     * non-integer, or not a positive integer is malformed persisted state and
     * raises an explicit failure; the same applies to a malformed core score
     * field, a malformed or physically inconsistent expiry, and partial or
     * otherwise structurally invalid lifecycle evidence — including a
     * generation-less (legacy) score that carries complete lifecycle
     * evidence, which is an impossible persisted combination. Only once the
     * stored generation is confirmed structurally valid does a mismatch
     * against `$expectedGeneration` become an ordinary, non-exceptional
     * unapplied transition; that stale-generation conflict never writes
     * partial state. When no Current generated score exists, a Previous
     * score that is itself persisted without a physical deadline or that is
     * structurally malformed also raises an explicit failure rather than
     * being silently treated as a Current-only contract violation.
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
