# DEC-003 — Multiple-block-cycle Decay Pause

**Decision ID:** `DEC-003`
**Status:** `ACTIVE`
**Date:** `2026-09-23`
**Decision authority:** Lead-approved WU-S3-07 contract in PR #36
**Scope / Concern:** Persisted hard-block cycle history, key rotation, score decay, and the additive storage capability
**Canonical contract / current owner:** `src/Repository/HardBlockCycleStoreInterface.php`, `src/Service/EvaluationPipeline.php`, `src/Service/DecayCalculator.php`, and the versioned contracts in `docs/DECISION_MATRIX.md`, `docs/POLICIES.md`, and `docs/KEY_STRATEGY.md`

## Context

The package has progressive persisted blocks and lazy score decay. Repeated hard
block cycles must pause score decay for a bounded interval without changing
block TTLs, penalty durations, budgets, correlation state, local fallback, or
circuit-breaker state. The behavior must remain independent for each canonical
enforcement key and survive the existing one-Current/one-Previous rotation
model.

## Decision

1. A cycle is scoped to `policy + canonical enforcement key type + logical
   enforcement identity`. K1, K2, K3, K4, and K5 have independent histories.
2. A cycle is recorded only on the transition from no active persisted HARD
   BLOCK at L2+ to an active persisted HARD BLOCK at L2+. L1 is not a cycle;
   active-block escalation, refresh, and reads do not create cycles. An expiry
   at exact `now` is inactive.
3. The rolling cycle window is `21600` seconds and includes the exact boundary
   timestamp `now - 21600`. A retained count reaching `2` activates a fixed
   `600`-second pause when no pause is active. The pause is
   `[pauseStartedAt, pauseUntil)`, does not renew on refresh or escalation, and
   a cycle during an active pause is retained without extending the pause.
4. After a pause ends, a later real cycle may activate another pause if the
   rolling history still reaches the threshold. Pause history is retained for
   `86400` seconds for lazy score-decay accounting.
5. The pause affects score decay only. Effective elapsed decay is
   `max(0, (now - lastUpdateTimestamp) - elapsedPausedSeconds)`. Score-derived
   Retry-After uses that arithmetic and adds `max(0, activePauseUntil - now)`;
   an active persisted block remains authoritative.
6. `HardBlockCycleStoreInterface` extends `RateLimitStoreInterface` and adds the
   atomic `blockWithCycleTracking()` and read-only `readDecayPauseState()` APIs.
   `RateLimitStoreInterface` is unchanged. The pipeline requires the additive
   capability before any persisted L2+ block write and never silently falls
   back to `block()`; L1 continues through `block()`.
7. Current and Previous are one logical history. Writes target Current only;
   Previous is read-only. The first Current mutation adopts relevant Previous
   cycle and pause timestamps without changing Previous TTL or state. An opaque
   historical snapshot member without a known pair is persisted as Current with
   `previousKey = null`.
8. The package passes `21600`, `2`, `600`, and `86400` to the capability. No
   package-level cross-key transaction is introduced; atomicity is per logical
   block-cycle operation.

## Rationale

The additive capability preserves source compatibility for existing base-only
read and L1 integrations while making the first L2+ persistence requirement
explicit. Transition-based classification prevents refreshes and escalations
from fabricating cycles, and the half-open fixed interval prevents boundary
ambiguity or pause renewal. Current/Previous adoption preserves continuity
without writing historical keys or double-counting migrated history.

## Consequences

- Host stores that persist L2+ blocks must implement the new capability with
  their backend's atomic transaction or script boundary.
- Base-only stores remain usable for normal reads and L1 writes; an L2+
  persistence attempt fails through the existing package failure semantics.
- Completed pauses remain observable to lazy decay until their retention ends,
  even when no request arrives during the pause.
- The behavior is covered by direct store, decay-calculator, and pipeline
  regression tests plus the standalone consumer verification adapter.

## Alternatives considered

No alternative architecture is selected. The Lead-locked contract requires a
per-logical-key additive capability, Current/Previous continuity, and a single
`DecayCalculator` source of truth.
