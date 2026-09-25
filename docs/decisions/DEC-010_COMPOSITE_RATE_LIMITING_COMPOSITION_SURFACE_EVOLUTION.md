# DEC-010 — Composite Rate-Limiting Composition Surface Evolution

## Decision ID

`DEC-010`

## Status

`ACTIVE`

## Date

2026-09-25

## Decision Authority

Owner-approved WU-S4-03C decision under PR #60.

## Scope / Concern

Evolution of the package-owned default composition surface so score-based protection and simple fixed-window throttling remain discoverable through one coherent runtime graph.

## Context

DEC-004 established `RateLimiterBuilder` as the single package-owned default composition owner and intentionally limited its targeted overrides to the then-existing score-based policy model.

DEC-009 adds a new package-owned simple throttling semantic family. Adding a second builder, factory, facade, or independent host composition path would violate the architectural purpose of DEC-004 and would create two competing default graphs.

The existing runtime contracts must remain source-compatible for current consumers while the builder becomes capable of returning a runtime that exposes both semantic families.

## Decision

`RateLimiterBuilder` remains the single package-owned default composition owner.

The builder evolves additively to register simple fixed-window policies alongside existing score-based block policies.

The composition model is:

```text
RateLimiterBuilder
    |
    +-- score-based BlockPolicyInterface registry
    |
    +-- simple fixed-window policy registry
    |
    +-- one build()
            |
            +-- one composite runtime object
                    |
                    +-- existing RateLimiterRuntimeInterface behavior
                    +-- SimpleRateLimiterInterface behavior
```

The package introduces one composite runtime interface that extends the existing runtime contract and the new simple-throttling runtime contract.

Because `RateLimiterBuilder` is final, `build()` may narrow its declared return type to that composite runtime subtype while remaining assignable to existing `RateLimiterRuntimeInterface` consumer code.

No second default builder or factory is introduced.

## Preserved DEC-004 Invariants

The following DEC-004 choices remain binding under this superseding decision:

- `Maatify\RateLimiter\Builder\RateLimiterBuilder` remains the package-owned composition owner.
- `Maatify\RateLimiter\Config\RateLimiterConfig` remains the configuration owner for active/previous outer-key and fingerprint secrets plus environment scope.
- The package does not generate secrets or read Host environment variables.
- The Host continues to supply the required storage and failure-signal boundaries explicitly.
- The default graph continues to use one coordinated UTC clock for package-owned clock-dependent services.
- Current/previous key generations remain coordinated; the implementation must not probe arbitrary Cartesian secret combinations.
- The default score-policy registry continues to contain exactly `login_protection`, `otp_protection`, and `api_heavy_protection`.
- `withPolicy()` retains same-name replacement and new-name append semantics for `BlockPolicyInterface`.
- Existing targeted overrides `withClock()`, `withDeviceIdentityResolver()`, and `withPolicy()` remain source-compatible.
- Existing low-level constructors remain the Advanced Path.
- The builder must continue to fail fast when a configured policy requires a storage/runtime capability that is absent.

## Simple-Policy Registration

Simple throttling has a separate typed registry because its semantic contract is not `BlockPolicyInterface`.

The builder exposes one additive targeted override for that registry:

```text
withSimpleThrottlePolicy(SimpleThrottlePolicyInterface $policy): self
```

A matching simple-policy name replaces the existing simple policy of that name; a new name is appended.

There are no arbitrary package-default simple policies because limit and interval are use-case-specific. Consumers opt in by registering an explicit simple policy.

A package-owned immutable fixed-window policy implementation may be provided as the normal construction convenience, but its policy name, limit, and interval remain explicit caller inputs.

## Composite Runtime Contract

The new composite runtime interface extends:

- the existing `RateLimiterRuntimeInterface`;
- the new `SimpleRateLimiterInterface`.

Existing score-based methods retain their current semantics.

Simple throttling is exposed through its dedicated runtime method and DTOs. The Host does not call storage primitives directly and does not construct a parallel simple-throttle engine beside the builder output.

Internal delegation between package-owned engine objects is allowed as an implementation detail as long as the public result is one coherent runtime object from one `build()`.

## Compatibility

The implementation must preserve existing consumer code that:

- assigns `RateLimiterBuilder::build()` to `RateLimiterRuntimeInterface`;
- calls `limit()`;
- calls `claimPostPunishmentReentry()`;
- uses the three official score-policy names;
- uses existing builder construction paths.

No existing consumer is required to register a simple policy.

The addition of simple throttling must therefore be opt-in and additive.

## Rationale

The package now owns two distinct rate-limiting semantic families, but consumers should not need two competing composition roots.

One builder and one composite runtime preserve discoverability, central validation, storage capability checks, key configuration, clock configuration, and future package evolution without collapsing the two semantic models into one misleading policy interface.

## Consequences

- DEC-004 is superseded by this decision.
- `RateLimiterBuilder` remains the default composition owner.
- The builder gains one simple-policy registration method.
- `build()` returns a composite runtime subtype that remains assignable to the existing runtime interface.
- Simple throttling remains opt-in; the default score-policy registry is unchanged.
- Runtime/tests/docs work for this composition evolution belongs to WU-S4-03C implementation and must land before the simple-throttling capability is claimed as available.

## Supersedes

DEC-004.

## Superseded By

None.

## Canonical Contract / Current Owner

This decision is the active composition authority for WU-S4-03C. The existing `RateLimiterBuilder` implementation remains the compatibility baseline until the corresponding implementation child lands.
