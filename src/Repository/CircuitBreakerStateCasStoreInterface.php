<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;

/**
 * Additive circuit-breaker capability: the state write fenced by the version it was computed from (#185, F-185-18,
 * DEC-031). `CircuitBreaker::reportFailure()` reads the state, appends the failure and may trip the circuit; two
 * concurrent failures on the same read would each save their own one-failure state. With this capability the save is
 * applied only while the stored state is still the one that was read.
 *
 * `build()` requires it of the circuit-breaker store.
 */
interface CircuitBreakerStateCasStoreInterface extends CircuitBreakerStoreInterface
{
    /**
     * `load()` together with an opaque version of what is stored (`''` when nothing is). The version changes whenever
     * the stored state changes.
     *
     * @return array{0: ?CircuitBreakerStateDTO, 1: string}
     */
    public function loadWithVersion(string $policyName): array;

    /**
     * Atomically saves `$state` only while the stored version still equals `$expectedVersion`. A mismatch is an ordinary
     * outcome (`false`) and writes nothing.
     */
    public function saveIfVersion(string $policyName, string $expectedVersion, CircuitBreakerStateDTO $state): bool;
}
