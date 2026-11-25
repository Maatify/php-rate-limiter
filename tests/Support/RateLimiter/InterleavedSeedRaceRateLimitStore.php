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
final class InterleavedSeedRaceRateLimitStore implements \Maatify\RateLimiter\Repository\E1KeyspaceStoreInterface, BudgetSeedStoreInterface, \Maatify\RateLimiter\Repository\PunishmentLifecycleStoreInterface, \Maatify\RateLimiter\Repository\SnapshotFencedScoreStoreInterface, \Maatify\RateLimiter\Repository\ScoreReplaceStoreInterface
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

    private bool $raceInjected = false;

    public function __construct(private InMemoryRateLimitStore $delegate) {}

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
