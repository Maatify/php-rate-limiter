# DEC-007 — Generation-Bound Authentication K4 Post-Punishment Re-entry

**Status:** ACTIVE

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
