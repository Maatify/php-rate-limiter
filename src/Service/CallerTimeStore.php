<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use DateTimeImmutable;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO;
use Maatify\RateLimiter\Repository\BoundedCorrelationRotationStoreInterface;
use Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotRotationStoreInterface;
use Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotStoreInterface;
use Maatify\RateLimiter\Repository\BoundedCorrelationStoreInterface;
use Maatify\RateLimiter\Repository\BudgetSeedStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimeBoundedCorrelationRotationStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimeBoundedCorrelationSnapshotRotationStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimeBoundedCorrelationSnapshotStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimeBoundedCorrelationStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimeBudgetSeedStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimeCorrelationRotationStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimeCorrelationStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimePunishmentLifecycleStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimeRateLimitStoreInterface;
use Maatify\RateLimiter\Repository\CorrelationRotationStoreInterface;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\RateLimiter\Repository\PunishmentLifecycleStoreInterface;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;

/**
 * Dispatches a store call to its caller-time capability when the store
 * implements it, and to the existing method otherwise (#153 phase 2,
 * LT-P2-01). The time is the request's single reading (LT-P2-05); a store
 * without the capability keeps its own time source, as before.
 *
 * @internal Used by the runtime services; not a public extension point.
 */
final class CallerTimeStore
{
    public static function increment(RateLimitStoreInterface $store, string $key, int $ttlSeconds, int $amount, DateTimeImmutable $now): int
    {
        return $store instanceof CallerTimeRateLimitStoreInterface
            ? $store->incrementAt($key, $ttlSeconds, $amount, $now->getTimestamp())
            : $store->increment($key, $ttlSeconds, $amount);
    }

    public static function set(RateLimitStoreInterface $store, string $key, int $value, int $ttlSeconds, DateTimeImmutable $now): void
    {
        if ($store instanceof CallerTimeRateLimitStoreInterface) {
            $store->setAt($key, $value, $ttlSeconds, $now->getTimestamp());

            return;
        }
        $store->set($key, $value, $ttlSeconds);
    }

    public static function block(RateLimitStoreInterface $store, string $key, int $level, int $durationSeconds, DateTimeImmutable $now): void
    {
        if ($store instanceof CallerTimeRateLimitStoreInterface) {
            $store->blockAt($key, $level, $durationSeconds, $now->getTimestamp());

            return;
        }
        $store->block($key, $level, $durationSeconds);
    }

    public static function checkBlock(RateLimitStoreInterface $store, string $key, DateTimeImmutable $now): ?BlockStateDTO
    {
        return $store instanceof CallerTimeRateLimitStoreInterface
            ? $store->checkBlockAt($key, $now->getTimestamp())
            : $store->checkBlock($key);
    }

    public static function getBudget(RateLimitStoreInterface $store, string $key, DateTimeImmutable $now): ?BudgetStateDTO
    {
        return $store instanceof CallerTimeRateLimitStoreInterface
            ? $store->getBudgetAt($key, $now->getTimestamp())
            : $store->getBudget($key);
    }

    public static function incrementBudget(RateLimitStoreInterface $store, string $key, int $epochDurationSeconds, int $amount, DateTimeImmutable $now): BudgetStateDTO
    {
        return $store instanceof CallerTimeRateLimitStoreInterface
            ? $store->incrementBudgetAt($key, $epochDurationSeconds, $amount, $now->getTimestamp())
            : $store->incrementBudget($key, $epochDurationSeconds, $amount);
    }

    public static function incrementBudgetWithSeed(BudgetSeedStoreInterface $store, string $key, int $epochDurationSeconds, BudgetStateDTO $seed, int $amount, DateTimeImmutable $now): BudgetStateDTO
    {
        return $store instanceof CallerTimeBudgetSeedStoreInterface
            ? $store->incrementBudgetWithSeedAt($key, $epochDurationSeconds, $seed, $amount, $now->getTimestamp())
            : $store->incrementBudgetWithSeed($key, $epochDurationSeconds, $seed, $amount);
    }

    public static function addDistinct(CorrelationStoreInterface $store, string $key, string $item, int $ttlSeconds, DateTimeImmutable $now): int
    {
        return $store instanceof CallerTimeCorrelationStoreInterface
            ? $store->addDistinctAt($key, $item, $ttlSeconds, $now->getTimestamp())
            : $store->addDistinct($key, $item, $ttlSeconds);
    }

    public static function incrementWatchFlag(CorrelationStoreInterface $store, string $key, int $ttlSeconds, DateTimeImmutable $now): int
    {
        return $store instanceof CallerTimeCorrelationStoreInterface
            ? $store->incrementWatchFlagAt($key, $ttlSeconds, $now->getTimestamp())
            : $store->incrementWatchFlag($key, $ttlSeconds);
    }

    public static function addDistinctBounded(BoundedCorrelationStoreInterface $store, string $key, string $item, int $ttlSeconds, int $maxDistinct, DateTimeImmutable $now): BoundedDistinctResultDTO
    {
        return $store instanceof CallerTimeBoundedCorrelationStoreInterface
            ? $store->addDistinctBoundedAt($key, $item, $ttlSeconds, $maxDistinct, $now->getTimestamp())
            : $store->addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
    }

    public static function addDistinctBoundedWithSnapshot(BoundedCorrelationSnapshotStoreInterface $store, string $key, string $item, int $ttlSeconds, int $maxDistinct, DateTimeImmutable $now): BoundedDistinctSnapshotDTO
    {
        return $store instanceof CallerTimeBoundedCorrelationSnapshotStoreInterface
            ? $store->addDistinctBoundedWithSnapshotAt($key, $item, $ttlSeconds, $maxDistinct, $now->getTimestamp())
            : $store->addDistinctBoundedWithSnapshot($key, $item, $ttlSeconds, $maxDistinct);
    }

    public static function addDistinctAcrossRotation(CorrelationRotationStoreInterface $store, string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, DateTimeImmutable $now): int
    {
        return $store instanceof CallerTimeCorrelationRotationStoreInterface
            ? $store->addDistinctAcrossRotationAt($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, (int) $now->format('Uv'))
            : $store->addDistinctAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds);
    }

    public static function incrementWatchFlagAcrossRotation(CorrelationRotationStoreInterface $store, string $currentKey, string $previousKey, int $ttlSeconds, DateTimeImmutable $now): int
    {
        return $store instanceof CallerTimeCorrelationRotationStoreInterface
            ? $store->incrementWatchFlagAcrossRotationAt($currentKey, $previousKey, $ttlSeconds, $now->getTimestamp())
            : $store->incrementWatchFlagAcrossRotation($currentKey, $previousKey, $ttlSeconds);
    }

    public static function addDistinctBoundedAcrossRotation(BoundedCorrelationRotationStoreInterface $store, string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct, DateTimeImmutable $now): BoundedDistinctResultDTO
    {
        return $store instanceof CallerTimeBoundedCorrelationRotationStoreInterface
            ? $store->addDistinctBoundedAcrossRotationAt($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct, (int) $now->format('Uv'))
            : $store->addDistinctBoundedAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct);
    }

    public static function addDistinctBoundedWithSnapshotAcrossRotation(BoundedCorrelationSnapshotRotationStoreInterface $store, string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct, DateTimeImmutable $now): BoundedDistinctSnapshotDTO
    {
        return $store instanceof CallerTimeBoundedCorrelationSnapshotRotationStoreInterface
            ? $store->addDistinctBoundedWithSnapshotAcrossRotationAt($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct, (int) $now->format('Uv'))
            : $store->addDistinctBoundedWithSnapshotAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct);
    }

    public static function readGenerationBoundScoreState(PunishmentLifecycleStoreInterface $store, string $currentKey, ?string $previousKey, DateTimeImmutable $now): ?GenerationBoundScoreStateDTO
    {
        return $store instanceof CallerTimePunishmentLifecycleStoreInterface
            ? $store->readGenerationBoundScoreStateAt($currentKey, $previousKey, (int) $now->format('Uv'))
            : $store->readGenerationBoundScoreState($currentKey, $previousKey);
    }

    public static function mutateGenerationBoundScore(PunishmentLifecycleStoreInterface $store, string $currentKey, ?string $previousKey, ?GenerationBoundScoreStateDTO $expectedState, int $ttlSeconds, int $newValue, DateTimeImmutable $now): GenerationBoundScoreMutationDTO
    {
        return $store instanceof CallerTimePunishmentLifecycleStoreInterface
            ? $store->mutateGenerationBoundScoreAt($currentKey, $previousKey, $expectedState, $ttlSeconds, $newValue, (int) $now->format('Uv'))
            : $store->mutateGenerationBoundScore($currentKey, $previousKey, $expectedState, $ttlSeconds, $newValue);
    }

    public static function blockWithPunishmentLifecycleTracking(PunishmentLifecycleStoreInterface $store, string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds, DateTimeImmutable $now): PunishmentLifecycleTransitionDTO
    {
        return $store instanceof CallerTimePunishmentLifecycleStoreInterface
            ? $store->blockWithPunishmentLifecycleTrackingAt($currentKey, $previousKey, $expectedGeneration, $proposedLifecycleId, $level, $durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds, $now->getTimestamp())
            : $store->blockWithPunishmentLifecycleTracking($currentKey, $previousKey, $expectedGeneration, $proposedLifecycleId, $level, $durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds);
    }

    public static function claimPostPunishmentReentry(PunishmentLifecycleStoreInterface $store, string $currentKey, ?string $previousKey, string $lifecycleId, DateTimeImmutable $now): bool
    {
        return $store instanceof CallerTimePunishmentLifecycleStoreInterface
            ? $store->claimPostPunishmentReentryAt($currentKey, $previousKey, $lifecycleId, (int) $now->format('Uv'))
            : $store->claimPostPunishmentReentry($currentKey, $previousKey, $lifecycleId);
    }
}
