<?php

declare(strict_types=1);

namespace ConsumerVerification;

use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotRotationStoreInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

final class InMemoryCorrelationStore implements BoundedCorrelationSnapshotRotationStoreInterface
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

    public function addDistinctBounded(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO {
        $this->assertTtl($ttlSeconds);
        $this->assertMaxDistinct($maxDistinct);
        $this->operations++;
        $now = $this->clock->now()->getTimestamp();
        $set = $this->prepareSet($this->distinctSets[$key] ?? null, $now, $ttlSeconds);
        $this->assertWithinCapacity($set, $maxDistinct);

        if (isset($set['items'][$item])) {
            return new BoundedDistinctResultDTO(count($set['items']), true);
        }

        if (count($set['items']) >= $maxDistinct) {
            return new BoundedDistinctResultDTO(count($set['items']), false);
        }

        $set['items'][$item] = true;
        $this->distinctSets[$key] = $set;

        return new BoundedDistinctResultDTO(count($set['items']), true);
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
        $set = $this->prepareSet($this->distinctSets[$key] ?? null, $now, $ttlSeconds);
        $this->assertWithinCapacity($set, $maxDistinct);

        if (isset($set['items'][$item])) {
            return $this->snapshot($set, true, false);
        }

        if (count($set['items']) >= $maxDistinct) {
            return $this->snapshot($set, false, false);
        }

        $set['items'][$item] = true;
        $this->distinctSets[$key] = $set;

        return $this->snapshot($set, true, true);
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
        $this->operations++;

        $this->assertWithinCapacity($previous, $maxDistinct);
        if ($currentKey === $previousKey) {
            $current = $this->prepareSet($this->distinctSets[$currentKey] ?? null, $now, $ttlSeconds);
            $this->assertWithinCapacity($current, $maxDistinct);
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
                $this->distinctSets[$currentKey] = $current;
            }

            return new BoundedDistinctResultDTO(count($current['items']), true);
        }

        $current = $this->prepareSet($this->distinctSets[$currentKey] ?? null, $now, $ttlSeconds);
        $bridge = $this->prepareBridgeSet(
            $this->distinctSets[$bridgeKey] ?? null,
            $now,
            $ttlSeconds,
            $previous['expiresAt'],
        );
        $this->assertWithinCapacity($current, $maxDistinct);
        $this->assertWithinCapacity($bridge, $maxDistinct);
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
            if (count($current['items']) < $maxDistinct) {
                $current['items'][$currentMember] = true;
                $this->distinctSets[$currentKey] = $current;
            }

            return new BoundedDistinctResultDTO($effectiveCount, true);
        }
        if ($effectiveCount >= $maxDistinct) {
            return new BoundedDistinctResultDTO($effectiveCount, false);
        }

        $current['items'][$currentMember] = true;
        $bridge['items'][$currentMember] = true;
        $this->distinctSets[$currentKey] = $current;
        $this->distinctSets[$bridgeKey] = $bridge;

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
        $this->assertWithinCapacity($previous, $maxDistinct);

        if ($currentKey === $previousKey) {
            $current = $this->prepareSet($this->distinctSets[$currentKey] ?? null, $now, $ttlSeconds);
            $this->assertWithinCapacity($current, $maxDistinct);
            $known = isset($current['items'][$currentMember]) || isset($current['items'][$previousMember]);

            if (! $known && count($current['items']) < $maxDistinct) {
                $current['items'][$currentMember] = true;
                $this->distinctSets[$currentKey] = $current;

                return $this->snapshot($current, true, true, $previous['expiresAt']);
            }

            return $this->snapshot($current, $known, false, $previous['expiresAt']);
        }

        $current = $this->prepareSet($this->distinctSets[$currentKey] ?? null, $now, $ttlSeconds);
        $bridge = $this->prepareBridgeSet(
            $this->distinctSets[$bridgeKey] ?? null,
            $now,
            $ttlSeconds,
            $previous['expiresAt'],
        );
        $this->assertWithinCapacity($current, $maxDistinct);
        $this->assertWithinCapacity($bridge, $maxDistinct);
        if (array_intersect_key($previous['items'], $bridge['items']) !== []) {
            throw new RateLimiterException('Bridge bounded distinct state overlaps previous-generation members.');
        }

        $members = array_merge(array_keys($previous['items']), array_keys($bridge['items']));
        if (count($members) > $maxDistinct) {
            throw new RateLimiterException('Bounded distinct rotation state exceeds its logical capacity.');
        }
        if (isset($bridge['items'][$currentMember]) || isset($previous['items'][$previousMember])) {
            return new BoundedDistinctSnapshotDTO(count($members), true, false, $members, $previous['expiresAt']);
        }
        if (count($members) >= $maxDistinct) {
            return new BoundedDistinctSnapshotDTO(count($members), false, false, $members, $previous['expiresAt']);
        }

        $current['items'][$currentMember] = true;
        $bridge['items'][$currentMember] = true;
        $this->distinctSets[$currentKey] = $current;
        $this->distinctSets[$bridgeKey] = $bridge;
        $members[] = $currentMember;

        return new BoundedDistinctSnapshotDTO(count($members), true, true, $members, $previous['expiresAt']);
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

    private function assertMaxDistinct(int $maxDistinct): void
    {
        if ($maxDistinct < 1) {
            throw new RateLimiterException('Correlation maximum distinct count must be positive.');
        }
    }

    /**
     * @param array{items: array<string, true>, expiresAt: int} $set
     */
    private function snapshot(
        array $set,
        bool $accepted,
        bool $added,
        ?int $expiresAt = null,
    ): BoundedDistinctSnapshotDTO {
        $members = array_keys($set['items']);

        return new BoundedDistinctSnapshotDTO(
            count($members),
            $accepted,
            $added,
            $members,
            $expiresAt ?? $set['expiresAt'],
        );
    }

    /** @param array{items: array<string, true>, expiresAt: int} $set */
    private function assertWithinCapacity(array $set, int $maxDistinct): void
    {
        if (count($set['items']) > $maxDistinct) {
            throw new RateLimiterException('Bounded correlation state exceeds its configured capacity.');
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
        if ($set !== null) {
            assert($set['expiresAt'] !== null);
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
        if ($set !== null) {
            assert($set['expiresAt'] !== null);
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
        if ($flag !== null) {
            assert($flag['expiresAt'] !== null);
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
