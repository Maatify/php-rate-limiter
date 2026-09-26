<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Correlation;

use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotRotationStoreInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

class StatefulInMemoryCorrelationStore implements BoundedCorrelationSnapshotRotationStoreInterface
{
    /** @var array<string, array{items: array<string, bool>, expiresAt: int|null}> */
    private array $sets = [];

    /** @var array<string, array{count: int, expiresAt: int|null}> */
    private array $flags = [];

    private int $operations = 0;

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

    public function addDistinctBounded(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO {
        $this->assertTtl($ttlSeconds);
        $this->assertMaxDistinct($maxDistinct);
        $now = $this->clock->now()->getTimestamp();
        $state = $this->prepareSet($this->sets[$key] ?? null, $now, $ttlSeconds);
        $this->assertWithinCapacity($state, $maxDistinct, 'bounded distinct state');

        if (isset($state['items'][$item])) {
            return new BoundedDistinctResultDTO(count($state['items']), true);
        }

        $count = count($state['items']);
        if ($count >= $maxDistinct) {
            return new BoundedDistinctResultDTO($count, false);
        }

        $state['items'][$item] = true;
        $this->sets[$key] = $state;

        return new BoundedDistinctResultDTO($count + 1, true);
    }

    public function addDistinctBoundedWithSnapshot(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctSnapshotDTO {
        $this->assertTtl($ttlSeconds);
        $this->assertMaxDistinct($maxDistinct);
        $this->operations++;
        $now = $this->clock->now()->getTimestamp();
        $state = $this->prepareSet($this->sets[$key] ?? null, $now, $ttlSeconds);
        $this->assertWithinCapacity($state, $maxDistinct, 'bounded distinct state');

        if (isset($state['items'][$item])) {
            return $this->snapshot($state, true, false);
        }

        if (count($state['items']) >= $maxDistinct) {
            return $this->snapshot($state, false, false);
        }

        $state['items'][$item] = true;
        $this->sets[$key] = $state;

        return $this->snapshot($state, true, true);
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

    public function addDistinctBoundedAcrossRotation(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO {
        $this->assertTtl($ttlSeconds);
        $this->assertMaxDistinct($maxDistinct);
        $now = $this->clock->now()->getTimestamp();
        $previous = $this->activeSet($previousKey, $now);

        if ($previous === null) {
            return $this->addDistinctBounded($currentKey, $currentMember, $ttlSeconds, $maxDistinct);
        }

        $this->assertWithinCapacity($previous, $maxDistinct, 'previous bounded distinct state');

        if ($currentKey === $previousKey) {
            $current = $this->prepareSet($this->sets[$currentKey] ?? null, $now, $ttlSeconds);
            $this->assertWithinCapacity($current, $maxDistinct, 'aliased bounded distinct state');

            if (isset($current['items'][$currentMember])) {
                return new BoundedDistinctResultDTO(count($current['items']), true);
            }

            $knownPreviousMember = isset($current['items'][$previousMember]);
            if (! $knownPreviousMember && count($current['items']) >= $maxDistinct) {
                return new BoundedDistinctResultDTO($maxDistinct, false);
            }

            if ($knownPreviousMember) {
                return new BoundedDistinctResultDTO(count($current['items']), true);
            }

            if (count($current['items']) < $maxDistinct) {
                $current['items'][$currentMember] = true;
                $this->sets[$currentKey] = $current;
            }

            return new BoundedDistinctResultDTO(count($current['items']), true);
        }

        $current = $this->prepareSet($this->sets[$currentKey] ?? null, $now, $ttlSeconds);
        $bridge = $this->prepareBridgeSet(
            $this->sets[$bridgeKey] ?? null,
            $now,
            $ttlSeconds,
            $previous['expiresAt'],
        );
        $this->assertWithinCapacity($current, $maxDistinct, 'current bounded distinct state');
        $this->assertWithinCapacity($bridge, $maxDistinct, 'bridge bounded distinct state');

        if (array_intersect_key($previous['items'], $bridge['items']) !== []) {
            throw new RateLimiterException('Bridge bounded distinct state overlaps previous-generation members.');
        }

        $effectiveCount = count($previous['items']) + count($bridge['items']);
        if ($effectiveCount > $maxDistinct) {
            throw new RateLimiterException('Bounded distinct rotation state exceeds its logical capacity.');
        }

        if (isset($current['items'][$currentMember]) || isset($bridge['items'][$currentMember])) {
            return new BoundedDistinctResultDTO($effectiveCount, true);
        }

        if (isset($previous['items'][$previousMember])) {
            if (! isset($current['items'][$currentMember]) && count($current['items']) < $maxDistinct) {
                $current['items'][$currentMember] = true;
                $this->sets[$currentKey] = $current;
            }

            return new BoundedDistinctResultDTO($effectiveCount, true);
        }

        if ($effectiveCount >= $maxDistinct) {
            return new BoundedDistinctResultDTO($effectiveCount, false);
        }

        $current['items'][$currentMember] = true;
        $bridge['items'][$currentMember] = true;
        $this->sets[$currentKey] = $current;
        $this->sets[$bridgeKey] = $bridge;

        return new BoundedDistinctResultDTO($effectiveCount + 1, true);
    }

    public function addDistinctBoundedWithSnapshotAcrossRotation(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctSnapshotDTO {
        $this->assertTtl($ttlSeconds);
        $this->assertMaxDistinct($maxDistinct);
        $now = $this->clock->now()->getTimestamp();
        $previous = $this->activeSet($previousKey, $now);

        if ($previous === null) {
            return $this->addDistinctBoundedWithSnapshot($currentKey, $currentMember, $ttlSeconds, $maxDistinct);
        }

        $this->operations++;
        $this->assertWithinCapacity($previous, $maxDistinct, 'previous bounded distinct state');

        if ($currentKey === $previousKey) {
            $current = $this->prepareSet($this->sets[$currentKey] ?? null, $now, $ttlSeconds);
            $this->assertWithinCapacity($current, $maxDistinct, 'aliased bounded distinct state');
            $known = isset($current['items'][$currentMember]) || isset($current['items'][$previousMember]);

            if (! $known && count($current['items']) < $maxDistinct) {
                $current['items'][$currentMember] = true;
                $this->sets[$currentKey] = $current;
                $known = true;
                return $this->snapshot($current, true, true, $previous['expiresAt']);
            }

            return $this->snapshot($current, $known, false, $previous['expiresAt']);
        }

        $current = $this->prepareSet($this->sets[$currentKey] ?? null, $now, $ttlSeconds);
        $bridge = $this->prepareBridgeSet(
            $this->sets[$bridgeKey] ?? null,
            $now,
            $ttlSeconds,
            $previous['expiresAt'],
        );
        $this->assertWithinCapacity($current, $maxDistinct, 'current bounded distinct state');
        $this->assertWithinCapacity($bridge, $maxDistinct, 'bridge bounded distinct state');

        if (array_intersect_key($previous['items'], $bridge['items']) !== []) {
            throw new RateLimiterException('Bridge bounded distinct state overlaps previous-generation members.');
        }

        $members = array_merge(array_keys($previous['items']), array_keys($bridge['items']));
        if (count($members) > $maxDistinct) {
            throw new RateLimiterException('Bounded distinct rotation state exceeds its logical capacity.');
        }

        if (isset($bridge['items'][$currentMember]) || isset($previous['items'][$previousMember])) {
            return new BoundedDistinctSnapshotDTO(
                count($members),
                true,
                false,
                $members,
                $previous['expiresAt'],
            );
        }

        if (count($members) >= $maxDistinct) {
            return new BoundedDistinctSnapshotDTO(
                count($members),
                false,
                false,
                $members,
                $previous['expiresAt'],
            );
        }

        $current['items'][$currentMember] = true;
        $bridge['items'][$currentMember] = true;
        $this->sets[$currentKey] = $current;
        $this->sets[$bridgeKey] = $bridge;
        $members[] = $currentMember;

        return new BoundedDistinctSnapshotDTO(
            count($members),
            true,
            true,
            $members,
            $previous['expiresAt'],
        );
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

    public function distinctCount(string $key): int
    {
        return count($this->sets[$key]['items'] ?? []);
    }

    /** @return list<string> */
    public function distinctKeys(): array
    {
        return array_keys($this->sets);
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

    private function assertMaxDistinct(int $maxDistinct): void
    {
        if ($maxDistinct < 1) {
            throw new RateLimiterException('Correlation maximum distinct count must be positive.');
        }
    }

    /**
     * @param array{items: array<string, bool>, expiresAt: int} $state
     */
    private function snapshot(
        array $state,
        bool $accepted,
        bool $added,
        ?int $expiresAt = null,
    ): BoundedDistinctSnapshotDTO {
        $members = array_keys($state['items']);

        return new BoundedDistinctSnapshotDTO(
            count($members),
            $accepted,
            $added,
            $members,
            $expiresAt ?? $state['expiresAt'],
        );
    }

    /**
     * @param array{items: array<string, bool>, expiresAt: int} $state
     */
    private function assertWithinCapacity(array $state, int $maxDistinct, string $label): void
    {
        if (count($state['items']) > $maxDistinct) {
            throw new RateLimiterException("{$label} exceeds its configured capacity.");
        }
    }
}
