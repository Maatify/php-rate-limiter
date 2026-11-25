<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Correlation;

use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\Repository\BoundedCorrelationRotationStoreInterface;

class NullCorrelationStore implements \Maatify\RateLimiter\Repository\E1KeyspaceStoreInterface, BoundedCorrelationRotationStoreInterface, \Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotRotationStoreInterface
{
    use \Maatify\RateLimiter\Tests\Support\E1View;

    public function addDistinctBoundedWithSnapshot(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO
    {
        return new \Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO(1, true, true, [$item], time() + $ttlSeconds);
    }

    public function addDistinctBoundedWithSnapshotAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO
    {
        return $this->addDistinctBoundedWithSnapshot($currentKey, $currentMember, $ttlSeconds, $maxDistinct);
    }

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        return 0;
    }

    public function addDistinctBounded(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO {
        if ($ttlSeconds < 1 || $maxDistinct < 1) {
            throw new \InvalidArgumentException('Bounded correlation arguments must be positive.');
        }

        return new BoundedDistinctResultDTO(1, true);
    }

    public function addDistinctBoundedAcrossRotation(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO {
        return $this->addDistinctBounded($currentKey, $currentMember, $ttlSeconds, $maxDistinct);
    }

    public function addDistinctAcrossRotation(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
    ): int {
        return 1;
    }

    public function incrementWatchFlagAcrossRotation(string $currentKey, string $previousKey, int $ttlSeconds): int
    {
        return 1;
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        return 0;
    }

    public function getWatchFlag(string $key): int
    {
        return 0;
    }
}
