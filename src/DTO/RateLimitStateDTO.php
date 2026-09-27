<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Represents a stored score value and the timestamp of its last update.
 */
final readonly class RateLimitStateDTO implements \JsonSerializable
{
    /**
     * @param int $value Stored counter or score value.
     * @param int $updatedAt Unix timestamp of the last update.
     */
    public function __construct(
        public int $value,
        public int $updatedAt,
    ) {}

    /**
     * Return the stored value and timestamp in serialized form.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'value' => $this->value,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
