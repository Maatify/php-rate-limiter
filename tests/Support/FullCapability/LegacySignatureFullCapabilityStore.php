<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\FullCapability;

use Maatify\RateLimiter\Repository\FullCapabilityStoreInterface;

/**
 * A Host store written against the storage contracts as they stood before
 * #153 phase 2: it implements exactly `FullCapabilityStoreInterface` and
 * none of the caller-time capability interfaces, by delegating to an inner
 * store. Proves that an old-signature Host store still loads and runs (BC2).
 */
final class LegacySignatureFullCapabilityStore implements FullCapabilityStoreInterface
{
    public function __construct(private readonly FullCapabilityStoreInterface $inner) {}

    public function acquireProbeLease(string $policyName, int $now, int $leaseSeconds): bool
    {
        return $this->inner->acquireProbeLease($policyName, $now, $leaseSeconds);
    }

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        return $this->inner->addDistinct($key, $item, $ttlSeconds);
    }

    public function addDistinctAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds): int
    {
        return $this->inner->addDistinctAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds);
    }

    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
    {
        return $this->inner->addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
    }

    public function addDistinctBoundedAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
    {
        return $this->inner->addDistinctBoundedAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct);
    }

    public function addDistinctBoundedWithSnapshot(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO
    {
        return $this->inner->addDistinctBoundedWithSnapshot($key, $item, $ttlSeconds, $maxDistinct);
    }

    public function addDistinctBoundedWithSnapshotAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO
    {
        return $this->inner->addDistinctBoundedWithSnapshotAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct);
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        $this->inner->block($key, $level, $durationSeconds);
    }

    public function blockWithCycleTracking(string $currentKey, ?string $previousKey, int $level, int $durationSeconds, int $now, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): \Maatify\RateLimiter\DTO\HardBlockCycleResultDTO
    {
        return $this->inner->blockWithCycleTracking($currentKey, $previousKey, $level, $durationSeconds, $now, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds);
    }

    public function blockWithPunishmentLifecycleTracking(string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): \Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO
    {
        return $this->inner->blockWithPunishmentLifecycleTracking($currentKey, $previousKey, $expectedGeneration, $proposedLifecycleId, $level, $durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds);
    }

    public function checkBlock(string $key): ?\Maatify\RateLimiter\DTO\BlockStateDTO
    {
        return $this->inner->checkBlock($key);
    }

    public function claimPostPunishmentReentry(string $currentKey, ?string $previousKey, string $lifecycleId): bool
    {
        return $this->inner->claimPostPunishmentReentry($currentKey, $previousKey, $lifecycleId);
    }

    public function get(string $key): ?\Maatify\RateLimiter\DTO\RateLimitStateDTO
    {
        return $this->inner->get($key);
    }

    public function getBudget(string $key): ?\Maatify\RateLimiter\DTO\BudgetStateDTO
    {
        return $this->inner->getBudget($key);
    }

    public function getWatchFlag(string $key): int
    {
        return $this->inner->getWatchFlag($key);
    }

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        return $this->inner->increment($key, $ttlSeconds, $amount);
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): \Maatify\RateLimiter\DTO\BudgetStateDTO
    {
        return $this->inner->incrementBudget($key, $epochDurationSeconds, $amount);
    }

    public function incrementBudgetWithSeed(string $key, int $epochDurationSeconds, \Maatify\RateLimiter\DTO\BudgetStateDTO $seed, int $amount = 1): \Maatify\RateLimiter\DTO\BudgetStateDTO
    {
        return $this->inner->incrementBudgetWithSeed($key, $epochDurationSeconds, $seed, $amount);
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        return $this->inner->incrementWatchFlag($key, $ttlSeconds);
    }

    public function incrementWatchFlagAcrossRotation(string $currentKey, string $previousKey, int $ttlSeconds): int
    {
        return $this->inner->incrementWatchFlagAcrossRotation($currentKey, $previousKey, $ttlSeconds);
    }

    public function isHealthy(): bool
    {
        return $this->inner->isHealthy();
    }

    public function load(string $policyName): ?\Maatify\RateLimiter\DTO\CircuitBreakerStateDTO
    {
        return $this->inner->load($policyName);
    }

    public function mutateGenerationBoundScore(string $currentKey, ?string $previousKey, ?\Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO $expectedState, int $ttlSeconds, int $newValue): \Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO
    {
        return $this->inner->mutateGenerationBoundScore($currentKey, $previousKey, $expectedState, $ttlSeconds, $newValue);
    }

    public function readDecayPauseState(string $currentKey, ?string $previousKey, int $fromTimestamp, int $now): \Maatify\RateLimiter\DTO\DecayPauseStateDTO
    {
        return $this->inner->readDecayPauseState($currentKey, $previousKey, $fromTimestamp, $now);
    }

    public function readGenerationBoundScoreState(string $currentKey, ?string $previousKey): ?\Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO
    {
        return $this->inner->readGenerationBoundScoreState($currentKey, $previousKey);
    }

    public function save(string $policyName, \Maatify\RateLimiter\DTO\CircuitBreakerStateDTO $state): void
    {
        $this->inner->save($policyName, $state);
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $this->inner->set($key, $value, $ttlSeconds);
    }
}
