<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\RateLimiter;

use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Exception\BackendFailureException;
use RuntimeException;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;

class ThrowingRateLimitStore implements \Maatify\RateLimiter\Repository\E1KeyspaceStoreInterface, RateLimitStoreInterface, \Maatify\RateLimiter\Repository\PunishmentLifecycleStoreInterface, \Maatify\RateLimiter\Repository\SnapshotFencedScoreStoreInterface, \Maatify\RateLimiter\Repository\ScoreReplaceStoreInterface
{
    use \Maatify\RateLimiter\Tests\Support\E1View;
    public function readGenerationBoundScoreState(string $currentKey, ?string $previousKey): ?\Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO
    {
        $this->fail();
    }

    public function mutateGenerationBoundScore(string $currentKey, ?string $previousKey, ?\Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO $expectedState, int $ttlSeconds, int $newValue): \Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO
    {
        $this->fail();
    }

    public function blockWithPunishmentLifecycleTracking(string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): \Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO
    {
        $this->fail();
    }

    public function claimPostPunishmentReentry(string $currentKey, ?string $previousKey, string $lifecycleId): bool
    {
        $this->fail();
    }

    public function blockWithCycleTracking(string $currentKey, ?string $previousKey, int $level, int $durationSeconds, int $now, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): \Maatify\RateLimiter\DTO\HardBlockCycleResultDTO
    {
        $this->fail();
    }

    public function readDecayPauseState(string $currentKey, ?string $previousKey, int $fromTimestamp, int $now): \Maatify\RateLimiter\DTO\DecayPauseStateDTO
    {
        $this->fail();
    }

    public function incrementIfSnapshot(string $key, int $ttlSeconds, ?int $expectedValue, ?int $expectedUpdatedAt, int $newValue): ?int
    {
        $this->fail();
    }

    public function replaceScore(string $key, int $value, int $ttlSeconds): ?\Maatify\RateLimiter\DTO\RateLimitStateDTO
    {
        $this->fail();
    }

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
