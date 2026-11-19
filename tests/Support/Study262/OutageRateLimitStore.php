<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Study262;

use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * #262 study: a store that satisfies every capability the default policies need (it extends the in-memory store) and whose base
 * operations fail on demand, with the typed operational failure or with a non-operational RuntimeException.
 */
final class OutageRateLimitStore extends InMemoryRateLimitStore
{
    public function __construct(private readonly bool $typed, ClockInterface $clock)
    {
        parent::__construct($clock);
    }

    private function fail(): never
    {
        throw $this->typed ? new BackendFailureException('Store offline') : new \RuntimeException('Store offline');
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
}
