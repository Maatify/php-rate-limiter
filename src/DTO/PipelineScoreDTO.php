<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Carries a stored score together with its timestamp and key generation.
 */
final readonly class PipelineScoreDTO implements \JsonSerializable
{
    /**
     * @param int $value Stored score before decay or the current update.
     * @param int $updatedAt Unix timestamp of the score update.
     * @param bool $isFromV1 Whether the score came from the previous key generation.
     */
    public function __construct(
        public int $value,
        public int $updatedAt,
        public bool $isFromV1,
    ) {}

    /**
     * Return score value, freshness, and generation provenance.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'value' => $this->value,
            'updatedAt' => $this->updatedAt,
            'isFromV1' => $this->isFromV1,
        ];
    }
}
