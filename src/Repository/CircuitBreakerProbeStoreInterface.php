<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

/**
 * Additive circuit-breaker capability for atomic recovery-probe leasing.
 */
interface CircuitBreakerProbeStoreInterface extends CircuitBreakerStoreInterface
{
    /**
     * Acquire the per-policy recovery-probe lease when it is absent or expired.
     *
     * Implementations MUST perform the expiry check and lease write atomically.
     * An expired lease is eligible at the exact expiry timestamp.
     */
    public function acquireProbeLease(
        string $policyName,
        int $now,
        int $leaseSeconds,
    ): bool;
}
