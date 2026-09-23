<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Describes the result of an atomic hard-block cycle transition.
 */
final readonly class HardBlockCycleResultDTO implements \JsonSerializable
{
    /**
     * @param bool $newCycle Whether this operation entered a new hard-block cycle.
     * @param int $cycleCount Number of retained cycles after this operation.
     * @param bool $pauseActivated Whether this operation started a decay pause.
     * @param int $pauseUntil Active pause end timestamp, or zero when inactive.
     */
    public function __construct(
        public bool $newCycle,
        public int $cycleCount,
        public bool $pauseActivated,
        public int $pauseUntil,
    ) {}

    /**
     * Return the stable serialized cycle transition representation.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'newCycle' => $this->newCycle,
            'cycleCount' => $this->cycleCount,
            'pauseActivated' => $this->pauseActivated,
            'pauseUntil' => $this->pauseUntil,
        ];
    }
}
