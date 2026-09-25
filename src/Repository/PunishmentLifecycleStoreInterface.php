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
 * accounting, generation evidence, and one-shot claim marker.
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
     * The expected generation fences publication. A stale generation returns
     * an unapplied transition; an applied transition contains every resulting
     * state object and exposes a claimable lifecycle identity.
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
