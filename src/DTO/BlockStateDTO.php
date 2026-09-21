<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Represents a persisted block level and its Unix expiry timestamp.
 */
final readonly class BlockStateDTO implements \JsonSerializable
{
    /**
     * @param int $level Persisted block level, where L2 and above are hard blocks.
     * @param int $expiresAt Unix timestamp at which the block expires.
     */
    public function __construct(
        public int $level,
        public int $expiresAt,
    ) {}

    /**
     * Return the stable serialized block representation.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'level' => $this->level,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
