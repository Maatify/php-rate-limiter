<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO;

/**
 * Caller-time mirror of `PunishmentLifecycleStoreInterface`.
 *
 * Additive caller-time capability (#153 phase 2, DEC-016 rule 1): the same
 * operations as `PunishmentLifecycleStoreInterface`, with the caller's semantic time (a non-negative Unix
 * timestamp, DEC-017; `$now` in seconds, `$nowMilliseconds` in milliseconds
 * where the operation derives a millisecond lifetime) as the only time authority
 * for every stored and compared timestamp. A store that does not implement it
 * keeps its existing behaviour; the runtime then calls the existing methods.
 */
interface CallerTimePunishmentLifecycleStoreInterface extends PunishmentLifecycleStoreInterface, CallerTimeRateLimitStoreInterface
{
    /**
     * `readGenerationBoundScoreState()`, judged at the caller's time in
     * milliseconds (a generated expiry is ⌈(now ms + TTL) / 1 s⌉).
     */
    public function readGenerationBoundScoreStateAt(string $currentKey, ?string $previousKey, int $nowMilliseconds): ?GenerationBoundScoreStateDTO;

    /**
     * `mutateGenerationBoundScore()` at the caller's time in milliseconds:
     * `updatedAt` is its second, and a created generation expires at
     * ⌈(now ms + TTL) / 1 s⌉.
     */
    public function mutateGenerationBoundScoreAt(
        string $currentKey,
        ?string $previousKey,
        ?GenerationBoundScoreStateDTO $expectedState,
        int $ttlSeconds,
        int $newValue,
        int $nowMilliseconds,
    ): GenerationBoundScoreMutationDTO;

    /**
     * `blockWithPunishmentLifecycleTracking()` with `$now` as the publication time.
     */
    public function blockWithPunishmentLifecycleTrackingAt(
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
        int $now,
    ): PunishmentLifecycleTransitionDTO;

    /**
     * `claimPostPunishmentReentry()`, judged at the caller's time in milliseconds.
     */
    public function claimPostPunishmentReentryAt(string $currentKey, ?string $previousKey, string $lifecycleId, int $nowMilliseconds): bool;
}
