<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

final readonly class BlockStateDTO implements \JsonSerializable
{
    public function __construct(
        public int $level,
        public int $expiresAt,
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'level' => $this->level,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
