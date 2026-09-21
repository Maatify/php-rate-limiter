<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Represents the persisted count and start time of a budget epoch.
 */
final readonly class BudgetStateDTO implements \JsonSerializable
{
    /**
     * @param int $count Number of events recorded in the epoch.
     * @param int $epochStart Unix timestamp at which the epoch started.
     */
    public function __construct(
        public int $count,
        public int $epochStart,
    ) {}

    /**
     * Return the persisted budget state as a stable field map.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'count' => $this->count,
            'epochStart' => $this->epochStart,
        ];
    }
}
