<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO;

/** Atomic storage capability for generation-bound authentication K4 lifecycle. */
interface PunishmentLifecycleStoreInterface extends HardBlockCycleStoreInterface
{
    public function readGenerationBoundScoreState(string $currentKey, ?string $previousKey): ?GenerationBoundScoreStateDTO;

    public function mutateGenerationBoundScore(
        string $currentKey,
        ?string $previousKey,
        ?GenerationBoundScoreStateDTO $expectedState,
        int $ttlSeconds,
        int $newValue,
    ): GenerationBoundScoreMutationDTO;

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

    public function claimPostPunishmentReentry(string $currentKey, ?string $previousKey, string $lifecycleId): bool;
}
