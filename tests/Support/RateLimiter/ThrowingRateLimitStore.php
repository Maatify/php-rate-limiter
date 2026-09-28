<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\RateLimiter;

use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Exception\BackendFailureException;
use RuntimeException;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;

class ThrowingRateLimitStore implements RateLimitStoreInterface
{
    public function __construct(private readonly bool $typed = true) {}

    private function fail(): never
    {
        if ($this->typed) {
            throw new BackendFailureException('Store offline');
        }

        throw new RuntimeException('Store offline');
    }

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        $this->fail();
    }

    public function get(string $key): ?RateLimitStateDTO
    {
        $this->fail();
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $this->fail();
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        $this->fail();
    }

    public function checkBlock(string $key): ?BlockStateDTO
    {
        $this->fail();
    }

    public function getBudget(string $key): ?BudgetStateDTO
    {
        $this->fail();
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        $this->fail();
    }

    public function isHealthy(): bool
    {
        return false;
    }
}
