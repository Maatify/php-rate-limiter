# DEC-019 — Interim Security-First Correlation Posture Pending Shared-NAT Remediation

## Decision ID

`DEC-019`

## Status

`SUPERSEDED` by DEC-027 on 2026-10-20 (PR #150). It was `ACTIVE` — for the **interim preserve-default posture only**, on the Owner's explicit 2026-10-13 authorization.

## Date

2026-10-13

## Decision Authority

Owner; approval stated in the project conversation on 2026-10-13 and handed off in
[Issue #97 comment 6138985838](https://github.com/Maatify/php-rate-limiter/issues/97#issuecomment-6138985838).
The Owner approved exactly this: *"Security-first as a temporary security direction;
prepare the normative decisions/ADR, and do not consider NAT problems resolved."*

This authority does **not** extend to: new Runtime policy; tolerating false positives as a
permanent goal; automatic acceptance of the exact 4-distinct + repeat WATCH / repeated
block cycles; any particular new classifier; or release/merge authority.

## Scope / Concern

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

The Owner prefers preserving measured protection against the modeled attackers while the
availability harm to shared-NAT users is still unresolved, over adopting a candidate whose
measured effect is to let attackers reach verification. The evidence above shows that no
tested candidate resolves both sides, so the choice is explicitly temporary.

## Consequences

- S0 blocks continue to have **known unacceptable availability effects**. No production
  remediation happens under this record; existing safety against measured attacks remains
  until a later explicit risk decision.
- Not approved by this record: the current 4-distinct + repeat N−1 behavior as a desirable
  safety design; repeated 60s blocks over ~600s as the final user experience; trusted
  advisory's cross-client K1 state persistence; LOW cohort hard blocks as a
  fairness-compliant final default.
- Custom-policy compatibility remains governed by ACTIVE DEC-008.
- Closing this decision's documentation PR does not close #97, #100, or #106.

## Relationship to Other Decisions

Complements, and does **not** supersede, ACTIVE DEC-003 and DEC-008 or the canonical
correlation rules in `docs/DECISION_MATRIX.md` §5. All other ACTIVE decisions
(DEC-002, 003, 007, 008, 010–018) are preserved unchanged. No ADR is created for any
hypothetical remedy.

## Canonical Contract / Current Owner

`docs/DECISION_MATRIX.md` §5, `docs/POLICIES.md`, `docs/DEVICE_FINGERPRINT.md`, and
`docs/KEY_STRATEGY.md` (unchanged runtime authorities).

## Supersedes

None

## Superseded By

`DEC-027`
