# DEC-021 — Known-Device Verifier Bound for Authentication Policies

## Decision ID

`DEC-021`

`DEC-020` is intentionally left unallocated: the merged #110 evidence (PR #111, #115, #116)
uses that label for the pending shared-NAT replacement decision.

## Status

`PROPOSED` — not implementation authority. The Draft PR that carries this record and its
Runtime change MUST NOT be merged until the Owner changes this status to `ACTIVE` in the
same PR (decision, canonical contracts, and Runtime delivered atomically), after the Lead's
technical acceptance and the Owner's review of the effects below.

## Date

2026-10-14

## Decision Authority / Deciders

Owner, not yet exercised. The numeric boundary implemented here (K5 thresholds `8 / 12 / 16`,
allowance Login 8 / OTP 2, K4 scoring after the allowance, OTP `precheck_enforcement`
unchanged) was supplied by the Owner to the executor in the project conversation on
2026-10-14, after the Lead selected this slice in
[Issue #110 comment 6165422526](https://github.com/Maatify/php-rate-limiter/issues/110#issuecomment-6165422526).
That conversation is not visible on GitHub and the Lead has not independently confirmed it
([PR #117 review 5517131013](https://github.com/Maatify/php-rate-limiter/pull/117#pullrequestreview-5517131013),
F117-04). It is an implementation boundary only; activation requires explicit Owner
acceptance after review. The Owner's first choice ("account cap through the existing budget")
was withdrawn the same day because `DECISION_MATRIX.md` §2.4.4 makes the budget a one-shot
anti-spam guard that does not deny pre-checks during its cooldown.

## Scope / Concern

Verifier reach of known devices (`isKnownForAccount`: trusted session or previously
verified device) on Login, OTP, and custom authentication policies. Out of scope: shared-NAT
correlation (K1/K2/LOW, #97/#100/#106), `API_OVERUSE`, budget semantics, the OTP
unknown-device path, and DEC-019.

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

Measured on S0 with an explicitly advancing clock, one account, 24 h, `pre+out`: a stable
known device reaches the verifier 128 / 268 / 1417 times when failing every 600 / 300 / 60 s;
a known device rotating fingerprint and user agent every 300 s reaches it 288 / 288 times. An
unknown device on the same Login streams reaches it 37–39 times.

## Decision

1. **K5 thresholds on the official presets.** Login and OTP use K5 thresholds
   `l1 = 8, l2 = 12, l3 = 16`. With the existing deltas, a known device reaches L1 at its
   4th Login failure (+2 each) or its 2nd OTP failure (+4 each), L2 at the 6th / 3rd, and
   L3 at the 8th / 4th. K5 decays one point per 300 s.
2. **A K5 level is persisted on the device key.** A level derived from K5 is persisted on
   the K5 key only and never on K4, for every policy (official or custom). Levels from other
   keys keep their existing K4 persistence.
3. **Known-device account allowance.** A policy opts in through
   `KnownDeviceAccountAllowancePolicyInterface`. While the allowance is active (not null),
   every known-device failure consumes one unit of a fingerprint-independent account counter
   with a fixed 24-hour epoch, and every known-device failure beyond the allowance also adds
   the policy's `k4_failure` delta to K4. This is a **per-failure K4 scoring rule**, not an
   absolute cap on verifier reaches: K4 score protection decays, depends on recorded
   outcomes, and read-only pre-checks reserve nothing. Login allowance = 8, OTP = 2.
4. **The opt-in is an authentication capability.** An implementing policy is classified as
   an authentication policy, so every authentication prerequisite applies (positive monotonic
   K4 thresholds, positive `k4_failure`, a budget, a device-aware signal, `FAIL_CLOSED`, an
   ACCOUNT + IP_PREFIX fallback) and `API_OVERUSE` cannot be combined with it. An active
   allowance additionally requires a positive allowance, a positive `k5_failure`, and
   positive monotonic K5 thresholds; registration fails otherwise. `null` disables it
   explicitly. The official presets return their allowance only when their effective
   configuration scores K5 (positive `k5_failure` and K5 thresholds), so a Host subclass
   that overrides thresholds without K5 keeps S0 known-device behavior.
5. **Source-scoped Anti-Equilibrium (amends ACTIVE `DECISION_MATRIX.md` §2.5, PROPOSED).**
   Every issued `SOFT_BLOCK` carries the scopes of its winning candidates. A level that
   comes from K5 alone has **device** scope; every other source keeps **account** scope (the
   conservative default, i.e. the current §2.5 behavior). Each issued `SOFT_BLOCK` writes
   exactly **one** event: on the account watcher whenever any account source is present
   (mixed K4 + K5 included), on the device watcher only for a K5-only `SOFT_BLOCK` with an
   eligible device, and on the account watcher otherwise (F117-FR-02, Lead decision
   [5521663868](https://github.com/Maatify/php-rate-limiter/pull/117#pullrequestreview-5521663868)).
   Device events escalate (three in 6 h, on `recordFailure`) to a K5 `HARD_BLOCK` L2 on that
   account + device only; account events keep the K4 account-wide escalation. Both watchers
   follow rotation. The device watcher exists only for a budgeted, non-`API_OVERUSE` policy
   with K5 thresholds (the account watcher's eligibility plus a K5 level source); for any
   other policy it is never read, written, or escalated, including after a same-name policy
   replacement with stored events (F117-FR-01). Rationale: §2.5 exists to break
   "low-and-slow" equilibrium; for a K5-only source the equilibrium is per device, so the
   escalation is kept at the same scope as the penalty, and mixed evidence keeps the
   stronger account escalation. Account protection against a known device is not lost:
   rotating devices fall under the fingerprint-independent allowance and then under K4
   scoring, whose soft events remain account-scoped.
6. **Unchanged.** Budget, cooldown, micro-cap, the account watcher and its K4 escalation,
   OTP `precheck_enforcement = false`, K1/K2/K3 correlation, trusted K1 advisory, Store and
   Redis contracts, and DEC-003 / DEC-007 / DEC-008 / DEC-015 / DEC-019.

## Rationale

The bound is account- and device-scoped, uses no network correlation, and reuses existing
primitives (score thresholds, the fixed-epoch budget counter, K4 lifecycle). Measured with
an explicitly advancing clock, one account, 24 h, Login `pre+out`: a stable known device
reaches the verifier 45 / 44 / 45 times (failing every 600 / 300 / 60 s), and a rotating one
44 times, against 37–39 for an unknown device. OTP: a stable known device 54 / 52 / 51 (S0
144 / 288 / 1440); a rotating one 285, equal to the unknown OTP device (285, S0 property).
On the merged #113 / #116 harness streams (unchanged; driver control reproduces the S0
goldens 355/355 and 60/60): the #114 control 25/25 → 4/25; Host-tampered trust TMX
200 → 60 (Login) and 200 → 36 (OTP); TMXb 300 → 48; TMSs 18 → 5 / 3; no legitimate
decision changes and no attacker reach increases **within those 415 harness cells**. The
harness does not contain the known-device owner-retry scenario; it is covered by
`KnownDeviceAntiEquilibriumScopeTest`.

## Consequences

- **Owner friction on one device.** Four consecutive Login typos on one known device give
  `SOFT_BLOCK` L1 on that device for 300 s; six give a K5 hard block on that device only.
- **Device-scoped Anti-Equilibrium (F117-05).** Four typos, three retries during the soft
  window, and one more typo after 300 s now give a K5 `HARD_BLOCK` L2 on that device only;
  the owner's other device stays allowed and the account watcher records nothing
  (`KnownDeviceAntiEquilibriumScopeTest`). Before this change (HEAD `744f571`) the same
  sequence gave an account-wide K4 `HARD_BLOCK` L2. An attacker retrying every minute on the
  offending device reaches the verifier at most 4 times in an hour, each further failure
  re-escalating to K5 L2. Measured with an advancing clock, 24 h, one account, Login
  `pre+out`: stable known device 45 / 44 / 45 (failing every 600 / 300 / 60 s; `744f571`:
  45 / 44 / 44), rotating 44 (unchanged); OTP stable known device 54 / 52 / 51 (`744f571`:
  74 / 61 / 60). The #113 / #116 harness cells are identical to `744f571`.
- **Account-wide effect after the allowance.** Beyond the allowance, K4 scoring (and its
  DEC-007 punishment / re-entry lifecycle) applies to the account as for an unknown device.
- **Partial commit on faults (pre-existing).** The pipeline is not transactional across
  store operations (as on S0): a fault after a request's blocks are persisted leaves them
  persisted. Anti-Equilibrium recording is a single write per issued `SOFT_BLOCK`, so no
  two-watcher partial publication exists. Through `limit()`, mixed requests whose account
  write fails answered `FAIL_CLOSED` twice then `DEGRADED_MODE`, left no watcher event and no
  account-wide `HARD_BLOCK` (`KnownDeviceAntiEquilibriumBoundaryTest`).
- **Cost of the single-write rule (measured).** Mixed K4 + K5 soft events no longer count on
  the device watcher. Against HEAD `09c58d1` (which wrote both), every Login 24 h row is
  identical; one OTP row (stable known device failing every 300 s) reaches the verifier 52
  instead of 51 times in 24 h, with unchanged per-600 / 1800 / 3600 s peaks (2 / 3 / 5).
- **Concurrency.** Pre-checks are read-only, so in-flight attempts are not serialized
  (unchanged from S0). The allowance counter is exact under real-Redis overlap. Under heavy
  same-account overlap, DEC-007 K4 generation conflicts answer some demoted failures with a
  fail-closed `k4_concurrency_conflict` `HARD_BLOCK` without scoring them (existing DEC-007
  behavior; observed 4–5 of 8 demoted failures in a 16-way burst). A `recordFailure` that
  meets an active block is answered early and records nothing (existing S0 behavior).
- **Flows.** Record-only (`out`) Hosts gain decisions but no verifier-reach reduction;
  check-only (`pre`) Hosts report no failures, so the bound cannot learn from them.
- **DEC-015.** A backend failure while consuming the allowance takes the existing typed
  backend-failure path. Observed through `limit()`: two `FAIL_CLOSED` `HARD_BLOCK`s, then the
  open circuit's `DEGRADED_MODE` fallback admits three per account per 600 s
  (`AUTHENTICATION_PRIMARY`), then `fallback_limit_exceeded`. Unchanged by this slice.
- **Mixed version and rollback.** Committed regressions replay the old worker with a
  pinned S0 pipeline fixture (`tests/Fixtures/S0Baseline`, provenance-checked against
  `main@4d37be5`) and assert these semantic differences, not parity:
  - The S0 pipeline is byte-identical to `main@4d37be5` after a reversible rename; the S0
    Login policy fixture is a compatibility subclass whose effective configuration is
    pinned by a snapshot hash of the real S0 preset, not a copy of its source.
  - M1: a K4 block that S0 derived from a K5 level is honored by this Runtime and keeps
    blocking the owner's other device until it expires (this Runtime alone blocks only the
    offending device).
  - M2: S0 honors K5 blocks written by this Runtime for that device only.
  - M3 (real Redis, instantaneous back-to-back sequence, not the timed #114 stream):
    S0 alone 25/25, this Runtime alone 4/25, alternating per request 15/25.
  - M3t (advancing clock, the timed #114 stream: 25 attempts every 3 s): S0 alone 25,
    this Runtime alone 4, alternating 15 (S0 first) / 14 (new first), S0 pre-checks with
    this Runtime recording 6, this Runtime pre-checks with S0 recording 4.
  - M4: after rollback, S0 ignores the allowance counter, the K5 L1 level and the device
    Anti-Equilibrium events, and does not escalate the account; rolling forward, this
    Runtime escalates on K5 only.
  - M5: S0 scores and account events written under the previous secret, and device events
    written by this Runtime under the previous secret, are read through the previous keys;
    writes go to the current secret only.
- **Upgrade.** Existing K5 scores (24 h TTL) are evaluated against the new thresholds
  immediately.

## Canonical Contract / Current Owner

`docs/DECISION_MATRIX.md` §2.2.2, §2.5.3 and §3.1.2, `docs/POLICIES.md` (Login and OTP),
`docs/KEY_STRATEGY.md` §4.6, `KnownDeviceAccountAllowancePolicyInterface`,
`LoginProtectionPolicy`, `OtpProtectionPolicy`, `RateLimiterEngine`, and
`EvaluationPipeline`.

## Supersedes

None

## Superseded By

None
