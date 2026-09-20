<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

final readonly class PipelineScoreDTO implements \JsonSerializable
{
    public function __construct(
        public int $value,
        public int $updatedAt,
        public bool $isFromV1
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'value' => $this->value,
            'updatedAt' => $this->updatedAt,
            'isFromV1' => $this->isFromV1,
        ];
    }
}
