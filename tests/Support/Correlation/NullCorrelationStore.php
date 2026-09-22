<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Correlation;

use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\Repository\BoundedCorrelationRotationStoreInterface;

class NullCorrelationStore implements BoundedCorrelationRotationStoreInterface
{
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
