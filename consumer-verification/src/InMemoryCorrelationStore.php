<?php

declare(strict_types=1);

namespace ConsumerVerification;

use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\CorrelationRotationStoreInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

final class InMemoryCorrelationStore implements CorrelationRotationStoreInterface
{
    /** @var array<string, array{items: array<string, true>, expiresAt: int|null}> */
    private array $distinctSets = [];

    /** @var array<string, array{value: int, expiresAt: int|null}> */
    private array $watchFlags = [];

    private int $operations = 0;

    public function __construct(private readonly ClockInterface $clock) {}

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        $this->assertTtl($ttlSeconds);
        $this->operations++;
        $now = $this->clock->now()->getTimestamp();
        $set = $this->distinctSets[$key] ?? null;

        if ($set !== null && $set['expiresAt'] === null) {
            throw new RateLimiterException('Correlation distinct state has no valid TTL.');
        }

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
        $this->assertTtl($ttlSeconds);
        $this->operations++;
        $now = $this->clock->now()->getTimestamp();
        $flag = $this->watchFlags[$key] ?? null;

        if ($flag !== null && $flag['expiresAt'] === null) {
            throw new RateLimiterException('Correlation WATCH state has no valid TTL.');
        }

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
        if ($flag === null) {
            return 0;
        }

        if ($flag['expiresAt'] === null) {
            throw new RateLimiterException('Correlation WATCH state has no valid TTL.');
        }

        if ($flag['expiresAt'] <= $this->clock->now()->getTimestamp()) {
            return 0;
        }

        return $flag['value'];
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
        $this->operations++;
        $now = $this->clock->now()->getTimestamp();
        $previous = $this->activeSet($previousKey, $now);
        $current = $this->prepareSet($this->distinctSets[$currentKey] ?? null, $now, $ttlSeconds);
        $current['items'][$currentMember] = true;

        if ($previous === null) {
            $this->distinctSets[$currentKey] = $current;

            return count($current['items']);
        }

        $shouldWriteBridge = ! isset($previous['items'][$previousMember]);
        $bridge = $this->prepareBridgeSet(
            $this->distinctSets[$bridgeKey] ?? null,
            $now,
            $ttlSeconds,
            $previous['expiresAt'],
        );
        if ($shouldWriteBridge) {
            $bridge['items'][$currentMember] = true;
        }

        $this->distinctSets[$currentKey] = $current;
        if ($shouldWriteBridge) {
            $this->distinctSets[$bridgeKey] = $bridge;
        }

        return count($previous['items']) + count($bridge['items']);
    }

    public function incrementWatchFlagAcrossRotation(
        string $currentKey,
        string $previousKey,
        int $ttlSeconds,
    ): int {
        $this->assertTtl($ttlSeconds);
        $this->operations++;
        $now = $this->clock->now()->getTimestamp();
        $previous = $this->activeFlag($previousKey, $now);
        $current = $this->prepareFlag($this->watchFlags[$currentKey] ?? null, $now, $ttlSeconds);
        $current['value']++;

        $this->watchFlags[$currentKey] = $current;

        return $current['value'] + ($previous['value'] ?? 0);
    }

    private function assertTtl(int $ttlSeconds): void
    {
        if ($ttlSeconds < 1) {
            throw new RateLimiterException('Correlation TTL must be positive.');
        }
    }

    /**
     * @return array{items: array<string, true>, expiresAt: int}|null
     */
    private function activeSet(string $key, int $now): ?array
    {
        $set = $this->distinctSets[$key] ?? null;
        if ($set === null) {
            return null;
        }

        if ($set['expiresAt'] === null) {
            throw new RateLimiterException('Previous correlation distinct state has no valid TTL.');
        }

        return $set['expiresAt'] <= $now ? null : $set;
    }

    /**
     * @return array{value: int, expiresAt: int}|null
     */
    private function activeFlag(string $key, int $now): ?array
    {
        $flag = $this->watchFlags[$key] ?? null;
        if ($flag === null) {
            return null;
        }

        if ($flag['expiresAt'] === null) {
            throw new RateLimiterException('Previous correlation WATCH state has no valid TTL.');
        }

        return $flag['expiresAt'] <= $now ? null : $flag;
    }

    /**
     * @param array{items: array<string, true>, expiresAt: int|null}|null $set
     * @return array{items: array<string, true>, expiresAt: int}
     */
    private function prepareSet(?array $set, int $now, int $ttlSeconds): array
    {
        if ($set !== null && $set['expiresAt'] === null) {
            throw new RateLimiterException('Current correlation distinct state has no valid TTL.');
        }

        if ($set === null || $set['expiresAt'] <= $now) {
            return ['items' => [], 'expiresAt' => $now + $ttlSeconds];
        }

        return $set;
    }

    /**
     * @param array{items: array<string, true>, expiresAt: int|null}|null $set
     * @return array{items: array<string, true>, expiresAt: int}
     */
    private function prepareBridgeSet(?array $set, int $now, int $ttlSeconds, int $previousExpiresAt): array
    {
        if ($set !== null && $set['expiresAt'] === null) {
            throw new RateLimiterException('Bridge correlation state has no valid TTL.');
        }

        if ($set === null || $set['expiresAt'] <= $now) {
            return [
                'items' => [],
                'expiresAt' => $now + min($ttlSeconds, $previousExpiresAt - $now),
            ];
        }

        if ($set['expiresAt'] > $previousExpiresAt) {
            throw new RateLimiterException('Bridge correlation TTL exceeds previous-generation remaining TTL.');
        }

        return $set;
    }

    /**
     * @param array{value: int, expiresAt: int|null}|null $flag
     * @return array{value: int, expiresAt: int}
     */
    private function prepareFlag(?array $flag, int $now, int $ttlSeconds): array
    {
        if ($flag !== null && $flag['expiresAt'] === null) {
            throw new RateLimiterException('Current correlation WATCH state has no valid TTL.');
        }

        if ($flag === null || $flag['expiresAt'] <= $now) {
            return ['value' => 0, 'expiresAt' => $now + $ttlSeconds];
        }

        return $flag;
    }

    public function operationCount(): int
    {
        return $this->operations;
    }
}
