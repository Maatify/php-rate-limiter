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
final class PartialFailureRateLimitStore implements \Maatify\RateLimiter\Repository\E1KeyspaceStoreInterface, BudgetSeedStoreInterface, \Maatify\RateLimiter\Repository\PunishmentLifecycleStoreInterface, \Maatify\RateLimiter\Repository\SnapshotFencedScoreStoreInterface, \Maatify\RateLimiter\Repository\ScoreReplaceStoreInterface
{
    use \Maatify\RateLimiter\Tests\Support\E1View;
    protected function initializeAccountView(object $view): void
    {
        if ($view instanceof self) {
            $view->delegate = $this->delegate->e1Keyspace();
        }
    }

    public function readGenerationBoundScoreState(string $currentKey, ?string $previousKey): ?\Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO
    {
        return $this->delegate->readGenerationBoundScoreState($currentKey, $previousKey);
    }

    public function mutateGenerationBoundScore(string $currentKey, ?string $previousKey, ?\Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO $expectedState, int $ttlSeconds, int $newValue): \Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO
    {
        return $this->delegate->mutateGenerationBoundScore($currentKey, $previousKey, $expectedState, $ttlSeconds, $newValue);
    }

    public function blockWithPunishmentLifecycleTracking(string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): \Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO
    {
        return $this->delegate->blockWithPunishmentLifecycleTracking($currentKey, $previousKey, $expectedGeneration, $proposedLifecycleId, $level, $durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds);
    }

    public function claimPostPunishmentReentry(string $currentKey, ?string $previousKey, string $lifecycleId): bool
    {
        return $this->delegate->claimPostPunishmentReentry($currentKey, $previousKey, $lifecycleId);
    }

    public function blockWithCycleTracking(string $currentKey, ?string $previousKey, int $level, int $durationSeconds, int $now, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): \Maatify\RateLimiter\DTO\HardBlockCycleResultDTO
    {
        return $this->delegate->blockWithCycleTracking($currentKey, $previousKey, $level, $durationSeconds, $now, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds);
    }

    public function readDecayPauseState(string $currentKey, ?string $previousKey, int $fromTimestamp, int $now): \Maatify\RateLimiter\DTO\DecayPauseStateDTO
    {
        return $this->delegate->readDecayPauseState($currentKey, $previousKey, $fromTimestamp, $now);
    }

    public function incrementIfSnapshot(string $key, int $ttlSeconds, ?int $expectedValue, ?int $expectedUpdatedAt, int $newValue): ?int
    {
        return $this->delegate->incrementIfSnapshot($key, $ttlSeconds, $expectedValue, $expectedUpdatedAt, $newValue);
    }

    public function replaceScore(string $key, int $value, int $ttlSeconds): ?\Maatify\RateLimiter\DTO\RateLimitStateDTO
    {
        return $this->delegate->replaceScore($key, $value, $ttlSeconds);
    }

    public function __construct(
        private InMemoryRateLimitStore $delegate,
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
