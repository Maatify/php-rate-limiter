<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\RateLimiter;

use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Repository\SnapshotFencedScoreStoreInterface;
use Maatify\RateLimiter\Repository\ScoreReplaceStoreInterface;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Test double exposing only the base RateLimitStoreInterface contract.
 *
 * Intentionally does NOT implement BudgetSeedStoreInterface while every normal
 * store operation still works by delegating to InMemoryRateLimitStore. Used to
 * prove that paths requiring a V1→V2 migration fail explicitly instead of
 * silently resetting the previous-secret budget.
 */
final class BaseOnlyInMemoryRateLimitStore implements RateLimitStoreInterface, SnapshotFencedScoreStoreInterface, ScoreReplaceStoreInterface
{
    private readonly InMemoryRateLimitStore $delegate;

    public function __construct(ClockInterface $clock)
    {
        $this->delegate = new InMemoryRateLimitStore($clock);
    }

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        return $this->delegate->increment($key, $ttlSeconds, $amount);
    }

    /** #185 F-185-08 (DEC-030): the snapshot-fenced score write, required by build() for a policy that writes scores. */
    /** #185 F-185-17 (DEC-031): write a score and return the one it replaced. */
    public function replaceScore(string $key, int $value, int $ttlSeconds): ?RateLimitStateDTO
    {
        return $this->delegate->replaceScore($key, $value, $ttlSeconds);
    }

    public function incrementIfSnapshot(string $key, int $ttlSeconds, ?int $expectedValue, ?int $expectedUpdatedAt, int $newValue): ?int
    {
        return $this->delegate->incrementIfSnapshot($key, $ttlSeconds, $expectedValue, $expectedUpdatedAt, $newValue);
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
        return $this->delegate->getBudget($key);
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        return $this->delegate->incrementBudget($key, $epochDurationSeconds, $amount);
    }

    public function isHealthy(): bool
    {
        return $this->delegate->isHealthy();
    }
}
