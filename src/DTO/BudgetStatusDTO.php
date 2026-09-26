<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Represents the budget status returned by the tracker, including an empty state.
 */
final readonly class BudgetStatusDTO implements \JsonSerializable
{
    /**
     * @param int $count Current count, or zero when no persisted state exists.
     * @param int $epochStart Unix timestamp of the current epoch, or zero when empty.
     */
    public function __construct(
        public int $count,
        public int $epochStart,
    ) {}

    /**
     * Return the normalized budget status as a stable field map.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'count' => $this->count,
            'epochStart' => $this->epochStart,
        ];
    }
}
