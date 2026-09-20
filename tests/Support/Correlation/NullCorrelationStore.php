<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Correlation;

use Maatify\RateLimiter\Repository\CorrelationStoreInterface;

class NullCorrelationStore implements CorrelationStoreInterface
{
    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        return 0;
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
