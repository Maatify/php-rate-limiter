<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Tracks repeated soft blocks and escalates persistent account-level patterns.
 */
class AntiEquilibriumGate
{
    private const WINDOW = 21600; // 6h
    private const THRESHOLD = 3;

    /**
     * @param CorrelationStoreInterface $store Store for the six-hour watch flag.
     * @param ?ClockInterface $clock The runtime's clock; with it, a store with the
     *        caller-time capability stores the request's time (#153 phase 2).
     */
    public function __construct(
        private readonly CorrelationStoreInterface $store,
        private readonly ?ClockInterface $clock = null,
    ) {}

    /**
     * Record one soft block for the current package-derived state key.
     *
     * The caller owns policy, environment, version, and subject derivation.
     * This service only records the opaque key supplied by the pipeline.
     */
    public function recordSoftBlock(string $currentStateKey): void
    {
        if ($this->clock === null) {
            $this->store->incrementWatchFlag($currentStateKey, self::WINDOW);

            return;
        }
        CallerTimeStore::incrementWatchFlag($this->store, $currentStateKey, self::WINDOW, $this->clock->now());
    }

    /**
     * Return whether current and active previous state reach the threshold.
     *
     * The previous state is read-only and is not read twice when it is absent
     * or resolves to the same opaque key as the current generation.
     */
    public function shouldEscalate(string $currentStateKey, ?string $previousStateKey = null): bool
    {
        $count = $this->store->getWatchFlag($currentStateKey);
        if ($previousStateKey !== null && $previousStateKey !== $currentStateKey) {
            $count += $this->store->getWatchFlag($previousStateKey);
        }

        return $count >= self::THRESHOLD;
    }
}
