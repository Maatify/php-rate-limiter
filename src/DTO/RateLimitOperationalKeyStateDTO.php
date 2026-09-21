<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Snapshot of one scope's score and active hard-block provenance.
 */
final readonly class RateLimitOperationalKeyStateDTO implements \JsonSerializable
{
    /**
     * @param ?RateLimitStateDTO $score Stored score selected for the snapshot.
     * @param int $effectiveScore Score after the applicable decay calculation.
     * @param bool $scoreFromPreviousGeneration Whether the score came from V1.
     * @param ?BlockStateDTO $activeHardBlock Active L2+ block, when present.
     * @param bool $blockFromPreviousGeneration Whether the block came from V1.
     */
    public function __construct(
        public ?RateLimitStateDTO $score,
        public int $effectiveScore,
        public bool $scoreFromPreviousGeneration,
        public ?BlockStateDTO $activeHardBlock,
        public bool $blockFromPreviousGeneration,
    ) {}

    /**
     * Return score and block provenance in serialized form.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'score' => $this->score,
            'effectiveScore' => $this->effectiveScore,
            'scoreFromPreviousGeneration' => $this->scoreFromPreviousGeneration,
            'activeHardBlock' => $this->activeHardBlock,
            'blockFromPreviousGeneration' => $this->blockFromPreviousGeneration,
        ];
    }
}
