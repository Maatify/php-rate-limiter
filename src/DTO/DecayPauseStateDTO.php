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
     * @param int $activePauseUntil Active pause end timestamp, or zero when inactive.
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
