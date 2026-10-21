# DEC-027 — Retained Correlation Posture for Policies That Do Not Opt Into Admission (successor of DEC-019)

## Decision ID

`DEC-027`

The sections carried from DEC-019 are reproduced byte for byte from
`DEC-019_INTERIM_SECURITY_FIRST_CORRELATION_POSTURE.md`; inside them "this decision" and "this record" are the carried
decision.

## Status

`ACTIVE` — on the Owner's decisions of 2026-10-20 (OD-6 (a) and OD-9 (a)), delivered atomically in PR #150 with DEC-022 becoming
`ACTIVE` and DEC-026.

## Date

2026-10-20

## Decision Authority

**Owner.** OD-6 (a): a successor record supersedes DEC-019 and restates its posture verbatim for the policies that do not opt
in; DEC-019 becomes `SUPERSEDED`; #97, #100 and #106 stay open for those policies. OD-9 (a): DEC-022's scope at activation is
the policies that opt in under DEC-024, and the others stay under this record. Both in
[PR #150 comment 6299051848](https://github.com/Maatify/php-rate-limiter/pull/150#issuecomment-6299051848). Decision Governance
§9 has no partial supersession, and two `ACTIVE` records with conflicting rules in an overlapping scope are an unresolved
conflict (§6); this record is why DEC-019 can be superseded while its posture stays where no measured replacement applies.

**DEC-019's authority, quoted:**

Owner; approval stated in the project conversation on 2026-10-13 and handed off in
[Issue #97 comment 6138985838](https://github.com/Maatify/php-rate-limiter/issues/97#issuecomment-6138985838).
The Owner approved exactly this: *"Security-first as a temporary security direction;
prepare the normative decisions/ADR, and do not consider NAT problems resolved."*

This authority does **not** extend to: new Runtime policy; tolerating false positives as a
permanent goal; automatic acceptance of the exact 4-distinct + repeat WATCH / repeated
block cycles; any particular new classifier; or release/merge authority.

## Scope / Concern

DEC-019's scope, quoted below, for **every policy that does not declare `PolicyCapabilityEnum::ADMISSION_RESERVATION`**, and for
a policy that declares it **whenever the admission path is not taken** (its budget group's fleet fence is closed: the policy then
runs the path of a policy that did not opt in). For a policy that opts in, while its fence is open, the correlation posture is
DEC-022 (Level 1), DEC-025 (the bounds) and DEC-026, under DEC-023 and DEC-024.

**DEC-019's scope, quoted:**

Current production rate-limit enforcement of:

- K1 credential-spray detection,
- K2 fingerprint churn / dilution,
- LOW passive-UA cross-IP behavior,
- Login / OTP policies,
- `API_OVERUSE` and custom policies with the applicable DEC-008 capabilities,
- `checkOnly` integration limitations.

This ADR records a temporary **policy posture**. It is not a new algorithm and not a global
rule about all future deployments.

## Context

### Carried from DEC-019 (verbatim)

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

### Added at activation

DEC-019 Decision 6 sets the exit: an Owner-approved replacement material decision, its durable ADR and canonical contract, and
separately proven and reviewed Runtime. That exit now exists **for the policies that opt in** (DEC-022 Level 1, DEC-025 Level 2,
DEC-026 and the admission runtime, proven in stages 5-b1 … 5-b3b and reviewed by the Lead on each exact HEAD). It does not exist
for any other policy: DEC-023 R-3 rejects making FULL2's removal of collective penalties the behaviour of `pre` or `out` Hosts or
of policies that did not opt in, and no measured replacement exists for them.

## Decision

The carried Decisions 1 to 6 apply, unchanged, within this record's scope. Read with them:

1. **Scope.** "Current production rate-limit enforcement" in the carried text means the enforcement of the policies in this
   record's scope. A policy that opts in leaves it for as long as its fleet fence is open.
2. **API anti-evasion.** `API_OVERUSE` cannot be combined with the admission capability (DEC-025 8.4), so every API policy stays
   here.
3. **`checkOnly` limitations.** `pre` and `out` Hosts cannot opt in (DEC-023), so the carried `checkOnly` statements hold for
   them and for every policy in scope.
4. **Custom policies under DEC-008.** A custom policy that does not declare the admission capability stays here.
5. **Issues.** #97, #100 and #106 stay open for the policies in scope.
6. **Compatibility.** The carried Decision 4 keeps holding in scope: the policies that do not opt in give today's results,
   byte for byte (gate S0-PARITY of every 5-b stage and of PR #150).

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

### Carried from DEC-019 (verbatim)

The Owner prefers preserving measured protection against the modeled attackers while the
availability harm to shared-NAT users is still unresolved, over adopting a candidate whose
measured effect is to let attackers reach verification. The evidence above shows that no
tested candidate resolves both sides, so the choice is explicitly temporary.

### Added at activation

- **Why a successor record.** DEC-019 cannot stay `ACTIVE` unchanged (its Decision 1 would contradict the opted path) and
  cannot be edited in place (Decision Governance §9). Restating its posture inside DEC-022 would put two material decisions in
  one record; Decision Governance §4 defaults to one per record.
- **Why verbatim.** Nothing about the posture of the policies in scope changes; only the set of policies it covers does.

## Consequences

### Carried from DEC-019 (verbatim)

- S0 blocks continue to have **known unacceptable availability effects**. No production
  remediation happens under this record; existing safety against measured attacks remains
  until a later explicit risk decision.
- Not approved by this record: the current 4-distinct + repeat N−1 behavior as a desirable
  safety design; repeated 60s blocks over ~600s as the final user experience; trusted
  advisory's cross-client K1 state persistence; LOW cohort hard blocks as a
  fairness-compliant final default.
- Custom-policy compatibility remains governed by ACTIVE DEC-008.
- Closing this decision's documentation PR does not close #97, #100, or #106.

### Added at activation

- The measured harm stays for every policy in scope, as DEC-019 and DEC-023 record it: D1 596/600, D2 117/121, ABA_B 118/120,
  trusted office `N12T` 55/61 in `pre` and in `out`.
- A Host relieves it only by opting in under DEC-023 / DEC-024, which requires `pre+out`, the permit and the binding credential.

## Relationship to Other Decisions

### Carried from DEC-019 (verbatim)

Complements, and does **not** supersede, ACTIVE DEC-003 and DEC-008 or the canonical
correlation rules in `docs/DECISION_MATRIX.md` §5. All other ACTIVE decisions
(DEC-002, 003, 007, 008, 010–018) are preserved unchanged. No ADR is created for any
hypothetical remedy.

### Added at activation

Supersedes DEC-019. DEC-022 (`ACTIVE`, scope: opted policies) and this record divide DEC-019's former scope by the opt-in, so no
two `ACTIVE` records govern the same request. DEC-026 (which supersedes DEC-021) refers to this record for the policies that do
not opt in. DEC-023, DEC-024, DEC-025 and DEC-028 are unchanged by it.

## Canonical Contract / Current Owner

`docs/DECISION_MATRIX.md` §5, `docs/POLICIES.md`, `docs/DEVICE_FINGERPRINT.md` and `docs/KEY_STRATEGY.md`, for the policies in
scope (as DEC-019 names them); the interim-posture notes of `DECISION_MATRIX.md` §5 and `POLICIES.md` §5 name this record.

## Supersedes

`DEC-019`

## Superseded By

None
