# DEC-034 — Level-2 Sliding Admission Budgets and Identity-Layer Cost Decisions for #97 (replaces DEC-025)

## Decision ID

`DEC-034`

Text carried from DEC-025 is reproduced in full; section references are to the step-4 decision paper (`investigations/k2-identity-admission-97/BUDGETS_DECISION.md`) and study archives. The modifications in decisions 1.4, 3.1, and 8.2 record the continuous sliding windows approved under LT-185-30 (b) and measured in `investigations/sliding-admission-windows-185/RESULTS.md`, superseding DEC-025 under Decision Governance Standard §9.

## Status

`PROPOSED` — proposed in PR-Q (#185) to supersede DEC-025 under Decision Governance Standard §9.
Activation on Owner approval on the PR, followed by activation commit.

## Date

2026-11-10

## Decision Authority / Deciders

**Decision Authority.** Owner. Reopening and supersession of DEC-025 pursuant to Decision Governance Standard §9, authorized under LT-185-33 ([#185 comment 6772966460](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6772966460)).

DEC-025's original authority and history:
Owner decision on 2026-10-19 ([PR #139 comment 6263682706](https://github.com/Maatify/php-rate-limiter/pull/139#issuecomment-6263682706); Decision 8 in [PR #141 comment 6264207884](https://github.com/Maatify/php-rate-limiter/pull/141#issuecomment-6264207884)).
Sliding window authority: Owner decision LT-185-30 (b) ([#185 comment 6675589019](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6675589019)), draft approval ([comment 6679435278](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6679435278)), and acceptance of measured values ([comment 6700833731](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6700833731)).

## Scope / Concern

The Level-2 decisions of DEC-022 (security decisions on cost) for the admission and identity layer measured for #97, updated for continuous sliding windows:

- the source budget of the strangers' door (`U_src`, the failure cap `F`) and where the single-IP spray line is set;
- the per-account guessing budget (`T`, `C`);
- the proof door's caps and the new-device allowance (`P_acct`, `P_proof`, `N_P`);
- the `K4P` rule at a password change; the known device on the strangers' door; the re-issue of credentials at a password change;
- the global ceiling question (`U_glob`) and multi-IP spray;
- the replacement bounds for what K2 gives against the multi-session and API families;
- the K5 micro-cap and known-device allowance under the new K5 material.

Out of scope: DEC-022 Levels 1 and 3 (the principle, the FULL2 assembly); the Host mode contract and the `checkOnly` reservation (DEC-023, DEC-024); the supersession of DEC-021 (DEC-026); any API or Store shape; Runtime (step 5).

## Context

DEC-022 (`PROPOSED`) names, in its Condition 3, an Owner choice of the budgets, "Each is a Level 2 security decision with its measured cost stated, including where the single-IP spray gate is set", and assumes no value. Steps 1 to 3 are closed: DEC-022 `PROPOSED`; DEC-023 and DEC-024 `ACTIVE` (the mode contract and the opt-in reservation, as principles); the identity layer `SUPPORTED — LIMITED SCOPE` (G5 v2). The measured cost of every option, per decision, on the legitimate user (`L1`, `D2`, `ABA_B`, MIX legitimate, HAR, UAC, KD) and on the attacker's reach (`SP6` / `SP3` / `SP1n`, `POI`, `BRU24`, `X1` / `BRU` / `BRD`, STEAL, `MULTITOK`), with its effect on DEC-021, DEC-015 and DEC-008 and its sources, is in the decision paper [`investigations/k2-identity-admission-97/BUDGETS_DECISION.md`](../../investigations/k2-identity-admission-97/BUDGETS_DECISION.md). The registered measurement of step 4 is [`G6_BUDGETS_MEASUREMENT.md`](../../investigations/k2-identity-admission-97/G6_BUDGETS_MEASUREMENT.md).

Under DEC-033, all windowed admission scopes operate as continuous sliding windows (`ZSET`) scored by element expiry. This record restates the DEC-025 budgets with continuous sliding window semantics and records the measured sliding values alongside the historical tumbling values from `investigations/sliding-admission-windows-185/RESULTS.md`.

## Decision

The Owner chose the following. Numerical constants remain unchanged; window semantics are continuous sliding windows of duration `W` (600 s) and `DAY` (86400 s):

| # | Decision | Choice |
| --- | --- | --- |
| 1 | Source budget of the strangers' door [paper D1] | **1.4:** `U_src` 24 with a net failure cap `F` 4 enforced as a sliding window of duration `W = 600 s`. `pre+out` Hosts only gain the cap; `pre` Hosts get the outer ceiling only (`SP6` 144). Concurrency boundary (F-185-37): failure sets count recorded failure outcomes (`applyOutcome`); the cap `F = 4` governs subsequent reservations, not in-flight permits issued prior to recording a failure. Under concurrent in-flight requests, distinct account failures can reach the admission source capacity (`source_capacity = 24` for U-lane, measured 20/20 in Train 8 / `adm1.php`). |
| 2 | Per-account bucket [paper D2] | **2.2:** `T` 30 minutes (1800 s), `C` 4, refund on success (bound 52, 51 measured on `BRU24`). Retains token-bucket semantics. |
| 3 | Proof-door caps and the new-device allowance [paper D3] | **3.1:** set R: `P_acct` 10 per sliding window of `W = 600 s` and 60 per sliding day of `DAY = 86400 s`, `P_proof` 6 and 30, `N_P` 10 per sliding day; the `UAX-NP-K` curve is a declared cost. |
| 4 | `K4P` rule at a password change [paper D4] | **4.A:** account-wide, not cleared |
| 5 | Known device on the strangers' door [paper D5] | **5.1:** the account bucket only. Option 5.4 is an open item (below) |
| 6 | Re-issue at a password change [paper D6] | **6.1:** the changing device only; F-G5v2-05 is a declared cost |
| 7 | Global ceiling and multi-IP spray [paper D7] | **7.1:** no global ceiling. P4 is an open item (below) |
| 8 | Replacement bounds for what K2 gives [paper D8] | **8.2 as a rule, in the form measured in G7 and updated for sliding admission windows** ([`investigations/sliding-admission-windows-185/RESULTS.md`](../../investigations/sliding-admission-windows-185/RESULTS.md)): a per-source, continuous sliding window capacity on the **proven door (P)** that counts the **different accounts that failed through P**; a P request for a further account is refused (`cap:pfail_src`) when the source's set already holds `F` such accounts. Enforced as a continuous sliding window of duration `W = 600 s`. Success is neither counted nor refunded. Nothing is persisted outside the window; no escalation and no block on the source. Concurrency boundary (F-185-37): failure sets count recorded failure outcomes; cap `F = 4` governs subsequent reservations. **Values: `F` 4, sliding window 600 s.** Declared costs accepted by the Owner: **`X2`** (sliding window metrics: office cost 26/61 after 21/61; attacker reach 40 after 72; `TMS` 21/61; `TMSd` 40/200; historical tumbling values were office cost 21/61, attacker reach 72, `TMS` 29/61, `TMSd` 40/200 on `pre+out`, G7 baseline S0 being 7/61 and 2/200; inert in `pre`) and **`TPAIRT`** (an office that shares its source with a `TMSd` attacker pays 26 of 61 requests refused instead of 0, and the attacker's reach falls to 40; the residual of being unable to tell them apart in a shared source, G3 §4.3). **API: 8.4**, today's enforcement unchanged |
| 9 | K5 micro-cap and known-device allowance [paper D9] | **9.1:** the DEC-021 values unchanged (Login: K5 8 / 12 / 16, allowance 8, micro-cap 8; OTP: allowance 2) |

### Open items (gate-shaped, none is run or approved here)

- **Option 5.4** (the UK lane counting admission refusals as failures of the device): *not measured*; gate N-4 of the decision paper §11 (`LOST` ≤ 45 / 44 / 45 behind the bucket, no attacker cell above the reference).
- **P4** (multi-IP spray with no global ceiling): `POI` 96, `SP4b` 432 / 432 on S0, `X2` 121 / 121; any finite `U_glob` is a lane-wide denial lever (#115, #127). A measurement of that lever on the U door is gate N-6.

### What this record does not approve

`FULL2` or the identity layer as a product; any change to `src/`, `tests/`, a preset or a threshold; any API or Store shape outside what DEC-033 specifies; any value other than those above; or the activation of DEC-022.

## Rationale

- **Why sliding windows:** As established under LT-185-30 (b) and DEC-033, fixed tumbling buckets allowed boundary double-bursting (up to 2x capacity across a bucket boundary). Continuous sliding windows enforce the decided caps strictly across any interval of length `W` (600 s) or `DAY` (86400 s).
- **Historical cost comparison:** Measured in `investigations/sliding-admission-windows-185/RESULTS.md`:
  - Attacker reach falls from 72 to 40 (positive security gain).
  - Co-located office cost is 26/61 compared to 21/61 historically (accepted as an operational trade-off of strict sliding bounds).
  - Target Metric Score (`TMS`) improves from 29/61 to 21/61; `TMSd` remains 40/200.
- **Full supersession of DEC-025:** Pursuant to Decision Governance Standard §9, active decisions cannot be partially amended; a full replacement record ensures clear lineage and single current authority.

## Consequences

- The declared budgets of DEC-025 remain numerically identical (`W=600`, `F=4`, `U_src=24`, `P_acct=10/60`, `P_proof=6/30`, `N_P=10`, `D=30`, `G=600`), operating under continuous sliding window semantics (DEC-033).
- DEC-025 is marked `SUPERSEDED`.
- Current references in documentation and contracts cite DEC-034.

## Evidence

- `investigations/sliding-admission-windows-185/RESULTS.md` (sliding window measurements).
- Studies G3, G3 v2, G4 v2, G5 v2, G6, G7 (`investigations/k2-identity-admission-97/`).

## Relationship to Other Decisions

- **Supersedes DEC-025** in full.
- **DEC-033:** Operates the sliding window mechanism and storage structure for the budgets declared here.
- **DEC-035:** Governs the admission runtime constants and timing.
- **DEC-026:** Governs the known-device verifier bound.

## Canonical Contract / Current Owner

`AdmissionBudgetsDTO::packageDefaults()`, `docs/POLICIES.md` §7.2, `docs/DECISION_MATRIX.md` §12, `docs/KEY_STRATEGY.md` §4.8.

## Supersedes

`DEC-025`

## Superseded By

None
