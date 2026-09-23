<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

/**
 * Aggregate storage contract for adapters that provide every advanced capability.
 *
 * The individual capability interfaces remain authoritative; this interface only
 * composes them for a single adapter object and adds no storage operations.
 */
interface FullCapabilityStoreInterface extends
    BudgetSeedStoreInterface,
    BoundedCorrelationSnapshotRotationStoreInterface,
    CircuitBreakerProbeStoreInterface,
    HardBlockCycleStoreInterface {}
