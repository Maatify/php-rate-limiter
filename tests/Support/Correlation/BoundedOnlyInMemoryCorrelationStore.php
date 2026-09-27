<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Correlation;

use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\Repository\BoundedCorrelationStoreInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Test double exposing only BoundedCorrelationStoreInterface: bounded
 * distinct observations without rotation or snapshot support.
 *
 * Used to prove build-time preflight requirements for the base bounded
 * capability independently of the additive rotation/snapshot capabilities.
 */
final class BoundedOnlyInMemoryCorrelationStore implements BoundedCorrelationStoreInterface
{
    private readonly StatefulInMemoryCorrelationStore $delegate;

    public function __construct(ClockInterface $clock)
    {
        $this->delegate = new StatefulInMemoryCorrelationStore($clock);
    }

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        return $this->delegate->addDistinct($key, $item, $ttlSeconds);
    }

    public function addDistinctBounded(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO {
        return $this->delegate->addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        return $this->delegate->incrementWatchFlag($key, $ttlSeconds);
    }

    public function getWatchFlag(string $key): int
    {
        return $this->delegate->getWatchFlag($key);
    }
}
