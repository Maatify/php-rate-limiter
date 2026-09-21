<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Correlation;

use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\CorrelationRotationStoreInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

class StatefulInMemoryCorrelationStore implements CorrelationRotationStoreInterface
{
    /** @var array<string, array{items: array<string, bool>, expiresAt: int|null}> */
    private array $sets = [];

    /** @var array<string, array{count: int, expiresAt: int|null}> */
    private array $flags = [];

    public function __construct(private readonly ClockInterface $clock) {}

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        $this->assertTtl($ttlSeconds);
        $now = $this->clock->now()->getTimestamp();
        $state = $this->sets[$key] ?? null;

        if ($state !== null && $state['expiresAt'] === null) {
            throw new RateLimiterException('Correlation distinct state has no valid TTL.');
        }

        if ($state === null || $state['expiresAt'] <= $now) {
            $state = [
                'items' => [],
                'expiresAt' => $now + $ttlSeconds,
            ];
        }

        $state['items'][$item] = true;
        $this->sets[$key] = $state;

        return count($state['items']);
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        $this->assertTtl($ttlSeconds);
        $now = $this->clock->now()->getTimestamp();
        $state = $this->flags[$key] ?? null;

        if ($state !== null && $state['expiresAt'] === null) {
            throw new RateLimiterException('Correlation WATCH state has no valid TTL.');
        }

        if ($state === null || $state['expiresAt'] <= $now) {
            $state = [
                'count' => 0,
                'expiresAt' => $now + $ttlSeconds,
            ];
        }

        $state['count']++;
        $this->flags[$key] = $state;

        return $state['count'];
    }

    public function getWatchFlag(string $key): int
    {
        $now = $this->clock->now()->getTimestamp();
        $state = $this->flags[$key] ?? null;

        if ($state === null) {
            return 0;
        }

        if ($state['expiresAt'] === null) {
            throw new RateLimiterException('Correlation WATCH state has no valid TTL.');
        }

        if ($state['expiresAt'] <= $now) {
            return 0;
        }

        return $state['count'];
    }

    public function addDistinctAcrossRotation(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
    ): int {
        $this->assertTtl($ttlSeconds);
        $now = $this->clock->now()->getTimestamp();
        $previous = $this->activeSet($previousKey, $now);
        $current = $this->prepareSet($this->sets[$currentKey] ?? null, $now, $ttlSeconds);
        $bridge = null;

        $current['items'][$currentMember] = true;

        if ($previous === null) {
            $this->sets[$currentKey] = $current;

            return count($current['items']);
        }

        $shouldWriteBridge = ! isset($previous['items'][$previousMember]);
        $bridge = $this->prepareBridgeSet($this->sets[$bridgeKey] ?? null, $now, $ttlSeconds, $previous['expiresAt']);
        if ($shouldWriteBridge) {
            $bridge['items'][$currentMember] = true;
        }

        $this->sets[$currentKey] = $current;
        if ($shouldWriteBridge) {
            $this->sets[$bridgeKey] = $bridge;
        }

        return count($previous['items']) + count($bridge['items']);
    }

    public function incrementWatchFlagAcrossRotation(
        string $currentKey,
        string $previousKey,
        int $ttlSeconds,
    ): int {
        $this->assertTtl($ttlSeconds);
        $now = $this->clock->now()->getTimestamp();
        $previous = $this->activeFlag($previousKey, $now);
        $current = $this->prepareFlag($this->flags[$currentKey] ?? null, $now, $ttlSeconds);
        $current['count']++;

        $this->flags[$currentKey] = $current;

        return $current['count'] + ($previous['count'] ?? 0);
    }

    /** @return list<string> */
    public function distinctItems(string $key): array
    {
        return array_keys($this->sets[$key]['items'] ?? []);
    }

    public function distinctExpiresAt(string $key): ?int
    {
        return $this->sets[$key]['expiresAt'] ?? null;
    }

    public function watchValue(string $key): int
    {
        return $this->flags[$key]['count'] ?? 0;
    }

    public function watchExpiresAt(string $key): ?int
    {
        return $this->flags[$key]['expiresAt'] ?? null;
    }

    public function corruptDistinctTtl(string $key): void
    {
        if (! isset($this->sets[$key])) {
            throw new RateLimiterException('Cannot corrupt an absent correlation distinct state.');
        }

        $this->sets[$key]['expiresAt'] = null;
    }

    public function corruptWatchTtl(string $key): void
    {
        if (! isset($this->flags[$key])) {
            throw new RateLimiterException('Cannot corrupt an absent correlation WATCH state.');
        }

        $this->flags[$key]['expiresAt'] = null;
    }

    /** @return array{items: array<string, bool>, expiresAt: int}|null */
    private function activeSet(string $key, int $now): ?array
    {
        $state = $this->sets[$key] ?? null;
        if ($state === null) {
            return null;
        }

        if ($state['expiresAt'] === null) {
            throw new RateLimiterException('Previous correlation distinct state has no valid TTL.');
        }

        return $state['expiresAt'] <= $now ? null : $state;
    }

    /** @return array{count: int, expiresAt: int}|null */
    private function activeFlag(string $key, int $now): ?array
    {
        $state = $this->flags[$key] ?? null;
        if ($state === null) {
            return null;
        }

        if ($state['expiresAt'] === null) {
            throw new RateLimiterException('Previous correlation WATCH state has no valid TTL.');
        }

        return $state['expiresAt'] <= $now ? null : $state;
    }

    /**
     * @param array{items: array<string, bool>, expiresAt: int|null}|null $state
     * @return array{items: array<string, bool>, expiresAt: int}
     */
    private function prepareSet(?array $state, int $now, int $ttlSeconds): array
    {
        if ($state === null) {
            return ['items' => [], 'expiresAt' => $now + $ttlSeconds];
        }

        $expiresAt = $state['expiresAt'];
        if ($expiresAt === null) {
            throw new RateLimiterException('Current correlation distinct state has no valid TTL.');
        }

        if ($expiresAt <= $now) {
            return ['items' => [], 'expiresAt' => $now + $ttlSeconds];
        }

        return [
            'items' => $state['items'],
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * @param array{items: array<string, bool>, expiresAt: int|null}|null $state
     * @return array{items: array<string, bool>, expiresAt: int}
     */
    private function prepareBridgeSet(?array $state, int $now, int $ttlSeconds, int $previousExpiresAt): array
    {
        if ($state === null) {
            return [
                'items' => [],
                'expiresAt' => $now + min($ttlSeconds, $previousExpiresAt - $now),
            ];
        }

        $expiresAt = $state['expiresAt'];
        if ($expiresAt === null) {
            throw new RateLimiterException('Bridge correlation state has no valid TTL.');
        }

        if ($expiresAt <= $now) {
            return [
                'items' => [],
                'expiresAt' => $now + min($ttlSeconds, $previousExpiresAt - $now),
            ];
        }

        if ($expiresAt > $previousExpiresAt) {
            throw new RateLimiterException('Bridge correlation TTL exceeds previous-generation remaining TTL.');
        }

        return [
            'items' => $state['items'],
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * @param array{count: int, expiresAt: int|null}|null $state
     * @return array{count: int, expiresAt: int}
     */
    private function prepareFlag(?array $state, int $now, int $ttlSeconds): array
    {
        if ($state === null) {
            return ['count' => 0, 'expiresAt' => $now + $ttlSeconds];
        }

        $expiresAt = $state['expiresAt'];
        if ($expiresAt === null) {
            throw new RateLimiterException('Current correlation WATCH state has no valid TTL.');
        }

        if ($expiresAt <= $now) {
            return ['count' => 0, 'expiresAt' => $now + $ttlSeconds];
        }

        return [
            'count' => $state['count'],
            'expiresAt' => $expiresAt,
        ];
    }

    private function assertTtl(int $ttlSeconds): void
    {
        if ($ttlSeconds < 1) {
            throw new RateLimiterException('Correlation TTL must be positive.');
        }
    }
}
