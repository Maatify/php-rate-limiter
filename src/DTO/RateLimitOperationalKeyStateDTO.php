<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

final readonly class RateLimitOperationalKeyStateDTO implements \JsonSerializable
{
    public function __construct(
        public ?RateLimitStateDTO $score,
        public int $effectiveScore,
        public bool $scoreFromPreviousGeneration,
        public ?BlockStateDTO $activeHardBlock,
        public bool $blockFromPreviousGeneration,
    ) {}

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
