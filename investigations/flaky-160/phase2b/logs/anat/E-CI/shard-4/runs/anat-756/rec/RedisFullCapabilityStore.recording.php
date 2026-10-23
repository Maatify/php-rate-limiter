<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository\Redis;

class RedisFullCapabilityStore extends RedisFullCapabilityStoreInner
{
    public function __construct(...$args)
    {
        parent::__construct(...$args);
        \Flaky160B\Rec::$stores[] = [getmypid(), $this];
    }

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        $__t = \Flaky160B\Rec::begin('increment', [$key, $ttlSeconds, $amount]);
        try {
            $__r = parent::increment($key, $ttlSeconds, $amount);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        \Flaky160B\Rec::$keys[$key] = true;
        return $__r;
    }

    public function incrementAt(string $key, int $ttlSeconds, int $amount, int $now): int
    {
        $__t = \Flaky160B\Rec::begin('incrementAt', [$key, $ttlSeconds, $amount, $now]);
        try {
            $__r = parent::incrementAt($key, $ttlSeconds, $amount, $now);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        \Flaky160B\Rec::$keys[$key] = true;
        return $__r;
    }

    public function get(string $key): ?\Maatify\RateLimiter\DTO\RateLimitStateDTO
    {
        $__t = \Flaky160B\Rec::begin('get', [$key]);
        try {
            $__r = parent::get($key);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $__t = \Flaky160B\Rec::begin('set', [$key, $value, $ttlSeconds]);
        try {
            parent::set($key, $value, $ttlSeconds);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, null);
        return;
    }

    public function setAt(string $key, int $value, int $ttlSeconds, int $now): void
    {
        $__t = \Flaky160B\Rec::begin('setAt', [$key, $value, $ttlSeconds, $now]);
        try {
            parent::setAt($key, $value, $ttlSeconds, $now);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, null);
        return;
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        $__t = \Flaky160B\Rec::begin('block', [$key, $level, $durationSeconds]);
        try {
            parent::block($key, $level, $durationSeconds);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, null);
        return;
    }

    public function blockAt(string $key, int $level, int $durationSeconds, int $now): void
    {
        $__t = \Flaky160B\Rec::begin('blockAt', [$key, $level, $durationSeconds, $now]);
        try {
            parent::blockAt($key, $level, $durationSeconds, $now);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, null);
        return;
    }

    public function checkBlock(string $key): ?\Maatify\RateLimiter\DTO\BlockStateDTO
    {
        $__t = \Flaky160B\Rec::begin('checkBlock', [$key]);
        try {
            $__r = parent::checkBlock($key);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function checkBlockAt(string $key, int $now): ?\Maatify\RateLimiter\DTO\BlockStateDTO
    {
        $__t = \Flaky160B\Rec::begin('checkBlockAt', [$key, $now]);
        try {
            $__r = parent::checkBlockAt($key, $now);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function getBudget(string $key): ?\Maatify\RateLimiter\DTO\BudgetStateDTO
    {
        $__t = \Flaky160B\Rec::begin('getBudget', [$key]);
        try {
            $__r = parent::getBudget($key);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function getBudgetAt(string $key, int $now): ?\Maatify\RateLimiter\DTO\BudgetStateDTO
    {
        $__t = \Flaky160B\Rec::begin('getBudgetAt', [$key, $now]);
        try {
            $__r = parent::getBudgetAt($key, $now);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): \Maatify\RateLimiter\DTO\BudgetStateDTO
    {
        $__t = \Flaky160B\Rec::begin('incrementBudget', [$key, $epochDurationSeconds, $amount]);
        try {
            $__r = parent::incrementBudget($key, $epochDurationSeconds, $amount);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function incrementBudgetAt(string $key, int $epochDurationSeconds, int $amount, int $now): \Maatify\RateLimiter\DTO\BudgetStateDTO
    {
        $__t = \Flaky160B\Rec::begin('incrementBudgetAt', [$key, $epochDurationSeconds, $amount, $now]);
        try {
            $__r = parent::incrementBudgetAt($key, $epochDurationSeconds, $amount, $now);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function incrementBudgetWithSeed(string $key, int $epochDurationSeconds, \Maatify\RateLimiter\DTO\BudgetStateDTO $seed, int $amount = 1): \Maatify\RateLimiter\DTO\BudgetStateDTO
    {
        $__t = \Flaky160B\Rec::begin('incrementBudgetWithSeed', [$key, $epochDurationSeconds, $seed, $amount]);
        try {
            $__r = parent::incrementBudgetWithSeed($key, $epochDurationSeconds, $seed, $amount);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function incrementBudgetWithSeedAt(string $key, int $epochDurationSeconds, \Maatify\RateLimiter\DTO\BudgetStateDTO $seed, int $amount, int $now): \Maatify\RateLimiter\DTO\BudgetStateDTO
    {
        $__t = \Flaky160B\Rec::begin('incrementBudgetWithSeedAt', [$key, $epochDurationSeconds, $seed, $amount, $now]);
        try {
            $__r = parent::incrementBudgetWithSeedAt($key, $epochDurationSeconds, $seed, $amount, $now);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        $__t = \Flaky160B\Rec::begin('addDistinct', [$key, $item, $ttlSeconds]);
        try {
            $__r = parent::addDistinct($key, $item, $ttlSeconds);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function addDistinctAt(string $key, string $item, int $ttlSeconds, int $now): int
    {
        $__t = \Flaky160B\Rec::begin('addDistinctAt', [$key, $item, $ttlSeconds, $now]);
        try {
            $__r = parent::addDistinctAt($key, $item, $ttlSeconds, $now);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        $__t = \Flaky160B\Rec::begin('incrementWatchFlag', [$key, $ttlSeconds]);
        try {
            $__r = parent::incrementWatchFlag($key, $ttlSeconds);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function incrementWatchFlagAt(string $key, int $ttlSeconds, int $now): int
    {
        $__t = \Flaky160B\Rec::begin('incrementWatchFlagAt', [$key, $ttlSeconds, $now]);
        try {
            $__r = parent::incrementWatchFlagAt($key, $ttlSeconds, $now);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function getWatchFlag(string $key): int
    {
        $__t = \Flaky160B\Rec::begin('getWatchFlag', [$key]);
        try {
            $__r = parent::getWatchFlag($key);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
    {
        $__t = \Flaky160B\Rec::begin('addDistinctBounded', [$key, $item, $ttlSeconds, $maxDistinct]);
        try {
            $__r = parent::addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function addDistinctBoundedAt(string $key, string $item, int $ttlSeconds, int $maxDistinct, int $now): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
    {
        $__t = \Flaky160B\Rec::begin('addDistinctBoundedAt', [$key, $item, $ttlSeconds, $maxDistinct, $now]);
        try {
            $__r = parent::addDistinctBoundedAt($key, $item, $ttlSeconds, $maxDistinct, $now);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function addDistinctBoundedWithSnapshot(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO
    {
        $__t = \Flaky160B\Rec::begin('addDistinctBoundedWithSnapshot', [$key, $item, $ttlSeconds, $maxDistinct]);
        try {
            $__r = parent::addDistinctBoundedWithSnapshot($key, $item, $ttlSeconds, $maxDistinct);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function addDistinctBoundedWithSnapshotAt(string $key, string $item, int $ttlSeconds, int $maxDistinct, int $now): \Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO
    {
        $__t = \Flaky160B\Rec::begin('addDistinctBoundedWithSnapshotAt', [$key, $item, $ttlSeconds, $maxDistinct, $now]);
        try {
            $__r = parent::addDistinctBoundedWithSnapshotAt($key, $item, $ttlSeconds, $maxDistinct, $now);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function addDistinctAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds): int
    {
        $__t = \Flaky160B\Rec::begin('addDistinctAcrossRotation', [$currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds]);
        try {
            $__r = parent::addDistinctAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function addDistinctAcrossRotationAt(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $nowMilliseconds): int
    {
        $__t = \Flaky160B\Rec::begin('addDistinctAcrossRotationAt', [$currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $nowMilliseconds]);
        try {
            $__r = parent::addDistinctAcrossRotationAt($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $nowMilliseconds);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function addDistinctBoundedAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
    {
        $__t = \Flaky160B\Rec::begin('addDistinctBoundedAcrossRotation', [$currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct]);
        try {
            $__r = parent::addDistinctBoundedAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function addDistinctBoundedAcrossRotationAt(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct, int $nowMilliseconds): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
    {
        $__t = \Flaky160B\Rec::begin('addDistinctBoundedAcrossRotationAt', [$currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct, $nowMilliseconds]);
        try {
            $__r = parent::addDistinctBoundedAcrossRotationAt($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct, $nowMilliseconds);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function addDistinctBoundedWithSnapshotAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO
    {
        $__t = \Flaky160B\Rec::begin('addDistinctBoundedWithSnapshotAcrossRotation', [$currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct]);
        try {
            $__r = parent::addDistinctBoundedWithSnapshotAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function addDistinctBoundedWithSnapshotAcrossRotationAt(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct, int $nowMilliseconds): \Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO
    {
        $__t = \Flaky160B\Rec::begin('addDistinctBoundedWithSnapshotAcrossRotationAt', [$currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct, $nowMilliseconds]);
        try {
            $__r = parent::addDistinctBoundedWithSnapshotAcrossRotationAt($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct, $nowMilliseconds);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function incrementWatchFlagAcrossRotation(string $currentKey, string $previousKey, int $ttlSeconds): int
    {
        $__t = \Flaky160B\Rec::begin('incrementWatchFlagAcrossRotation', [$currentKey, $previousKey, $ttlSeconds]);
        try {
            $__r = parent::incrementWatchFlagAcrossRotation($currentKey, $previousKey, $ttlSeconds);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function incrementWatchFlagAcrossRotationAt(string $currentKey, string $previousKey, int $ttlSeconds, int $now): int
    {
        $__t = \Flaky160B\Rec::begin('incrementWatchFlagAcrossRotationAt', [$currentKey, $previousKey, $ttlSeconds, $now]);
        try {
            $__r = parent::incrementWatchFlagAcrossRotationAt($currentKey, $previousKey, $ttlSeconds, $now);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function load(string $policyName): ?\Maatify\RateLimiter\DTO\CircuitBreakerStateDTO
    {
        $__t = \Flaky160B\Rec::begin('load', [$policyName]);
        try {
            $__r = parent::load($policyName);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function save(string $policyName, \Maatify\RateLimiter\DTO\CircuitBreakerStateDTO $state): void
    {
        $__t = \Flaky160B\Rec::begin('save', [$policyName, $state]);
        try {
            parent::save($policyName, $state);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, null);
        return;
    }

    public function acquireProbeLease(string $policyName, int $now, int $leaseSeconds): bool
    {
        $__t = \Flaky160B\Rec::begin('acquireProbeLease', [$policyName, $now, $leaseSeconds]);
        try {
            $__r = parent::acquireProbeLease($policyName, $now, $leaseSeconds);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function blockWithCycleTracking(string $currentKey, ?string $previousKey, int $level, int $durationSeconds, int $now, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): \Maatify\RateLimiter\DTO\HardBlockCycleResultDTO
    {
        $__t = \Flaky160B\Rec::begin('blockWithCycleTracking', [$currentKey, $previousKey, $level, $durationSeconds, $now, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds]);
        try {
            $__r = parent::blockWithCycleTracking($currentKey, $previousKey, $level, $durationSeconds, $now, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function readDecayPauseState(string $currentKey, ?string $previousKey, int $fromTimestamp, int $now): \Maatify\RateLimiter\DTO\DecayPauseStateDTO
    {
        $__t = \Flaky160B\Rec::begin('readDecayPauseState', [$currentKey, $previousKey, $fromTimestamp, $now]);
        try {
            $__r = parent::readDecayPauseState($currentKey, $previousKey, $fromTimestamp, $now);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function readGenerationBoundScoreState(string $currentKey, ?string $previousKey): ?\Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO
    {
        $__t = \Flaky160B\Rec::begin('readGenerationBoundScoreState', [$currentKey, $previousKey]);
        try {
            $__r = parent::readGenerationBoundScoreState($currentKey, $previousKey);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function readGenerationBoundScoreStateAt(string $currentKey, ?string $previousKey, int $nowMilliseconds): ?\Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO
    {
        $__t = \Flaky160B\Rec::begin('readGenerationBoundScoreStateAt', [$currentKey, $previousKey, $nowMilliseconds]);
        try {
            $__r = parent::readGenerationBoundScoreStateAt($currentKey, $previousKey, $nowMilliseconds);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function mutateGenerationBoundScore(string $currentKey, ?string $previousKey, ?\Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO $expectedState, int $ttlSeconds, int $newValue): \Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO
    {
        $__t = \Flaky160B\Rec::begin('mutateGenerationBoundScore', [$currentKey, $previousKey, $expectedState, $ttlSeconds, $newValue]);
        try {
            $__r = parent::mutateGenerationBoundScore($currentKey, $previousKey, $expectedState, $ttlSeconds, $newValue);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        \Flaky160B\Rec::$keys[$currentKey] = true;
        return $__r;
    }

    public function mutateGenerationBoundScoreAt(string $currentKey, ?string $previousKey, ?\Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO $expectedState, int $ttlSeconds, int $newValue, int $nowMilliseconds): \Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO
    {
        $__t = \Flaky160B\Rec::begin('mutateGenerationBoundScoreAt', [$currentKey, $previousKey, $expectedState, $ttlSeconds, $newValue, $nowMilliseconds]);
        try {
            $__r = parent::mutateGenerationBoundScoreAt($currentKey, $previousKey, $expectedState, $ttlSeconds, $newValue, $nowMilliseconds);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        \Flaky160B\Rec::$keys[$currentKey] = true;
        return $__r;
    }

    public function blockWithPunishmentLifecycleTracking(string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): \Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO
    {
        $__t = \Flaky160B\Rec::begin('blockWithPunishmentLifecycleTracking', [$currentKey, $previousKey, $expectedGeneration, $proposedLifecycleId, $level, $durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds]);
        try {
            $__r = parent::blockWithPunishmentLifecycleTracking($currentKey, $previousKey, $expectedGeneration, $proposedLifecycleId, $level, $durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function blockWithPunishmentLifecycleTrackingAt(string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds, int $now): \Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO
    {
        $__t = \Flaky160B\Rec::begin('blockWithPunishmentLifecycleTrackingAt', [$currentKey, $previousKey, $expectedGeneration, $proposedLifecycleId, $level, $durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds, $now]);
        try {
            $__r = parent::blockWithPunishmentLifecycleTrackingAt($currentKey, $previousKey, $expectedGeneration, $proposedLifecycleId, $level, $durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds, $now);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        \Flaky160B\Rec::$keys[$currentKey] = true;
        if (! $__r->applied) {
            $this->__flaky160bObserve($currentKey, ++\Flaky160B\Rec::$pubCount);
        }
        return $__r;
    }

    public function claimPostPunishmentReentry(string $currentKey, ?string $previousKey, string $lifecycleId): bool
    {
        $__t = \Flaky160B\Rec::begin('claimPostPunishmentReentry', [$currentKey, $previousKey, $lifecycleId]);
        try {
            $__r = parent::claimPostPunishmentReentry($currentKey, $previousKey, $lifecycleId);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function claimPostPunishmentReentryAt(string $currentKey, ?string $previousKey, string $lifecycleId, int $nowMilliseconds): bool
    {
        $__t = \Flaky160B\Rec::begin('claimPostPunishmentReentryAt', [$currentKey, $previousKey, $lifecycleId, $nowMilliseconds]);
        try {
            $__r = parent::claimPostPunishmentReentryAt($currentKey, $previousKey, $lifecycleId, $nowMilliseconds);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function reserveAdmission(\Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO $request, int $now): \Maatify\RateLimiter\DTO\AdmissionReservationResultDTO
    {
        $__t = \Flaky160B\Rec::begin('reserveAdmission', [$request, $now]);
        try {
            $__r = parent::reserveAdmission($request, $now);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function applyAdmissionOutcome(\Maatify\RateLimiter\DTO\AdmissionOutcomeDTO $outcome, int $now): void
    {
        $__t = \Flaky160B\Rec::begin('applyAdmissionOutcome', [$outcome, $now]);
        try {
            parent::applyAdmissionOutcome($outcome, $now);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, null);
        return;
    }

    public function readBindingEpoch(string $key, ?string $previousKey): int
    {
        $__t = \Flaky160B\Rec::begin('readBindingEpoch', [$key, $previousKey]);
        try {
            $__r = parent::readBindingEpoch($key, $previousKey);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function bumpBindingEpoch(string $key, ?string $previousKey): int
    {
        $__t = \Flaky160B\Rec::begin('bumpBindingEpoch', [$key, $previousKey]);
        try {
            $__r = parent::bumpBindingEpoch($key, $previousKey);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function claimAdmissionPermit(string $permitId, string $lane, string $subject, string $operation, \Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum $outcome, int $now): \Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum
    {
        $__t = \Flaky160B\Rec::begin('claimAdmissionPermit', [$permitId, $lane, $subject, $operation, $outcome, $now]);
        try {
            $__r = parent::claimAdmissionPermit($permitId, $lane, $subject, $operation, $outcome, $now);
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    public function isHealthy(): bool
    {
        $__t = \Flaky160B\Rec::begin('isHealthy', []);
        try {
            $__r = parent::isHealthy();
        } catch (\Throwable $__e) {
            \Flaky160B\Rec::end($__t, null, $__e);
            throw $__e;
        }
        \Flaky160B\Rec::end($__t, $__r);
        return $__r;
    }

    /** LT-160C-02: read-only block read after a non-applied publication; records, changes nothing. */
    public function __flaky160bObserve(string $key, int $n): void
    {
        \Flaky160B\Rec::$phase = 'obs';
        try {
            $b = parent::checkBlock($key);
            \Flaky160B\Rec::note('obs', ['n' => $n, 'key' => $key, 'level' => $b?->level, 'expiresAt' => $b?->expiresAt]);
        } finally {
            \Flaky160B\Rec::$phase = 'run';
        }
    }
}
