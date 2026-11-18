<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Ownership;

use Fiber;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;

/**
 * A Host store (DEC-041, F266-14): implements only `RateLimitStoreInterface`, has NO relation to the package's Redis store, and carries
 * a method named like the package's internal inventory method, which the package must never call. It counts every call it receives
 * and can suspend a fiber inside `getBudget()`.
 */
class HostStore implements RateLimitStoreInterface
{
    public int $calls = 0;

    public int $inventoryCalls = 0;

    /** @var Fiber<mixed, mixed, mixed, mixed>|null */
    public ?Fiber $suspendIn = null;

    /** @return list<object> a Host method with the package's internal name: it must never be used as the resource inventory */
    public function ownedResources(): array
    {
        $this->inventoryCalls++;

        return [];
    }

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        $this->calls++;

        return $amount;
    }

    public function get(string $key): ?RateLimitStateDTO
    {
        $this->calls++;

        return null;
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $this->calls++;
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        $this->calls++;
    }

    public function checkBlock(string $key): ?BlockStateDTO
    {
        $this->calls++;

        return null;
    }

    public function getBudget(string $key): ?BudgetStateDTO
    {
        $this->calls++;
        if ($this->suspendIn !== null && Fiber::getCurrent() === $this->suspendIn) {
            Fiber::suspend('inside the Host store');
        }

        return null;
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        $this->calls++;

        return new BudgetStateDTO($amount, 1_000_000);
    }

    public function isHealthy(): bool
    {
        $this->calls++;

        return true;
    }
}
