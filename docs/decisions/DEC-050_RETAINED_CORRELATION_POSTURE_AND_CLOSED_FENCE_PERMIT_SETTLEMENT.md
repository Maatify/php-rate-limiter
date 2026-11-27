# DEC-050 — Retained Correlation Posture and Closed-Fence Permit Settlement Boundary (successor of DEC-027)

## Decision ID

`DEC-050`

The carried sections of `DEC-019` and `DEC-027` are reproduced verbatim below for every policy that does not opt into admission and for permit-less requests of an opted policy while its fleet fence is closed; inside the carried sections, "this decision" and "this record" refer to the carried decision. This record adds only the narrow Owner-approved `D7` boundary for explicit permit-bearing outcomes of a healthy opted policy when its fleet fence is closed.

## Status

`ACTIVE` — approved by the Project Owner on 2026-11-26 in [#284 comment 7185799202](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7185799202) (G3 `D7` from [#284 comment 7185756131](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7185756131)), superseding `DEC-027` in full under Decision Governance Standard §9.

## Date

2026-11-26

## Decision Authority / Deciders

**Project Owner**, explicit approval of G3 `D7` in [#284 comment 7185799202](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7185799202): *"تسوية تصريح صحيح صدر سابقًا حتى عند إغلاق fence ضمن حدوده، دون reservation جديدة أو scoring لتصريح قديم/مخالف"* — delivered atomically with `DEC-048` and `DEC-049`.

Historical authorities of `DEC-027` (Owner OD-6(a) and OD-9(a), 2026-10-20, PR #150) and `DEC-019` (Owner, 2026-10-13, Issue #97 comment 6138985838) remain preserved in their respective historical records.

## Scope / Concern

`DEC-019`'s and `DEC-027`'s scope, carried below, for:
1. **Every policy that does not declare `PolicyCapabilityEnum::ADMISSION_RESERVATION`** (unchanged in full; zero admission backend reads added).
2. **A policy that declares `PolicyCapabilityEnum::ADMISSION_RESERVATION` while its budget group's fleet fence is closed**, for all `checkOnly` and permit-less outcome commands (unchanged S0 path; closed fence creates no new reservations and requires no permit on permit-less outcomes).
3. **Narrow `D7` exclusion from S0 fallback:** when a **healthy** opted policy receives an **explicit permit-bearing outcome** (`(isFailure || isSuccess) && permit !== null`), it routes to the admission permit claim/settlement path even if the budget group's fleet fence is closed. Degraded and `FAIL_OPEN` paths remain unchanged and perform no admission backend reads.

For a policy that opts in, while its fence is open, the correlation posture remains `DEC-022`, `DEC-034`, `DEC-035`, `DEC-026`, `DEC-048`, and `DEC-049` under `DEC-023` and `DEC-024`.

## Context

### Why this narrow delta is required (G3 D7)

Previously (`RateLimiterEngine` lines 441–447 and 664–674 at `main@1d87036c`), when an opted policy's fleet fence was closed, **every** command—including an outcome carrying an explicit `$request->permit`—fell through directly to the S0 pipeline without inspecting the permit. That produced two contradictions with G3 `D6` (`DEC-048`):
1. An old, v1, foreign, or tampered permit presented on an outcome while the fence was closed was silently scored in S0 instead of being rejected with `permit_*` and zero scoring.
2. A valid outstanding permit issued just before the fence closed could not settle its prior reservation atomically before `D` (`30 s`, with `G = 600 s` retaining the record for `permit_late` / `permit_duplicate` / `permit_conflict` classification per `DEC-035`).

Because Decision Governance Standard §9 prohibits partial supersession or editing `DEC-027` in place, `DEC-050` supersedes `DEC-027` in full while preserving every other rule of `DEC-027` and `DEC-019` verbatim.

### Carried from DEC-027 and DEC-019 (verbatim)

Issues #97 (LOW passive-UA / cohort), #100 (K2 shared-NAT fingerprint churn and block
renewal), and #106 (K1 credential-spray on shared NAT) document legitimate users behind a
shared IP being denied by the shipped correlation rules. Studies merged in #103–#108
measured candidate remedies and did not find a replacement that removes this collateral
without also removing protection against the modeled attackers.

Exact merged, synthetic experimental evidence (all reports are deterministic/synthetic and
are **not** production incident statistics):

- **#108 K1/NAT** (`investigations/k1-credential-spray-nat/`). Scenario D2 (shared NAT
  office, legitimate denials out of 121 requests): S0 arm A = 117; K2 disabled arm B = 108;
  K1 disabled arm C = 117; both disabled arm D = 27; Nu4 with K1 isolated = 47/61 denied.
  With K1 disabled, attacker verifications reaching the check rise: SP1n 4/200 → 200/200,
  SP3 7/1000 → 1000/1000, SP6 20/1200 → 1200/1200. Arm B's 81 incremental denials
  over arm D (108 − 27) must not be misattributed as uniquely K1 in arm A; the K1 and K2
  effects overlap.
- **#107 PSC** (`investigations/workstream-b-anti-evasion/feasibility/`). Unified default
  is NO-GO: D1 Login 180/600 legitimate users still denied; X7 LOW attacker 121/121
  reaches verification; X1 API 24/61, Y7 API 54/241, and 90 attacker verifications
  sustained over 30 minutes. #105 and A06 establish identical *tested* benign/attacker
  observable pairs; that does not prove that arbitrary future server-trusted information
  is impossible.
- **S0 versus EQL/EQV** (`investigations/workstream-b-anti-evasion/`). S0 on LOW: D1 596/600
  legitimate users denied, versus observed EQL advisory collateral 0 but LOW distributed
  attacker 121/121 reaching verification. S0 low-volume API anti-evasion is stronger than
  EQV; EQV X1 has 61/61 reaching verification. This is a **material unresolved trade-off**,
  not a measured secure candidate.

## Decision

### Narrow G3 D7 Boundary (added by DEC-050)

1. **Healthy opted policy + explicit permit-bearing outcome:** When a registered policy declares `PolicyCapabilityEnum::ADMISSION_RESERVATION`, the circuit is healthy (not degraded/open/guard-violated), and an outcome command (`isFailure` or `isSuccess`) carries a non-null `$request->permit`, `RateLimiterEngine` **always** routes the call to the admission permit claim/settlement path (`AdmissionRuntime::applyOutcome()`), whether the budget group's fleet fence is open or closed.
2. **Valid outstanding permit settles once:** A current, valid, outstanding v2 permit settles its prior reservation **before `D` (`30 s`) only** and scores once on its recorded lane (`U` or `P`); `G` (`600 s`) is the retention window for classifying `permit_late`, `permit_duplicate`, and `permit_conflict`, never a window for new settlement (`DEC-035`).
3. **Invalid, old, v1, foreign, or mismatched permit never scores S0:** Any permit that fails signature/version/cohort/context verification or store claim (`permit_unknown`, `permit_rejected`, `permit_late`, `permit_duplicate`, `permit_conflict`) returns `ALLOW` (`blockLevel = 0`, `scope = 'permit'`) with **no** S0 scoring and **no** store mutation.
4. **Closed fence creates no new reservations and preserves S0 for permit-less requests:** When the fleet fence is closed, `checkOnly` and permit-less outcome commands (`permit === null`) execute the S0 pipeline verbatim as under `DEC-027`; no reservation is created and no permit is required.
5. **Non-opted, degraded, and fail-open paths untouched:** Policies that do not opt into admission, and degraded / `FAIL_OPEN` paths of any policy, remain unchanged and perform no admission backend reads. S0/NAT/UA posture is unchanged.

### Carried from DEC-027 (verbatim)

1. **Scope.** "Current production rate-limit enforcement" in the carried text means the enforcement of the policies in this
   record's scope. A policy that opts in leaves it for as long as its fleet fence is open.
2. **API anti-evasion.** `API_OVERUSE` cannot be combined with the admission capability (DEC-025 8.4, now DEC-034 8.4), so every API policy stays
   here.
3. **`checkOnly` limitations.** `pre` and `out` Hosts cannot opt in (DEC-023), so the carried `checkOnly` statements hold for
   them and for every policy in scope.
4. **Custom policies under DEC-008.** A custom policy that does not declare the admission capability stays here.
5. **Issues.** #97, #100 and #106 stay open for the policies in scope.
6. **Compatibility.** The carried Decision 4 keeps holding in scope: the policies that do not opt in give today's results,
   byte for byte (gate S0-PARITY of every 5-b stage and of PR #150).

*(Scope clarification from `Narrow G3 D7 Boundary` above: for a healthy opted policy while its fleet fence is closed, `checkOnly` and permit-less outcomes stay in this S0 scope, while explicit permit-bearing outcomes follow the D7 boundary above. Historical-status note (2026-11-27): issues #97, #100, and #106 were closed as `completed` on 2026-11-03 prior to DEC-050; the carried verbatim text above and below preserves the historical wording, while the underlying S0 shared-NAT/UA availability harm remains unresolved for policies in scope.)*

### Carried from DEC-019 (verbatim)

1. **Retain the shipped S0 behavior and existing public contracts for now.** The merge of
   the #103–#108 studies implies no mandate to change thresholds, scoring, correlation
   hard-block scope, WATCH semantics, TTL/rearm, trusted-session advisory/persistence, or
   score/Store behavior. `docs/DECISION_MATRIX.md`, `docs/POLICIES.md`,
   `docs/DEVICE_FINGERPRINT.md`, and `docs/KEY_STRATEGY.md` remain the authorities for
   runtime meaning.
2. **No automatic adoption of EQL, EQV, PSC, or the K1-off test control as production
   defaults.** They show measured attacker-leak regressions and are not Owner-approved
   replacement policies. Their investigation artifacts are evidence, not normative
   specifications.
3. **The demonstrated false-positive problems are not declared fixed or accepted as final
   behavior.** #97, #100, and #106 remain **OPEN**, with independently observed end-to-end
   K5/score sources as well. S0 is a **temporary security-preservation choice amid
   unresolved availability harm**; it is not a security/availability acceptance of the
   existing mechanism.
4. **Integration, source, and storage compatibility are preserved.** This decision requires
   no `src/`, Builder/DTO, or Policy interface change, no Redis/Store/key migration or
   flush, no mandatory new Host callback/signal, and no new dependency. Trust is not
   claimed from a LOW/MEDIUM client fingerprint or a raw `correlationId`. Actual
   server-established session trust and `correlationSubject = correlationId ?? accountId`
   are existing documented boundaries, not new Host requirements authorized here.
5. **Scoped future changes require new, explicit Owner decision(s)** that quantify fairness
   and attacker attempts reaching verification, including `checkOnly`-only / record-only
   guarantees, K1+K2 overlap, IPv6 macro, Current/Previous rotation, trusted/nontrusted
   collateral, block renewal, and cap / N−1 risks. Any future material policy is recorded
   in new ADR(s) with index and canonical deltas **before or atomically with** a separately
   authorized, runtime-reviewed PR. DEC-019 does not pre-approve any replacement or
   classification heuristic.
6. **Exit from this interim posture** requires a future Owner-approved replacement material
   decision, its durable ADR and canonical contract, and separately proven and reviewed
   Runtime behavior. There is no deadline, automatic expiration, or automatic supersession.
   This ADR can be superseded formally under Decision Governance §9; an incompatible ACTIVE
   replacement must not silently coexist with it.

## Rationale

Routing explicit permit-bearing outcomes of a healthy opted policy through the admission claim path even after fence closure ensures two properties simultaneously: (a) an in-flight admission issued immediately before a fence closure can still settle cleanly once before `D` (`30 s`, with `G = 600 s` retaining the record for `permit_late` / `permit_duplicate` / `permit_conflict` classification per `DEC-035`), and (b) stale, v1, foreign, or forged permits can never bypass permit validation into S0 scoring simply because a fence is closed. Carrying `DEC-019` and `DEC-027` verbatim preserves the entire interim S0 posture for non-opted policies and permit-less closed-fence traffic without partial supersession.

## Consequences

- All S0 consequences of `DEC-019` and `DEC-027` remain unchanged for non-opted policies and permit-less closed-fence requests.
- Closing a fleet fence stops new admission reservations immediately while allowing in-flight valid permits to settle before `D` (`30 s`) and rejecting late (`D .. D+G`), invalid, old, or foreign permits without S0 scoring.
- Non-opted policies and degraded/fail-open paths incur zero additional backend calls.

## Relationship to Other Decisions

- **Supersedes `DEC-027`** in full (Decision Governance Standard §9), preserving `DEC-019` → `DEC-027` → `DEC-050` chain without cycle.
- **Paired with `DEC-048` and `DEC-049`.**
- **Preserves `DEC-022`, `DEC-023`, `DEC-024`, `DEC-026`, `DEC-034`, `DEC-035`, `DEC-036`, `DEC-037`, `DEC-003`, `DEC-008`, `DEC-015`, `DEC-046`, `DEC-047`.**

## Canonical Contract / Current Owner

`RateLimiterEngine`, `docs/DECISION_MATRIX.md` §5, `docs/POLICIES.md`, `docs/DEVICE_FINGERPRINT.md`, `docs/KEY_STRATEGY.md`, `docs/guides/USAGE_GUIDE.md`.

## Supersedes

`DEC-027`

## Superseded By

None.
