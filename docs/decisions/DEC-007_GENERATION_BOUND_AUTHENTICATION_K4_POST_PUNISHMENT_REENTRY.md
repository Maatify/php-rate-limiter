# DEC-007 — Generation-Bound Authentication K4 Post-Punishment Re-entry

## Decision ID

`DEC-007`

## Status

`ACTIVE`

## Date

2026-09-24

## Decision Authority / Deciders

Lead-approved WU-S4-03B-01 contract under PR #58.

## Scope / Concern

Generation-bound K4 score mutation, punishment lifecycle evidence, and the
public post-punishment re-entry claim surface for Login and OTP policies.

## Context

Authentication K4 state must preserve score and TTL continuity across current
and previous key generations while preventing stale or replayed punishment
evidence from authorizing re-entry. The package therefore needs one atomic,
generation-fenced lifecycle contract shared by supported storage backends and
the public runtime.

## Decision

Login and OTP policies opt into package-owned generation-bound K4 lifecycle
semantics through `PostPunishmentReentryPolicyInterface`. A real K4 mutation
advances a monotonic generation. A K4 score-derived L2+ punishment may publish
positive evidence only atomically with the hard block and DEC-003 cycle/pause
accounting. Evidence is historical-only after the punishment is served and is
invalidated by the next real K4 mutation; `recordSuccess()` never resets it.

The package owns snapshot resolution, generation fencing, bounded atomic claim,
and Redis persistence. Hosts see only public metadata and the composite runtime
claim surface. API Heavy and non-opt-in policies remain on their existing score
and decay behavior.

## Rationale

Generation fencing makes each real K4 mutation observable as one monotonic
state transition. Atomic publication couples positive re-entry evidence to the
hard block and existing DEC-003 accounting, while the bounded claim marker
prevents replay without exposing backend state to consumers.

## Compatibility and migration

Generation-less current or previous scores are legacy ordinary enforcement and
cannot fabricate re-entry evidence. Existing active legacy blocks remain
authoritative through expiry. The first real mutation upgrades state without
resetting its score or remaining TTL. Pre-DEC-007 writers must not concurrently
write the same logical namespace with DEC-007 writers; migrate writers before
enabling the opt-in policies. Custom `FullCapabilityStoreInterface`
implementers must provide the lifecycle capability.

`RateLimitStoreInterface` remains source-compatible. `FullCapabilityStoreInterface`
intentionally gains the lifecycle capability, and `RateLimiterBuilder` fails
early when an opt-in policy is configured without it.

## Consequences

Opt-in authentication policies require the lifecycle storage capability and the
builder fails fast when it is absent. Legacy generation-less state remains
readable and is upgraded on its first real mutation without score or remaining
TTL reset. Generated state requires authoritative expiry data; malformed
generated backend state follows the package failure path. Consumers receive
typed re-entry metadata through the composite runtime and claim it once.

## Supersedes

None.

## Superseded By

None.

## Canonical Contract / Current Owner

`src/Repository/PunishmentLifecycleStoreInterface.php`,
`src/Service/PostPunishmentReentryClaimInterface.php`,
`src/Service/RateLimiterRuntimeInterface.php`,
`src/Service/EvaluationPipeline.php`,
`src/Service/RateLimiterEngine.php`, and
`RATE_LIMITER_PACKAGE_REFERENCE.md`.
