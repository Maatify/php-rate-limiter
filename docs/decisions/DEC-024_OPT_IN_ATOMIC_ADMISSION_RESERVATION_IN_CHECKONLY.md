# DEC-024 — Opt-in Atomic Admission Reservation in `checkOnly` (Tier T0), as a Principle (#97)

## Decision ID

`DEC-024`

## Status

`ACTIVE` — as a principle, on the Owner's decision of 2026-10-19.

It closes the choice of whether `checkOnly` may take an admission reservation (Decision Governance §8). It is **not** an
authorisation to write production code: Decision point 3 lists what must happen first. It approves neither FULL2, nor any
value, nor any API shape.

## Date

2026-10-19

## Decision Authority / Deciders

**Owner.** Step 2 of the Owner's five-step plan for #97. This decision was option C of DEC-023 as prepared in PR #134. On
2026-10-19 the Owner approved it as a principle, with the seven conditions C-c1 … C-c7 as written there (C-c2 in its amended
form), and with NM-5, NM-6, NM-7 and a separate Runtime authorisation before any production code. The decision is recorded on
GitHub in [PR #134 comment 6256657121](https://github.com/Maatify/php-rate-limiter/pull/134#issuecomment-6256657121), posted by the Lead on the Owner's behalf after the Owner's explicit approval.
It is a contract and capability decision only: not an approval of FULL2, of any value, of any Runtime, or of a Public API
change. On the same day the Owner chose how this record relates to DEC-021: DEC-021 is not touched, and the supersession of
DEC-021 is a binding part of the first activation (Decision point 4). In the Owner's assignment for recording this decision
(2026-10-19, quoted in the description of the PR that adds this record), an in-place edit of DEC-021 was rejected under
Decision Governance §9, and a supersession of DEC-021 in this step was deferred to step 5.

Technical review: the independent Lead reviews of PR #134
([comment 6255448192](https://github.com/Maatify/php-rate-limiter/pull/134#issuecomment-6255448192),
[comment 6256342944](https://github.com/Maatify/php-rate-limiter/pull/134#issuecomment-6256342944)).

## Scope / Concern

- Whether `checkOnly` may take an irreversible, all-or-nothing admission reservation (tier T0) for the policies that opt in,
  and the store-safety conditions it must meet.
- How this principle relates to DEC-021 until, and at, its first activation.

Out of scope: which Host modes may use a capability built on it (DEC-023); every value; any API or Store interface shape;
the optional permit token (T1); and Runtime (step 5).

## Context

Every admission bound measured for #97 (source ceiling, net failure cap, per-account bucket, P budgets) is a reservation
taken at `checkOnly` (`ARCHITECTURE_PROPOSAL.md` §6.2). Today `checkOnly` reserves nothing, although it is not globally
read-only: it records bounded correlation and WATCH observations (`docs/DECISION_MATRIX.md` §2.2.2, §5.0, §5.1;
`docs/guides/USAGE_GUIDE.md`). A reservation taken there needs no type-signature change, but it is a **material behavioural
change** of `checkOnly` for the policies that enable it (`ARCHITECTURE_PROPOSAL.md` §6.5, GA-5). Outcome-gated admission
leaks per cycle as much as the burst's concurrency (#111); one all-or-nothing atomic reservation per request was exact under
real concurrency (#116). The study store integrity rule P2 was met only by the fixed study store; the original study Redis
store failed it (`G1_FINAL_DISPOSITION.md` §3). The per-mode evidence is in
[`investigations/k2-identity-admission-97/HOST_MODE_CONTRACT_DECISION.md`](../../investigations/k2-identity-admission-97/HOST_MODE_CONTRACT_DECISION.md).

## Decision

1. **Principle.** `checkOnly` may take an irreversible, all-or-nothing admission reservation, **only** for the policies that
   opt in. For every other policy `checkOnly` keeps today's behaviour.
2. **Binding conditions.** Every implementation **MUST** meet all seven conditions:

   | # | Condition | Source |
   | --- | --- | --- |
   | C-c1 | Opt-in only, through a typed capability. The official default names and every custom policy that does not opt in keep today's `checkOnly`. Nothing switches the defaults implicitly | `ARCHITECTURE_PROPOSAL.md` §6.5 and LT-2; DEC-008 Backward Compatibility |
   | C-c2 | Applies to the T0 admission-reservation writes only: all-or-nothing across every reserved scope; on a T0 refusal no part of the reservation state is written; the previous generation stays read-only (C-c3). It does not forbid the observation writes `checkOnly` already makes in S0, where it is not globally read-only: it records bounded correlation and WATCH observations (`docs/DECISION_MATRIX.md` §5.0, §5.1; `docs/guides/USAGE_GUIDE.md`). The condition covers the new reservation state only, as the DEC-015 row under Consequences also states | `G1_FINAL_DISPOSITION.md` §3 P2; `ARCHITECTURE_PROPOSAL.md` §6.2 |
   | C-c3 | The previous generation is read-only, and the secret-rotation interval is at least the window | `G1_FINAL_DISPOSITION.md` §3 P2; `ARCHITECTURE_PROPOSAL.md` §10, migration step 5 |
   | C-c4 | Registration fails fast when the Store lacks the reservation capability | `ARCHITECTURE_PROPOSAL.md` §10; DEC-007 precedent |
   | C-c5 | The new Store operation reports outages through the typed backend-failure contract | DEC-015 |
   | C-c6 | Dormant ship, then a fleet fence; bounds are `UNPROVEN` while the fleet is mixed | `ARCHITECTURE_PROPOSAL.md` §10, migration steps 1–2 |
   | C-c7 | For every enabled policy, one `checkOnly` = one admission, stated in the usage guide | `ARCHITECTURE_PROPOSAL.md` §6.5 |

3. **Preconditions before any production code.** Both of the following, as the Owner set them:
   - the gates NM-5 (Host integration), NM-6 (production store integrity) and NM-7 (concurrency, failover, mixed fleet,
     rollback), as defined in the decision paper §5, which places them in step 5;
   - a separate Runtime authorisation in step 5.

   This record is not that authorisation.
4. **DEC-021 supersession is part of the first activation (binding).** The first activation of this principle is the step-5
   change that lets any policy opt in. That change **MUST** include, in the same PR, a new decision record that supersedes
   DEC-021 **entirely** under Decision Governance §9 (the new record `ACTIVE`, DEC-021 `SUPERSEDED`). The canonical contracts
   and the code references to DEC-021 are updated in that same change (Decision Governance §3, §9). No policy may opt in before
   that record is `ACTIVE`.

### Not adopted

- **R-1, turning the reservation on by default, or implicitly, for the official names or for custom policies that did not
  opt in.** Rejected: a silent behavioural change of the public `checkOnly`. It contradicts DEC-008 Backward Compatibility
  items 2 and 4 and `ARCHITECTURE_PROPOSAL.md` §6.5 ("Nothing switches the defaults implicitly"). The same section sets an
  explicit, versioned adoption by the default presets as the proposal's required end state; that explicit adoption is a later
  Owner decision and is outside this record.
- **An in-place edit of DEC-021** to scope its two pre-check statements. Rejected: Decision Governance §9 changes an `ACTIVE`
  decision only through a new record that supersedes it.
- **Superseding DEC-021 now.** Deferred to the first activation (point 4). In this step it would revise a record for a
  capability that does not exist yet, and the canonical contracts and code that reference DEC-021 are outside this step.

## Relationship to DEC-021

1. **Its two statements stay accurate now.** DEC-021 Decision 3 ("read-only pre-checks reserve nothing") and its
   Consequences ("Pre-checks are read-only, so concurrent in-flight attempts on one device are not serialized") remain true
   for the state DEC-021 governs (its allowance and K5), because no policy can opt into a reservation before step 5: the
   capability does not exist in the code. (`checkOnly` as a whole is not globally read-only; see Context.)
2. **No overlap until the first activation.** This record applies only to policies that opt in, and Decision point 4 forbids
   any opt-in before a record superseding DEC-021 is `ACTIVE`. Until then no policy can fall under both records, so no two
   `ACTIVE` decisions conflict on the same scope (Decision Governance §6).
3. **Supersession at the first activation.** Decision point 4 binds the first activation to a record that supersedes DEC-021
   entirely, in the same PR.

DEC-021 is not edited by this record, in text or in status.

## Rationale

- **Why a reservation at all.** Every pre-verifier admission bound in the decision paper is a reservation at `checkOnly`;
  outcome-gated admission leaks per cycle as much as the burst's concurrency (#111), while an all-or-nothing reservation was
  exact under real concurrency (#116).
- **Why opt-in only.** It is a material behavioural change of a public command (GA-5). Opt-in keeps every existing policy and
  default as it is (DEC-008 Backward Compatibility).
- **Why a principle without implementation authority.** The integration costs (NM-5), the production store integrity (NM-6)
  and the operational envelope (NM-7) are not measured; P5 is `UNPROVEN` (`G1_FINAL_DISPOSITION.md` §3).
- **Why DEC-021 is handled at the first activation.** Decision Governance §9 allows no in-place edit and no partial
  supersession. The statements DEC-021 makes stay accurate until a policy can opt in, and the full supersession belongs with
  the Runtime and canonical updates that make them inaccurate.

## Consequences

Declared costs and effects, from existing evidence:

| Item | Effect |
| --- | --- |
| Legitimate users | Policies that opt in: a `checkOnly` used as a probe costs one admission; a `checkOnly` with no verifier call spends one with nothing to cancel; lost replies and double submits count (`ARCHITECTURE_PROPOSAL.md` §6.5, `DERIVED`, not measured: NM-5). Policies that do not opt in: no change |
| Attacker reach | This principle is the precondition of every pre-verifier admission bound in the decision paper's matrix. `out` Hosts gain nothing, since they never call `checkOnly` |
| DEC-008 | A typed capability; Backward Compatibility 1–4 preserved |
| DEC-015 | `checkOnly` begins to reserve (consume) admission capacity for the opting policies. Today it reserves nothing, although it may already update bounded correlation state (`docs/guides/USAGE_GUIDE.md`; `docs/DECISION_MATRIX.md` §2.2.2). A typed backend failure during the reservation takes the existing typed path that DEC-021 describes for its writes; the admission bounds do not hold in fallback (decision paper §2 C-7) |
| DEC-021 | The outcome path is unchanged; the allowance is still consumed on `recordFailure` (`ARCHITECTURE_PROPOSAL.md` §6.5). Its two pre-check statements are handled by Decision point 4 and [Relationship to DEC-021](#relationship-to-dec-021) |
| Public API | No method-signature change. A **material behavioural change** of `checkOnly` for the opting policies (GA-5). Additive public surfaces when it ships: the typed capability (a new `PolicyCapabilityEnum` case), the Store reservation capability interface (`ARCHITECTURE_PROPOSAL.md` §10), and new values in the existing `cause` string. Their shapes are not designed here. The optional permit token (T1) is additive and not part of this decision |

**Now:** no Runtime, contract, preset, threshold, test or canonical document changes. The canonical contracts
(`docs/guides/USAGE_GUIDE.md` for C-c7, `docs/DECISION_MATRIX.md`, `docs/POLICIES.md`) are updated with the implementation
(Decision Governance §3). DEC-019, DEC-021 and DEC-022 (still `PROPOSED`) are unchanged.

## Evidence

| Source | Used for | Class |
| --- | --- | --- |
| #111 `nat-remediation-110/` | Outcome-gated admission leaks per cycle as much as the burst's concurrency | `MEASURED` |
| #116 `outcome-aware-atomic-admission-110/` | One all-or-nothing atomic reservation per request is exact under real concurrency | `MEASURED` |
| #126 `k2-identity-admission-97/` (G1) | Store integrity P2 (original store fails T1-N4b / N5, fixed study store passes); operational envelope P5 | `MEASURED` (P2); `UNPROVEN` (P5) |
| `ARCHITECTURE_PROPOSAL.md` §6.2, §6.5, §10, GA-5, LT-2 | T0 as a material behavioural change; migration and store conditions | `DERIVED` (proposal text) |
| `HOST_MODE_CONTRACT_DECISION.md` §2, §5 | C-7 (degraded mode per mode); NM-5, NM-6, NM-7 | Decision paper |

## Relationship to Other Decisions

- **DEC-023:** the companion decision on mode eligibility. Its admission components need this principle; this principle
  does not depend on it.
- **DEC-021:** unchanged and `ACTIVE`; see [Relationship to DEC-021](#relationship-to-dec-021) and Decision point 4.
- **DEC-019:** unchanged and `ACTIVE`.
- **DEC-022:** unchanged and `PROPOSED`. DEC-023 and this record decide its Condition 1.
- **DEC-008, DEC-015:** unchanged; effects as stated under Consequences.
- **DEC-003, DEC-007:** unchanged; not affected.

## Canonical Contract / Current Owner

None until step 5. The current `checkOnly` semantics stay with `RateLimitCommand`, `docs/guides/USAGE_GUIDE.md` and
`docs/DECISION_MATRIX.md`, under DEC-019 and DEC-021.

## Supersedes

None

## Superseded By

None
