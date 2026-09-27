# DEC-013 — Variable-Cost Simple Fixed-Window Consumption

## Decision ID

`DEC-013`

## Status

`ACTIVE`

## Date

2026-09-27

## Decision Authority

Owner-approved WU-S4-05 decision.

## Scope / Concern

The public simple fixed-window consumption contract and its persisted weighted-cost semantics.

## Context

DEC-009 established the simple throttling capability with unit-cost consumes. WU-S4-05 evolves that public operation while preserving the fixed-window model, existing storage boundary, rotation continuity, failure behavior, and one-builder/one-composite composition surface.

## Decision

`SimpleRateLimiterInterface::consume()` remains the sole public enforcement operation and becomes:

```php
consume(string $policyName, string $subject, int $cost = 1): SimpleRateLimitResultDTO
```

`$cost` must be a positive integer and is validated before any storage read or mutation. Omitting it is exactly equivalent to passing `1`; existing two-argument callers remain usable.

Each consume atomically increments the existing fixed-window state by the supplied cost. Cost is not policy configuration and is not part of storage identity or key derivation. The key remains based on policy name, limit, interval, environment scope, subject, and secret generation. Different costs therefore accumulate in the same state.

The decision is derived from the resulting persisted count: `allowed` is true when count is less than or equal to the policy limit, and `remaining` is `max(0, limit - count)`. A weighted consume that crosses the limit is denied but its full cost remains persisted; there is no rollback, cap, or window extension. Rotation migration seeds Current with `previous.count + cost`, preserves Previous unchanged, and preserves Previous's epoch start as the fixed boundary.

The operation does not add `check()`, `peek()`, reservation, token bucket, sliding window, burst/refill, compound quota, policy type, Builder, DTO field, backend, or fallback mode. `FAIL_CLOSED` behavior, privacy/key derivation, operational read, and the existing one-builder/one-composite runtime remain unchanged.

Any third-party implementation of `SimpleRateLimiterInterface` or an extending interface must update its method signature to accept the optional `int $cost = 1` parameter. This is caller compatibility through the default, not complete implementer source compatibility.

## Rationale

Variable caller-supplied cost covers weighted events while retaining the package's existing atomic fixed-window primitive and bounded semantics. Keeping cost outside identity prevents separate state for each weight and preserves rotation and operational-read behavior.

## Consequences

- Simple throttling's current public contract supports positive weighted consumes with default cost `1`.
- DEC-009 remains historical and is superseded; its unit-cost decision is not rewritten.
- Existing two-argument callers remain compatible.
- Implementers must accept the evolved optional parameter.
- No Redis runtime or storage contract change is required.

## Supersedes

`DEC-009`

## Superseded By

None.

## Canonical Contract / Current Owner

`docs/SIMPLE_THROTTLING.md`, `SimpleRateLimiterInterface`, `FixedWindowSimpleRateLimiter`, `CompositeRateLimiterRuntime`, and `SimpleRateLimitResultDTO`.
