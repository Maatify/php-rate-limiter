<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\RateLimiter;

use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\RateLimiter\Repository\BudgetSeedStoreInterface;

/**
 * Deterministically simulates a concurrent actor winning the race to
 * initialize Current, interleaved strictly between the caller's own
 * "Current absent" read and its incrementBudgetWithSeed() call.
 *
 * The caller (FixedWindowSimpleRateLimiter) observes Current as absent via
 * getBudget(), then calls incrementBudgetWithSeed() to migrate a valid
 * Previous state. This decorator injects a real, independent
 * incrementBudget() call on Current — as a genuinely concurrent second
 * actor's first consume would — at the one instant that matters: strictly
 * between that observation and the caller's own atomic seed call. The real
 * incrementBudgetWithSeed() delegate then executes with Current already
 * populated, so its own locked contract ("If a valid V2 budget already
 * exists, the seed is ignored and the existing V2 count is incremented by
 * $amount while the existing V2 epochStart is preserved") is exercised for
 * real, end-to-end, through the caller's real code path — not merely
 * asserted by a renamed sequential test.
 */
final class InterleavedSeedRaceRateLimitStore implements BudgetSeedStoreInterface
{
    private bool $raceInjected = false;

    public function __construct(private readonly InMemoryRateLimitStore $delegate) {}

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
        return $this->delegate->getBudget($key);
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        return $this->delegate->incrementBudget($key, $epochDurationSeconds, $amount);
    }

    public function incrementBudgetWithSeed(
        string $key,
        int $epochDurationSeconds,
        BudgetStateDTO $seed,
        int $amount = 1,
    ): BudgetStateDTO {
        if (! $this->raceInjected) {
            $this->raceInjected = true;
            // A genuinely concurrent actor's own first consume, executing
            // strictly between the caller's "Current absent" read and this
            // atomic seed call.
            $this->delegate->incrementBudget($key, $epochDurationSeconds, 1);
        }

        return $this->delegate->incrementBudgetWithSeed($key, $epochDurationSeconds, $seed, $amount);
    }

    public function isHealthy(): bool
    {
        return $this->delegate->isHealthy();
    }
}
