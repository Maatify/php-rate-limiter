# DEC-014 — Simple Fixed-Window Reset-Boundary Representability

## Decision ID

`DEC-014`

## Status

`ACTIVE`

## Date

2026-09-28

## Decision Authority

Owner-authorized material contract decision for Gate 10 remediation under PR #74.

## Scope / Concern

Integer representability of the effective reset boundary for simple fixed-window enforcement and Operational Read.

## Context

A positive interval can overflow when added to the effective epoch start, causing PHP float promotion and a typed-contract failure after enforcement storage has already mutated.

## Decision

A simple fixed-window interval is usable only when its effective fixed reset boundary is exactly representable as a PHP integer. The package rejects an unrepresentable boundary through the existing `RateLimiterException` contract before any enforcement storage mutation. The check uses overflow-safe comparison against `PHP_INT_MAX - intervalSeconds`; it does not use an arbitrary maximum, float timestamp, string timestamp, or saturation.

`resetAt` remains an integer Unix timestamp, current fixed-window semantics and DTO shapes remain unchanged, and exactly representable boundaries remain valid. Configuration/contract rejection remains an exception rather than a quota decision or `FAIL_CLOSED`. Backend/runtime failures retain existing `FAIL_CLOSED` behavior. Operational Read applies the same checked arithmetic, raises the package exception for an unrepresentable persisted boundary, and remains read-only.

This decision complements DEC-013: DEC-014 governs reset-boundary integer representability, while DEC-013 continues to govern positive variable-cost consumption and weighted fixed-window state.

## Rationale

The effective epoch start is the source of truth for a fixed window, so a static arbitrary interval cap would reject valid configurations and would not solve persisted-boundary corruption. Checked subtraction preserves the public integer contract without changing weighted consumption or storage signatures.

## Consequences

- Direct `SimpleThrottlePolicyInterface` implementations receive the same package-level enforcement protection.
- An invalid boundary is rejected before enforcement increment/seed mutation.
- Operational Read never leaks a native `TypeError` from reset arithmetic.
- No storage interface, DTO, exception taxonomy, backend capability, or failure mode changes.

## Supersedes

None.

## Superseded By

None.

## Canonical Contract / Current Owner

`FixedWindowSimpleRateLimiter`, `SimpleRateLimitOperationalReader`, and `docs/SIMPLE_THROTTLING.md`, with weighted consumption remaining under DEC-013.
