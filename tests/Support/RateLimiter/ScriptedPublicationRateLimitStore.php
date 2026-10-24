<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\RateLimiter;

use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO;

/**
 * A K4 punishment publication that loses its generation race a scripted number
 * of times. Disarmed, it behaves exactly like InMemoryRateLimitStore.
 *
 * After the first lost publication the store can also change what the next
 * snapshot read returns: the K4 score drops below level 2 (a concurrent writer
 * lowered it) or no snapshot is readable at all.
 */
final class ScriptedPublicationRateLimitStore extends InMemoryRateLimitStore
{
    public const AFTER_LOSS_KEEP = 'keep';
    public const AFTER_LOSS_LEVEL_DROP = 'level_drop';
    public const AFTER_LOSS_NO_SNAPSHOT = 'no_snapshot';

    public bool $armed = false;
    public int $lossesBeforeApply = 0;
    public string $afterLoss = self::AFTER_LOSS_KEEP;
    public int $publicationCalls = 0;
    public int $snapshotReadsAfterLoss = 0;

    private bool $lost = false;
    private bool $snapshotHidden = false;

    public function arm(int $lossesBeforeApply, string $afterLoss = self::AFTER_LOSS_KEEP): void
    {
        $this->armed = true;
        $this->lossesBeforeApply = $lossesBeforeApply;
        $this->afterLoss = $afterLoss;
        $this->publicationCalls = 0;
        $this->snapshotReadsAfterLoss = 0;
        $this->lost = false;
        $this->snapshotHidden = false;
    }

    public function blockWithPunishmentLifecycleTracking(string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): PunishmentLifecycleTransitionDTO
    {
        if (! $this->armed) {
            return parent::blockWithPunishmentLifecycleTracking($currentKey, $previousKey, $expectedGeneration, $proposedLifecycleId, $level, $durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds);
        }
        if ($this->publicationCalls++ >= $this->lossesBeforeApply) {
            return parent::blockWithPunishmentLifecycleTracking($currentKey, $previousKey, $expectedGeneration, $proposedLifecycleId, $level, $durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds);
        }
        if (! $this->lost) {
            $this->lost = true;
            if ($this->afterLoss === self::AFTER_LOSS_LEVEL_DROP) {
                $latest = parent::readGenerationBoundScoreState($currentKey, $previousKey);
                parent::mutateGenerationBoundScore($currentKey, $previousKey, $latest, 86400, 0);
            } elseif ($this->afterLoss === self::AFTER_LOSS_NO_SNAPSHOT) {
                $this->snapshotHidden = true;
            }
        }

        return new PunishmentLifecycleTransitionDTO(false, null, null, null);
    }

    public function readGenerationBoundScoreState(string $currentKey, ?string $previousKey): ?GenerationBoundScoreStateDTO
    {
        if ($this->snapshotHidden) {
            $this->snapshotReadsAfterLoss++;

            return null;
        }
        if ($this->lost) {
            $this->snapshotReadsAfterLoss++;
        }

        return parent::readGenerationBoundScoreState($currentKey, $previousKey);
    }
}
