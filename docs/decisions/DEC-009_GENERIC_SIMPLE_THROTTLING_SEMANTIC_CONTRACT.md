# DEC-009 — Generic / Simple Throttling Semantic Contract

**Decision ID:** `DEC-009`
**Status:** `PROPOSED`
**Date:** `2026-09-25`
**Decision authority:** Pending WU-S4-03C Lead/Owner decision
**Scope / Concern:** First-class reusable generic/simple throttling semantics distinct from the package's score-based security policies
**Canonical contract / current owner:** Pending; no current public simple-throttling contract exists

> This record is a proposal only. It is not implementation authority while its status is `PROPOSED`.

## Context

The current package is optimized for multi-signal security enforcement:

- K1-K5 scoped scores;
- score deltas;
- lazy decay;
- progressive penalty levels;
- correlation and abuse detection;
- budgets;
- circuit-breaker and failure-mode behavior.

WU-S4-03C Fresh Lead baseline found no public contract that directly represents the common reusable requirement:

```text
allow up to N events in an interval
then return a deterministic retry boundary
```

Trying to approximate that behavior through `ScoreThresholdsDTO` is not semantically equivalent because the current score model has package-owned decay intervals and a fixed progressive penalty ladder.

Historical `Maatify/rate-limiter` evidence includes action-oriented `limit / interval / banTime` configuration, and mature external evidence such as Symfony RateLimiter treats general throttling as a first-class concern independent of login-specific protection. These are comparative inputs only and do not dictate this package's architecture.

## Proposed direction

The package should own a first-class generic/simple throttling capability **if** the final WU-S4-03C decision confirms that consumers otherwise need to build parallel rate-limit behavior for this common family.

The simple-throttling contract must remain semantically distinct from the existing score-based security-policy model.

It must not be implemented by disguising a fixed event quota as arbitrary score thresholds.

## Semantic questions that must be resolved

Before implementation, the accepted decision must define:

1. **Identity / scope** — what package-owned typed input identifies the throttled subject.
2. **Limit** — how the maximum event count is represented and validated.
3. **Cost** — whether every operation costs exactly one unit or whether variable cost is supported.
4. **Interval / window** — the exact window semantics and boundary behavior.
5. **Retry-After** — deterministic derivation and exact-boundary semantics.
6. **Mutation model** — the distinction between checking and consuming an event, if both exist.
7. **Failure mode** — what backend failure means for a generic limiter.
8. **Result contract** — whether existing `RateLimitResultDTO` remains semantically correct or a dedicated typed result is required.
9. **Coexistence** — how simple throttling coexists with existing score-based security policies without creating two conflicting enforcement sources for the same logical concern.
10. **Construction** — how the capability is discoverable through the package's public construction surface without expanding 03C into backend-breadth work owned by WU-S4-03D.

## Scope guard

WU-S4-03C may define and implement the package-owned semantic capability needed on the currently supported package path.

WU-S4-03C does **not** decide:

- PSR adapter breadth;
- additional backend families;
- Redis Cluster portability;
- operational mutation APIs;
- broad observability architecture.

Those remain WU-S4-03D concerns.

## Alternatives under review

### Alternative A — No first-class simple throttling

Declare the package intentionally limited to score-based security enforcement and require consumers to use another limiter for generic quotas.

This is acceptable only if WU-S4-03C can prove that doing so does not force consumers to create parallel behavior that this package is expected to own.

### Alternative B — First-class simple throttling capability

Add a package-owned semantic contract for a simple event quota with deterministic retry behavior, while keeping it distinct from the score-based policy model.

This is the leading proposal.

### Alternative C — Emulate simple throttling with score policies

Map event counts onto score deltas and thresholds.

This is not considered semantically safe unless the final analysis can prove exact equivalence, including interval and Retry-After behavior.

## Decision required before implementation

WU-S4-03C must resolve the semantic questions above and explicitly accept or reject a first-class simple-throttling capability.

No storage/API implementation may rely on this proposal while its status remains `PROPOSED`.
