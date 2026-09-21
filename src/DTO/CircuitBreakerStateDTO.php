<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Stores the complete circuit-breaker state required for persistence and recovery.
 */
final readonly class CircuitBreakerStateDTO implements \JsonSerializable
{
    /**
     * @param string $status Current circuit state, normally CLOSED or OPEN.
     * @param array<int, int> $failures Failure timestamps retained for trip evaluation.
     * @param int $lastFailure Unix timestamp of the latest observed failure.
     * @param int $openSince Unix timestamp at which the circuit opened.
     * @param int $lastSuccess Unix timestamp of the latest successful operation.
     * @param array<int, int> $reEntries Circuit re-entry timestamps used by the guard.
     * @param int $failClosedUntil Unix timestamp until which re-entry is fail-closed.
     */
    public function __construct(
        public string $status,
        public array $failures,
        public int $lastFailure,
        public int $openSince,
        public int $lastSuccess,
        public array $reEntries,
        public int $failClosedUntil = 0,
    ) {}

    /**
     * Return the persisted circuit-breaker state as a stable field map.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'status' => $this->status,
            'failures' => $this->failures,
            'lastFailure' => $this->lastFailure,
            'openSince' => $this->openSince,
            'lastSuccess' => $this->lastSuccess,
            'reEntries' => $this->reEntries,
            'failClosedUntil' => $this->failClosedUntil,
        ];
    }
}
