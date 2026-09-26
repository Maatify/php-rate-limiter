<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\RateLimiter;

use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\RateLimiter\Repository\BudgetSeedStoreInterface;

/**
 * Test double delegating to a real InMemoryRateLimitStore while optionally
 * throwing a caller-supplied Throwable from one or more specific budget
 * operations, leaving every other operation working normally.
 *
 * Used to prove that a storage/runtime failure is treated as a typed
 * FAIL_CLOSED result regardless of the exception class the store raises —
 * including a RateLimiterException, which is otherwise the package's own
 * configuration/contract failure type. Distinct from ThrowingRateLimitStore,
 * which fails every operation unconditionally.
 */
final class PartialFailureRateLimitStore implements BudgetSeedStoreInterface
{
    public function __construct(
        private readonly InMemoryRateLimitStore $delegate,
        private readonly ?\Throwable $throwOnGetBudget = null,
        private readonly ?\Throwable $throwOnIncrementBudget = null,
        private readonly ?\Throwable $throwOnIncrementBudgetWithSeed = null,
    ) {}

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        return $this->delegate->increment($key, $ttlSeconds, $amount);
    }

    public function get(string $key): ?RateLimitStateDTO
    {
        return $this->delegate->get($key);
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $this->delegate->set($key, $value, $ttlSeconds);
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        $this->delegate->block($key, $level, $durationSeconds);
    }

    public function checkBlock(string $key): ?BlockStateDTO
    {
        return $this->delegate->checkBlock($key);
    }

    public function getBudget(string $key): ?BudgetStateDTO
    {
        if ($this->throwOnGetBudget !== null) {
            throw $this->throwOnGetBudget;
        }

        return $this->delegate->getBudget($key);
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        if ($this->throwOnIncrementBudget !== null) {
            throw $this->throwOnIncrementBudget;
        }

        return $this->delegate->incrementBudget($key, $epochDurationSeconds, $amount);
    }

    public function incrementBudgetWithSeed(
        string $key,
        int $epochDurationSeconds,
        BudgetStateDTO $seed,
        int $amount = 1,
    ): BudgetStateDTO {
        if ($this->throwOnIncrementBudgetWithSeed !== null) {
            throw $this->throwOnIncrementBudgetWithSeed;
        }

        return $this->delegate->incrementBudgetWithSeed($key, $epochDurationSeconds, $seed, $amount);
    }

    public function isHealthy(): bool
    {
        return $this->delegate->isHealthy();
    }
}
