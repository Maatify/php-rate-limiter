# DEC-023 — Host Mode Contract for `checkOnly` and Mode Eligibility of New Admission Policies (#97)

## Decision ID

`DEC-023`

## Status

`PROPOSED`

This is a draft for the Owner's choice. **No option below is selected.** Under Decision Governance §5 a `PROPOSED`
decision is **not implementation authority**: no Runtime, contract, preset, threshold, test, study gate or review verdict may
rely on it. It does not change the status, text or effect of DEC-019, DEC-021 or DEC-022. After the Owner chooses, a later PR
records the choice: the status, the Decision text and the index line change together in that PR.

## Date

2026-10-19

## Decision Authority / Deciders

**Owner.** This record prepares step 2 of the Owner's five-step plan for #97 (the plan set out after the merge of PR #132;
step 1 is DEC-022, PR #133). It is documentation only and records no Owner approval.

## Scope / Concern

- What the library guarantees to a Host in each integration mode, with the repository's terms: `pre+out` (`checkOnly`, then
  `recordFailure` / `recordSuccess`), `pre` (checkOnly-only), and `out` (record-only).
- Which modes may be offered FULL2 (#132) and any other policy whose guarantees need refunds or recorded outcomes.
- Whether `checkOnly` may take an atomic admission reservation for policies that opt in (tier T0), and under which
  store-safety conditions.

Out of scope: every value (`U_src`, `F`, `T`, `C`, budgets; step 4), the binding design, K5 material and the DEC-021 bounds
(step 3; named here only where they change what a mode can get), and Runtime (step 5).

## Context

DEC-019 Decision 5 requires any change to the S0 posture to quantify the `checkOnly`-only and record-only guarantees.
DEC-022 (`PROPOSED`, not approved) names this decision as step 2 in its Condition 1. The evidence is collected, per mode and per guarantee, in the decision
paper
[`investigations/k2-identity-admission-97/HOST_MODE_CONTRACT_DECISION.md`](../../investigations/k2-identity-admission-97/HOST_MODE_CONTRACT_DECISION.md).
Its matrix, in short:

| Mode | Source ceiling | Net failure cap | Per-account bound | Bucket refund | P door and budget | P refund | Known-device routing ² | Binding, owner side |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `pre+out` | GUARANTEED | GUARANTEED | GUARANTEED | GUARANTEED | CONDITIONAL | GUARANTEED | CONDITIONAL | GUARANTEED |
| `pre` | GUARANTEED (outer ceiling only) | NOT AVAILABLE | NOT MEASURED (NM-9) | NOT AVAILABLE | NOT MEASURED (NM-1) | NOT AVAILABLE ¹ | CONDITIONAL | NOT MEASURED (NM-2) |
| `out` | NOT AVAILABLE | NOT AVAILABLE | NOT AVAILABLE | NOT AVAILABLE | NOT AVAILABLE | NOT AVAILABLE | NOT AVAILABLE | NOT MEASURED (NM-3) |

¹ In `pre` the P budget and the P refund exclude each other: with refunds L1 72; with `P-NETF` L1 0, but a stolen token
has no P budget except `N_P` (#132 §5.5). ² Not part of FULL2; measured on the G3 v2 `TBK` arms (#130). The conditions,
the evidence for every cell and the gates NM-1 … NM-10 are in the paper (§3–§5).

Three facts from that evidence shape the options:

1. **`out` gets no pre-verifier bound from any design.** Every decision arrives after the verifier (#113: record-only has
   full reach on every stream, e.g. `TMSd` 200/200; `ARCHITECTURE_PROPOSAL.md` §6.4). The same is true of S0 today (#108: 0 spray observations in `out`).
2. **`pre` gets what a reservation alone can give, and nothing that needs an outcome.** The net failure cap is inert
   (#130), refunds cannot happen (#130: L1 122/134, D2 61), gate P fails (#132: L1 72, D2 61), and the DEC-021 bound
   never learns (#119: `D24K1` 1440/1440).
3. **Every admission bound is a reservation inside `checkOnly`.** That is a material behavioural change of `checkOnly`
   for the policies that enable it, with no type-signature change (`ARCHITECTURE_PROPOSAL.md` §6.5, GA-5). The new
   reservation state needs integrity P2: no reservation write before the decision, previous generation read-only
   (`G1_FINAL_DISPOSITION.md` §3). The bounded observation writes `checkOnly` already makes in S0 are a separate matter
   (C-c2).

## Decision

**Proposed, not approved. Owner selection: pending.**

The Owner chooses **at most one of A or B** (B contains A) and, **separately, yes or no on C** with its conditions.
Choosing none leaves this record open to `REJECTED` or `DEFERRED` under Decision Governance §5, and S0 stays under
DEC-019.

**Dependency between the questions.** A and B decide which modes may be offered a capability such as FULL2. C decides
whether the admission layer that such a capability needs may exist at all. If C is not approved, A or B still fixes the
eligibility contract, but no admission component (source ceiling, failure cap, bucket, P budgets) has a permitted
implementation, in any mode (paper §2 C-1).

**What no option approves.** Selecting A, B or C approves neither FULL2, nor any DEC-022 Level 2 or Level 3 decision, nor any
value. It fixes only the contract a mode would be offered if such a capability is approved later.

### Option A — Two separate contracts

If selected, this record would state:

1. The current `checkOnly` / `recordFailure` / `recordSuccess` contract stays exactly as it is, for every mode and every
   existing policy. No default changes implicitly.
2. FULL2, and any policy whose guarantees depend on refunds or recorded outcomes, is a **new capability**, offered to `pre+out`
   Hosts only and declared **not available** to `pre` and `out` Hosts. The DEC-008 capability model carries that per-mode
   statement.
3. The library cannot see a Host's mode from today's inputs: `RateLimiterBuilder::withPolicy()` takes no mode, and the intent
   is carried per call by `RateLimitCommand`. How "not available" is enforced is therefore a sub-question for the Owner
   within option A:
   - **A-1, contract statement only.** Eligibility is stated in the capability documentation. A `pre` or `out` Host that
     enables the capability anyway is outside the contract and gets the matching matrix row. For a `pre` Host that means
     losing the S0 protection kept for it, with only the outer ceiling in its place (SP6 144 at `U_src` 24, #132) and the
     measured costs L1 72, D2 61; this is the outcome R-3 below rejects as a supported behaviour.
   - **A-2, enforces the declared eligibility.** A new additive declaration lets the library refuse or ignore the capability
     for a Host that has not declared `pre+out`. It does nothing more. Its form is not designed here; it would be an additive
     Public API surface.

     **A declaration is not evidence that outcomes are complete.** It does not prove that the Host actually calls
     `recordFailure` and `recordSuccess` after every attempt. A Host that declares `pre+out` but does not record its outcomes
     runs, for those attempts, as `pre`: the net failure cap is inert, nothing is refunded, and gate P fails (#130, #132).
     This trust boundary, **outcome completeness (missing or late outcomes)**, is `UNPROVEN`, and the declaration does not
     resolve it. It is the open item NM-10 (paper §5).

| Item | Effect of A (from existing evidence) |
| --- | --- |
| Legitimate users | `pre+out` Hosts that opt in: every benign stream at or below S0 (#132 A1 PASS): D1 596 → 0, D2 117 → 24, ABA_B 118 → 0, L3 104 → 0, MIX 117 → 54. `pre` and `out` Hosts, and every Host that does not opt in: S0 unchanged, including its harm (trusted office `N12T` denied 55/61 in `pre` and in `out`, #113) |
| Attacker reach | `pre+out` opt-in, at study values (#132, S0 in brackets): SP6 24 (20), SP3 8 (7), SP1n 4 (4), ABA_A 8 (3), POI 96 (80), MIX attack 8 (0), X1 / BRU / BRD 4 / 5 / 5 (2 / 3 / 3). The S0 K2 bound against valid sessions for several accounts (`TMSd` 2/200, #113) was not measured on FULL2; DEC-022 (`PROPOSED`, not approved) proposes to keep those cells as gates. `pre` and `out`: S0 unchanged |
| DEC-008 | Reuses the typed capability model: a new capability plus the Store capability it needs, failing fast at registration without it (`ARCHITECTURE_PROPOSAL.md` §10, DEC-007 precedent). The official names, the existing capabilities and every custom policy that does not opt in keep their behaviour (DEC-008 Backward Compatibility 1–4). The per-mode eligibility is stated in `docs/POLICIES.md` by the decision that ships the capability |
| DEC-015 | Unchanged. Admission bounds do not hold in degraded mode (`ARCHITECTURE_PROPOSAL.md` §10, Owner decision D6). The fallback is one code path for every command but counts calls, so per mode: a `pre+out` attempt uses two fallback units, a `pre` attempt one, and in `out` the fallback decision arrives after the verifier, with no pre-verifier bound (paper §2 C-7) |
| DEC-021 | Unchanged in every mode. Its statement that check-only (`pre`) Hosts report no failures, so its bound cannot learn from them, stays true. K5 separation and the allowance coupling are step 3 |
| Public API | No change to any existing method signature or command, and no change to the meaning of an existing case. Additive public surfaces: a new case on the existing public `PolicyCapabilityEnum`, a new Store capability interface (`ARCHITECTURE_PROPOSAL.md` §10), new values in the existing `RateLimitMetadataDTO::$cause` string (§6.5), and, under A-2 only, the eligibility declaration. Optional context inputs (instance id, token) are step 3 |
| New measurement | Not needed to take A. The `pre+out` cells are measured within the studies' scope; A claims nothing in the cells marked NOT MEASURED, so NM-1 … NM-3 and NM-9 are not preconditions. Outcome completeness under A-2 (NM-10) is an open item for step 5 or a separate study, not a precondition of the choice. Runtime proof is step 5 |

### Option B — Option A plus a separate preventive contract for `pre`

If selected, this record would state everything in A, plus:

1. A commitment to design a separate preventive contract for `pre` Hosts, with its own security guarantees, as a later study
   with its own scope and its own authorisation.
2. That contract does **not** exempt known devices from their budgets. The evidence for this condition: when the Host marks
   the attacker as known, a known-device route around the bucket lets all 5,760 BRU24 attempts reach the verifier (#130), and
   in `pre` the DEC-021 bound cannot take over (`D24K1` 1440/1440, #119).
3. The study starts from paper §6: what a reservation alone can guarantee for `pre`, what is impossible without an
   additional information source that carries outcomes, and the open question NM-8.

| Item | Effect of B (from existing evidence) |
| --- | --- |
| Legitimate users | Now: as A. For the later `pre` contract, the measured trade-offs (#132 `pre` table): a bucket gives BRU 5 with D2 61; a fixed window gives D2 24 with BRU 80 and X1 17. A P budget without refund costs L1 72 and KD / UAC 4 / 4; `P-NETF` removes both in `pre` (L1 0, KD / UAC 0 / 0) but leaves a stolen token only `N_P` (paper §6). Routing around the bucket, which relieved the bucket's KD / UAC cost on the U door in `pre` (8 / 8 → 0 / 0, #129 / #130), is excluded by condition 2 |
| Attacker reach | Now: as A. For a `pre` contract, single-source spray is bounded only by the source ceiling: SP6 144 at `U_src` 24, 240 at 40 (#132). S0's K1 spray protection gives SP6 20 in `pre+out` (#108 §3), and `pre` makes the same spray observations (#108 §5), so the same protection in `pre` is `DERIVED`. A `pre` contract that replaced it with the source ceiling alone would be a regression against that figure unless it kept an equivalent |
| DEC-008 | Now: as A. Later: a second capability or variant, only if the study supports one |
| DEC-015 | Now: as A. The `pre` contract must state its own degraded behaviour. The fallback code path is the same for every command; its per-mode effect is as in A (paper §2 C-7) |
| DEC-021 | Unchanged. A `pre` contract cannot rely on K5 learning, because nothing is recorded |
| Public API | None now. If the study finds that an outcome-carrying input is needed (paper §6), that input would be additive and decided then |
| New measurement | Yes: the separately authorised `pre` study, with at least NM-1, NM-2, NM-8 and NM-9 (paper §5) |

### Option C — Opt-in reservation inside `checkOnly` (tier T0)

A separate yes or no: may `checkOnly` take an irreversible, all-or-nothing admission reservation for the policies that opt
in, and under which store-safety conditions. The conditions below come from the evidence. The Owner accepts, amends or
refuses each one; none is accepted here.

| # | Condition | Source |
| --- | --- | --- |
| C-c1 | Opt-in only, through a typed capability. The official default names and every custom policy that does not opt in keep today's `checkOnly`. Nothing switches the defaults implicitly | `ARCHITECTURE_PROPOSAL.md` §6.5 and LT-2; DEC-008 Backward Compatibility |
| C-c2 | Applies to the T0 admission-reservation writes only: all-or-nothing across every reserved scope; on a T0 refusal no part of the reservation state is written; the previous generation stays read-only (C-c3). It does not forbid the observation writes `checkOnly` already makes in S0, where it is not globally read-only: it records bounded correlation and WATCH observations (`docs/DECISION_MATRIX.md` §5.0, §5.1; `docs/guides/USAGE_GUIDE.md`). The condition covers the new reservation state only, as the DEC-015 row below also states | `G1_FINAL_DISPOSITION.md` §3 P2; `ARCHITECTURE_PROPOSAL.md` §6.2 |
| C-c3 | The previous generation is read-only, and the secret-rotation interval is at least the window | `G1_FINAL_DISPOSITION.md` §3 P2; `ARCHITECTURE_PROPOSAL.md` §10, migration step 5 |
| C-c4 | Registration fails fast when the Store lacks the reservation capability | `ARCHITECTURE_PROPOSAL.md` §10; DEC-007 precedent |
| C-c5 | The new Store operation reports outages through the typed backend-failure contract | DEC-015 |
| C-c6 | Dormant ship, then a fleet fence; bounds are `UNPROVEN` while the fleet is mixed | `ARCHITECTURE_PROPOSAL.md` §10, migration steps 1–2 |
| C-c7 | For every enabled policy, one `checkOnly` = one admission, stated in the usage guide | `ARCHITECTURE_PROPOSAL.md` §6.5 |

| Item | Effect of C (from existing evidence) |
| --- | --- |
| Legitimate users | Policies that opt in: a `checkOnly` used as a probe costs one admission; a `checkOnly` with no verifier call spends one with nothing to cancel; lost replies and double submits count (`ARCHITECTURE_PROPOSAL.md` §6.5, `DERIVED`, not measured: NM-5). Policies that do not opt in: no change |
| Attacker reach | C is the precondition of every pre-verifier admission bound in the matrix. Without it, S0 stays and no admission bound exists in any mode. Outcome-gated admission leaks per cycle as much as the burst's concurrency (#111); one all-or-nothing atomic reservation per request was exact under real concurrency (#116). `out` Hosts gain nothing, since they never call `checkOnly` |
| DEC-008 | A typed capability; Backward Compatibility 1–4 preserved |
| DEC-015 | `checkOnly` begins to reserve (consume) admission capacity for the opting policies. Today it reserves nothing, although it may already update bounded correlation state (`docs/guides/USAGE_GUIDE.md`; `docs/DECISION_MATRIX.md` §2.2.2). A typed backend failure during the reservation takes the existing typed path that DEC-021 describes for its writes; the admission bounds do not hold in fallback (paper §2 C-7) |
| DEC-021 | The outcome path is unchanged; the allowance is still consumed on `recordFailure` (`ARCHITECTURE_PROPOSAL.md` §6.5). Two DEC-021 statements would no longer hold for the opting policies: Decision 3 ("read-only pre-checks reserve nothing") and the Consequences ("Pre-checks are read-only, so concurrent in-flight attempts on one device are not serialized"). Activating C needs a note or amendment there, by its own decision |
| Public API | No method-signature change. A **material behavioural change** of `checkOnly` for the opting policies (GA-5). Additive public surfaces: the typed capability (a new `PolicyCapabilityEnum` case), the Store reservation capability interface (`ARCHITECTURE_PROPOSAL.md` §10), and new values in the existing `cause` string. The optional permit token (T1) is additive and not part of this option |
| New measurement | Yes: NM-5 (Host integration), NM-6 (production store integrity), NM-7 (concurrency, failover, mixed fleet, rollback); paper §5. Their place is step 5 or a separately authorised study |

### Written as rejected, not offered

These would change the current Public API or weaken a protection that is in force today. They are listed so the Owner
can see why they are not options.

| # | Rejected alternative | Reason |
| --- | --- | --- |
| R-1 | Turning the reservation on by default, or implicitly, for the official names or for custom policies that did not opt in | A silent behavioural change of the public `checkOnly`. It contradicts DEC-008 Backward Compatibility items 2 and 4 and `ARCHITECTURE_PROPOSAL.md` §6.5 ("Nothing switches the defaults implicitly"). The same section sets an explicit, versioned adoption by the default presets as the proposal's required end state; that explicit adoption is a later Owner decision and is outside the options of this record |
| R-2 | Requiring every Host to record outcomes, or changing the signatures or meaning of `checkOnly`, `recordFailure` or `recordSuccess` for existing policies | Modifies the current Public API and drops the `pre` and `out` usages it supports today |
| R-3 | Making FULL2's removal of collective penalties the supported or default behaviour for `pre` or `out` Hosts, or for policies that did not opt in | Weakens the S0 protection that DEC-019 keeps. In `pre`, the K2 cross-account bound (`TMSd` 2/200, #113) and the K1 spray protection (SP6 20 in `pre+out`, #108 §3; the same observations in `pre`, #108 §5) would be replaced at most by the outer source ceiling (SP6 144 at `U_src` 24, #132), with no net cap and no refund available. Under option A-1 a `pre` Host can still reach this state by enabling the capability outside the contract. A-2 prevents enabling it without a declaration; reaching the weaker behaviour through missing outcomes after a declaration stays open (A-2 above; NM-10, paper §5) |

## Rationale

- **Why options about modes, not values.** The evidence separates by mode before it separates by value: the same FULL2 passes
  every gate in `pre+out` and fails gate P in `pre` with identical parameters (#132). No value choice changes which inputs a
  mode gives the library.
- **Why A and B keep the current contract for everyone.** The `pre` and `out` rows lose most of FULL2's guarantees, and R-3
  shows the cost of moving them anyway. Keeping S0 for them preserves DEC-019's posture where no measured replacement exists.
- **Why C is asked on its own.** Whether `checkOnly` may reserve admission capacity is a property of the public command,
  independent of who may use a given capability (GA-5). It also carries its own unmeasured items (NM-5 … NM-7).
- **Why B is offered.** Under A, `pre` Hosts keep S0, harm included, and no later path for them is recorded. B is the option
  that records one. Whether such a contract is worth designing is the Owner's choice; paper §6 states what it could and could
  not deliver on the existing evidence.

## Consequences

**While `PROPOSED` (now):** no Runtime, contract, preset, threshold or test changes. DEC-019, DEC-021 and DEC-022 are
unchanged. #97, #100 and #106 stay open. DEC-022 Condition 1 stays open.

**When the Owner chooses:** a later PR records the selected option(s), with their conditions as accepted or amended, sets the
status, and updates the index in the same change (Decision Governance §6). Canonical contract updates (`docs/POLICIES.md`,
`docs/guides/USAGE_GUIDE.md`, `docs/DECISION_MATRIX.md`) follow Decision Governance §3: before or together with the
implementation that relies on them. The declared costs of each option are the ones in its table above.

## Evidence

Every number is quoted from the merged source named. None is new. The per-cell evidence is in the decision paper (§4).

| Source | Used for | Class |
| --- | --- | --- |
| DEC-019 Decision 5 | The `checkOnly`-only / record-only guarantees as a condition of any change | Decision record |
| DEC-021 Consequences | Record-only Hosts gain decisions but no verifier-reach reduction; check-only Hosts report no failures | Decision record |
| DEC-022 Condition 1 | This decision as step 2 | Decision record (`PROPOSED`) |
| #108 `k1-credential-spray-nat/` | K1 spray: SP1n 4, SP3 7, SP6 20 in `pre+out`; identical observations in `pre`; none in `out` (200/200, 1000/1000) | `MEASURED` |
| #111 `nat-remediation-110/` | Outcome-gated admission leaks per cycle as much as the burst's concurrency | `MEASURED` |
| #113 `trusted-multi-session-s0/` | `TMSd` 2/200 in `pre+out` and `pre`, 200/200 in `out`; `TMX` 200/200; `N12T` 56 / 55 / 55 of 61 | `MEASURED` |
| #116 `outcome-aware-atomic-admission-110/` | One all-or-nothing atomic reservation per request is exact under real concurrency | `MEASURED` |
| #119 `account-scoped-known-device-relief-110/` | `D24K1` `pre` 1440/1440, `KS1` `pre` 200/200 (`[MAIN]`); relief `TMS` 7 → 61, `TMSd` 2 → 60 | `MEASURED` (`[MAIN]`); `MODEL` (relief) |
| #126 `k2-identity-admission-97/` (G1) | Store integrity P2 (original store fails T1-N4b / N5, fixed study store passes); operational envelope P5 | `MEASURED` (P2); `UNPROVEN` (P5) |
| #129 / #130 `k2-identity-admission-97/` (G3, G3 v2) | Net cap inert in `pre`; no refund in `pre` (L1 122/134, D2 61); `out` reach (SP6 1200, BRU 100); Host violation 5,760/5,760 | `MEASURED` |
| #132 `k2-identity-admission-97/` (G4 v2) | FULL2 `pre+out` gates; gate P FAIL (L1 72, D2 61); `P-NETF`; `pre` table | `MEASURED`; `MODEL` (C6) |
| `ARCHITECTURE_PROPOSAL.md` §6.2, §6.4, §6.5, §10, GA-5, LT-2 | T0 as a material behavioural change; modes; migration | `DERIVED` (proposal text) |

## Relationship to Other Decisions

- **DEC-019:** unchanged and `ACTIVE`. This record answers the `checkOnly`-only / record-only item of its Decision 5 for
  FULL2 and its components; it is not a replacement of DEC-019.
- **DEC-021:** unchanged and `ACTIVE`.
- **DEC-022:** unchanged and `PROPOSED`. This record is its Condition 1. A selection here does not activate DEC-022.
- **DEC-008, DEC-015:** unchanged. Each option states its effect on them.
- **DEC-003, DEC-007:** unchanged; not affected by any option.

## Canonical Contract / Current Owner

None while `PROPOSED`. The current `checkOnly` and outcome semantics stay with `RateLimitCommand`,
`docs/guides/USAGE_GUIDE.md` and `docs/DECISION_MATRIX.md`, under DEC-019 and DEC-021.

## Supersedes

None

## Superseded By

None
