# DEC-004 — Default Composition Surface

## Decision ID

`DEC-004`

## Status

`ACTIVE`

## Date

2026-09-23

## Decision Authority

Lead-approved WU-S4-01 contract under PR #36.

## Scope / Concern

Production default composition and configuration entrypoints for the standalone
rate-limiter package.

## Context

Consumers previously had to assemble the package-owned evaluation pipeline,
budget, correlation, decay, ephemeral, circuit-breaker, failure-mode, and
identity services manually before constructing `RateLimiterEngine`. That
assembly made the supported production path harder to discover while the
existing low-level constructors remain useful for advanced control.

## Decision

- `Maatify\RateLimiter\Builder\RateLimiterBuilder` is the package-owned
  composition owner. The construction responsibility is `Builder/`, not a
  competing Factory or Facade.
- `Maatify\RateLimiter\Config\RateLimiterConfig` is a `final readonly`
  configuration contract with explicit active and optional previous outer-key
  and fingerprint secrets plus a non-blank environment scope. Outer and
  fingerprint rotation inputs remain independently configurable.
- Configuration validation rejects empty or whitespace-only active, previous,
  and environment values. The package does not generate secrets, read
  environment variables, trim valid caller values, or serialize secrets.
- The builder requires `RateLimitStoreInterface`, `CorrelationStoreInterface`,
  `CircuitBreakerStoreInterface`, and `FailureSignalEmitterInterface` from the
  Host. It does not construct null/no-op production substitutes or a service
  container.
- The default graph uses one UTC `SystemClock` instance for every
  clock-dependent package-owned component. It composes the existing
  `EvaluationPipeline`, `BudgetTracker`, `AntiEquilibriumGate`,
  `DecayCalculator`, `EphemeralBucket`, `CircuitBreaker`,
  `FailureModeResolver`, `DeviceIdentityResolver`, and `RateLimiterEngine`.
- The default identity graph uses the active fingerprint hasher and, when
  configured, one previous fingerprint hasher. The runtime keeps one
  coordinated current/previous generation and never probes Cartesian
  combinations.
- The default policy registry contains exactly `LoginProtectionPolicy`,
  `OtpProtectionPolicy`, and `ApiHeavyProtectionPolicy`.
- `withPolicy()` uses `BlockPolicyInterface::getName()` as identity: a matching
  name replaces one policy, and a new name is added without removing unrelated
  defaults. The existing `RateLimiterEngine` remains the final policy
  validation and execution owner.
- The only targeted builder overrides are `withClock()`,
  `withDeviceIdentityResolver()`, and `withPolicy()`. The existing low-level
  constructors remain the Advanced Path.
- WU-S4-01 does not add Redis, PDO, a full-capability aggregate store, a Real
  Host migration, the replacement-style consumer harness, release/tag work, or
  any Stage 4 WU-S4-02/03/04 implementation.

## Rationale

Builder semantics express progressive construction with package defaults and
targeted overrides while keeping Host-owned storage and signal boundaries
explicit. Reusing the existing runtime services preserves capability
enforcement, failure semantics, and the coordinated two-generation key
contract without introducing a second policy registry or a hidden resolver.

## Consequences

Consumers can reach a supported production graph through one discoverable
public construction surface. Host integrations still implement the required
atomic storage and signal contracts, and advanced consumers can retain manual
construction through the existing low-level APIs. The policy registry is
centralized in the builder, while policy validation remains in the engine.

## Supersedes

None.

## Superseded By

None.

## Canonical Contract / Current Owner

`src/Builder/RateLimiterBuilder.php`, `src/Config/RateLimiterConfig.php`, and
the root `RATE_LIMITER_PACKAGE_REFERENCE.md`.
