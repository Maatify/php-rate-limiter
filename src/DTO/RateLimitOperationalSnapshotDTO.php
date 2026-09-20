<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Read-only operational view of one policy evaluation context.
 */
final readonly class RateLimitOperationalSnapshotDTO implements \JsonSerializable
{
    /**
     * @param string $policyName Policy represented by the snapshot.
     * @param int $observedAt Unix timestamp at which the snapshot was read.
     * @param bool $backendHealthy Whether the backing store reported healthy.
     * @param RateLimitOperationalScopesDTO $scopes Per-scope score and block state.
     * @param ?RateLimitOperationalBudgetDTO $budget Account budget state, when applicable.
     * @param ?CircuitBreakerStateDTO $circuitBreaker Persisted circuit-breaker state, when present.
     */
    public function __construct(
        public string $policyName,
        public int $observedAt,
        public bool $backendHealthy,
        public RateLimitOperationalScopesDTO $scopes,
        public ?RateLimitOperationalBudgetDTO $budget,
        public ?CircuitBreakerStateDTO $circuitBreaker,
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
            'scopes' => $this->scopes,
            'budget' => $this->budget,
            'circuitBreaker' => $this->circuitBreaker,
        ];
    }
}
