<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO\Store;

final readonly class CircuitBreakerStateDTO implements \JsonSerializable
{
    /**
     * @param string $status
     * @param array<int, int> $failures
     * @param int $lastFailure
     * @param int $openSince
     * @param int $lastSuccess
     * @param array<int, int> $reEntries
     * @param int $failClosedUntil
     */
    public function __construct(
        public string $status,
        public array $failures,
        public int $lastFailure,
        public int $openSince,
        public int $lastSuccess,
        public array $reEntries,
        public int $failClosedUntil = 0
    ) {}

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
