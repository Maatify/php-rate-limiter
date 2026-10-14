# DEC-021 — Known-Device Verifier Bound for Authentication Policies

## Decision ID

`DEC-021`

`DEC-020` is intentionally left unallocated: the merged #110 evidence (PR #111, #115, #116)
uses that label for the pending shared-NAT replacement decision.

## Status

`ACTIVE` — on the Owner's explicit approval of 2026-10-14. Activation is delivered
atomically with its canonical contracts and Runtime in PR #117.

## Date

2026-10-14

## Decision Authority / Deciders

**Owner.** The Owner explicitly approved this decision on 2026-10-14 («اعتمد القرار»),
after the complete plain-language presentation of its effects and the Lead's exact-HEAD
technical acceptance; the approval is recorded on GitHub in
[PR #117 comment 6175999609](https://github.com/Maatify/php-rate-limiter/pull/117#issuecomment-6175999609).
The approval covers the full material policy below, including the disclosed security cost
and owner-availability consequences. It is not a merge or release decision; merging PR #117
remains the Owner's separate decision.

Technical acceptance: Lead
[review 5522083905](https://github.com/Maatify/php-rate-limiter/pull/117#pullrequestreview-5522083905)
on the implementation at `07b75ace30c8291cfc230b37c397a8bf3c2d2bc3` (GitHub CI 14/14
SUCCESS). Slice selection: Lead in
[Issue #110 comment 6165422526](https://github.com/Maatify/php-rate-limiter/issues/110#issuecomment-6165422526).

History of the boundary (kept for the record): the Owner first chose an account cap through
the existing budget; that was withdrawn the same day because `DECISION_MATRIX.md` §2.4.4
makes the budget a one-shot anti-spam guard that does not deny pre-checks during its
cooldown, and the fingerprint-independent allowance with K4 scoring replaced it. The
source-scoped Anti-Equilibrium rule and its single-watcher form came from Lead review
findings F117-05 and F117-FR-02 on PR #117.

## Scope / Concern

Verifier reach of known devices (`isKnownForAccount`: trusted session or previously
verified device) on Login, OTP, and custom authentication policies, and the scope of
Anti-Equilibrium escalation for soft decisions that come from those devices. Out of scope:
shared-NAT correlation (K1/K2/LOW, #97/#100/#106, governed by DEC-019), `API_OVERUSE`,
budget semantics, and the OTP unknown-device path.

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
   `l1 = 8, l2 = 12, l3 = 16` with their existing deltas (+2 Login, +4 OTP). A known device
   reaches L1 at its 4th Login failure or 2nd OTP failure, L2 at the 6th / 3rd, and L3 at the
   8th / 4th. K5 decays one point per 300 s.
2. **A K5 level is persisted on the device key.** A level derived from K5 is persisted on
   the K5 key (account + device) only and never on K4, for every policy, official or custom.
   Levels from other keys keep their existing K4 persistence.
3. **Known-device account allowance.** A policy opts in through
   `KnownDeviceAccountAllowancePolicyInterface`. While the allowance is active (not null),
   every known-device failure consumes one unit of a fingerprint-independent account counter
   with a fixed 24-hour epoch, and every known-device failure beyond the allowance also adds
   the policy's existing `k4_failure` delta to K4. Login allowance = 8, OTP allowance = 2.
   This is a **per-failure K4 scoring rule, not an absolute cap on verifier reaches**: K4
   protection decays, depends on recorded outcomes, and read-only pre-checks reserve nothing.
4. **The opt-in is an authentication capability.** An implementing policy is classified as
   an authentication policy, so every authentication prerequisite applies (positive monotonic
   K4 thresholds, positive `k4_failure`, a budget, a device-aware signal, `FAIL_CLOSED`, an
   ACCOUNT + IP_PREFIX fallback) and `API_OVERUSE` cannot be combined with it. An active
   allowance additionally requires a positive allowance, a positive `k5_failure`, and
   positive monotonic K5 thresholds; registration fails otherwise. `null` disables it
   explicitly. The official presets return their allowance only when their effective
   configuration scores K5, so a Host subclass that overrides thresholds without K5 keeps S0
   known-device behavior (a disclosed downgrade from the new default, not active protection).
5. **Source-scoped Anti-Equilibrium with a single watcher per issued SOFT.** Every issued
   `SOFT_BLOCK` carries the scopes of its winning candidates: **device** when the level comes
   from K5 alone, **account** for every other source. Each issued `SOFT_BLOCK` writes exactly
   **one** Anti-Equilibrium event:
   - on the **account** watcher whenever any winning candidate has account scope, including a
     mixed K4 + K5 `SOFT_BLOCK`;
   - on the **device** watcher (same account and device) only when every winning candidate is
     device-scoped and the device watcher is eligible;
   - on the **account** watcher otherwise.

   Three account events in 6 h escalate the next `recordFailure` to an account-wide K4
   `HARD_BLOCK` L2 (unchanged). Three device events in 6 h escalate the next `recordFailure`
   to a K5 `HARD_BLOCK` L2 on that account + device only. The device watcher exists only for
   a budgeted, non-`API_OVERUSE` policy with K5 thresholds; for any other policy it is never
   read, written, or escalated, including after a same-name policy replacement with stored
   events. Both watchers follow outer-secret rotation (current writable, previous read-only);
   the device watcher also follows the previous fingerprint generation.
6. **Unchanged.** Budget, cooldown, micro-cap, OTP `precheck_enforcement = false`, K1/K2/K3
   correlation and the shared-NAT posture (DEC-019), trusted K1 advisory, the Store and Redis
   contracts, DEC-003 cycle tracking, DEC-007 K4 lifecycle and re-entry, DEC-008 capability
   model, and DEC-015 failure semantics.

## Rationale

- **Why bound known devices at all.** Known-device failures were scored where nothing
  enforced them (no K5 thresholds) and the budget only signals once per cooldown, so a
  trusted or previously verified device could keep reaching the verifier indefinitely on
  its own account (#114). K5 thresholds bound one device; the fingerprint-independent
  allowance bounds rotation and pacing below K5 decay, after which a known device is scored
  on K4 exactly like an unknown device.
- **Why the penalty follows its source.** A level that comes from K5 describes one account
  + device; enforcing it on K4 would lock the owner out of every other device (the S0 custom
  policy hazard). Anti-Equilibrium exists to break "low-and-slow" equilibrium; for a K5-only
  source the equilibrium is per device, so its escalation stays at device scope. Any evidence
  that involves the account (K4, budget, flood, Recovery Guard, mixed K4 + K5) keeps the
  stronger account-wide escalation.
- **Why one watcher.** One write per issued `SOFT_BLOCK` removes the two-key partial
  publication class without a new Store capability, and for mixed evidence the account
  watcher is the conservative, stronger choice.
- **Measured effect** (advancing clock, one account, 24 h, `pre+out`): Login stable known
  device 45 / 44 / 45 (failing every 600 / 300 / 60 s), rotating 44, against 37–39 for an
  unknown device; OTP stable known device 54 / 52 / 51 (S0 144 / 288 / 1440), rotating 285,
  equal to the unknown OTP device (an S0 property outside this decision). On the merged #113
  / #116 harness streams (driver control reproduces the S0 goldens 355/355 and 60/60): the
  #114 control 25/25 → 4/25; Host-tampered trust TMX 200 → 60 (Login) and 200 → 36 (OTP);
  TMXb 300 → 48; TMSs 18 → 5 / 3; no legitimate decision changes and no attacker reach
  increases within those 415 harness cells (the known-device owner-retry scenario is
  outside them and covered by `KnownDeviceAntiEquilibriumScopeTest`).

## Consequences

**Legitimate users**

- Four consecutive Login typos on one known device give `SOFT_BLOCK` L1 on that device for
  about 300 s; six give a K5 `HARD_BLOCK` on that device only.
- Four typos, three retries during the soft window, and one more typo after 300 s give a K5
  `HARD_BLOCK` L2 on that device only; the owner's other device stays allowed and the account
  watcher records nothing (`KnownDeviceAntiEquilibriumScopeTest`).
- After the account allowance (Login 8, OTP 2 known-device failures per 24-hour epoch), K4
  scoring and its DEC-007 punishment / re-entry lifecycle apply to the account as for an
  unknown device. Real account evidence can still cause account-level enforcement.
- Existing K5 scores (24 h TTL) are evaluated against the new thresholds at upgrade.

**Attackers**

- The #114 trusted-session burst reaches the verifier 4 times instead of 25. An attacker
  retrying every minute on the offending device reaches it at most 4 times in an hour, each
  further failure re-escalating to K5 L2. Host-tampered trust (one session against twelve
  accounts) is bounded per account by each account's K5 and allowance.
- **Accepted security cost:** mixed K4 + K5 soft events no longer count on the device watcher.
  In the measured OTP stream of a stable known device failing every 300 s, the verifier is
  reached 52 times in 24 h instead of 51 on the preceding two-watcher implementation
  (`09c58d1`), with unchanged per-600 / 1800 / 3600 s peaks (2 / 3 / 5); every Login row is
  identical. This narrow measured margin is not a general attacker-leak waiver.

**Runtime and operations**

- Pre-checks are read-only, so concurrent in-flight attempts on one device are not
  serialized (unchanged from S0). The allowance counter is exact under real-Redis overlap. A
  `recordFailure` that meets an active block is answered early and records nothing (S0
  behavior). Under heavy same-account overlap, DEC-007 K4 generation conflicts answer some
  demoted failures with a fail-closed `k4_concurrency_conflict` `HARD_BLOCK` without scoring
  them (existing DEC-007 behavior).
- The pipeline is not transactional across store operations (as on S0): a fault after a
  request's blocks are persisted leaves them persisted. Anti-Equilibrium recording is one
  write per issued `SOFT_BLOCK`, so no two-watcher partial publication exists.
- DEC-015 is unchanged. A backend failure while consuming the allowance or reading or writing
  a watcher takes the typed backend-failure path; through `limit()` the observed sequence is
  two `FAIL_CLOSED` `HARD_BLOCK`s, then the open circuit's `DEGRADED_MODE` fallback (three per
  account per 600 s under `AUTHENTICATION_PRIMARY`), then `fallback_limit_exceeded`.
- Record-only (`out`) Hosts gain decisions but no verifier-reach reduction; check-only (`pre`)
  Hosts report no failures, so the bound cannot learn from them.

**Mixed version and rollback** (committed regressions replay the old worker with a pinned S0
pipeline fixture in `tests/Fixtures/S0Baseline`, byte-identical to `main@4d37be5` after a
reversible rename; the S0 Login policy fixture is a compatibility subclass whose effective
configuration is pinned by a snapshot hash of the real S0 preset). These are semantic
differences, not parity:

- M1: a K4 block that S0 derived from a K5 level is honored by the new Runtime and keeps
  blocking the owner's other device until it expires.
- M2: S0 honors K5 blocks written by the new Runtime for that device only.
- M3 (real Redis, instantaneous back-to-back sequence): S0 alone 25/25, new alone 4/25,
  alternating 15/25. M3t (advancing clock, 25 attempts every 3 s): S0 alone 25, new alone 4,
  alternating 15 / 14, S0 pre-checks with new recording 6, new pre-checks with S0 recording 4.
- M4: after rollback, S0 ignores the allowance counter, the K5 L1 level and the device
  Anti-Equilibrium events, and does not escalate the account; rolling forward, the new
  Runtime escalates on K5 only.
- M5: S0 scores and account events written under the previous secret, and device events
  written under the previous secret, are read through the previous keys; writes go to the
  current secret only.
- A mixed fleet enforces the known-device bound only on upgraded workers. Upgraded workers
  intentionally enforce a K5-derived level on the device instead of the account, so for a
  custom policy with K5 thresholds they block less account-wide than S0 did.

## Alternatives Not Adopted

- **The account budget as the account cap.** Rejected: §2.4.4 makes it a one-shot signal per
  cooldown; pre-checks pass during the cooldown, so it does not bound verifier reach.
- **K5 thresholds alone.** Rejected: a device that rotates its fingerprint, or paces below
  K5 decay, is not bounded per account.
- **Account-only Anti-Equilibrium for K5 soft decisions** (the §2.5 contract applied
  unchanged). Rejected: four typos and three retries on one device locked every device of the
  account through K4 (measured on `744f571`).
- **Recording a mixed soft decision on both watchers** (`5992b72`, then device-first ordering
  in `09c58d1`). Rejected: the two writes are separate store operations, so a fault between
  them left a partial publication (an orphan account event, then an orphan device event) for
  a decision the engine did not issue.
- **An atomic multi-key watcher operation in the Store.** Not adopted: it would add a
  mandatory Store capability for custom stores; the single-watcher rule removes the need.
- **Raising thresholds or disabling Anti-Equilibrium.** Not considered acceptable: either
  would remove protection rather than scope it.

## Relationship to Other Decisions

Complements and does not supersede ACTIVE DEC-003, DEC-007, DEC-008, DEC-015, and DEC-019.
DEC-019 and the shared-NAT issues #97 / #100 / #106 are unaffected. `DECISION_MATRIX.md`
§2.5 (Anti-Equilibrium) is a canonical contract not owned by an earlier decision record;
this decision updates it in §2.5.3.

## Canonical Contract / Current Owner

`docs/DECISION_MATRIX.md` §2.2.2 (known-device bound), §2.5 including §2.5.3
(Anti-Equilibrium source scope) and §3.1.2 (OTP); `docs/POLICIES.md` (Login and OTP
known-device bound); `docs/KEY_STRATEGY.md` §4.6 (`known_device_allowance`,
`anti_equilibrium_device`); `KnownDeviceAccountAllowancePolicyInterface`,
`LoginProtectionPolicy`, `OtpProtectionPolicy`, `RateLimiterEngine` (registration
validation), and `EvaluationPipeline` (enforcement).

## Supersedes

None

## Superseded By

None
