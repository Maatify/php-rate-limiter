# G7 cross-account P — a failure-capacity rule on the proven door, measured on `FULL2` (#97, step 4, DEC-025 decision 8 direction 8.2)

> **Status:** G7 INVESTIGATION EVIDENCE for the conditional direction of DEC-025 decision 8. Not an ADR, not a policy, not a production value, not a public API shape, not a statement that Runtime is ready, and not an Owner decision.
> No change to `src/`, `tests/`, `composer.json`, presets, public contracts, DEC-015 / DEC-019 / DEC-021 / DEC-023 / DEC-024 / DEC-025, `DECISIONS_INDEX.md`, or any earlier study or archive (the G5 v2, G6 and G4 v2 archives are reused read-only).
> **Design on paper:** [`D8_CROSS_ACCOUNT_P_FAILURE_DESIGN.md`](D8_CROSS_ACCOUNT_P_FAILURE_DESIGN.md) (`10aee4f`). **Pre-registration:** [`g7/cross_account_p/PLAN.md`](g7/cross_account_p/PLAN.md) (v1, `fa7ad3f`), committed **before** the harness (the harness commit) and before any
> archived result. Gates, values and verdict rules were not changed afterwards (§5). **No `POST-HOC` material.**
> **Owner scope:** the Owner's choice on [PR #139 comment 6263682706](https://github.com/Maatify/php-rate-limiter/pull/139#issuecomment-6263682706). `U_src` 24, `F` 4 (U door), `T` 1800 s, `C` 4, set R are archive values. Test values only.

## 1. Baseline and provenance

| Item | Value |
| --- | --- |
| Source baseline | `main@44e4cd8`; `src/`, `composer.json`, `composer.lock` byte-identical to `4c6ca61` (checked by `run.sh`) |
| Build | the archived `G5V2` tree, unchanged (pipeline `dcf1161a…3b77`): **no patch, no change to the pipeline.** The rule is an admission-layer component of the harness, like the U door's failure cap (`G3V2Gate`) |
| New code | [`lib/g7.php`](g7/cross_account_p/lib/g7.php) (`G7PxGate`, `g7Replay` = the G6 replay without the UK lane plus the rule), two scenarios, [`report.php`](g7/cross_account_p/report.php), [`run.sh`](g7/cross_account_p/run.sh) |
| The rule | per window (600 s) and source, on P only: a P request for a **further** account is refused (`cap:pfail_src`) when the source's set already holds `F` accounts that **failed through P**; success neither counts nor refunds; capacity only, nothing persisted. Arms: `NOUA2` (rule off, the reference), **`PX4`** (`F` 4, primary), `PX8` (`F` 8, reporting), `D-PXALL` (every admitted outcome counts, the detector) |
| Reproduction | `bash investigations/k2-identity-admission-97/g7/cross_account_p/run.sh`; two consecutive runs byte-identical, the second under a `php.ini` that sets only `memory_limit=128M`; the runner passes `-d memory_limit=1G` to **every** php call. Exit 0 = SUPPORTED. PHP 8.5.9 |

### 1.1 Harness validity (PLAN.md §3)

| Check | Result |
| --- | --- |
| **V0** | PASS: `NOUA2` through the G7 code equals the G5 v2 archive field for field: 182 stream rows, 18 fixture rows, STEAL, 2 NP-IN rows |
| **V1** | PASS: the 18 `NOUA2` rows of `TMS`, `TMSd`, `TMX` equal the G6 archive |
| **V2** | PASS: the archived `g6_tms_s0` scenario, run unchanged, is byte-identical to the G6 archive |
| **IN / D-IN** | PASS: the archived assertion runs in every replay; the archived `v2_detector.php`, run unchanged, is byte-identical (3 families) |
| **NP-IN** | PASS: 11 audited scenario parts, 9,643 P admissions; the member is the fingerprint in every one |

## 2. Verdict (PLAN.md §6)

**SUPPORTED — LIMITED SCOPE.** X1, X3 and X4 pass, every detector fires, X2 is a declared cost with its measured number (§3.3).

| Gate | Result | Detector |
| --- | --- | --- |
| **X1 — the office** (`N12T` and the 22 legitimate streams, `pre+out` and `pre`) | **PASS** (60 rows, none above the reference; `N12T` 0/61 against 0/61) | fires: `D-PXALL` refuses 32/61 of `N12T` and 24/61 of `N12` |
| **X3 — `TMX`** | **PASS** (26/200 and 34/200, equal to the reference) | — |
| **X4 — attacker cells** | **PASS** (210 stream rows, BRU24, BRU24-HV, HAR-B, HAR-N, FORGE, MINT, CROSS, BOOT-X, STEAL) | — |
| Study detector: the reference returns `TMS` 61/61 and `TMSd` 120/200 | fires | 61/61 and 120/200 |
| **X2 — `TMS`, `TMSd` against S0** | **declared cost** (registered before the run) | — |

What the verdict says, and what it does not: it says that the rule is **measurable without a loss in the registered cells**: the office alone, `TMX`, and every attacker cell. It does **not** say that the rule brings `TMS` and `TMSd` to S0's values (it does not, §3.3), that the office is
unaffected when an attacker shares its source (it is not, §3.4), or that Runtime is ready. It applies to `pre+out` Hosts only (§3.5).

## 3. Results (`MEASURED`)

Full tables: [`g7/cross_account_p/expected/summary.md`](g7/cross_account_p/expected/summary.md).

### 3.1 X1 — the office

`N12T` legitimate denied / 61: reference 0, `PX4` 0, `PX8` 0 (`pre+out`, policies `fresh` and none); 1 / 61 in `pre` with no ids, equal in every arm. No legitimate STREAMS row is above the reference. The detector shows why failures only: counting every admitted outcome refuses 32/61 of `N12T` and 24/61 of `N12`.

### 3.2 X3 and X4

`TMX` best response 26/200 (`pre+out`) and 34/200 (`pre`) in all arms: its foreign accounts are on the U door. BRU24 and BRU24-HV 51 / 51; HAR 4 / 4; FORGE 0 and 27; MINT 51 and 0; CROSS 4 and 0; BOOT-X 192; STEAL thief 9 / 2 / 0 and U after 27: all equal to the reference. Fixtures: none differs.

### 3.3 X2 — declared cost: the multi-session cells against S0 (best response over `fresh`, `const`, no ids)

| Stream | Flow | `S0` (this baseline) | reference `FULL2` | **`PX4`** | `PX8` |
| --- | --- | --- | --- | --- | --- |
| `TMS` | `pre+out` | **7/61** | 61/61 | **29/61** | 45/61 |
| `TMS` | `pre` | 6/61 | 61/61 | 61/61 | 61/61 |
| `TMSd` | `pre+out` | **2/200** | 120/200 | **40/200** | 80/200 |
| `TMSd` | `pre` | 2/200 | 120/200 | 120/200 | 120/200 |

The rule cuts `TMS` from 61 to 29 and `TMSd` from 120 to 40 in `pre+out` (`cap:pfail_src` 32 and 132 refusals), and does **not** reach S0's 7/61 and 2/200: it bounds the attacker to about `F` accounts per window, not to the first request. In `pre` it is inert (no outcome),
so the reference values stand.

### 3.4 `TPAIRT` (reported, not gated): the office and the `TMSd` attacker behind one NAT

| Flow | Policy | Arm | office denied / 61 | attacker reach / 200 |
| --- | --- | --- | --- | --- |
| `pre+out` | `fresh` | reference / **`PX4`** / `PX8` | 0 / **21** / 20 | 120 / **72** / 108 |
| `pre+out` | `const` | reference / **`PX4`** / `PX8` | 0 / **21** / 17 | 60 / **32** / 52 |
| `pre+out` | no ids | all arms | 21 | 32 |
| `pre` | all | all arms | 0 / 0 / 19 (no ids) | unchanged |

When the attacker shares the office's source, the rule refuses 21 of the office's 61 requests (`PX4`): the failed accounts of the attacker fill the source's set, and an office user on a further account is refused. This is the co-located residual that no source rule removes
(G3 §4.3); X1, as registered, gates the office alone. With no ids the reference already denies 21 (the U door's failure cap).

### 3.5 Hosts

The rule needs outcomes: in `pre` the set stays empty and every number equals the reference; in `out` nothing is bounded before the verifier in any design (DEC-023).

## 4. Findings

| ID | Finding |
| --- | --- |
| **F-G7-01** | **A per-window cross-account failure capacity on the proven door bounds the multi-session attacker on `FULL2`.** `TMS` 61 → 29 (`F` 4) or 45 (`F` 8); `TMSd` 120 → 40 or 80, in `pre+out`, with no attacker cell above the reference and `TMX` unchanged |
| **F-G7-02** | **It does not reach S0's bound.** 29/61 and 40/200 against 7/61 and 2/200: X2 is a declared cost of those numbers. S0's bound came from K2 churn on the first request; the rule allows about `F` accounts per window, each up to `P_acct` / `P_proof` |
| **F-G7-03** | **Counting failures only is what spares the office.** `N12T` and `N12` are 0/61 under both thresholds; counting every admitted outcome (`D-PXALL`) refuses 32/61 and 24/61. The office alone never has more than `F` different users failing in one window |
| **F-G7-04** | **The office pays when an attacker shares its source.** `TPAIRT` `pre+out`: office denied 0 → 21/61 (`PX4`; 20 and 17 under `PX8`), attacker reach 120 → 72 (`fresh`). The gate X1 did not cover this case; it is the residual of any source-scoped rule |
| **F-G7-05** | **Inert and absent where there is no outcome.** `pre`: the three arms equal the reference in every cell; `out` and degraded mode: no bound |

## 5. Plan conformance

* **Plan unchanged after the harness commit.** Gates, arms, values and verdict rules are those of `fa7ad3f`; there is no amendment and no `POST-HOC` material. X2 was registered as a declared cost before the run.
* **Development run before archiving (not archived):** one full run of the committed harness, whose result is the archived one (the same verdict and numbers; nothing was changed between it and the archive).
* **Interpretations.** The reference `FULL2` is `NOUA2` (the G5 v2 cell); in G6 `REF` and `NOUA2` are identical in every `TMS` cell. The reference rows of `N12T` and `TPAIRT`, which have no archive, are produced in the same run by the rule-off arm, whose code path V0 and V1 prove equal to the archives.
  The `TMS` family inherits the proof-acquisition model M1 and the corrected G4 v2 builder (a credential per fresh instance id of a proof holder), as G6.
* The runner passes `memory_limit=1G` to every php call from the first one.

## 6. What this means for decision 8 (`DERIVED`, not decisions)

DEC-025 recorded 8.2 as a direction conditional on this measurement, with 8.1 as the declared cost if the office gate failed. The registered gate passed. What the Owner reads from it: the rule keeps the office alone untouched and cuts the multi-session attacker by about half to two thirds on `pre+out`
Hosts, at the price of (i) not reaching S0's bound and (ii) a measured cost to an office that shares its source with an attacker. Whether those are acceptable, and whether to record 8.2 as a rule, is the Owner's; this PR changes neither DEC-025 nor the index (the update of decision 8 is for a later recording PR).

## 7. Limits

Reference stores and a controlled clock; no Redis, concurrency or failover; synthetic streams; study-code tokens and a Host model; the proof-acquisition model M1; offices larger than `N12T` and mass failure events (a password reset) are not measured; `F` 4 and 8 and the 600 s window only; Login only; `pre` and `out` Hosts are outside the rule; test values only.
