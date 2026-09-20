<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

final readonly class BudgetStateDTO implements \JsonSerializable
{
    public function __construct(
        public int $count,
        public int $epochStart,
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'count' => $this->count,
            'epochStart' => $this->epochStart,
        ];
    }
}
