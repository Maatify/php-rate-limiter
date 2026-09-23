<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\DecayPauseStateDTO;
use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;

/**
 * Additive capability for atomic hard-block cycle and decay-pause state.
 */
interface HardBlockCycleStoreInterface extends RateLimitStoreInterface
{
    /**
     * Persist a hard block and update its logical cycle state atomically.
     */
    public function blockWithCycleTracking(
        string $currentKey,
        ?string $previousKey,
        int $level,
        int $durationSeconds,
        int $now,
        int $cycleWindowSeconds,
        int $cycleThreshold,
        int $pauseSeconds,
        int $pauseHistoryRetentionSeconds,
    ): HardBlockCycleResultDTO;

    /**
     * Read retained decay-pause time without mutating persistence.
     */
    public function readDecayPauseState(
        string $currentKey,
        ?string $previousKey,
        int $fromTimestamp,
        int $now,
    ): DecayPauseStateDTO;
}
