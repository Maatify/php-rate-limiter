<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Reports the result of one atomic bounded-distinct observation.
 */
final readonly class BoundedDistinctResultDTO implements \JsonSerializable
{
    public function __construct(
        public int $count,
        public bool $accepted,
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'count' => $this->count,
            'accepted' => $this->accepted,
        ];
    }
}
