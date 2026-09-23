# DEC-005 — Full-Capability Storage Boundary

## Decision ID

`DEC-005`

## Status

`SUPERSEDED`

## Date

2026-09-23

## Decision Authority

Lead-approved WU-S4-02A contract under PR #36.

## Scope / Concern

The core aggregate storage boundary for a single adapter that provides every
currently locked storage capability.

## Decision

`FullCapabilityStoreInterface` is an additive aggregate contract with exactly
these inherited capabilities:

```php
interface FullCapabilityStoreInterface extends
    BudgetSeedStoreInterface,
    BoundedCorrelationSnapshotRotationStoreInterface,
    CircuitBreakerProbeStoreInterface,
    HardBlockCycleStoreInterface
{
}
```

The aggregate declares no methods of its own and does not redeclare inherited
methods. The individual capability interfaces remain authoritative contracts
and remain the Advanced Path for consumers that provide separate boundaries.

`RateLimiterBuilder::fromFullCapabilityStore()` is a composition convenience
only. It passes the same aggregate object to the rate-limit, correlation, and
circuit-breaker storage boundaries and keeps `FailureSignalEmitterInterface` as
an independent dependency. It does not introduce a wrapper, proxy, service
container, duplicate runtime graph, or alternate storage abstraction.

The core remains backend-agnostic. This work unit adds no Redis, PDO, Lua,
`ext-redis`, migration, or production adapter implementation. Any official
production adapter supplied later must prove its backend-specific atomicity,
fixed-TTL behavior, rotation read-only semantics, budget seeding, probe lease,
and hard-block cycle guarantees against these contracts.

## Rationale

The aggregate gives one production adapter a typed composition entrypoint while
preserving source compatibility for existing multi-store construction and the
individual capability contracts. Runtime ownership and capability semantics do
not move into the aggregate interface.

## Consequences

- A full-capability adapter can be passed once through the named Builder constructor.
- Existing `RateLimiterBuilder::__construct()` callers remain source-compatible.
- A store may still implement only the individual contracts required by its path.
- Test support may provide a delegating aggregate fixture; it is not a production adapter.
- Redis companion work is explicitly deferred to WU-S4-02B.

## Supersedes

None.

## Superseded By

DEC-006

## Canonical Contract / Current Owner

`src/Repository/FullCapabilityStoreInterface.php`,
`src/Builder/RateLimiterBuilder.php`, and this decision record.
