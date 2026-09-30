<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Describes retained pause time intersecting a score-decay interval.
 */
final readonly class DecayPauseStateDTO implements \JsonSerializable
{
    /**
     * @param int $elapsedPausedSeconds Union duration of retained pause intervals.
     * @param int $activePauseUntil Active pause end Unix timestamp
     *     (non-negative per DEC-017), or `0` as the sentinel for "no
     *     active pause". `0` is not itself a possible active-pause end
     *     timestamp because a pause is only ever activated relative to a
     *     `$now` in the same non-negative domain plus a positive pause
     *     duration.
     */
    public function __construct(
        public int $elapsedPausedSeconds,
        public int $activePauseUntil,
    ) {}

    /**
     * Return the stable serialized pause-state representation.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'elapsedPausedSeconds' => $this->elapsedPausedSeconds,
            'activePauseUntil' => $this->activePauseUntil,
        ];
    }
}
