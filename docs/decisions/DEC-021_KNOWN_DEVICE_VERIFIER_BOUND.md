# DEC-021 — Known-Device Verifier Bound for Authentication Policies

## Decision ID

`DEC-021`

`DEC-020` is intentionally left unallocated: the merged #110 evidence (PR #111, #115, #116)
uses that label for the pending shared-NAT replacement decision.

## Status

`PROPOSED` — not implementation authority. The Draft PR that carries this record and its
Runtime change MUST NOT be merged until the Owner changes this status to `ACTIVE` in the
same PR (decision, canonical contracts, and Runtime delivered atomically).

## Date

2026-10-14

## Decision Authority / Deciders

Owner. The normative boundary below was supplied by the Owner in the project conversation
on 2026-10-14, after the Lead selected this slice in
[Issue #110 comment 6165422526](https://github.com/Maatify/php-rate-limiter/issues/110#issuecomment-6165422526).
The Owner first chose "K5 thresholds plus an account cap through the existing budget"; that
choice was corrected the same day because `DECISION_MATRIX.md` §2.4.4 makes the budget a
one-shot anti-spam guard that does not deny pre-checks during its cooldown. The Owner then
chose the K4 demotion below. The OTP `precheck_enforcement` flag stays unchanged by Owner
choice.

## Scope / Concern

Verifier reach of known devices (`isKnownForAccount`: trusted session or previously
verified device) on Login, OTP, and custom authentication policies. Out of scope: shared-NAT
correlation (K1/K2/LOW, #97/#100/#106), `API_OVERUSE`, budget semantics, and DEC-019.

## Context

Issue #114 measured on S0 that one contract-compliant trusted session can fail 25/25 times
on its own account in 72 s with no denial on Login. The causes on `main@4d37be5`:

1. Known-device failures are scored on K5 only, and the official Login and OTP presets
   define no K5 thresholds, so K5 never produces a level.
2. Any score-derived level, including one from K5, is persisted on K4 (the account key).
   A custom policy that defines K5 thresholds therefore locks the whole account, including
   the owner's other devices.
3. The account budget issues one `SOFT_BLOCK` per cooldown and lets pre-checks through
   during the cooldown (§2.4.4). It does not bound verifier reach.
4. A stable known device that paces one failure every 600 s keeps any K5 score flat
   (+2 per failure, −2 decay), so a per-device threshold alone does not bound it.

## Decision

1. **K5 thresholds on the official presets.** Login and OTP use K5 thresholds
   `l1 = 8, l2 = 12, l3 = 16`. With the existing deltas, a known device reaches L1 at its
   4th Login failure (+2 each) or its 2nd OTP failure (+4 each), L2 at the 6th / 3rd, and
   L3 at the 8th / 4th.
2. **A K5 level is device scoped.** A level derived from K5 is persisted on the K5 key only
   and never on K4, for every policy (official or custom). Levels from other keys keep
   their existing K4 persistence. The owner's other devices are not blocked by it.
3. **Known-device account allowance.** A policy may opt in through
   `KnownDeviceAccountAllowancePolicyInterface`. Every known-device failure consumes one
   unit of a fingerprint-independent account counter with a fixed 24-hour epoch. Failures
   beyond the allowance are additionally scored on K4 with the policy's `k4_failure` delta,
   exactly as an unknown device, and are therefore budget-eligible. Login allowance = 8,
   OTP allowance = 2. After the allowance, a known device is never bounded more weakly than
   an unknown device; K4 punishment keeps the DEC-007 owner re-entry path.
4. **Validation.** An opted-in policy must return a positive allowance, a positive
   `k4_failure`, and positive monotonic K4 thresholds. With previous outer-key rotation the
   Builder requires `BudgetSeedStoreInterface`, as for the account budget.
5. **Unchanged.** Budget, cooldown, micro-cap, OTP `precheck_enforcement = false`,
   K1/K2/K3 correlation, trusted K1 advisory, Store and Redis contracts, and DEC-003 /
   DEC-007 / DEC-008 / DEC-015 / DEC-019.

## Rationale

The bound is account- and device-scoped, uses no network correlation, and reuses existing
primitives (score thresholds, the fixed-epoch budget counter, K4 lifecycle). Device scoping
of K5 levels removes an existing account-lockout hazard instead of adding one. Measured on
the merged #113 / #116 harness streams (unchanged, run on this Runtime): the #114 control
drops from 25/25 to 4/25 verifier reaches; Host-tampered trust TMX 200 → 60 (Login) and
200 → 36 (OTP); TMXb 300 → 48; TMSs 18 → 5 / 3; no legitimate decision changes in any of
415 cells and no attacker reach increases.

## Consequences

- An owner with four consecutive Login typos on one device is soft-denied on that device
  until K5 decays below 8 (one point per 300 s); six give a K5 hard block. Other devices of
  the same account are unaffected until the account allowance (8 known-device failures per
  24-hour epoch) is spent; after that, K4 scoring applies as for an unknown device.
- Pre-checks are read-only: concurrent in-flight attempts on one known device are not
  serialized (unchanged from S0 for every key).
- Under extreme same-account concurrency, DEC-007 K4 generation conflicts answer some
  demoted failures with a fail-closed `k4_concurrency_conflict` HARD_BLOCK without scoring
  them (existing DEC-007 behavior, observed in the real-Redis test).
- Record-only (`out`) hosts gain decisions but not verifier-reach reduction; check-only
  (`pre`) hosts report no failures, so this bound cannot learn from them.
- Rollback to S0 is fail-safe: persisted K5/K4 blocks keep being honored by S0 until they
  expire; the allowance counter is ignored and expires with its epoch.
- Mixed-version fleets enforce the bound only on upgraded workers; no worker is weaker
  than S0.

## Canonical Contract / Current Owner

`docs/DECISION_MATRIX.md` §2.2.2 and §3.1.2, `docs/POLICIES.md` (Login and OTP),
`docs/KEY_STRATEGY.md` §4.6, `KnownDeviceAccountAllowancePolicyInterface`,
`LoginProtectionPolicy`, `OtpProtectionPolicy`, and `EvaluationPipeline`.

## Supersedes

None

## Superseded By

None
