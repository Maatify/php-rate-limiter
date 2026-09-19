<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\RateLimiter;

use Maatify\RateLimiter\Contract\RateLimitStoreInterface;
use Maatify\RateLimiter\DTO\Store\BlockStateDTO;
use Maatify\RateLimiter\DTO\Store\BudgetStateDTO;
use Maatify\RateLimiter\DTO\Store\RateLimitStateDTO;
use RuntimeException;

class ThrowingRateLimitStore implements RateLimitStoreInterface
{
    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        throw new RuntimeException('Store offline');
    }

    public function get(string $key): ?RateLimitStateDTO
    {
        throw new RuntimeException('Store offline');
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        throw new RuntimeException('Store offline');
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        throw new RuntimeException('Store offline');
    }

    public function checkBlock(string $key): ?BlockStateDTO
    {
        throw new RuntimeException('Store offline');
    }

    public function getBudget(string $key): ?BudgetStateDTO
    {
        throw new RuntimeException('Store offline');
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        throw new RuntimeException('Store offline');
    }

    public function isHealthy(): bool
    {
        return false;
    }
}
