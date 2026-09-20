<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

final readonly class RateLimitOperationalSnapshotDTO implements \JsonSerializable
{
    public function __construct(
        public string $policyName,
        public int $observedAt,
        public bool $backendHealthy,
        public RateLimitOperationalScopesDTO $scopes,
        public ?RateLimitOperationalBudgetDTO $budget,
        public ?CircuitBreakerStateDTO $circuitBreaker,
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'policyName' => $this->policyName,
            'observedAt' => $this->observedAt,
            'backendHealthy' => $this->backendHealthy,
            'scopes' => $this->scopes,
            'budget' => $this->budget,
            'circuitBreaker' => $this->circuitBreaker,
        ];
    }
}
