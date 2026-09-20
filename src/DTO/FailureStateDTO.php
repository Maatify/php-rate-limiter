<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

final readonly class FailureStateDTO implements \JsonSerializable
{
    public const STATE_CLOSED = 'CLOSED';
    public const STATE_OPEN = 'OPEN';
    public const STATE_HALF_OPEN = 'HALF_OPEN';

    public function __construct(
        public string $state,
        public int $failureCount,
        public int $lastFailureTimestamp,
        public bool $isDegraded,
    ) {}

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
