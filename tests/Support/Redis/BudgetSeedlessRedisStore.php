<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Redis;

use Maatify\RateLimiter\Repository\HardBlockCycleStoreInterface;
use Maatify\RateLimiter\Repository\SnapshotFencedScoreStoreInterface;
use Maatify\RateLimiter\Repository\ScoreReplaceStoreInterface;
use Maatify\RateLimiter\Repository\OperationalReadOnlyStoreInterface;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\DecayPauseStateDTO;
use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;

/** Real Redis rate adapter deliberately withholding BudgetSeed and lifecycle; correlation is a separate store. */
final class BudgetSeedlessRedisStore implements \Maatify\RateLimiter\Repository\E1KeyspaceStoreInterface, HardBlockCycleStoreInterface, SnapshotFencedScoreStoreInterface, ScoreReplaceStoreInterface, OperationalReadOnlyStoreInterface
{
    use \Maatify\RateLimiter\Tests\Support\E1View;

    private RedisFullCapabilityStore $delegate;

    protected function initializeAccountView(object $view): void
    {
        if ($view instanceof self) {
            $view->delegate = $this->delegate->e1Keyspace();
        }
    }

    public function __construct(RedisFullCapabilityStore $delegate)
    {
        $this->delegate = $delegate;
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

    /** #185 F-185-26 (DEC-032): the wrapped store never deletes on read, so the read-only reads are its reads. */
    public function peekBlock(string $key): ?BlockStateDTO
    {
        return $this->delegate->checkBlock($key);
    }

    /** #185 F-185-26 (DEC-032): the wrapped store never deletes on read, so the read-only reads are its reads. */
    public function peekBudget(string $key): ?BudgetStateDTO
    {
        return $this->delegate->getBudget($key);
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
