# DEC-025 — Level-2 Admission Budgets and Identity-Layer Cost Decisions for #97 (Options, No Selection)

## Decision ID

`DEC-025`

## Status

`PROPOSED`

This record carries the options of the Owner's step 4 and **selects none of them**. It has not been approved. Under Decision Governance §5 a `PROPOSED` decision is **not implementation authority**: no Runtime, contract, preset,
threshold, test, study gate or review verdict may rely on any option below as an approved rule. It may be cited as non-binding research context, provided the citation states that the choice has not been made. It does not change
the status, text or effect of DEC-015, DEC-019, DEC-021, DEC-022, DEC-023 or DEC-024.

## Date

2026-10-19

## Decision Authority / Deciders

**Owner.** Every value, security trade-off, activation, merge and release in this record is the Owner's decision. This record was prepared as step 4 of the Owner's five-step plan for #97 (DEC-022 Condition 3), from the Owner's
assignment of 2026-10-19, and is documentation only. It records no Owner choice.

How a choice is recorded, by precedent: the options of step 2 were merged as `PROPOSED` with none selected (PR #134), the Owner chose on 2026-10-19, and the choice was recorded on GitHub in
[PR #134 comment 6256657121](https://github.com/Maatify/php-rate-limiter/pull/134#issuecomment-6256657121), posted by the Lead on the Owner's behalf after the Owner's explicit approval, then in a recording PR (#135). Step 4 follows
that path: this record is the options; the choice will be the Owner's, recorded on the PR and in a later recording PR.

The evidence that this record's decisions rest on is the merged step-3 study, [PR #138](https://github.com/Maatify/php-rate-limiter/pull/138) (`f4851eb`, G5 v2, `SUPPORTED — LIMITED SCOPE`), which left four Owner decisions to step 4
(decisions 3, 4, 5 and 6 below), and the merged studies G3, G3 v2, G4 v2 named under [Evidence](#evidence), plus the registered measurement of this step (G6).

## Scope / Concern

The Level-2 decisions of DEC-022 (security decisions on cost) for the admission and identity layer measured for #97:

- the source budget of the strangers' door (`U_src`, the failure cap `F`) and where the single-IP spray line is set;
- the per-account guessing budget (`T`, `C`);
- the proof door's caps and the new-device allowance (`P_acct`, `P_proof`, `N_P`);
- the `K4P` rule at a password change; the known device on the strangers' door; the re-issue of credentials at a password change;
- the global ceiling question (`U_glob`) and multi-IP spray;
- the replacement bounds for what K2 gives against the multi-session and API families;
- the K5 micro-cap and known-device allowance under the new K5 material.

Out of scope: DEC-022 Levels 1 and 3 (the principle, the FULL2 assembly); the Host mode contract and the `checkOnly` reservation (DEC-023, DEC-024); the supersession of DEC-021 (DEC-024 binds it to the first activation); any API or Store
shape; Runtime (step 5).

## Context

DEC-022 (`PROPOSED`) names, in its Condition 3, an Owner choice of the budgets, "Each is a Level 2 security decision with its measured cost stated, including where the single-IP spray gate is set", and assumes no value. Steps 1 to 3 are closed:
DEC-022 `PROPOSED`; DEC-023 and DEC-024 `ACTIVE` (the mode contract and the opt-in reservation, as principles); the identity layer `SUPPORTED — LIMITED SCOPE` (G5 v2). The measured cost of every option, per decision, on the legitimate user
(`L1`, `D2`, `ABA_B`, MIX legitimate, HAR, UAC, KD) and on the attacker's reach (`SP6` / `SP3` / `SP1n`, `POI`, `BRU24`, `X1` / `BRU` / `BRD`, STEAL, `MULTITOK`), with its effect on DEC-021, DEC-015 and DEC-008 and its sources, is in the decision paper
[`investigations/k2-identity-admission-97/BUDGETS_DECISION.md`](../../investigations/k2-identity-admission-97/BUDGETS_DECISION.md). The one registered measurement of this step is
[`G6_BUDGETS_MEASUREMENT.md`](../../investigations/k2-identity-admission-97/G6_BUDGETS_MEASUREMENT.md).

## Decision

**Proposed, not approved; no option is selected.** Every value below is an **archive value of a merged study**, listed so that the Owner can choose a measured point and write it as a declared budget. None is a recommendation or a default.
The measured numbers per option are in the decision paper section named in brackets. An option marked *not measured* is listed so that it is visible, and cannot be chosen until its gate-shaped item (decision paper §11) has been measured.

1. **Source budget of the strangers' door** (`U_src`, `F`) [paper D1]. The options:
   1.0 keep S0's K1 credential-spray protection (no source ceiling).
   1.1 `U_src` 12, no cap. 1.2 `U_src` 24, no cap. 1.3 `U_src` 40, no cap.
   1.4 `U_src` 24 with a net failure cap `F` 4. 1.5 `U_src` 24 with a net `F` 8.
   1.6 `U_src` 40 with a net `F` 4. 1.7 `U_src` 40 with a net `F` 8.
   1.8 `U_src` 40 with a gross `F` 4. 1.9 `U_src` 40 with a gross `F` 8.
   The choice also states where the single-IP spray line is set.
2. **Per-account bucket** [paper D2]. The options (capacity `C` 4, refund on success): 2.1 `T` 10 minutes; 2.2 `T` 30 minutes; 2.3 `T` 60 minutes. `C` other than 4 is *not measured*.
3. **Proof-door caps and the new-device allowance** [paper D3]. 3.1 the set-R values: `P_acct` 10 per window and 60 per day, `P_proof` 6 per window and 30 per day, `N_P` 10 per day (the only point any study ran), with the `UAX-NP-K` curve as the declared cost.
   Any other value is *not measured*.
4. **`K4P` rule at a password change** [paper D4]. 4.A account-wide, not cleared. 4.B account plus credential epoch. 4.C one penalty state per credential.
5. **Known device on the strangers' door** [paper D5]. 5.1 the account bucket only. 5.2 the UK lane: the DEC-021 allowance and K4 overflow on their own state for a Host-known request on the U door (G6; registered gate U1 failed, gate U2 passed).
   5.3 known-device routing around the bucket (G3 v2). 5.4 the UK lane counting admission refusals as failures of the device (*not measured*).
6. **Re-issue of credentials at a password change** [paper D6]. 6.1 the changing device only. 6.2 every device the Host knows as an active holder (*not measured*).
7. **Global ceiling and multi-IP spray** [paper D7]: a decision that no value settles. 7.1 no global ceiling. 7.2 a finite `U_glob`. 7.3 per-policy ceilings. 7.4 a proof-door ceiling (`P_glob` or `P_src`).
8. **Replacement bounds for what K2 gives** [paper D8]. Multi-session cells: 8.1 none (as measured on `FULL2`). 8.2 keep a K2-type churn control for multi-account sessions. 8.3 a per-source or global P ceiling (*not measured*).
   API cells: 8.4 keep today's API enforcement. 8.5 admission-only API (the G1 candidate).
9. **K5 micro-cap and the known-device allowance** [paper D9]. 9.1 the DEC-021 values unchanged (Login: K5 8 / 12 / 16, allowance 8, micro-cap 8; OTP: allowance 2). Any other value, and OTP under `FULL2`, are *not measured*.

### What no option approves

No option of this record approves, and the choice of any option does not approve:

- **`FULL2`** or the G5 v2 identity layer, as a design, an assembly or a component (DEC-022 Level 3);
- **any Runtime**, any change to `src/`, `tests/`, a preset or a threshold, or the supersession of DEC-021 (DEC-024 binds it to the first activation, with the step-5 Runtime authorisation);
- **any API or Store shape**, including the UK lane's state, the `checkOnly` reservation's capability or the Host declaration of DEC-023;
- **any value other than the archive values listed**, or any value for an option marked *not measured*;
- **a statement that a measured point is acceptable** on a Host that records no outcome (`pre`) or on a record-only Host (`out`), for which DEC-023 offers no FULL2 and the paper states the numbers;
- **the activation of DEC-022**, which stays `PROPOSED` until its conditions are met.

## Rationale

- **Why options and no choice.** DEC-022 assumes no value and DEC-023 / DEC-024 closed the step-2 choices without one; the values are the Owner's. The numbers are measured; whether they are acceptable is a security decision on cost.
- **Why nine points in one record.** DEC-022 Condition 3 names decisions 1, 2 and 8, G5 v2 §8 names 3 (the `N_P` cost), 4, 5 and 6, and the Owner's step-4 assignment adds the P caps, 7 and 9; a single option list lets the Owner see the interactions (paper §10). Decision Governance's default is one material decision per record: when
  the Owner chooses, the recording PR may split the choice into records by decision, as step 2 was recorded in DEC-023 and DEC-024.
- **Why some options are marked not measured.** The registered rule of the studies is that a gate with no evidence is not a gate that passed. The missing measurements are written as gate-shaped items, not run here.

## Consequences

**While `PROPOSED` (now):** no Runtime, contract, preset, threshold or test changes. DEC-015, DEC-019 and DEC-021 stay `ACTIVE` and unchanged; S0 remains the deliberate interim posture under DEC-019. #97, #100 and #106 stay open.

**When the Owner chooses (declared effects, by reference):** each chosen point becomes a declared budget in the recording record, with its measured cost as the paper states it. In particular: a chosen `U_src` and `F` accept the paper's D1 costs (S0's collective spray denial retired; single-IP spray at the
chosen point; `pre` Hosts at the outer ceiling only); a chosen `T` and `C` accept D2's bound and recovery; a known-device option accepts D5's trust in the Host's signal; and a "none" option in decision 8 accepts that `TMS` and `TMSd` reach `FULL2` at the paper's D8 values.
Every option that is a new admission state family (the UK lane, the epoch or per-credential `K4P`, a global ceiling) must state its DEC-003 cycles, DEC-007 lifecycle, DEC-008 capability and DEC-015 failure behaviour when it is implemented (DEC-022 note); every admission bound is absent in degraded mode (DEC-024 Consequences).

| Record | Effect while `PROPOSED` |
| --- | --- |
| DEC-015 | none; the bounds above do not hold in fallback |
| DEC-019 | unchanged; option 1.0 is its current posture |
| DEC-021 | unchanged; options 5.2 and 9.1 describe its mechanism on other states, and its supersession stays with the first activation (DEC-024) |
| DEC-008 | unchanged; every admission component would be a typed opt-in capability (DEC-024 C-c1) |

## Evidence

Every number is quoted from the merged study named, at that study's baseline, except the G6 rows (this step's registered measurement, archived in `investigations/k2-identity-admission-97/g6/budgets/expected/`).

| PR / source | Study (folder under `investigations/`) | Used for | Class |
| --- | --- | --- | --- |
| #129 | `k2-identity-admission-97/` G3 | `U_src` 12 / 24 / 40, gross failure cap, bucket `T` 10 / 30 / 60, CGNAT | `MEASURED`; `MODEL` (CGN) |
| #130 | `k2-identity-admission-97/` G3 v2 | net failure cap, bucket with known-device routing and its Host-violation control (5,760) | `MEASURED` |
| #132 | `k2-identity-admission-97/` G4 v2 | `FULL2`, `FULL2-40`, the P refund budget, gates A1–P, CGN-B | `MEASURED`; `MODEL` (C6, CGN-B) |
| #138 | `k2-identity-admission-97/` G5 v2 | identity layer, `K4P` rules A / B / C, `UAX-NP-K`, F-G5-04, F-G5v2-05 | `MEASURED` |
| #113 | `trusted-multi-session-s0/` | `TMS`, `TMSd`, `TMX` on S0 at baseline `ce89cb2` | `MEASURED` |
| #108 | `k1-credential-spray-nat/` | `SP4b` 432 / 432 on S0 | `MEASURED` |
| #126, #127 | `k2-identity-admission-97/` G1, G2 | API cells, `U_glob` binding, proof-path global ceilings | `MEASURED` |
| this PR | `k2-identity-admission-97/g6/budgets/` | the UK lane (`UKD`: gate U1 FAIL, gate U2 PASS) and the `TMS` family on `FULL2` | `MEASURED`; `POST-HOC` (G6 §3.4) |

## Relationship to Other Decisions

- **DEC-022:** `PROPOSED`, unchanged. This record carries its Condition 3.
- **DEC-023 and DEC-024:** `ACTIVE`, unchanged. They decide the mode contract and the reservation principle; this record decides no value of theirs.
- **DEC-021, DEC-019, DEC-015:** `ACTIVE`, unchanged; effects as stated above.
- **DEC-003, DEC-007, DEC-008:** unchanged; any new key or door states its effects when implemented.
- **Index:** `DECISIONS_INDEX.md` lists this record as `PROPOSED`, as it did DEC-022 (#133) and DEC-023 (#134). The line changes with the PR that records the Owner's choice.

## Canonical Contract / Current Owner

None while `PROPOSED`.

## Supersedes

None

## Superseded By

None
