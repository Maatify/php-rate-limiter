<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

final readonly class BudgetConfigDTO implements \JsonSerializable
{
    public function __construct(
        public int $threshold,
        public int $block_level
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'threshold' => $this->threshold,
            'block_level' => $this->block_level,
        ];
    }
}
