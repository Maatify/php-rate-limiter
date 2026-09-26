# DEC-009 — Generic / Simple Throttling Semantic Contract

## Decision ID

`DEC-009`

## Status

`ACTIVE`

## Date

2026-09-25

## Decision Authority

Owner-approved WU-S4-03C decision under PR #60.

## Scope / Concern

First-class generic/simple throttling semantics distinct from the package's score-based security-policy model.

## Context

The current package is optimized for multi-signal security enforcement:

- K1-K5 scoped scores;
- score deltas;
- lazy decay;
- progressive penalty levels;
- correlation and abuse detection;
- budgets;
- circuit-breaker and failure-mode behavior.

The package does not currently expose a first-class public contract for the common reusable requirement:

```text
allow up to N events in an interval
then deny until that interval ends
```

The current score model is not semantically equivalent. `DecayCalculator` uses package-owned decay intervals and `PenaltyLadder` uses fixed progressive punishment durations. Mapping a simple quota onto score thresholds would therefore create misleading behavior and retry semantics.

Historical `Maatify/rate-limiter` evidence includes action-level `limit / interval / banTime` configuration. Mature external implementations also treat general window-based throttling as a distinct rate-limiting concern. Those are comparative inputs only; this decision is based on the current package's reusable coverage gap.

## Decision

The package owns a first-class generic/simple throttling capability.

Version 1 of that capability is intentionally narrow:

```text
FIXED WINDOW
cost = 1 per consume
one policy-defined limit
one policy-defined interval
one caller-supplied subject
atomic consume
deterministic retry boundary
```

It is not implemented by translating the request into `ScoreThresholdsDTO`, score decay, or the progressive penalty ladder.

### Fixed-window semantics

For one `policy + subject` pair:

1. the first consume starts the fixed window;
2. consumes `1..limit` return allowed;
3. consume `limit + 1` and later consumes inside the same window return denied;
4. denied consumes do not renew or extend the window;
5. the next window begins only after the current window expires and a new consume occurs;
6. `retryAfter` is the positive remaining duration until the fixed window ends when denied;
7. `resetAt` is the stable end instant of the current fixed window;
8. `remaining` is clamped at zero once the limit is exhausted.

Every call is an atomic consume. Version 1 does not expose a separate non-mutating `check()` followed by a later `consume()`, because that split would create a time-of-check/time-of-use race.

### Policy contract

Simple throttling uses a dedicated typed policy contract separate from `BlockPolicyInterface`.

The policy defines at least:

- stable policy name;
- positive integer limit;
- positive integer interval in seconds.

Version 1 uses unit cost only. Variable cost, token bucket, sliding window, leaky bucket, burst configuration, and weighted consumption are outside this decision.

### Identity and key protection

The Host supplies one non-empty subject identifier. The package owns physical key derivation.

The raw subject must not be persisted directly by the package-owned backend path. The derived key includes the environment scope and simple-policy identity and uses the package's configured key secret.

When a previous key secret is configured, fixed-window continuity across key rotation must follow the package's existing budget-epoch migration contract. A valid previous-generation epoch must not be silently reset.

The existing `BudgetSeedStoreInterface` capability is therefore the required migration boundary when previous-generation state must be carried into the active generation.

### Storage semantics

Version 1 reuses the existing atomic budget-epoch persistence primitive:

- `RateLimitStoreInterface::getBudget()`;
- `RateLimitStoreInterface::incrementBudget()`;
- `BudgetSeedStoreInterface::incrementBudgetWithSeed()` when rotation migration is required.

No new backend family is introduced by this decision.

The simple-throttling implementation must use a distinct package-owned key namespace so simple-window state cannot collide with authentication budgets or score state.

### Result contract

Simple throttling uses a dedicated typed result contract rather than `RateLimitResultDTO`.

The result exposes at least:

- `allowed`;
- `limit`;
- `remaining`;
- `retryAfter`;
- `resetAt`;
- explicit failure-state metadata required by the final implementation contract.

It does not invent a block level or score metadata when those concepts do not exist.

### Failure semantics

Version 1 simple throttling is `FAIL_CLOSED` only.

This keeps the capability bounded and prevents WU-S4-03C from inventing a second outage/fallback model that conflicts with the package-wide failure contract.

Availability-first `FAIL_OPEN` simple throttling is not part of this decision because the current package requires bounded local guardrails during shared-backend failure. Defining a generic subject-aware local fallback model is broader integration/failure architecture and belongs to later work, including WU-S4-03D where applicable.

The implementation must fail explicitly rather than silently resetting or bypassing the window when rotation migration or required persistence capability is unavailable.

## Coexistence with Score-Based Policies

Simple throttling and score-based security policies are separate semantic tools.

A Host may use both when they protect different concerns, but the package must not automatically translate, merge, or escalate state between the two models.

Simple throttling does not create K1-K5 score state, progressive punishment levels, correlation observations, authentication budgets, or DEC-007 lifecycle state.

## Construction Boundary

The capability must be reachable through the package-owned default composition surface without creating a second competing builder, factory, or Host-owned parallel engine.

The composition evolution required to add this surface is governed by DEC-010.

## Rationale

A fixed-window quota is common reusable rate-limiting behavior and is materially different from the package's score/decay/punishment model.

The existing budget-epoch primitives already provide the atomic persistence semantics needed for a bounded first version, including a defined migration capability for key rotation. Reusing those primitives keeps WU-S4-03C focused on package semantics rather than backend breadth.

## Consequences

- Generic/simple throttling moves from baseline classification C to target classification B.
- Version 1 is fixed-window only.
- There is no `check()`/later-`consume()` split.
- There is no variable cost.
- There is no `FAIL_OPEN` mode in version 1.
- No new backend family is required.
- Rotation continuity cannot silently reset an active window.
- The simple result contract stays semantically separate from score-policy results.

## Supersedes

None.

## Superseded By

None.

## Canonical Contract / Current Owner

The current implemented owners are `docs/SIMPLE_THROTTLING.md`, `SimpleThrottlePolicyInterface`, `SimpleRateLimiterInterface`, `FixedWindowSimpleRateLimiter`, and `SimpleRateLimitResultDTO`.
