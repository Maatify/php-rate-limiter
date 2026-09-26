<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\DTO\BudgetStatusDTO;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Reads and updates the fixed-duration account budget epoch.
 */
class BudgetTracker
{
    private const EPOCH_DURATION = 86400; // 24h

    /**
     * @param RateLimitStoreInterface $store Budget persistence boundary.
     * @param ClockInterface $clock Source of current time for epoch checks.
     */
    public function __construct(
        private readonly RateLimitStoreInterface $store,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Increment the budget counter while retaining the current epoch boundary.
     */
    public function increment(string $key): void
    {
        $this->store->incrementBudget($key, self::EPOCH_DURATION);
    }

    /**
     * Return a normalized status, using zero values when the store is empty.
     */
    public function getStatus(string $key): BudgetStatusDTO
    {
        $dto = $this->store->getBudget($key);
        if ($dto) {
            return new BudgetStatusDTO($dto->count, $dto->epochStart);
        }
        return new BudgetStatusDTO(0, 0);
    }

    /**
     * Return whether the limit is reached inside the active 24-hour epoch.
     */
    public function isExceeded(string $key, int $limit): bool
    {
        $status = $this->getStatus($key);

        if ($status->count >= $limit) {
            $now = $this->clock->now()->getTimestamp();
            if ($status->epochStart + self::EPOCH_DURATION > $now) {
                return true;
            }
        }
        return false;
    }
}
