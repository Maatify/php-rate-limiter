# DEC-025 — Level-2 Admission Budgets and Identity-Layer Cost Decisions for #97 (Declared Study-Value Budgets)

## Decision ID

`DEC-025`

## Status

`ACTIVE` — as a record of **declared budgets with study values**, on the Owner's decision of 2026-10-19. Decision 8 is active as a **direction conditional on the G7 measurement**, with its fallback recorded (see below).

It is **not** an Architecture GO, not an approval of `FULL2` as a product, not Runtime authority and not a Public API change. Every value below is a **study value** from a merged archive; it becomes a production value only through
the Runtime authorisation of step 5. DEC-015, DEC-019 and DEC-021 stay `ACTIVE` and unchanged until step 5; DEC-022 stays `PROPOSED`; DEC-023 and DEC-024 stay `ACTIVE`.

## Date

2026-10-19

## Decision Authority / Deciders

**Owner.** Step 4 of the Owner's five-step plan for #97 (DEC-022 Condition 3). The options were merged as `PROPOSED` with none selected (PR #139, `44e4cd8`). On 2026-10-19 the Owner chose, after the nine decisions of
[`BUDGETS_DECISION.md`](../../investigations/k2-identity-admission-97/BUDGETS_DECISION.md) and this record were presented in plain language with the cost of every option. The choice is recorded on GitHub in
[PR #139 comment 6263682706](https://github.com/Maatify/php-rate-limiter/pull/139#issuecomment-6263682706), posted by the Lead on the Owner's behalf after the Owner's explicit approval, following the precedent of step 2
([PR #134 comment 6256657121](https://github.com/Maatify/php-rate-limiter/pull/134#issuecomment-6256657121), recorded in DEC-023 and DEC-024 by PR #135). The Owner's text is the authority; the table below follows it.

The evidence is the merged step-3 study ([PR #138](https://github.com/Maatify/php-rate-limiter/pull/138), `f4851eb`, G5 v2), the merged studies G3, G3 v2 and G4 v2 named under [Evidence](#evidence), and the registered
measurement of this step (G6, PR #139). Technical review: the independent Lead reviews of PR #139.

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

The Owner chose the following. Numbers are archive values; the measured cost of each is in the decision paper section named in brackets.

| # | Decision | Choice |
| --- | --- | --- |
| 1 | Source budget of the strangers' door [paper D1] | **1.4:** `U_src` 24 with a net failure cap `F` 4. `pre+out` Hosts only gain the cap; `pre` Hosts get the outer ceiling only (`SP6` 144) |
| 2 | Per-account bucket [paper D2] | **2.2:** `T` 30 minutes, `C` 4, refund on success (bound 52, 51 measured on `BRU24`) |
| 3 | Proof-door caps and the new-device allowance [paper D3] | **3.1:** set R: `P_acct` 10 per window and 60 per day, `P_proof` 6 and 30, `N_P` 10 per day; the `UAX-NP-K` curve is a declared cost |
| 4 | `K4P` rule at a password change [paper D4] | **4.A:** account-wide, not cleared |
| 5 | Known device on the strangers' door [paper D5] | **5.1:** the account bucket only. Option 5.4 is an open item (below) |
| 6 | Re-issue at a password change [paper D6] | **6.1:** the changing device only; F-G5v2-05 is a declared cost |
| 7 | Global ceiling and multi-IP spray [paper D7] | **7.1:** no global ceiling. P4 is an open item (below) |
| 8 | Replacement bounds for what K2 gives [paper D8] | **8.2 as a direction conditional on G7:** a narrow rule on **failures across different accounts from one source on the proven door**, measured in G7; the decisive benign gate is that the legitimate office `N12T` is refused no more than `FULL2` does today. **If that gate fails, 8.2 falls and 8.1 (none) is recorded as a declared cost:** `TMS` 61/61 and `TMSd` 120/200 on `FULL2` against 7/61 and 2/200 on S0. **API: 8.4**, today's enforcement unchanged |
| 9 | K5 micro-cap and known-device allowance [paper D9] | **9.1:** the DEC-021 values unchanged (Login: K5 8 / 12 / 16, allowance 8, micro-cap 8; OTP: allowance 2) |

### Open items (gate-shaped, none is run or approved here)

- **Option 5.4** (the UK lane counting admission refusals as failures of the device): *not measured*; gate N-4 of the decision paper §11 (`LOST` ≤ 45 / 44 / 45 behind the bucket, no attacker cell above the reference).
- **P4** (multi-IP spray with no global ceiling): `POI` 96, `SP4b` 432 / 432 on S0, `X2` 121 / 121; any finite `U_glob` is a lane-wide denial lever (#115, #127). A measurement of that lever on the U door is gate N-6.
- **Decision 8, the direction:** its gate is the G7 study (`investigations/k2-identity-admission-97/g7/cross_account_p/`). Its verdict is recorded by a later record; until then 8.2 is a direction, not a rule.

### What this record does not approve

`FULL2` or the identity layer as a product; any Runtime, change to `src/`, `tests/`, a preset or a threshold; any API or Store shape; the supersession of DEC-021 (DEC-024 binds it to the first activation); any value other than those above; or the
activation of DEC-022.

## Rationale

- **Why study values.** DEC-022 assumes no value; the values are the Owner's. The numbers are measured; the Owner accepted their measured cost on 2026-10-19 as declared budgets, and they become production values only in step 5.
- **Why nine points in one record.** DEC-022 Condition 3 names decisions 1, 2 and 8, G5 v2 §8 names 3 (the `N_P` cost), 4, 5 and 6, and the Owner's step-4 assignment adds the P caps, 7 and 9; one record shows the interactions (paper §10). Decision Governance's default is one material decision per record; the nine are recorded together as one budget declaration, and a later change to one of them supersedes this record under §9.
- **Why some points are open items.** A gate with no evidence is not a gate that passed. 5.4, P4 and the direction of decision 8 are written as gates, not decided.

## Consequences

- **Values are study values.** They become production values only through the step-5 Runtime authorisation. No Runtime, API or Store shape follows from this record. DEC-015, DEC-019 and DEC-021 are unchanged until then.
- **Declared costs the Owner accepted** (decision paper): S0's collective spray denial is retired at `U_src` 24 / `F` 4 (`SP6` 24 in `pre+out`, 144 in `pre`; `POI` 96); owner recovery up to about 30 minutes on a continuing attack; a known device that lost its token is bounded by the bucket (51 against DEC-021's 45 / 44 / 45); a second credential holder is locked out after a password change (F-G5v2-05); no global ceiling (P4 open); the `UAX-NP-K` curve (refusals from the 11th distinct browser major in a day).
- **Decision 8 is conditional.** If the G7 gate fails, 8.1 is the declared cost: `TMS` 61/61 and `TMSd` 120/200 on `FULL2` (best response), against 7/61 and 2/200 on S0.
- Every admission bound is absent in degraded mode (DEC-024 Consequences) and unavailable to `pre` / `out` Hosts as DEC-023 states; every new admission state family must state its DEC-003 cycles, DEC-007 lifecycle, DEC-008 capability and DEC-015 failure behaviour when implemented.

| Record | Effect |
| --- | --- |
| DEC-015 | none now; the bounds do not hold in fallback |
| DEC-019 | unchanged; its exit stays with its Decision 6 and step 5 |
| DEC-021 | unchanged; supersession stays with the first activation (DEC-024) |
| DEC-022 | unchanged, `PROPOSED`; Condition 3 is decided by this record |
| DEC-008 | unchanged; every admission component would be a typed opt-in capability (DEC-024 C-c1) |

## Evidence

Every number is quoted from the merged study named, at that study's baseline (G6, PR #139, is archived in `investigations/k2-identity-admission-97/g6/budgets/expected/`).

| PR / source | Study (folder under `investigations/`) | Used for | Class |
| --- | --- | --- | --- |
| #129 | `k2-identity-admission-97/` G3 | `U_src` 12 / 24 / 40, gross failure cap, bucket `T` 10 / 30 / 60, CGNAT | `MEASURED`; `MODEL` (CGN) |
| #130 | `k2-identity-admission-97/` G3 v2 | net failure cap, bucket with known-device routing and its Host-violation control (5,760) | `MEASURED` |
| #132 | `k2-identity-admission-97/` G4 v2 | `FULL2`, `FULL2-40`, the P refund budget, gates A1–P, CGN-B | `MEASURED`; `MODEL` (C6, CGN-B) |
| #138 | `k2-identity-admission-97/` G5 v2 | identity layer, `K4P` rules A / B / C, `UAX-NP-K`, F-G5-04, F-G5v2-05 | `MEASURED` |
| #113 | `trusted-multi-session-s0/` | `TMS`, `TMSd`, `TMX` on S0 at baseline `ce89cb2` | `MEASURED` |
| #108 | `k1-credential-spray-nat/` | `SP4b` 432 / 432 on S0 | `MEASURED` |
| #126, #127 | `k2-identity-admission-97/` G1, G2 | API cells, `U_glob` binding, proof-path global ceilings | `MEASURED` |
| #139 | `k2-identity-admission-97/g6/budgets/` | the UK lane (`UKD`: gate U1 FAIL, gate U2 PASS) and the `TMS` family on `FULL2` | `MEASURED`; `POST-HOC` (G6 §3.4) |

## Relationship to Other Decisions

- **DEC-022:** `PROPOSED`, unchanged in status. Its Condition 3 is decided by this record.
- **DEC-023 and DEC-024:** `ACTIVE`, unchanged. They decide the mode contract and the reservation principle; this record decides no value of theirs.
- **DEC-021, DEC-019, DEC-015:** `ACTIVE`, unchanged; effects as stated above.
- **DEC-003, DEC-007, DEC-008:** unchanged; any new key or door states its effects when implemented.
- **Index:** `DECISIONS_INDEX.md` lists this record as `ACTIVE`.

## Canonical Contract / Current Owner

None until step 5. The values are study values; the current runtime authorities stay as DEC-019 and DEC-021 name them.

## Supersedes

None

## Superseded By

None
