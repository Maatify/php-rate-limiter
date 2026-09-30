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
     *
     * `$now` is a caller-supplied semantic Unix timestamp and is
     * caller-time authoritative per DEC-016. Per DEC-017 it MUST be a
     * non-negative Unix timestamp (`$now >= 0`); a negative value fails
     * explicitly before any backend mutation.
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
     *
     * `$fromTimestamp` and `$now` are caller-supplied semantic Unix
     * timestamps and are caller-time authoritative per DEC-016. Per
     * DEC-017 both MUST be non-negative Unix timestamps (`>= 0`); a
     * negative value fails explicitly before relying on it in arithmetic.
     */
    public function readDecayPauseState(
        string $currentKey,
        ?string $previousKey,
        int $fromTimestamp,
        int $now,
    ): DecayPauseStateDTO;
}
