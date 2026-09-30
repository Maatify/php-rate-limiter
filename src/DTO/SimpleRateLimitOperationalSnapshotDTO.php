<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Read-only operational view of one simple fixed-window policy/subject pair.
 */
final readonly class SimpleRateLimitOperationalSnapshotDTO implements \JsonSerializable
{
    /**
     * @param string $policyName Simple throttle policy represented by the snapshot.
     * @param int $observedAt Unix timestamp at which the snapshot was read.
     * @param bool $backendHealthy Whether the backing store reported healthy.
     * @param int $limit Policy-defined quota/consumption-unit limit per fixed window.
     * @param int $intervalSeconds Policy-defined fixed-window duration in seconds.
     * @param int $count Persisted quota/consumption units in the effective active fixed window; `0` if no active window exists.
     * @param int $remaining Remaining quota/consumption units, `max(0, limit - count)`.
     * @param ?int $epochStart Active epoch start, otherwise `null`.
     * @param ?int $resetAt `epochStart + intervalSeconds`, otherwise `null`.
     * @param bool $fromPreviousGeneration True only when Current has no active state and Previous supplies the effective state.
     */
    public function __construct(
        public string $policyName,
        public int $observedAt,
        public bool $backendHealthy,
        public int $limit,
        public int $intervalSeconds,
        public int $count,
        public int $remaining,
        public ?int $epochStart,
        public ?int $resetAt,
        public bool $fromPreviousGeneration,
    ) {}

    /**
     * Return the complete operational snapshot in serialized form.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'policyName' => $this->policyName,
            'observedAt' => $this->observedAt,
            'backendHealthy' => $this->backendHealthy,
            'limit' => $this->limit,
            'intervalSeconds' => $this->intervalSeconds,
            'count' => $this->count,
            'remaining' => $this->remaining,
            'epochStart' => $this->epochStart,
            'resetAt' => $this->resetAt,
            'fromPreviousGeneration' => $this->fromPreviousGeneration,
        ];
    }
}
