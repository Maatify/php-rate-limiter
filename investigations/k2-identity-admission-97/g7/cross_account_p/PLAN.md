# G7 cross-account P — pre-registered plan (#97, step 4, DEC-025 decision 8 direction 8.2)

> **Status:** PRE-REGISTRATION, plan **v1**. Committed **after** the design on paper ([`../../D8_CROSS_ACCOUNT_P_FAILURE_DESIGN.md`](../../D8_CROSS_ACCOUNT_P_FAILURE_DESIGN.md), `10aee4f`) and **before** any G7 harness code and any G7 result.
> Evidence only. **Not** an architecture, policy, threshold, contract or public-API approval. No change to `src/`, `tests/`, `composer.json`, `composer.lock`, presets, public contracts, ADRs, standards,
> DEC-015 / DEC-019 / DEC-021 / DEC-023 / DEC-024 / DEC-025, `DECISIONS_INDEX.md`, or any earlier study or archive.
> **Baseline:** the branch is cut from `main@44e4cd8` (PR #139). `src/`, `composer.json`, `composer.lock` are byte-identical to the G1 baseline `4c6ca618d8206ba502961eaf06ffb82a5d61b50d` (checked by `run.sh`).
> **Archives, read-only:** `../../g5/identity_layer_v2/` (library, builder, patches, `expected/`), `../../g6/budgets/` (library and `expected/`), and through them G5, G4 v2, G4, G3 v2, G3, G1. They are reused and never edited or regenerated.
> **Owner scope (DEC-025, recorded by PR #140 once merged; the Owner's choice is [PR #139 comment 6263682706](https://github.com/Maatify/php-rate-limiter/pull/139#issuecomment-6263682706)):** decision 8, direction 8.2, conditional on this measurement.
> `U_src` 24, `F` 4 (net, U door), `T` 1800 s, `C` 4, set R (`P_acct` 10 / 60, `P_proof` 6 / 30, `N_P` 10) are archive values, not choices. Test values only. No API shape.

## 0. Question

Can a **narrow rule on the proven door** bound an attacker who holds valid sessions for many accounts behind one source, **without refusing the legitimate office** (`N12T`) more than `FULL2` does today?
The rule is the one of the design paper: per window, per source, on P only, the set of **different accounts that failed through P**; success neither counts nor refunds; capacity only, no escalation, no persisted block; a separate counter from the
U door's `cap:fail_src`.

## 1. Pre-plan disclosure

No G7 pilot was run. Known before this plan and used by it, all `MEASURED` in the G6 archive: on `FULL2` (`REF` = `NOUA2`, identical in every cell) the best response over id policies reaches `TMS` 61/61 and `TMSd` 120/200 in `pre+out`, 61/61 and 120/200 in `pre`,
`TMX` 26/200 and 34/200; `S0` at this baseline gives 7/61, 2/200, 60/200 (`pre+out`) and 6/61, 2/200, 200/200 (`pre`). **`N12T` (the office, 61 requests) and `TPAIRT` have never been run on `FULL2` or on `NOUA2`**: `N12` (not `N12T`) is among the 22 G3 streams.
The primary threshold was chosen before any run, from the archive values of `F` (4: the U door's failure cap; 8: the neighbouring archive value of G3 v2).

## 2. Builds and arms

**Build:** the archived `G5V2` tree, unchanged (pipeline sha `dcf1161a…3b77`). The rule is an **admission-layer** component of the harness (like the U door's failure cap in the archived `G3V2Gate`): **no patch, no change to the pipeline.**
It acts in the replay: before the P admission of a request that the pipeline allowed, and after the outcome of an admitted P request.

| Arm | Rule | Role |
| --- | --- | --- |
| `NOUA2` | off | **reference** = `FULL2` with the G5 v2 identity layer; its archived rows (G5 v2, G6) are the reference values; the new code reproduces them (V0, V1) |
| **`PX4`** | on, `F` = 4, window 600 s | **primary arm** |
| `PX8` | on, `F` = 8 | neighbouring threshold, **reporting only** |
| `D-PXALL` | on, `F` = 4, but **every admitted outcome counts** (not only failures) | detector of gate X1: the check can see a rule that refuses the office |

Rule, exactly: a P request for account `a` from source `s` (the `U_src` source key) in window `w = ⌊t / 600⌋` is refused with cause `cap:pfail_src` **iff** `a` is not yet in `S(s, w)` and `|S(s, w)| ≥ F`. `a` joins `S(s, w)` when an admitted P attempt for `a` from `s` records a failure (`PX4`, `PX8`); in `D-PXALL`, on any recorded outcome.
Success never removes a member. The refusal happens before the P budgets are reserved. The set is per window and is not persisted. In `pre` no outcome arrives, so the set stays empty (the rule is inert, as the U failure cap is).

## 3. Input integrity and validity

* **IN:** the archived `g4v2AssertInput()` runs in every replay (exit 6). **D-IN:** the archived `v2_detector.php`, run unchanged on the `G4` tree, is byte-identical to its archive (exit 6). **NP-IN:** the archived audit after every P admission (exit 7).
* **V0 (reference through new code):** `NOUA2` replayed through the G7 code (rule off) equals the G5 v2 archive field for field on the 182 STREAMS rows, the fixture rows the G7 fixtures scenario reproduces, STEAL, and the NP-IN rows. Exit 5.
* **V1 (TMS family):** the `NOUA2` rows of `TMS`, `TMSd`, `TMX` produced by the G7 code equal the G6 archive's `NOUA2` rows field for field. Exit 5.
* **V2 (S0):** the archived `g6_tms_s0` scenario, run unchanged, is byte-identical to the G6 archive. Exit 5.
* Provenance: the `G5V2` pipeline, resolver and DTO shas are asserted (as G5 v2). Exit 4.

## 4. Fixtures

* **STREAMS:** the 22 G3 streams (attack id policies `fresh` and `const`, flows `pre+out` and `pre`, and the same streams with no ids) for `NOUA2`, `PX4`, `PX8`.
* **Office and multi-session cells:** `N12T`, `TPAIRT` (the office plus the `TMSd` attacker behind one NAT), `TMS`, `TMSd`, `TMX`, same policies and flows, for `NOUA2`, `PX4`, `PX8`; `N12T` and `N12` also for `D-PXALL`.
* **Fixtures** (G5 v2's, run as in G6): HAR-B, HAR-N, BRU24, BRU24-HV, REC, CROSS, CLEAR, E2, K5U, BOOT, BOOT-X, UAX-NP, FORGE, MINT, LOST, TOKEN, UAX-ASM, MIXPRES-ASM, and STEAL (thief side), for `NOUA2`, `PX4`, `PX8`; BRU24 and BRU24-HV for `D-PXALL`.

## 5. Gates

A gate is **all** of its items. A gate whose detector does not fire is not evidenced and counts as failed, named. Reference values are the archived `NOUA2` rows, except for `N12T`, `TPAIRT`, which the reference arm produces in this run (it has no archive).

**X1 — the office (decisive).** For `N12T` **and every legitimate STREAMS row** (the 22 streams: every strategy, id policy and flow), legitimate requests denied under `PX4` are **≤ the reference's**, in `pre+out` **and** `pre`. (`pre`: the rule is inert, so it equals.)
**Detector:** `D-PXALL` denies more legitimate requests than the reference on `N12T` or `N12` in `pre+out`.

**X3 — `TMX`.** The best response over id policies of `TMX` under `PX4` is **≤ the reference's** in each flow (the archive's 26/200 in `pre+out` and 34/200 in `pre`).

**X4 — no attacker cell above the reference.** For every attacker cell, `PX4` ≤ the reference: every STREAMS row (`reachForeign + reachProof`; this includes `X1`, `BRU`, `BRD`), BRU24 and BRU24-HV admissions, HAR-B and HAR-N attack reach, FORGE (thief P, thief U), MINT (reach, P lane), CROSS (victim reach, victim P), BOOT-X attack reach,
STEAL (thief P before / per window / after, thief U after), and `TMS`, `TMSd`, `TMX` rows.

**Detector (the rule has something to bound):** the reference arm returns **61/61** for `TMS` and **120/200** for `TMSd` (best response over id policies, `pre+out`); if it does not, the study is not measuring what DEC-025 recorded and the gate set fails.

**X2 — `TMS` and `TMSd` against S0 (declared cost, not a gate).** Registered **before the run** as a **declared cost** because the rule acts only in `pre+out` and cannot reach S0's bound (K2 churn on the first request, design §6): the best response under `PX4` is **reported with its measured number against S0's 7/61 and 2/200**
(`pre+out`; `pre` reported too, where the rule is inert). It decides no verdict by itself; the Owner reads the number.

## 6. Verdict rules (fixed now)

1. A harness-validity failure (V0, V1, V2, IN, D-IN, NP-IN, provenance) ⇒ no verdict; the run is invalid.
2. **SUPPORTED — LIMITED SCOPE** if X1, X3 and X4 pass **and** every detector fires; X2 is reported as a declared cost with its measured number. Otherwise **NO-GO AFTER EXPERIMENT**, naming every failed item, and **option 8.2 falls**: option 8.1 is the declared cost
   (to be recorded by a later record, not by this PR).
3. No gate is re-scoped after a result. There is no `POST-HOC` material in any gate. A failure does not re-open fixed evidence. A change needs a new plan.
4. `PX8` never rescues a failed `PX4` and never fails a passing one.
5. Nothing here is an Architecture GO, an ADR, a production value, or a statement that Runtime is ready. `SUPPORTED` says only that the rule is measurable without a loss in these cells (the office, `TMX`, the attacker cells) on the reference Host mode `pre+out`.

## 7. Reporting rules (not gated)

Every table separates `NOUA2` from `PX4` / `PX8`, gated from reported, and states per number whether it equals the archive. Reported with numbers: X2; the whole `TMS` family and `TPAIRT` (office denied and attacker reach, both populations) for the three arms; the number of `cap:pfail_src` refusals by population;
every STREAMS and fixture row where `PX4` or `PX8` differs from the reference; the `D-PXALL` rows.

## 8. What this cannot prove

Real cookies and Hosts; Redis, concurrency, failover, DEC-015 degraded mode; `pre` and `out` Hosts (the rule is inert or absent there); offices larger or with higher typo rates than `N12T`; a mass password-reset event; the proof-acquisition model M1 that the `TMS` family inherits;
OTP and API; any value other than `F` 4 and 8, window 600 s; the other decisions of DEC-025.

## 9. Reproduction

```bash
bash investigations/k2-identity-admission-97/g7/cross_account_p/run.sh
```

Builds the G4, G5 and G5V2 trees with the archived builders (unchanged); runs every scenario under `-d memory_limit=1G` (**passed to every `php` call, the first included**); runs the archived `v2_detector` and `g6_tms_s0` scenarios unchanged; the report; diffs against `expected/`.
Exit 0 = SUPPORTED; 3 = harness valid and NO-GO; 5 = V0 / V1 / V2 miss; 6 = IN / D-IN failure; 7 = NP-IN failure; 4 = baseline / provenance; 1 = output differs or a scenario fails. `UPDATE=1` regenerates `expected/`.
