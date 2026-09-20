<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Correlation;

use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

class StatefulInMemoryCorrelationStore implements CorrelationStoreInterface
{
    /** @var array<string, array{items: array<string, bool>, expiresAt: int}> */
    private array $sets = [];

    /** @var array<string, array{count: int, expiresAt: int}> */
    private array $flags = [];

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        $now = $this->clock->now()->getTimestamp();

        if (!isset($this->sets[$key]) || $this->sets[$key]['expiresAt'] < $now) {
            $this->sets[$key] = [
                'items' => [],
                'expiresAt' => $now + $ttlSeconds
            ];
        }

        $this->sets[$key]['items'][$item] = true;

        return count($this->sets[$key]['items']);
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        $now = $this->clock->now()->getTimestamp();

        if (!isset($this->flags[$key]) || $this->flags[$key]['expiresAt'] < $now) {
            $this->flags[$key] = [
                'count' => 0,
                'expiresAt' => $now + $ttlSeconds
            ];
        }

        $this->flags[$key]['count']++;

        return $this->flags[$key]['count'];
    }

    public function getWatchFlag(string $key): int
    {
        $now = $this->clock->now()->getTimestamp();

        if (!isset($this->flags[$key]) || $this->flags[$key]['expiresAt'] < $now) {
            return 0;
        }

        return $this->flags[$key]['count'];
    }
}
