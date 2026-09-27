<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\RateLimiter;

use Maatify\RateLimiter\Repository\HardBlockCycleStoreInterface;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\DecayPauseStateDTO;
use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Test double exposing HardBlockCycleStoreInterface but neither
 * BudgetSeedStoreInterface nor PunishmentLifecycleStoreInterface.
 *
 * Used to prove that a non-opt-in, non-budget-rotation graph only needs the
 * DEC-003 hard-block-cycle capability, not the full lifecycle/budget-seed
 * capabilities.
 */
final class HardBlockCycleOnlyInMemoryRateLimitStore implements HardBlockCycleStoreInterface
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

    public function blockWithCycleTracking(
        string $currentKey,
        ?string $previousKey,
        int $level,
        int $durationSeconds,
        int $now,
        int $cycleWindowSeconds,
        int $cycleThreshold,
        int $pauseSeconds,
        int $pauseHistoryRetentionSeconds,
    ): HardBlockCycleResultDTO {
        return $this->delegate->blockWithCycleTracking(
            $currentKey,
            $previousKey,
            $level,
            $durationSeconds,
            $now,
            $cycleWindowSeconds,
            $cycleThreshold,
            $pauseSeconds,
            $pauseHistoryRetentionSeconds,
        );
    }

    public function readDecayPauseState(
        string $currentKey,
        ?string $previousKey,
        int $fromTimestamp,
        int $now,
    ): DecayPauseStateDTO {
        return $this->delegate->readDecayPauseState($currentKey, $previousKey, $fromTimestamp, $now);
    }
}
