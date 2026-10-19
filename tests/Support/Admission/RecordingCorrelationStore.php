<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;

/**
 * Correlation reference store that records which keys were observed through the bounded distinct sets and the WATCH
 * flags. It changes no behaviour.
 */
final class RecordingCorrelationStore extends StatefulInMemoryCorrelationStore
{
    /** @var list<string> */
    public array $distinctKeys = [];

    /** @var list<string> */
    public array $watchIncrements = [];

    public function addDistinctBounded(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO {
        $this->distinctKeys[] = $key;

        return parent::addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
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
        $this->distinctKeys[] = $currentKey;

        return parent::addDistinctBoundedAcrossRotation(
            $currentKey,
            $bridgeKey,
            $previousKey,
            $currentMember,
            $previousMember,
            $ttlSeconds,
            $maxDistinct,
        );
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        $this->watchIncrements[] = $key;

        return parent::incrementWatchFlag($key, $ttlSeconds);
    }

    public function incrementWatchFlagAcrossRotation(string $currentKey, string $previousKey, int $ttlSeconds): int
    {
        $this->watchIncrements[] = $currentKey;

        return parent::incrementWatchFlagAcrossRotation($currentKey, $previousKey, $ttlSeconds);
    }
}
