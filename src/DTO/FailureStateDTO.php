<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Exposes the summarized circuit-breaker state used for failure-mode selection.
 */
final readonly class FailureStateDTO implements \JsonSerializable
{
    public const STATE_CLOSED = 'CLOSED';
    public const STATE_OPEN = 'OPEN';
    public const STATE_HALF_OPEN = 'HALF_OPEN';

    /**
     * @param string $state Circuit state, represented by the STATE_* constants.
     * @param int $failureCount Number of retained failures in the trip window.
     * @param int $lastFailureTimestamp Unix timestamp of the latest failure.
     * @param bool $isDegraded Whether the policy is currently degraded.
     */
    public function __construct(
        public string $state,
        public int $failureCount,
        public int $lastFailureTimestamp,
        public bool $isDegraded,
    ) {}

    /**
     * Return the summarized failure state in serialized form.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'state' => $this->state,
            'failureCount' => $this->failureCount,
            'lastFailureTimestamp' => $this->lastFailureTimestamp,
            'isDegraded' => $this->isDegraded,
        ];
    }
}
