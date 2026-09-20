<?php

declare(strict_types=1);

namespace ConsumerVerification;

use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

final class InMemoryCorrelationStore implements CorrelationStoreInterface
{
    /** @var array<string, array{items: array<string, true>, expiresAt: int}> */
    private array $distinctSets = [];

    /** @var array<string, array{value: int, expiresAt: int}> */
    private array $watchFlags = [];

    private int $operations = 0;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        $this->operations++;
        $now = $this->clock->now()->getTimestamp();
        $set = $this->distinctSets[$key] ?? null;

        if ($set === null || $set['expiresAt'] <= $now) {
            $set = [
                'items' => [],
                'expiresAt' => $now + $ttlSeconds,
            ];
        }

        $set['items'][$item] = true;
        $this->distinctSets[$key] = $set;

        return count($set['items']);
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        $this->operations++;
        $now = $this->clock->now()->getTimestamp();
        $flag = $this->watchFlags[$key] ?? null;

        if ($flag === null || $flag['expiresAt'] <= $now) {
            $flag = [
                'value' => 0,
                'expiresAt' => $now + $ttlSeconds,
            ];
        }

        $flag['value']++;
        $this->watchFlags[$key] = $flag;

        return $flag['value'];
    }

    public function getWatchFlag(string $key): int
    {
        $this->operations++;
        $flag = $this->watchFlags[$key] ?? null;
        if ($flag === null || $flag['expiresAt'] <= $this->clock->now()->getTimestamp()) {
            return 0;
        }

        return $flag['value'];
    }

    public function operationCount(): int
    {
        return $this->operations;
    }
}
