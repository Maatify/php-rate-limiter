<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository\Redis;

class RedisFullCapabilityStore extends RedisFullCapabilityStoreInner
{
    public function mutateGenerationBoundScoreAt(string $currentKey, ?string $previousKey, ?\Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO $expectedState, int $ttlSeconds, int $newValue, int $nowMilliseconds): \Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO
    {
        $__r = parent::mutateGenerationBoundScoreAt($currentKey, $previousKey, $expectedState, $ttlSeconds, $newValue, $nowMilliseconds);
        \Flaky160M\M2b::call('mutate', $__r->applied);
        return $__r;
    }

    public function blockWithPunishmentLifecycleTrackingAt(string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds, int $now): \Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO
    {
        $__r = parent::blockWithPunishmentLifecycleTrackingAt($currentKey, $previousKey, $expectedGeneration, $proposedLifecycleId, $level, $durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds, $now);
        \Flaky160M\M2b::call('publish', $__r->applied);
        return $__r;
    }
}
