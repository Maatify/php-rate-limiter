<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Repository\CorrelationStoreInterface;

/**
 * Tracks repeated soft blocks and escalates persistent account-level patterns.
 */
class AntiEquilibriumGate
{
    private const WINDOW = 21600; // 6h
    private const THRESHOLD = 3;

    /**
     * @param CorrelationStoreInterface $store Store for the six-hour watch flag.
     */
    public function __construct(
        private readonly CorrelationStoreInterface $store,
    ) {}

    /**
     * Record one soft block for an account in the anti-equilibrium window.
     */
    public function recordSoftBlock(string $accountId): void
    {
        $key = "gate:soft:{$accountId}";
        $this->store->incrementWatchFlag($key, self::WINDOW);
    }

    /**
     * Return whether the account has reached the escalation threshold.
     */
    public function shouldEscalate(string $accountId): bool
    {
        $key = "gate:soft:{$accountId}";
        return $this->store->getWatchFlag($key) >= self::THRESHOLD;
    }
}
