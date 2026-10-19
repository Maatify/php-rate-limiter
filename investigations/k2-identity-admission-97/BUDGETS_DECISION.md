# Admission budgets and the cost decisions of the identity layer — decision paper (#97, step 4)

> **Status:** DECISION PAPER for the Owner. It prepares the step-4 decisions of the Owner's five-step plan for #97 (DEC-022 Condition 3, "Level 2: security decisions on cost"). DEC-022 names `U_src`, `F`, `T`, `C` and the API and multi-session replacement bounds; G5 v2 §8 names four more (`N_P` cost, `K4P`, F-G5-04, re-issue); `U_glob`, the K5 micro-cap and allowance, and the P caps are in this paper because the Owner's step-4 assignment lists them. It takes **no**
> decision and states **no** recommendation, default or preferred value: for each decision it lists the options that were measured, what each costs the legitimate user and the attacker, what it does to
> DEC-021, DEC-015 and DEC-008, and whether the evidence is enough or a new measurement is needed. The options are carried, numbered and without a choice, by
> [DEC-025](../../docs/decisions/DEC-025_LEVEL_2_ADMISSION_BUDGETS_AND_IDENTITY_LAYER_COST_DECISIONS.md) (`PROPOSED`).
> Documentation and one registered measurement (G6: [`G6_BUDGETS_MEASUREMENT.md`](G6_BUDGETS_MEASUREMENT.md), plan in `g6/budgets/PLAN.md`). No change to `src/`, `tests/`, `composer.json`, any public contract, preset or
> threshold, and no change to the text or status of DEC-015, DEC-019, DEC-021, DEC-022, DEC-023 or DEC-024. `DECISIONS_INDEX.md` is not touched by this PR.
> **Every number is quoted from a merged archive at that archive's baseline, with its archive values** (`U_src` 12, 24 or 40, `F` 4 or 8, `T` 600, 1800 or 3600 s, `C` 4, `P_acct` 10 per window and 60 per day, `P_proof` 6 and 30, `N_P` 10 per day,
> `U_glob` 1000, token TTL 30 days sliding and 90 days absolute, taint 3), **except the G6 rows, which are this PR's own archived measurement.** No value is proposed as a choice.
> **Question.** For each of the nine decisions: which point does the Owner write as the declared budget, with its measured cost on the legitimate user and on the attacker?

## 0. Terms and how to read

| Term | Meaning |
| --- | --- |
| `pre+out` / `pre` / `out` | The Host calls `checkOnly` then records the outcome / calls `checkOnly` only / records only (DEC-023). The reference Host of every study is `pre+out`; where a row says `pre` it is the checkOnly-only Host |
| `FULL2` | The G4 v2 assembly (#132): `U_src` 24, net failure cap `F` 4, per-account bucket (`C` 4, `T` 1800 s, refund on success), U / P doors with binding, P refund budget, sliding renewal, password-change binding, K5 separation. `NOUA2` = `FULL2` plus the G5 v2 identity layer (K5 on the instance id, `N_P` on the fingerprint) |
| **`a / b`** in the stream tables | legitimate requests denied / best-response attacker reach (verifier reaches), as G3 v2 §3. A single number is stated with its label |
| `S0` | the shipped runtime at the study baseline (`4c6ca61`), DEC-019 / DEC-021 |
| `MEASURED` | archived harness output on the real pipeline or a pinned candidate; `MODEL` a simulation without the pipeline; `DERIVED` reasoning with nothing executed; `CODE` read from the code; `NOT MEASURED` no study ran it |
| **Gate-shaped item `N-n`** | a question the existing evidence cannot settle, written as a gate that can be registered: it names the arms, the cells and the pass condition. It belongs to step 5 or to a separate authorisation; this paper runs none |

**Sources.** Short keys: **G1V** `G1_VERIFICATION.md`; **G3** `G3_ADMISSION_BUDGETS.md` and `g3/admission_budgets/expected/summary.md`; **G3v2** `G3_V2_NET_CAP_KNOWN_ROUTING.md` and its `expected/summary.md`; **G4v2**
`G4_V2_INTEGRATED_REASSEMBLY.md` and its `expected/summary.md`; **G5v2** `G5_V2_IDENTITY_LAYER.md` and its `expected/summary.md`; **G6** `G6_BUDGETS_MEASUREMENT.md` and its `expected/summary.md`; **113**
`investigations/trusted-multi-session-s0/`; **108** `investigations/k1-credential-spray-nat/`; **SF** `SECURITY_FEASIBILITY.md`; **AP** `ARCHITECTURE_PROPOSAL.md`; **G2** `G2_PROOF_ACQUISITION_FEASIBILITY.md`; **HMC**
`HOST_MODE_CONTRACT_DECISION.md`. Study scope everywhere: reference stores and a controlled clock; no Redis, concurrency or failover; synthetic streams; study-code tokens and a Host model; test values only.

**Reading rule.** Every table is a menu. No row is marked as better. Where two decisions change each other's numbers, §10 says so.

## 1. The nine decisions at a glance

| # | Decision | Options (each is a measured point unless marked) | Evidence | New measurement? |
| --- | --- | --- | --- | --- |
| **D1** | Source ceiling `U_src` and failure cap `F` on the strangers' door | 0 keep S0's K1 spray protection · 1 `U_src` 12 · 2 24 · 3 40 · 4 24 + `F` 4 net · 5 24 + `F` 8 net · 6 40 + `F` 4 net · 7 40 + `F` 8 net · 8 40 + `F` 4 gross · 9 40 + `F` 8 gross | enough for these points | only for any other value (N-1) |
| **D2** | Per-account bucket: refill time `T`, capacity `C` | `T` 10 min · 30 min · 60 min, all at `C` 4 with refund | `T` enough; `C` measured at 4 only | `C` ≠ 4 (N-2) |
| **D3** | `P_acct`, `P_proof`, `N_P` | 1 the set-R values (10 / 60, 6 / 30, 10 per day), the only point any study ran | enough for that point | any other value (N-3) |
| **D4** | `K4P` rule at a password change | A account-wide, not cleared · B account + epoch · C one state per credential | enough | no |
| **D5** | Known device on the U door (F-G5-04) | 1 bucket only · 2 UK lane (G6) · 3 known-device routing around the bucket (G3 v2) · 4 UK lane counting admission refusals (not measured) | G6 measured 1–3 | option 4 (N-4) |
| **D6** | Re-issue of credentials at a password change (F-G5v2-05) | 1 the changing device only · 2 every active holder (not measured) | option 1 measured | option 2 (N-5) |
| **D7** | `U_glob` and multi-IP spray (P4) — **not settled by a value** | 1 no global ceiling · 2 a finite `U_glob` · 3 per-policy ceilings · 4 a P-door ceiling (`P_glob` / `P_src`) | POI, `SP4b`, `X2` measured; the lever is `DERIVED` | the lever on the U door (N-6) |
| **D8** | Bounds that replace what K2 gives against the multi-session and API families | 1 none (as measured on `FULL2`) · 2 keep a K2-type churn control for multi-account sessions · 3 a per-source or global P ceiling (written) · API: 4 keep today's API enforcement · 5 admission-only API (G1) | `TMS` family measured on `FULL2` (G6); API on G1 | the replacement bound (N-7) |
| **D9** | K5 micro-cap and the known-device allowance under the new K5 material | 1 the DEC-021 values unchanged (Login 8 / OTP 2) | enough for Login | other values; OTP under `FULL2` (N-8) |

## D1. Source ceiling `U_src` and failure cap `F`

**What is decided.** How much one source may reach the verifier per 600 s window on the strangers' door (U door), and whether a net or gross failure cap counts failed accounts per source. This replaces K1's spray role (DEC-019
Decision 5; DEC-022 Level 2). It also sets where the single-IP spray line is: the study gate A3 of G4 v2 used the `U_src` 12 values (`SP6` 72) as its bound.

### D1.1 The measured options (`pre+out`; `a / b` as §0 unless labelled)

| Option | `U_src` | `F` | L1 | ABA_B | D2 | MIX legit / attack | `SP6` | `SP3` | `SP1n` | `ABA_A` | `POI` | `pre`: D2 / `SP6` | Registered verdict |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0 `S0` (reference) | — (K1 / K2) | — | 0 | 118 | 117 | 117 / 0 | 20 | 7 | 4 | 3 | 12 legit / 80 | — | DEC-019 |
| 1 | 12 | none | **50** | 96 | 72 | 82 / 10 | 72 | 24 | 12 | 24 | 180 | not run | L1 worse than S0 |
| 2 | 24 | none | 0 | 72 | 39 | 63 / 27 | 144 | 48 | 24 | 48 | 180 | 36 / 144 | no benign stream worse than S0 |
| 3 | 40 | none | 0 | 56 | 27 | 59 / 55 | 240 | 80 | 40 | 80 | 180 | 24 / 240 | no benign stream worse than S0 |
| 4 | 24 | 4 net | 0 | 72 | 39 | 66 / 8 | 24 | 8 | 4 | 8 | **96** | 36 / 144 (cap inert) | `FCN4-24` EXPERIMENTALLY SUPPORTED — LIMITED SCOPE, `pre+out` only |
| 5 | 24 | 8 net | 0 | 72 | 39 | 65 / 15 | 48 | 16 | 8 | 16 | 180 | not run | FCN-1…3 pass; no `pre` gate registered |
| 6 | 40 | 4 net | 0 | 56 | **29** | 66 / 8 | 24 | 8 | 4 | 8 | 96 | 24 / 240 | FCN-2 **fails** (D2 29 > S40 27) |
| 7 | 40 | 8 net | 0 | 56 | 27 | 65 / 16 | 48 | 16 | 8 | 16 | 180 | not run | FCN-1…3 pass; no `pre` gate registered |
| 8 | 40 | 4 gross | 0 | 56 | 32 | 65 / 6 | 24 | 8 | 4 | 8 | 96 | 24 / 240 | NO-GO AFTER EXPERIMENT as registered (G3 v1: D2 32 > 27, MIX 65 > 59) |
| 9 | 40 | 8 gross | 0 | 56 | 27 | 64 / 14 | 48 | 16 | 8 | 16 | 180 | 24 / 240 | NO-GO AFTER EXPERIMENT as registered (G3 v1: MIX attack 14 > 10, MIX legit 64 > 59) |

KD and UAC are 9 / 6 in every row (equal to S0): no option changes the known-device streams. Sources: options 0–7: G3v2 `expected/summary.md` (streams `pre+out` and `pre`); options 8–9: G3 `expected/summary.md` (`FC4`, `FC8`), `MEASURED`.

**The same options inside `FULL2`** (the assembled form: with the bucket, the P door and binding; G4v2 `expected/summary.md`):

| Assembly | L1 | ABA_B | D2 | MIX legit / attack | `SP6` / `SP3` / `SP1n` / `ABA_A` | `POI` | `pre`: L1 / D2 / `SP6` | HAR-B owner denied / attack reach |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `FULL2` (`U_src` 24, `F` 4 net) | 0 | 0 | 24 | 54 / 8 | 24 / 8 / 4 / 8 | 96 | 72 / 61 / 144 | 0 / 4 |
| `FULL2-40` (`U_src` 40, `F` 4 net) | 0 | 0 | **2** | 42 / 8 | 24 / 8 / 4 / 8 | 96 | 72 / 61 / **240** | 0 / 4 |
| `R-FULL` (G4's definitions: no P refund) | 72 | 94 | 24 | 54 / 8 | 24 / 8 / 4 / 8 | 96 | 72 / 61 / 144 | 0 / 4 |
| `R-NO-FCN` | 72 | 94 | 24 | 51 / 27 | **144 / 48 / 24 / 48** | 180 | — | — |

### D1.2 What each option costs

* **Legitimate user.** `U_src` 12 refuses a browser office that has never failed (L1 50 of 134) and is the only measured point with a benign stream worse than S0; from 24 up no benign stream is worse than S0 (G3 §4.2). Raising 24 → 40
  lowers D2 (39 → 27; in `FULL2` 24 → 2) and MIX (63 → 59; 54 → 42). A **net** cap relieves typos (D2 29 against the gross 32 at 40) and at 24 adds nothing to any benign stream outside the co-located case; the **gross** cap costs
  D2 32 and MIX 65 at 40.
* **Attacker (single IP).** `SP6` reach grows with `U_src` (72 / 144 / 240 at 12 / 24 / 40) and is cut to 24 or 48 by `F` 4 or 8 in `pre+out`; `S0` is 20. The cap does not dominate S0: `SP6` 24 against 20, `ABA_A` 8 against 3, MIX attack 8 against 0
  (G3v2 §4.1). `POI` (eight sources, one fresh account each) is 180 with no cap that binds and **96** with `F` 4, because each source hits its own cap; `F` 8 leaves 180.
* **Co-located attacker.** MIX legit denied is 117 on S0, 63 at 24 with no cap, 65–66 with a net cap (G3 v2), 54 in `FULL2` and 42 in `FULL2-40` (G4 v2), and no source cap protects co-tenants from a co-located sprayer: in the CGNAT `MODEL` every arm refuses 92–100 % of co-tenants once a sprayer is behind the source (G3 §4.3).
* **CGNAT `MODEL` (G3 §4.3, `MODEL`).** Refused share of legitimate unproven attempts, N = 5,000 behind one source, λ = 1 per client per day, no attacker: `U_src` 12 / 24 / 40 = 88.46 % / 76.77 % / 61.18 % with no client yet proven; with 80 % already proven 44.94 % / 4.96 % / 0.00 %.
  Inside `FULL2` (binding, `MODEL` CGN-B, day 1, 21,144 attempts): 3,996 refused at 24, 987 at 40 (day 3: 2 and 0).
* **Hosts that record no outcome.** In `pre` the failure cap is inert (options 4, 6, 8, 9 equal options 2 and 3 there): `SP6` 144 at 24 and 240 at 40. In `out` nothing is bounded before the verifier (DEC-023 table). `FULL2` fails gate P in `pre` (L1 72, D2 61).
* **K1.** Every option except 0 retires K1's collective spray denial, so S0's `SP6` 20 / `SP3` 7 / `SP1n` 4 become the values above. D1 is the decision that accepts or refuses that trade (DEC-022 Condition 3).

### D1.3 Effects on other decisions

| Record | Effect |
| --- | --- |
| DEC-021 | None directly: known devices are on the P door in `FULL2` and on the U door under D5. The source ceiling and the cap sit before the pipeline |
| DEC-015 | The cap counts **recorded outcomes** (inert in `pre`, DEC-023). All admission bounds are absent in degraded mode (HMC §2 C-7; DEC-024 Consequences): every number above is a healthy-backend value |
| DEC-008 | The ceiling and the cap would be a typed, opt-in capability (DEC-024 C-c1); official presets keep today's behaviour until a policy opts in |
| DEC-019 | Option 0 is DEC-019's current posture for K1; options 1–9 are what its Decision 5 asks to be quantified: `SP6` / `SP3` / `SP1n` / MIX / `POI` above |

### D1.4 Evidence and what is missing

Enough for the ten points above. Not measured: any other `U_src` (16, 32, …) or `F` (6, …); IPv6 hierarchy; a per-operation ceiling for anonymous API (the G1 candidate used `A_src` 300). **N-1** — *for any other `U_src` / `F`*: arms `FULL2` with
the value; gates A1 (every benign stream ≤ S0 and ≤ S24), A2 (MIX legit ≤ S0), A3 (single-IP spray ≤ the Owner-stated line), and the `pre` row reported; the spray line is the Owner's (DEC-022 Condition 3).

## D2. Per-account bucket: `T` and `C`

**What is decided.** The refill time `T` and capacity `C` of the per-account token bucket on the U door: the per-account guessing bound is exactly `C + ⌊86,400 / T⌋` per day under rotation, and an owner locked out by a continuing attack
waits at most about `T`.

### D2.1 The measured options (`C` = 4, refund on success, G3 v2 `TBK` arms unless labelled)

| Option | `T` | Bound `C + ⌊86,400 / T⌋` | `BRU24` admitted in 24 h (recount) | Owner wait at δ = 0 (s) | `X1` / `BRU` / `BRD` reach (`pre+out`) | Benign streams (L1, ABA_B, D2, MIX legit) | UAC (routed / not routed) | `pre` (checkOnly-only) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| reference: no bucket (`R-NO-TB`, G4v2) | — | none | **2,304** (recount 0) | 60 | **17 / 80 / 80** (`S0`: 2 / 3 / 3) | D2 24 | 6 | `BRU` 80, `X1` 17 (S24 / S40 `pre`) |
| 1 | 600 s (10 min) | 148 | **147** (147) | 60 | **5 / 8 / 8** | 0, 40, 0, 35 | 6 / 8 | not run for 10 min |
| 2 | 1800 s (30 min) | 52 | **51** (51) | 60 | **4 / 5 / 5** | 0, 40, 0, 35 | 6 / 9 | L1 122, ABA_B 112, D2 61, Nu4 41; `BRU` 5, `X1` 4 |
| 3 | 3600 s (60 min) | 28 | **27** (27) | **1860** (≤ `T` + 60) | **4 / 4 / 4** | 0, 40, 0, 35 | 6 / 9 | not run for 60 min |
| in `FULL2` (`T` 30) | 1800 s | 52 | 51 (51); `BRU24-HV` 51 | 60 | 4 / 5 / 5 | 0, 0, 24, 54 | 6 (owner on P) | L1 72, D2 61 (gate P fails) |

Sources: G3v2 `expected/summary.md` (`TBK10R` / `TBK30R` / `TBK60R`, the `pre` table, BRU24 / REC table); G3 (`TB10R` … `TB60R`, no routing); G4v2 `expected/summary.md` (`FULL2`). `MEASURED`. The recovery gate reads "wait from arrival ≤ `max(0, T + 60 − δ)`"
and passes for every `T` and offset.

### D2.2 What each option costs

* **Legitimate user.** With refund and known-device routing no benign stream is worse than S40 for any `T`; the rows are identical across `T`. Part of that gain comes from the `NOESC` ablation, not from the bucket (G3 §4.4 confound). Without refund
  the bucket destroys success-heavy streams (L1 114–124 of 134, ABA_B 110–112, Nu4 32–45; G3). Without known-device routing UAC rises from 6 to 8–9 (the owner's known device pays the strangers' bucket); in `FULL2` the bound owner is on the P door and UAC is 6.
* **Attacker.** The per-day bound is exact: 147 / 51 / 27 against 148 / 52 / 28 (independent recount equal). A shorter `T` raises the first-window reach (`X1` / `BRU` / `BRD` 5 / 8 / 8 at 10 min, 4 / 5 / 5 at 30, 4 / 4 / 4 at 60) and the daily reach; S0 is 2 / 3 / 3.
* **`C`.** Only `C` = 4 was run. The reach at the start of an attack is `C` plus the refill, which is why `X1` / `BRU` / `BRD` are 4 / 5 / 5 against S0's 2 / 3 / 3 at `T` 30 (`DERIVED` from the bound; no other `C` measured).
* **Hosts that record no outcome.** A refund needs a recorded outcome: in `pre` the bucket still bounds the attacker (`BRU` 5 against 80) but harms success-heavy users (L1 122 of 134, D2 61).
* **Host violation.** Known-device routing around the bucket (`TBK`) lets a Host that marks an attacker known reach 5,760 of 5,760 (`BRU24-HV`); `FULL2`, which never routes around the bucket, holds 51 (G3v2 §4.3, G4v2).

### D2.3 Effects on other decisions

| Record | Effect |
| --- | --- |
| DEC-021 | With routing (`TBK`) the bucket skips known devices, so the DEC-021 allowance is their only bound on U; in `FULL2` the bound owner uses the P door. Both depend on the Host's known-device signal exactly as DEC-021 does. See D5 |
| DEC-015 | An account-keyed counter (a harness model; its storage shape is not designed here); absent in degraded mode (HMC C-7); refunds need a recorded outcome (DEC-023) |
| DEC-008 | A typed opt-in capability (DEC-024 C-c1) |

### D2.4 Evidence and what is missing

`T` 10 / 30 / 60 enough. Not measured: `C` other than 4; `T` between or beyond these. **N-2** — *for any other `C` or `T`*: arms `FULL2` with the value; gates B1 (admitted ≤ `C + ⌊86,400 / T⌋` with an independent recount equal), B2 (wait from arrival ≤ `max(0, T + 60 − δ)`), A1 (no benign
stream above S0 and S24), and the `X1` / `BRU` / `BRD` reach recorded against 2 / 3 / 3.

## D3. `P_acct`, `P_proof`, `N_P`

**What is decided.** The per-account and per-credential caps of the proven (P) door and the daily allowance of new devices per account (`N_P`).

### D3.1 The only measured point (set R, as used by G4 v2 and G5 v2)

| Parameter | Value | What it bounds, measured | Cost on the legitimate user | Cost / effect on the attacker | Source |
| --- | --- | --- | --- | --- | --- |
| `P_acct` | 10 per 600 s window, 60 per day, per account | proof holders in aggregate per account | with the P refund, 0 on every benign stream (L1, ABA_B, Y6b); **without** the refund (`N-PG1`, the G1 budgets): L1 72, ABA_B 94, Y6b 10, and E2 diverges | `TMSd` on `FULL2`: 120 of 200 reach the verifier = 12 accounts × 10 (G6); `MULTITOK-S` (8 credentials): 38 P admissions in 24 h | G4v2 §5.1, §6; G5v2 §5.1; G6 §4 |
| `P_proof` | 6 per window, 30 per day, per credential id | a stolen credential | none measured | the thief holds at most 2 P attempts per window (≤ 6) and 9 in 24 h (≤ 30), and 0 after the password change | G4v2 §4 (C4v2) |
| `N_P` | 10 new devices per day per account (`T_P` 86,400 s), member = fingerprint | a rotating attacker and the new-device flood | refusals on twelve logins a day with `k` distinct browser majors: 0 for `k` ≤ 10, 1 at 11, 2 at 12 (a normal browser update consumes one member) | a rotating attacker with many credentials is held at 10 by `N_P` (`MULTITOK` 10) | G5v2 F-G5v2-02, §5.1 |

`UAX-NP-K` curve (refusals out of 12, `NOUA2` = `REF`): k = 1, 2, 5, 10, 11, 12 → 0, 0, 0, 0, 1, 2 (the id-keyed member of G5 gives 0 at every k and lets the thief that rotates IP and UA reach 20 P admissions before the change instead of 9: STEAL).
A refusal after 10 issues in a day is the `k` = 11 point of this curve.

### D3.2 What is missing

**No other value was run.** G1's parameter grid varied `U_acct` and `U_src` only (G1V §6); `P_acct`, `P_proof` and `N_P` have exactly one measured point. The options are therefore: **1** the set-R values as measured; or any other value, which is not an option until it is
measured. **N-3** — *for any other P caps or `N_P`*: arms `FULL2` / `NOUA2` with the values; gates A1 (benign ≤ S0 with the refund), C4v2 (thief per window ≤ the new `P_proof`, 24 h ≤ the day cap, 0 after the change), `UAX-NP-K` curve, `MULTITOK` and `MULTITOK-S` recorded, `TMSd` recorded.

### D3.3 Effects on other decisions

DEC-021: the allowance and overflow of the P door live on the `_p` state and are not changed by these caps. DEC-015: P counters are admission state, absent in degraded mode. DEC-008: part of the opt-in capability. D4: rule C changes what `P_acct` bounds
(38 → 120 for eight credentials).

## D4. The `K4P` rule at a password change

**What is decided.** What happens to the account-wide P penalty state (`K4P`) when the password changes: whether it is kept (A), keyed by the credential epoch (B), or kept per credential (C).

### D4.1 The measured options (G5v2 §5.1; member = fingerprint unless labelled)

| Rule | Owner: denied before / wait after the change | Second proof holder: denied before the change | Second holder after the change | Thief P before / after | Account bound: `MULTITOK-S` P admissions (`pen:K4P` denials) | What it needs (`DERIVED`) |
| --- | --- | --- | --- | --- | --- | --- |
| **A** account-wide, not cleared | 2 / 0 s | 25 | not admitted by 86,400 s (U door, `cap:acct_tb` 719) | 9 / 0 | **38** (144) | nothing new |
| **B** account + epoch | 2 / 0 s | 25 | not admitted by 86,400 s | 9 / 0 | **38** (144) | the epoch as a pipeline input; drops pre-event evidence for every actor; as strong as the Host's proof of the event |
| **C** one state per credential id | 2 / 0 s | **0** | not admitted by 86,400 s | 9 / 0 | **120** (88) | one penalty state per credential in storage |
| A under the id-keyed member (G5) | **5 / 1,260 s** | 71 | not admitted | **20** / 0 | 38 | — |
| B under the id member | 5 / 0 s | 71 | not admitted | 20 / 0 | 38 | — |
| C under the id member | 5 / 0 s | 0 | not admitted | 20 / 0 | 120 | — |

### D4.2 What each rule costs

* **Owner.** With `N_P` on the fingerprint the three rules give the owner the same numbers (finding F-G5v2-03): the rule has no observable effect on STEAL. Under the id-keyed member, B and C remove the owner's 1,260 s wait but not the 5 denials before the change.
* **Second proof holder.** A and B charge the second holder for the thief's failures before the event (25 denials); C isolates the holder (0). After the event the second holder is locked out in **every** rule (D6).
* **Account bound.** C weakens the account bound: eight credentials reach 120 P admissions against 38 (A, B), because the bound becomes per credential (F-G5v2-04).
* **Attacker.** The thief is bounded identically in all three (9 before, 0 after).

### D4.3 Effects on other decisions

| Record | Effect |
| --- | --- |
| DEC-021 | The overflow of the allowance lands on `K4P` (A, B) or on a per-credential state (C); B and C change what "account-wide K4" means on the P door |
| DEC-015 / DEC-008 | A new key (B: epoch; C: credential id) is a new admission-state key family (DEC-022's note: state its DEC-003 cycles, DEC-007 lifecycle, DEC-008 capability, DEC-015 failure behaviour) |

Evidence: enough; no new measurement.

## D5. The known device on the U door (F-G5-04)

**What is decided.** A device the Host knows (a trusted session or a previously verified device) that has lost its credential is on the U door. DEC-021's allowance and K4 overflow are inert there (G5 v2: 79 / 79 / 79), so it is bounded only by the account bucket.
**One option is measured here by this PR's registered measurement (G6 arm 1).**

### D5.1 The measured options (`LOST`: reach in 24 h of a known device with no credential, failing every 600 / 300 / 60 s)

| Option | `LOST` 600 / 300 / 60 s | Attacker cells | Host-violation `BRU24-HV` | Legitimate streams | What it adds |
| --- | --- | --- | --- | --- | --- |
| reference: DEC-021 on S0, and the P door with the credential (`TOKEN`) | 45 / 44 / 45 | — | — | — | — |
| **1 bucket only** (`NOUA2`, as G4 v2 and G5 v2 measured) | **51 / 51 / 51**; pipeline alone on the U lane 144 / 79 / 77 | the reference | 51 | the reference | nothing |
| **2 UK lane** (G6 `UKD`): DEC-021's allowance, K4 overflow, watcher and budget on their own `_uk` / `k4uk` state for a request on the U door that the Host knows; the admission layer stays | **48 / 47 / 48**; registered gate U1 (≤ 45 / 44 / 45) **FAIL**; pipeline alone on the UK lane **45 / 44 / 45** (`k4uk` score 7, allowance 45 / 44 / 45) | none above the reference in 182 stream rows and 9 fixtures (gate U2 PASS); `BRU24-HV` and `MINT` 51 → 48 | 48 | 0 of 182 stream rows differ; 48 requests routed (`KD` / `UAC` without ids), decisions unchanged | a new state family (`k4uk`, `_uk` allowance, watcher, cooldown) |
| **3 known-device routing around the bucket** (G3 v2 `TBK`, here `D-UKBYP`) | 144 / 79 / 77 | the Host-violation row rises | **5,760** | G3 v2: UAC 6 and KD 9, equal to S40, for a routed device | nothing on the pipeline side |
| **4 UK lane that counts admission refusals as failures of the device** | **not measured** | — | — | — | see N-4 |

Sources: G5v2 F-G5-04; G6 §3 (`MEASURED`); G3v2 §4.3. `POST-HOC` (G6 §3.4, `DERIVED`): behind the bucket most attempts are refused before the verifier and record no failure, so the pipeline's counters advance on the admitted attempts only; that is why option 2 is 3 above
DEC-021 and 3 / 4 / 3 below option 1.

### D5.2 What each option costs

* **Legitimate user.** A known device that lost its token keeps failing until the bucket or the lane stops it: 51 (option 1), 48 / 47 / 48 (option 2), 45 / 44 / 45 (DEC-021, with a token). No legitimate stream among the 22 reaches the UK lane's limits (`KD` and `UAC` without ids are 12 attempts each, denied by the pipeline and the bucket as before; decisions unchanged).
* **Attacker.** Option 2 never raises an attacker cell and lowers the two that a Host-marked-known attacker can drive (51 → 48). Option 3 is the one that does not hold under a Host violation (5,760).
* **Trust.** Options 2 and 3 both rely on the Host's known-device signal exactly as DEC-021 does. A Host that marks an attacker known lets it write the `uk` state (option 2) or skip the bucket (option 3).

### D5.3 Effects on other decisions

| Record | Effect |
| --- | --- |
| DEC-021 | Option 2 is the DEC-021 mechanism run on a third state; it changes its contract surface (a new door state); `DERIVED`: it would belong to the DEC-021 supersession that DEC-024 binds to the first activation |
| DEC-022 | The `uk` state is door-scoped and written only through the U door by a Host-vouched request; no shared scope, nothing for a stranger to write |
| DEC-015 / DEC-008 | A new admission-state family; opt-in capability |

### D5.4 Evidence and what is missing

Options 1–3 measured. **N-4** — *option 4, or any variant that makes the UK-lane value reach 45 / 44 / 45 behind the bucket*: arm `UKD` + the variant; gates U1 (`LOST` ≤ 45 / 44 / 45), U2 (no attacker cell above the reference), and the legitimate-cost rows reported.

## D6. Re-issue of credentials at a password change (F-G5v2-05)

**What is decided.** At a password change every older credential is void. The Host decides who gets a new one: only the device that changed the password (the design's §4.3), or every device the Host knows to be an active holder.

### D6.1 The options

| Option | Who is re-issued | Cost to the second holder | Cost to the thief | Host burden | Evidence |
| --- | --- | --- | --- | --- | --- |
| **1** | the changing device only | **measured:** `STEAL2` — before the change 25 denials (rules A, B) or 0 (C); after it the second device is on the U door and the account bucket holds it (`cap:acct_tb` 719) **for the rest of the day, in every rule and for both members**; the re-issued owner device is admitted at once on P (0 s) | 0 P after the change; U reach 27 | the Host re-issues one credential | `MEASURED` G5v2 §4, §5.1; G4v2 C4v2 |
| **2** | every device the Host knows as an active holder | **not measured.** `DERIVED`: the second device returns to the P door at the event, as the re-issued owner device does in `STEAL` (wait 0 s) | `DERIVED`: unchanged, the thief holds a void copy under either option | the Host keeps a registry of active holders and must reach each at the event | `NOT MEASURED` |

### D6.2 Effects and what is missing

DEC-021 / DEC-015 / DEC-008: none (a Host policy; the library verifies the credential it is given). **N-5** — *option 2*: `STEAL2-R`: `STEAL2` with the second device re-issued at the event; gates: second-device wait after the change 0 s on the P lane; thief P after the change 0; thief U reach after ≤ the reference (27); `NOUA2` and every K4P rule.

## D7. `U_glob` and multi-IP spray (P4) — a decision that a value does not settle

**What is decided.** Whether any ceiling above the source and the account exists for the strangers' door, and for the proof door. No value is listed because the question is the cost of having one, not its number.

### D7.1 What was measured, and the options

| Cell | `S0` | `U_src` 24, no cap | with `F` 4 net (`FULL2`) | Source |
| --- | --- | --- | --- | --- |
| `POI` (8 IPs, one fresh account each) | 12 legit denied / **80** | **180** | **96** | G3v2 §3 |
| `SP4b` (8-IP botnet, 3 victims per IP) | **432 / 432** (K1 blind: fewer than 4 per IP) | not run | not run | 108 `REPORT.md`, `expected/summary.md` |
| `X2` (one attempt per source and account) | 121 / 121 | 121 / 121 | 121 / 121 | G3v2 §3; the best response reaches 121 on every arm |

`U_glob` (1000 per window, the archive value) "binds only on X2 / X2API" in the study (G1V §7, I7). A finite global ceiling is a lane-wide denial lever: a distributed attacker that exhausts it refuses every legitimate user of the lane, renewably each window (SF B4-3, B4-5, `DERIVED`; the same lever measured
on the proof path: with global proof-path ceilings, 8 of 12 and 2 of 12 owners are admitted under attack, G2 `MEASURED`, "needs an Owner policy"). #115 measured that a per-scope bound multiplies by the scopes the attacker holds.

| Option | Meaning | Cost (what is measured or derived) |
| --- | --- | --- |
| **1** no global ceiling | the aggregate cost of a distributed many-victim attack has no bound | `X2` 121 / 121, `SP4b` 432 / 432 on S0, `POI` 96 with `F` 4: unchanged from today's best response |
| **2** a finite `U_glob` | a ceiling on all U-door reaches per window | denies every legitimate unproven user while it binds; no value measured on the U door (N-6) |
| **3** per-policy ceilings | one ceiling per policy | bypassed by splitting traffic across policies that share a verifier (SF B4-6, `DERIVED`) |
| **4** a P-door ceiling (`P_glob`, `P_src`) | a ceiling on proof-door reach (the proposal's open D5) | a lever for an attacker with enough own proven accounts (SF B4-4); `TMSd` on `FULL2` shows what the P door bounds today (D8) |

### D7.2 Effects and what is missing

DEC-021 / DEC-015 / DEC-008: a global counter is one more admission key, absent in degraded mode. **N-6** — *the lever on the U door*: arm `FULL2` with a finite `U_glob` and a distributed attacker beside the legitimate population; gates: the share of legitimate unproven requests refused while the attacker holds `U_glob`, recorded for the Owner-stated value.

## D8. What replaces the bounds K2 gives against the multi-session and API families

**What is decided.** DEC-022 Consequences: converting K2 to observation removes the bound K2 gives today against an attacker holding valid sessions for many accounts and the low-volume API anti-evasion. Both stay gates (DEC-022 Decision 4); this decision names the replacement, if any.

### D8.1 The multi-session cells (G6 arm 2, `MEASURED`; reach of attempts reached / attempts)

| Cell | `S0` at `ce89cb2` (#113) | `S0` at this baseline | `FULL2` best response (`NOUA2` = `REF`) | `FULL2` `fresh` / `const` / no ids | What bounds it on `FULL2` |
| --- | --- | --- | --- | --- | --- |
| `TMS` (61, the office's cadence) `pre+out` / `pre` | 7/61 / 6/61 | 7/61 / 6/61 | **61/61** / 61/61 | 61 / 61 / 29 (`pre+out`) | nothing; ≤ 6 per account, below `P_acct`, `P_proof` and the allowance |
| `TMSd` (200, dense) `pre+out` / `pre` | 2/200 / 2/200 | 2/200 / 2/200 | **120/200** / 120/200 | 120 / 60 / 16 | `P_acct` 10 per account and window × 12 accounts |
| `TMX` (one session, 12 accounts) `pre+out` / `pre` | **200/200** / 200/200 | **60/200** / 200/200 | 26/200 / 34/200 | 26 / 21 / 16 | source ceiling and failure cap on the 11 foreign accounts |

The `S0` value of `TMX` depends on the baseline: 200/200 before DEC-021 (#113 at `ce89cb2`), **60/200** in `pre+out` after it (the G1 archive at `4c6ca61`, reproduced in G6, V4). Other history: the G1 candidate gave `TMS` 61/61, `TMSd` 60, `TMX` 12 F + 5 own-proof P (`G1_STUDY.md` §6.2 item 5); #119 `MODEL` relief variants 61/61 and 60/200.

### D8.2 The API cells (G1 archive, `MEASURED`; attacker reach / attempts; legitimate denied / attempts)

| Cell | `S0` | G1 candidate (admission-only API, `A_src` 300) |
| --- | --- | --- |
| `D1API` legitimate users on a shared NAT | 595 / 600 | **0 / 600** |
| `X1API` by strategy (`none` / `uarot` / `rotate`) | 122 / 301, 301 / 301, **2 / 301** | 301 / 301 in all three |
| `X2API` by strategy (`none` / `uarot` / `rotate`) | 6 / 121, 121 / 121, 121 / 121 | 121 / 121 in all three |

`none` = no id and a constant UA, `uarot` = a rotating UA, `rotate` = a fresh client id per request (#125). The best response (`uarot`) is 301 / 301 and 121 / 121 on both; `S0` is stronger only against the cells where the attacker keeps its fingerprint (DEC-019: S0's low-volume API anti-evasion is stronger than EQV).

### D8.3 The options

| Option | Meaning | Cost |
| --- | --- | --- |
| **1** none | the P and U doors as measured | `TMS` 61/61, `TMSd` 120/200 on `FULL2` (D8.1) |
| **2** keep a K2-type churn control for multi-account sessions | the control that gives `S0` 7/61 and 2/200 | it cannot tell the office from the attacker: `TMS` is `N12T` request for request and **0 of 61 decisions differ** on S0 (113), so the same control costs the office 56 of 61 (`pre+out`); `DEC-022` Decision 2 would not apply to that cell |
| **3** a per-source or global P ceiling (`P_src`, `P_glob`) | a ceiling over the proof door | written only (AP D5); no value measured; D7 option 4 describes its lever |
| **4** API: keep today's API enforcement | K2 / K1 API roles unchanged (DEC-022: not #97) | S0's: `D1API` 595 / 600 denied |
| **5** API: admission-only (the G1 candidate) | `A_src` / `A_glob` only | `D1API` 0 / 600; `X1API` 301 / 301 and `X2API` 121 / 121 for every strategy |

### D8.4 Effects and what is missing

DEC-021 / DEC-015 / DEC-008: option 3 is an admission key; option 2 re-opens DEC-022 Decision 2 for one cell. **N-7** — *the replacement bound for the multi-session cells*: arm `FULL2` with the candidate bound; gates: `TMS`, `TMSd`, `TMX` ≤ the Owner-stated line, and `N12T` (the office) and the benign streams not above S0; the line is the Owner's.

## D9. K5 micro-cap and the known-device allowance under the new K5 material

**What is decided.** Whether the DEC-021 values (Login: K5 thresholds 8 / 12 / 16 with +2 per failure and decay of 1 point per 300 s, allowance 8, micro-cap 8; OTP: allowance 2, no micro-cap, +4 per failure) change when K5 moves to the instance id and the doors are split.

### D9.1 The measured option

| Option | Values | Measured under the new material | Source |
| --- | --- | --- | --- |
| **1** the DEC-021 values unchanged | Login allowance 8, micro-cap 8; K5 8 / 12 / 16; OTP allowance 2 | P door, id-keyed K5: `STABLE-600 / 300 / 60` = 45 / 44 / 45, **equal to S0 and to the reference**; max K4 score 7; `KDL` string `AAAA!SSSSSA!HSSS` equal to S0 on both doors; the Anti-Equilibrium watcher equal; a browser update is the same device (`UAC12` equals `KDL`) at a declared cost: the owner's `UAC` denials 6 → 9; the U-lane K5 and allowance are separate states (`k5`, unsuffixed counter), and the UK-lane state (D5) is separate again | G5v2 §3 (G1 items 1–5, G4 MECH), G6 §3.3 |

The separation of U and P does not change the values on the P door: the P-door numbers equal S0 under every id policy tested. On the U door the allowance enforces nothing today (79 / 79 / 79) unless D5 option 2 is adopted.

### D9.2 Evidence and what is missing

Enough for Login at the DEC-021 values. Not measured: any other value; **OTP under `FULL2`** (G4 and G5 ran the Login shape only; OTP appears only on S0 in #113). **N-8** — *any other value, and OTP*: arm `NOUA2` with the value and the OTP shape; gates: G4 MECH (allowance, K4 overflow, K5, Anti-Equilibrium equal S0 on the P door) and `STABLE-i`, `KDL`, `AE` on the OTP shape.

## 10. Interactions between the decisions

| Pair | How they interact |
| --- | --- |
| D1 · D2 · Host mode | `F`, the bucket refund and `pre+out` outcomes are linked: in `pre` the cap is inert and refunds cannot happen, so D1 options 4–9 equal options 2–3 and D2 harms success-heavy users there (DEC-023) |
| D2 · D5 | known-device routing around the bucket (D2's UAC column, D5 option 3) and the UK lane (D5 option 2) are two ways to treat the same owner; option 3 does not hold under a Host violation |
| D3 · D4 | rule C changes what `P_acct` bounds (38 → 120); the `N_P` member (fingerprint or id) decides whether the rule has any effect on STEAL |
| D4 · D6 | no K4P rule helps the second holder after the event; only re-issue (D6) does |
| D1 · D7 · D8 | a global ceiling (D7) and a P-door ceiling (D8 option 3) are the only written answers for `POI`-type and `TMSd`-type reach that a source ceiling does not bound |
| D5 · D9 | the allowance values (D9) are the ones the UK lane (D5 option 2) would run on its own state |

## 11. Gate-shaped items that need a measurement (none is run here)

| ID | For | Gate (register before running) | Where |
| --- | --- | --- | --- |
| N-1 | D1 other `U_src` / `F` | A1, A2, A3 (the Owner-stated spray line), `pre` reported | step 5 or a separate authorisation |
| N-2 | D2 other `C` / `T` | B1 (bound + independent recount), B2 (recovery), A1, `X1` / `BRU` / `BRD` recorded | same |
| N-3 | D3 other P caps / `N_P` | A1 with refund, C4v2, `UAX-NP-K`, `MULTITOK`, `TMSd` recorded | same |
| N-4 | D5 option 4 | U1 (`LOST` ≤ 45 / 44 / 45), U2 (no attacker cell above the reference) | same |
| N-5 | D6 option 2 | `STEAL2-R`: second-device wait 0 s on P, thief 0 P after, U reach after ≤ 27 | same |
| N-6 | D7 option 2 | the share of legitimate unproven requests refused while an attacker holds `U_glob`, at the Owner-stated value | same |
| N-7 | D8 options 2–3 | `TMS`, `TMSd`, `TMX` ≤ the Owner-stated line; `N12T` and benign streams ≤ S0 | same |
| N-8 | D9 other values; OTP | G4 MECH on the P door, `STABLE-i` / `KDL` / `AE` on the OTP shape | same |

## 12. What this paper does not decide

It chooses no option and no value; it does not approve `FULL2`, the identity layer, the UK lane or any Runtime; it changes no contract, preset, DEC-019 / DEC-021 text or status, and adds no line to `DECISIONS_INDEX.md`. It does not state that any measured point is acceptable.
