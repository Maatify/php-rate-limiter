# DEC-026 — Known-Device Verifier Bound, Restated for the Admission Layer (replaces DEC-021)

## Decision ID

`DEC-026`

Text taken from the draft the Owner accepted as written (`investigations/k2-identity-admission-97/RUNTIME_IMPLEMENTATION_CONTRACT.md`
Appendix A, OD-2) is reproduced unchanged; its section references (§4, §6, §7, §9.3, M4) are to that contract. The sections
carried from DEC-021 are reproduced byte for byte from `DEC-021_KNOWN_DEVICE_VERIFIER_BOUND.md`; inside them "M1" … "M5" are
DEC-021's own mixed-version items and "this decision" is the carried decision.

## Status

`ACTIVE` — on the Owner's decisions of 2026-10-19 (OD-2: this record accepted as drafted) and 2026-10-20 (OD-3 … OD-10: the
activation package). Activation is delivered atomically with its canonical contracts and Runtime in PR #150, as DEC-024 point 4
requires.

## Date

2026-10-20

## Decision Authority / Deciders

**Decision Authority.** Owner. DEC-024 point 4 (2026-10-19) binds the first activation of the opt-in reservation to a record that supersedes DEC-021 entirely. The Owner's approval of this record is OD-2 of the 5-a contract. DEC-021's authority, history and technical acceptance are quoted from DEC-021 and are not rewritten.

Owner decisions: OD-2 ([PR #143 comment 6264699340](https://github.com/Maatify/php-rate-limiter/pull/143#issuecomment-6264699340));
OD-3 … OD-10 ([PR #150 comment 6299051848](https://github.com/Maatify/php-rate-limiter/pull/150#issuecomment-6299051848)), taken
after the study G8 (#151). Lead rulings for the activation: [PR #150 comment 6298046892](https://github.com/Maatify/php-rate-limiter/pull/150#issuecomment-6298046892).
Technical acceptance: the Lead's review of PR #150 on its exact HEAD. The implementation was accepted stage by stage in #144
(5-b1), #145 (5-b2), #146 (5-b3a), #148 (5-b2.1), #149 (5-b2.2) and #147 (5-b3b).

**DEC-021's authority, quoted:**

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

**Scope / Concern.** Verifier reach of known devices on Login, OTP and custom authentication policies, and the scope of Anti-Equilibrium escalation, **for every policy**. For policies that do not opt in under DEC-024 the behaviour is DEC-021's, unchanged. For policies that opt in, the delta below applies. Out of scope: as DEC-021 (shared-NAT correlation governed by DEC-019 until DEC-022 is `ACTIVE`, `API_OVERUSE`, budget semantics, the OTP unknown-device path).

## Context

### Carried from DEC-021 (verbatim)

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

### Added at activation

DEC-024 point 4 binds the first activation of the opt-in admission reservation to a record that supersedes DEC-021 entirely, in
the same PR (Decision Governance §9 has no partial supersession). The admission layer changes, for the policies that opt in,
three statements DEC-021 makes for every policy: that pre-checks reserve nothing, that the allowance and device state are one
family, and what K5 is derived from. Every other DEC-021 statement holds for every policy. The production implementation
reproduces the measured DEC-021 parity of the identity layer (G5 v2, `STABLE-600 / 300 / 60` 45 / 44 / 45 on the proven lane;
`investigations/k2-identity-admission-97/5b3a/RESULTS.md`, `5b3b/RESULTS.md`).

## Decision

**Carried unchanged from DEC-021 (the final record reproduces these sections verbatim).** Context; Decision 1 (K5 thresholds `8 / 12 / 16` on the official presets, K5 decay); Decision 2 (a K5-derived level persists on the K5 key only); Decision 3 (the known-device account allowance, Login 8 and OTP 2, per-failure K4 scoring rule and not an absolute cap) **except the pre-check clause below**; Decision 4 (the opt-in is an authentication capability; every prerequisite and the active-allowance prerequisites); Decision 5 (source-scoped Anti-Equilibrium, one watcher per issued `SOFT_BLOCK`); Decision 6 (what stays unchanged: budget, cooldown, micro-cap, OTP `precheck_enforcement = false`, K1/K2/K3 correlation and DEC-019, the Store and Redis contracts, DEC-003, DEC-007, DEC-008, DEC-015); Rationale; **Consequences including the mixed-version and rollback items M1–M5**; Alternatives Not Adopted.

**How the carried text reads.** Delta items 1 to 4 apply to the carried Decision and Consequences. In particular, Decision 3's
"read-only pre-checks reserve nothing" and the Consequences sentence "Pre-checks are read-only, so concurrent in-flight attempts
on one device are not serialized" hold for the policies that have not opted into DEC-024 (Delta 1).

### Carried from DEC-021 (verbatim)

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

### Delta

1. **Pre-check statements narrowed.** DEC-021 Decision 3, "read-only pre-checks reserve nothing", and its Consequences, "Pre-checks are read-only, so concurrent in-flight attempts on one device are not serialized", apply to **policies that have not opted into DEC-024**. For an opted policy `checkOnly` takes the all-or-nothing reservation of DEC-024: concurrent admissions on a scope are serialized by it, one `checkOnly` is one admission, and the allowance is still consumed on `recordFailure` (the outcome path is unchanged). DEC-021's statement that check-only Hosts report no failures and so its bound cannot learn from them stays true, and extends to the admission failure sets (DEC-023 Consequences).
2. **The allowance is tied to the P family for opted policies.** The known-device allowance counter, its K4 overflow, the K5 device state, the micro-cap and the device Anti-Equilibrium watcher of an opted policy are kept **per lane**: `known_device_allowance_p`, `K5p`, `k5p`, `anti_equilibrium_device_p` for requests admitted by a verified binding (the P family), and the existing unsuffixed state for every other request (the U family). The K4 overflow lands on the failing request's lane key (K4P or K4U). The values are DEC-021's (Login: K5 8 / 12 / 16, allowance 8, micro-cap 8; OTP: allowance 2) and are not changed (DEC-025 9.1).
3. **The known device on the strangers' door is bounded by the account bucket only** (DEC-025 5.1). On the U family of an opted policy the DEC-021 allowance and K4 overflow enforce nothing (no account-scoped candidate persists on U): the measured bound for a known device that lost its credential is 51 verifier reaches in 24 h against DEC-021's 45 / 44 / 45 (G5 v2 F-G5-04, `MEASURED`). This is a declared cost of this record for opted policies and of no other.
4. **K5 material for opted policies.** K5 is `account + device material`, where the material is the client instance id (`"id1|" + id`, hashed by the resolver, no UA and no `clientFingerprint`) when the request carries one, and today's fingerprint material otherwise. For policies that do not opt in, the material is DEC-021's.

## Rationale

### Carried from DEC-021 (verbatim)

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

### Added at activation

- **Why the allowance and device state follow the lane.** Without K5 separation by lane the owner's own unproven failures
  reach the proven family (G4 v2 gate K5: the arm without separation, `N-NOK5SEP`, differs on 12 owner rows); with it, the
  proven family keeps DEC-021's measured bound (45 / 44 / 45).
- **Why the instance id is the K5 material.** A browser update is then the same device for K5 (`UAC12` equals `KDL`, G5 v2),
  which the UA-inclusive material did not give (the ladder restarted at the update).
- **Why a full replacement and not an edit.** Decision Governance §9; DEC-024 point 4 and "Not adopted".

## Consequences

### Carried from DEC-021 (verbatim)

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

### Added (opted policies only)

**Consequences added to DEC-021's.** The declared costs of DEC-025 that touch this scope (the bucket bound of item 3, F-G5v2-05, the `UAX-NP-K` curve) are restated here by reference; a browser update is the same device for K5 on the id material (`UAC12` equals `KDL`: the owner's `UAC` denials 6 → 9, G5 v2). A device that starts sending an id has a one-time K5 restart (§6, M4). Every admission bound is absent in degraded mode.

- **The one-time K5 restart is a declared cost** (OD-3 (a)). No transition window reads the old fingerprint-derived K5 key as
  a previous derivation: nothing in the shapes opens or closes such a window and no decision gives its length. The size of one
  K5 key change, measured with the same mechanism (a key change mid-ladder, the `REF` arm of G5): `UAC12`
  `AAAA!SSSAAAA!SSS` against `AAAA!SSSSSA!HSSS` without the change (8 `A` against 5); `UAX24` 82 against 77 on the unproven lane in 24 h, 45 against 45 on the proven lane. An attacker
  gains nothing a fresh id per attempt does not already give (`MINT` 51 in 24 h against the bucket bound 52, `FORGE` 0 proven
  admissions; `5b3b/RESULTS.md`).
- **The owner's `UAC` denials are 9** (S0 6): the G5 v2 value reproduced on the production code (`5b3b/RESULTS.md`, A1 without
  `UAC`); it is the cost Delta 4 names.
- Every admission bound is absent while the policy's fleet fence is closed: the policy then runs the path of a policy that did
  not opt in, under DEC-021's carried text and DEC-027.

## Alternatives Not Adopted

### Carried from DEC-021 (verbatim)

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

### Added

**Alternatives Not Adopted (added).** Splitting the allowance per lane for non-opted policies (would redefine DEC-021 for them); an in-place edit of DEC-021 (Decision Governance §9); superseding DEC-021 before a policy can opt in (DEC-024 "Not adopted").

- **Reading the old fingerprint-derived K5 key as the previous derivation during a transition window** (OD-3 (b)). Not
  adopted: it needs a new input and a new value (the window length) and was never measured.

## Relationship to Other Decisions

**Relationship to Other Decisions.** DEC-024 (this record is its point 4); DEC-023, DEC-025 (decided inputs); DEC-022 and DEC-019 (their activation and exit are separate 5-c conditions, §9.3); DEC-003, DEC-007, DEC-008, DEC-015 (unchanged; effects in §4).

At activation (2026-10-20): DEC-022 is `ACTIVE` for the policies that opt in under DEC-024; DEC-019 is superseded by DEC-027,
which keeps DEC-019's posture for every other policy, so a reference to DEC-019 in the carried text reads as DEC-027 for
those policies; DEC-028 sets the permit timing and the package constants of the admission runtime; DEC-025's values are the
admission bounds. DEC-003, DEC-007, DEC-008 and DEC-015 are unchanged.

## Canonical Contract / Current Owner

**Canonical Contract / Current Owner.** `docs/DECISION_MATRIX.md` §2.2.2, §2.5 including §2.5.3, §3.1.2; `docs/POLICIES.md` (Login and OTP known-device bound); `docs/KEY_STRATEGY.md` §4.6; `KnownDeviceAccountAllowancePolicyInterface`, `LoginProtectionPolicy`, `OtpProtectionPolicy`, `RateLimiterEngine` (registration validation), `EvaluationPipeline` (enforcement), `AdmissionRuntime` (admission), and the §7 contracts.

Named at activation (the Lead ruling of RIC §9.3): `docs/KEY_STRATEGY.md` §3.4 (K4P), §3.5 (K5p and the K5 material) and §4.6
(the `_p` purposes, `binding_epoch`, `admission_fence`), LOCKED sections amended under this record; `docs/DECISION_MATRIX.md`
§12; `docs/POLICIES.md` §7.2 and the Login opt-in variant `LoginProtectionAdmissionPolicy`; `docs/DEVICE_FINGERPRINT.md` §5.1;
`docs/guides/USAGE_GUIDE.md` (the admission Host duties).

## Supersedes

`DEC-021`

## Superseded By

None
