# G3 v2 — Net failure cap and known-device routing (#97)

> **Status:** G3 v2 INVESTIGATION EVIDENCE for architectural review. Not an ADR, not a policy, not a production value, not a
> statement that Runtime is ready, and not an Owner decision on `U_src` or T. No change to `src/`, `tests/`, `composer.json`,
> public contracts, presets, thresholds, ADRs, standards, or the G1 / G2 / G3 v1 evidence. DEC-015, DEC-019 and DEC-021 stay ACTIVE.
> **Pre-registration:** [`g3/admission_budgets_v2/PLAN.md`](g3/admission_budgets_v2/PLAN.md) (plan v2), committed in `bda4d65`
> **before** any v2 harness code or result. Gates, values and verdict rules were not changed afterwards.
> **Predecessor:** G3 v1 ([`G3_ADMISSION_BUDGETS.md`](G3_ADMISSION_BUDGETS.md)); its plan, harness, outputs and verdicts are
> unchanged. v2 reuses the v1 library and builder read-only.

## 1. Baseline and provenance

| Item | Value |
| --- | --- |
| Source baseline | `main@3465dd371c9dafc0d5056fae560cfe1a4042fa8f`; `src/`, `composer.json`, `composer.lock` byte-identical to `4c6ca61` (checked by `run.sh`) |
| Builds | `CAND` and `NOESC`, built by the v1 builder with its pinned hashes (pipelines `1840d6e2…dfd9` and `2926e1c5…1db4`) |
| New code | `g3/admission_budgets_v2/lib/g3v2.php` (the v1 gate logic with a `net` and a `known` switch, and the v1 replay for it), two scenarios, `report.php`, `run.sh` |
| V0 | **PASS**, 211 rows: S12 / S24 / S40 / FC4 on CAND and TB30R on NOESC equal the v1 archive field for field |
| Reproduction | `bash investigations/k2-identity-admission-97/g3/admission_budgets_v2/run.sh`; two consecutive runs byte-identical. Exit 3 = outputs identical, harness valid, a registered gate fails (FCN-2 for `FCN4-40`) |

## 2. Verdicts (`PLAN.md` §6)

| Subject | Verdict |
| --- | --- |
| **FCN** (net failure cap) | **EXPERIMENTALLY SUPPORTED — LIMITED SCOPE: `FCN4-24`**, `pre+out` Hosts only |
| `FCN8-24`, `FCN8-40` | FCN-1 … FCN-3 pass; FCN-4 (the `pre` gate) was registered for the F = 4 arms only, so the rule gives them no verdict |
| `FCN4-40` | FCN-2 fails: D2 29 > S40 27 |
| **TBK10R**, **TBK30R**, **TBK60R** (bucket + refund + known-device routing) | **EXPERIMENTALLY SUPPORTED — LIMITED SCOPE**, conditional on Host-honest known-device signals (§4.3) |

Both detectors fired: the gross cap `FC4-40` denies more of D2 than the net cap `FCN4-40` (32 vs 29), and the unrouted `TB30R`
still exceeds S40 on UAC (9 vs 6).

## 3. Results (`MEASURED`, `pre+out`, legitimate denied / best-response `reach_foreign`)

| Stream | S0 | S24 | **FCN4-24** | S40 | FCN8-40 | TBK30R |
| --- | --- | --- | --- | --- | --- | --- |
| L1 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 |
| ABA_B | 118 / 0 | 72 / 0 | 72 / 0 | 56 / 0 | 56 / 0 | 40 / 0 |
| D2 | 117 / 0 | 39 / 0 | 39 / 0 | 27 / 0 | 27 / 0 | 0 / 0 |
| N12 / Nu4 / Y6b / OFF12 | 56 / 47 / 28 / 8 | all 0 | all 0 | all 0 | all 0 | all 0 |
| KD / UAC | 9 / 6 | 9 / 6 | 9 / 6 | 9 / 6 | 9 / 6 | 9 / 6 |
| MIX (legit / attack) | 117 / 0 | 63 / 27 | 66 / 8 | 59 / 55 | 65 / 16 | 35 / 55 |
| SP6 / SP3 / SP1n / ABA_A (reach) | 20 / 7 / 4 / 3 | 144 / 48 / 24 / 48 | **24 / 8 / 4 / 8** | 240 / 80 / 40 / 80 | 48 / 16 / 8 / 16 | 240 / 80 / 40 / 80 |
| POI (reach) | 80 | 180 | 96 | 180 | 180 | 180 |
| X1 / BRU / BRD (reach) | 2 / 3 / 3 | 2 / 3 / 3 | 2 / 3 / 3 | 2 / 3 / 3 | 2 / 3 / 3 | 4 / 5 / 5 |
| X2 / X7 (reach) | 121 / 40 | same | same | same | same | same |

Every arm, the `pre` flow and the BRU24 / REC tables are in
[`g3/admission_budgets_v2/expected/summary.md`](g3/admission_budgets_v2/expected/summary.md).

## 4. Findings

### 4.1 Net failure cap

* **The net rule is what relieves offices with typos.** A typo followed by a successful retry no longer counts: D2 goes from 32
  (gross cap, v1 FC4) to 29 at the 40 ceiling, and at the 24 ceiling the cap adds **nothing** to S24 on any non-co-located benign
  stream (D2 39 = 39, ABA_B 72 = 72, all others 0).
* **`FCN4-24` brings single-IP spray close to S0:** SP6 24 (S0 20), SP3 8 (7), SP1n 4 (4), ABA_A 8 (3), MIX attack 8 (0), and it
  lowers the multi-IP POI from 180 to 96. Every benign stream is at or below S0.
* **It does not dominate S0.** SP6, SP3, ABA_A and MIX attack stay a few attempts above S0, so this is still a written budget,
  not parity (`IDENTITY_ATTRIBUTION_PROPOSAL.md` §6.3).
* **Co-located attacker (MIX).** 66 legitimate denials, against 63 for S24 and 117 for S0. It is better than today and slightly
  worse than the plain source cap; the residual is the one no source rule can remove.
* **`pre` (checkOnly-only Hosts).** The cap is inert without outcomes (FCN-4 PASS: decisions identical to S24 / S40), so those Hosts
  get the outer ceiling only: SP6 144 at 24.
* **Why not "two failures per account".** Spray tries each victim once, so a per-account repeat threshold would never count a spray
  victim. The plan rejected it before any run.

### 4.2 Bucket with known-device routing

* **UAC is fixed.** Routing the owner's known device away from the bucket gives UAC 6 and KD 9, exactly S40, for every T; every
  other benign stream is at or below S40 (D2 0, ABA_B 40, MIX 35; part of that gain is the `NOESC` ablation, as disclosed in v1).
* **The bound and the recovery hold.** BRU24 admissions 147 / 51 / 27 against ceilings 148 / 52 / 28 with an equal recount; the
  corrected recovery gate (wait from arrival ≤ `max(0, T + 60 − δ)`) passes for every offset and every T.
* **`pre` (reported only).** Refunds need outcomes, so a checkOnly-only Host loses success-heavy streams again: L1 122 of 134 and
  D2 61 under TBK30R. The bucket helps those Hosts against guessing (BRU 5 against S40's 80) but harms their heavy legitimate users.

### 4.3 Host-violation control (`TBK30R-HV`, reported only)

When the Host marks the attacker's requests as known for the account, **all 5,760 BRU24 attempts reach the verifier**: the routing
skips the bucket, `NOESC` has removed every account-scoped reaction, and a rotating attacker gets a fresh K5 each time. Known-device
routing therefore trusts the Host exactly as DEC-021 does today (#119 measured the same dependency: SPV 4 → 200/200), and in this
design it removes the last per-account bound for such a Host. A known-device route that is adopted needs its own per-account
bound — for example the DEC-021 allowance overflowing into the bucket instead of into the removed K4 path. That was not tested.

## 5. What this means for the proposal (`DERIVED`, not decisions)

| Proposal item | Effect of v2 |
| --- | --- |
| Spray budget (§6.3) | `U_src` = 24 with a net failure cap F = 4 is a measured configuration that keeps every benign stream at or below S0 and equal to S24 outside the co-located case, with single-IP spray within a few attempts of S0. Adopting it is still an Owner security decision, limited to `pre+out` Hosts; checkOnly-only Hosts get 24 / window |
| Per-account guessing budget (§6.2) | With refund and known-device routing the bucket passes every registered gate. Two conditions remain before adoption: a per-account bound for known devices (§4.3), and an answer for checkOnly-only Hosts, where refunds cannot happen |
| Binding (§7) | Known-device routing is a stand-in for binding and inherits the Host-trust risk; the library-verified binding of the proposal would narrow it to stolen tokens, and is still not built |

## 6. Deviation from the plan (review F130-01)

`PLAN.md` §5 ends with "`TBK30R-HV` and the `out` flow are reported without a gate." The Host-violation control was run and is
reported (§4.3). **The `out` (record-only) flow was not executed in v2:** `PLAN.md` §4 lists only `pre+out` for every arm and `pre`
for `FCN4-24`, `FCN4-40` and `TBK30R`, and the v2 scenarios implement exactly that list. There are therefore **no v2 `out`
measurements** in this archive, and none are reported here.

This is an omission of one non-gated reporting item. It does not touch any registered gate, the archived results or a verdict, and
the plan is left as committed. The only `out` numbers in the G3 series are the v1 ones (S40, FC8 and TB30R; `G3_ADMISSION_BUDGETS.md`
and `g3/admission_budgets/expected/summary.md`), which do not include the v2 arms. Measuring v2 `out` would need a separate
pre-registered supplement if the Owner asks for it; this report does not open one.

## 7. Limits

As v1: reference stores and a controlled clock, no Redis or concurrency, synthetic streams, test values only. CGN was not re-run.
No v3 is opened by this report.
