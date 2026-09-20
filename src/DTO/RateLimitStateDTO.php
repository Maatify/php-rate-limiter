<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

final readonly class RateLimitStateDTO implements \JsonSerializable
{
    public function __construct(
        public int $value,
        public int $updatedAt,
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'value' => $this->value,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
