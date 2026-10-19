# G6 budgets — the two registered measurements of step 4: the UK lane (F-G5-04) and the TMS family on `FULL2` (#97)

> **Status:** G6 INVESTIGATION EVIDENCE for the step-4 decision paper. Not an ADR, not a policy, not a production value, not a public API shape, not a statement that Runtime is ready, and not an Owner
> decision on any budget. No change to `src/`, `tests/`, `composer.json`, public contracts, presets, thresholds, ADRs, standards, `DECISIONS_INDEX.md`, or any earlier study or archive (the G5 v2, G5 and G4 v2
> archives are reused read-only). DEC-015, DEC-019, DEC-021, DEC-023 and DEC-024 stay ACTIVE and unchanged; DEC-022 stays PROPOSED.
> **Pre-registration:** [`g6/budgets/PLAN.md`](g6/budgets/PLAN.md) (v1 in `c5262e0`, v1.1 in `7af3fc2`), committed **before** the harness (`1f278ca`) and before any archived result (`956ffa3`).
> Gates, values and verdict rules were not changed afterwards (§5).
> **Owner scope (step 4):** the assignment of 2026-10-19, item ج: **one** small registered measurement on the G5 v2 harness with its archive values, two arms only. `U_src` 24, `F` 4, `T` 1800 s, `C` 4,
> `N_P` 10 per day and the rest of the archive values are not choices. Test values only.
> **Evidence classes:** `MEASURED` (archived harness output, as planned), `POST-HOC` (added after a result, in no gate), `DERIVED`.

## 1. Baseline and provenance

| Item | Value |
| --- | --- |
| Source baseline | the branch is cut from `main@f4851eb` (G5 v2, PR #138, merged); `src/`, `composer.json`, `composer.lock` byte-identical to `4c6ca61` (checked by `run.sh`) |
| Reference builds | the archived builders, run unchanged: `G4` (pipeline `45d201d0…63fd`), `G5V2` (`dcf1161a…3b77`); `S0` = the repository's own unpatched `src/` (`40213945…eab6`, asserted) |
| New build | `G6UK` = `G5V2` + [`patches/g6-uk-lane.diff`](g6/budgets/patches/g6-uk-lane.diff) (patch sha-256 `d7e5d4aa…b050`, pipeline `924ce118…e7a7`, both pinned in [`build_g6.sh`](g6/budgets/build_g6.sh); only the pipeline file differs from `G5V2`, resolver and DTO are the G5 ones, asserted) |
| New code | [`lib/g6.php`](g6/budgets/lib/g6.php), six scenarios, [`report.php`](g6/budgets/report.php), [`run.sh`](g6/budgets/run.sh). The G5 v2, G5, G4 v2, G3 v2, G3 and G1 libraries, the Host model and the stream tables are reused read-only |
| Reproduction | `bash investigations/k2-identity-admission-97/g6/budgets/run.sh`; two consecutive runs byte-identical, the second under a `php.ini` that sets only `memory_limit=128M` (scan directory emptied); the runner passes `-d memory_limit=1G` to **every** php call. Exit 0 = arm 1 SUPPORTED; 3 = harness valid and arm 1 NO-GO (this result). PHP 8.5.9 here; the G5 v2 archive was produced on PHP 8.4.4 and, run unchanged on this machine before G6 started, reproduced byte for byte (PLAN.md §1) |

### 1.1 Harness validity (PLAN.md §3)

| Check | Result |
| --- | --- |
| **V0** | PASS: `NOUA2` through the new code on `G6UK` (the UK lane never requested) equals the G5 v2 archive field for field: 182 stream rows, 18 fixture rows, STEAL, 3 NP-IN rows |
| **V1** | PASS: the 28 pipeline rows of `G6UK` on the U and the P lane equal the G5 v2 archive |
| **V2** | PASS: the archived `g5_s0` scenario, run unchanged, is byte-identical to the G5 v2 archive |
| **V3** | PASS: 20 control rows (`N12`, `SP1n`) of `REF` and `NOUA2` equal their archive rows field for field |
| **V4** | PASS: the 6 `S0` rows of `TMS`, `TMSd`, `TMX` (`pre+out`, `pre`) produced here equal the G1 archive's `m01` `S0` rows |
| **IN / D-IN** | PASS: the archived assertion runs in every assembled replay; the archived `v2_detector.php` run unchanged is byte-identical (3 families) |
| **NP-IN** | PASS: 8 audited scenario parts, 4,451 P admissions; the member is the fingerprint in every one, the id component in none |

## 2. Verdicts (PLAN.md §6)

| Arm | Verdict |
| --- | --- |
| **Arm 1 — `UKD`** | **NO-GO AFTER EXPERIMENT (U1).** U2 PASS. Both detectors fire |
| **Arm 2 — TMS family on `FULL2`** | no verdict by design; the rows are in §4 |

| Gate | Result | Detector |
| --- | --- | --- |
| **U1 — LOST** `UKD` ≤ 45 / 44 / 45 | **FAIL**: 48 / 47 / 48 | fires (reference `NOUA2` 51 / 51 / 51) |
| **U2 — no attacker cell above the reference** | **PASS** (182 stream rows, BRU24, BRU24-HV, HAR-B, HAR-N, FORGE, MINT, CROSS, BOOT-X, STEAL) | fires (`D-UKBYP` admits 5,760 on BRU24-HV against 51) |

What the verdict says, and what it does not:

* It says that the registered gate **U1 failed**: with the account bucket in front, a known device that lost its token is held to 48 / 47 / 48 verifier reaches in 24 h, **3 above** DEC-021's 45 / 44 / 45 at each
  interval and 3 / 4 / 3 below the bucket-only value 51 / 51 / 51.
* It does **not** say the mechanism does not work: at the pipeline level the UK lane equals DEC-021 exactly (§3.3), and no attacker cell rises (U2). The gap is between the pipeline alone and the pipeline behind the
  U admission layer (§3.4, `POST-HOC`).
* It does **not** say what the Owner should choose, and it does not reopen the gate (PLAN.md §6.4). A variant that closes the gap is a new measurement (§6).

## 3. Results, arm 1 (`MEASURED` unless marked)

Full tables: [`g6/budgets/expected/summary.md`](g6/budgets/expected/summary.md).

### 3.1 U1 — LOST (reach in 24 h of a Host-known device with no token, failing every 600 / 300 / 60 s)

| Arm | 600 s | 300 s | 60 s |
| --- | --- | --- | --- |
| `S0` `STABLE-i` (DEC-021) | 45 | 44 | 45 |
| `NOUA2` (archive = new code) | 51 | 51 | 51 |
| **`UKD`** | **48** | **47** | **48** |
| `D-UKBYP` (G3 v2's known-device routing) | 144 | 79 | 77 |
| `TOKEN` (the same device **with** its token, P lane): `NOUA2` = `UKD` | 45 | 44 | 45 |

### 3.2 U2 — attacker reach against the reference (`UKD` / `NOUA2` archive)

STREAMS: **182 rows, none above the reference, none different** in decision, lane, reach or cause. Fixtures: `BRU24` 51 / 51; **`BRU24-HV` 48 / 51**; HAR-B and HAR-N 4 / 4; `FORGE` thief P 0 / 0 and U reach 27 / 27;
**`MINT` 48 / 51**; `CROSS` 4 / 4; `BOOT-X` 192 / 192; STEAL thief P before / per window / after 9 / 2 / 0 and U after 27, all equal.
`D-UKBYP`: `BRU24` 51 and **`BRU24-HV` 5,760** (the G3 v2 control's 5,760 of 5,760, reproduced in this assembly).

### 3.3 The UK lane at pipeline level (no admission layer)

| Fixture | `S0` | P lane | U lane | **UK lane** |
| --- | --- | --- | --- | --- |
| `STABLE-600` reach (max K4 score) | 45 (7) | 45 (7) | 144 (273) | **45 (7)** |
| `STABLE-300` | 44 (7) | 44 (7) | 79 (75) | **44 (7)** |
| `STABLE-60` | 45 (7) | 45 (7) | 77 (71) | **45 (7)** |
| `KDL` | `AAAA!SSSSSA!HSSS` | same | same | same |

The `_uk` allowance counter ends at 45 / 44 / 45: the DEC-021 mechanism, on its own state, gives DEC-021's bound.

### 3.4 `POST-HOC`: why the assembled value is 48, not 45 (not in the plan, not in any gate)

Added after a development run showed U1 failing. It re-runs the gated `LOST` replay unchanged and reads what the gate discards:

| Arm | Interval | Attempts | Reach = bucket admissions | Denials by cause | Failures counted on the allowance |
| --- | --- | --- | --- | --- | --- |
| `NOUA2` | 600 s | 144 | 51 | `cap:acct_tb` 93 | 51 (U counter) |
| `UKD` | 600 s | 144 | 48 | `cap:acct_tb` 59, `pen:K4UK` 16, `soft` 38 | 48 (`_uk` counter) |
| `UKD` | 300 s | 288 | 47 | `cap:acct_tb` 155, `pen:K4UK` 15, `soft` 87 | 47 |
| `UKD` | 60 s | 1,440 | 48 | `cap:acct_tb` 927, `pen:K4UK` 16, `pen:K5` 12, `soft` 467 | 48 |

`DERIVED` from §3.1 and §3.3 and this table: the 45 / 44 / 45 of DEC-021 is the pipeline's bound against a **continuous** stream of attempts. Behind the U admission layer the account bucket refuses most attempts
**before** the verifier runs, so those refusals record no failure; the allowance and K4 counters advance only on admitted attempts, the K4 and K5 scores decay between them, and the pipeline lets 3 more through than
it does alone. The same effect is why the `UKD` value is still **below** the bucket-only 51. The bucket's own ceiling (`C + ⌊86,400 / T⌋` = 52) is never exceeded.

### 3.5 The cost side (`MEASURED`, reported)

| Item | Result |
| --- | --- |
| STREAMS | 0 of 182 rows differ from `NOUA2`. 48 requests were routed to the UK lane, all in `KD` and `UAC` with no ids (12 requests × 2 flows × 2 streams); their decisions are unchanged (legit denied 9 / 9 in `pre+out`, 8 / 8 in `pre`) |
| Fixtures that differ | `BRU24-HV` 51 → 48, `MINT` 51 → 48, `LOST` 51 → 48 / 47 / 48 (all lower; none higher) |
| Host-violation (`BRU24-HV`, every attacker request marked known) | 48: the marked attacker writes the `uk` state and is bounded by it (`pen:K4UK` 64 denials); without the state, `D-UKBYP` admits 5,760 |
| Owner-side cost | a legitimate known device that lost its token and keeps failing now meets `pen:K4UK` / `soft` after the allowance, as it does today on S0 and on the P lane (45 / 44 / 45). The only legitimate streams routed to the UK lane are `KD` and `UAC` without ids, and their decisions are unchanged; no longer legitimate stream with a known device and no token exists among the 22, so the owner-side cost of a long failing run is measured only by `LOST` itself |

## 4. Results, arm 2 — the TMS family (reporting only; `MEASURED`)

Verifier reach, attempts reached / attempts. `S0` is the repository's `src/` **at this baseline**, re-run here (V4). `REF` = `FULL2` of G4 v2; `NOUA2` = `FULL2` with the G5 v2 identity layer (identical in every cell).
The attacker holds a valid session for each target (the archived mapping M1: a request whose session is bound to its own account holds a proof, hence the P lane when ids are present).

| Stream | Flow | `S0` | `FULL2` `fresh` | `FULL2` `const` | `FULL2` no ids | best response |
| --- | --- | --- | --- | --- | --- | --- |
| `TMS` (N12T cadence, 61) | `pre+out` | **7/61** | 61/61 (P 61) | 61/61 (P 61) | 29/61 | **61/61** |
| `TMS` | `pre` | 6/61 | 61/61 | 61/61 | 60/61 | 61/61 |
| `TMSd` (dense, 200) | `pre+out` | **2/200** | 120/200 (P 120) | 60/200 (P 60) | 16/200 | **120/200** |
| `TMSd` | `pre` | 2/200 | 120/200 | 72/200 | 24/200 | 120/200 |
| `TMX` (one session, 12 accounts; Host-violating) | `pre+out` | **60/200** | 26/200 (P 10) | 21/200 (P 5) | 16/200 | 26/200 |
| `TMX` | `pre` | 200/200 | 34/200 (P 10) | 30/200 (P 6) | 24/200 | 34/200 |

What bounds each cell on `FULL2` (attacker denial causes, `fresh`): `TMS`, none (every attempt reaches the verifier: per account at most 6, below `P_acct`, `P_proof` and the allowance);
`TMSd`, `P_acct` (10 per account per window, 12 accounts = 120; `cap:pacct` 80 in `pre`, `soft` 92 in `pre+out`); `TMX`, the source ceiling and the failure cap on the 11 accounts that are not the session's own (`pre+out`: `cap:fail_src` 115, `cap:acct_tb` 52; `pre`: `cap:src` 159, `cap:pacct` 7), plus `N_P` / `P_acct` on the one that is.

**History quoted from archives (not re-run here):**

| Source | `TMS` | `TMSd` | `TMX` |
| --- | --- | --- | --- |
| #113 `S0` at baseline `ce89cb2`, `pre+out` / `pre` | 7/61 / 6/61 | 2/200 / 2/200 | **200/200** / 200/200 |
| `S0` at `4c6ca61` (this baseline; G1 archive, V4), `pre+out` / `pre` | 7/61 / 6/61 | 2/200 / 2/200 | **60/200** / 200/200 |
| G1 candidate, set R (`G1_STUDY.md` §6.2 item 5) | 61/61 | 60/200 | 12 F + 5 own-proof P |
| #119 `MODEL`, relief variants | 61/61 | 60/200 | — |

## 5. Findings

| ID | Finding |
| --- | --- |
| **F-G6-01** | **The UK lane restores DEC-021's bound at the pipeline level, on its own state.** `STABLE-600 / 300 / 60` 45 / 44 / 45 with a maximum `k4uk` score of 7 and an allowance counter of 45 / 44 / 45, equal to `S0` and to the P lane; the `KDL` string is DEC-021's. No state is shared with the U strangers' door (`k4`, the unsuffixed allowance) or with the P door (`k4p`, `_p`) |
| **F-G6-02** | **Behind the U admission layer the registered gate U1 fails by 3 / 3 / 3** (48 / 47 / 48 against 45 / 44 / 45), while the value is 3 / 4 / 3 below the bucket-only 51. `POST-HOC`, `DERIVED`: refusals by the bucket record no failure, so the pipeline's counters advance on the admitted attempts only (§3.4). The 45 / 44 / 45 of DEC-021 is therefore not reproduced by this design (the pipeline behind the U admission layer); whether a design that counts those refusals as failures of the device would reach it is **not measured** |
| **F-G6-03** | **No attacker cell rises (U2).** `BRU24-HV` and `MINT` fall from 51 to 48; every other attacker cell is equal. The other way to route a known device (G3 v2's: not charged to the bucket) admits 5,760 on `BRU24-HV`, so the check can see a violation |
| **F-G6-04** | **`FULL2` bounds the multi-session attacker far less than S0 on the cells where S0 is strongest.** `TMS` 61/61 against 7/61; `TMSd` 120/200 against 2/200 (best response over id policies). The bound is `P_acct` per account and window times the accounts held, the form of bound `SECURITY_FEASIBILITY.md` row 7 derives (`≤ min(12·P_acct, P_glob)`); DEC-022's Consequences say only that K2's bound goes away unless Level 2 replaces it. `REF` and `NOUA2` are identical in every cell, so the identity layer neither helps nor hurts here |
| **F-G6-05** | **The `S0` reference of `TMX` depends on the baseline.** At `ce89cb2` (#113) `TMX` is 200/200; at `4c6ca61`, with DEC-021's K5 thresholds and allowance, it is **60/200** in `pre+out` and still 200/200 in `pre`. Where the paper and DEC-022 quote 200/200 for `S0`, it is the earlier baseline's value. `TMS` and `TMSd` are unchanged by DEC-021 (7/61, 2/200) |
| **F-G6-06** | **Under `FULL2` the Host-violating `TMX` is bounded below `S0` in both flows** (26/200 and 34/200 against 60/200 and 200/200): its eleven foreign targets sit on the U door, behind the source ceiling and the failure cap |

## 6. Plan conformance

* **Plan unchanged after the harness commit.** Gates, arms, values and verdict rules are those of `7af3fc2`. The verdict was computed by the registered rules; the gate U1 was not re-scoped after it failed.
* **Amendment before the harness commit** (PLAN.md §10): A1 — `D-UKBYP` is realised with the archived `G3V2Gate` `known` switch instead of a bypass of the whole U admission layer. It changes no gate or value.
* **Development runs before the harness commit (not archived):** two full runs of the harness. The first showed U1 failing (`LOST` 48 / 47 / 48). Nothing in the gates, arms or fixtures was changed after it; the only
  addition was the `POST-HOC` scenario `g6_posthoc.php` (§3.4), written after that run and committed with the harness; it reads the gated replay and varies nothing. (A defect in it, a fresh Host passed in place of the arm's own, was
  corrected in the second development run; the numbers of the archived run are those of the committed code.)
* **Interpretations.** The UK lane is requested for a request that is on the U door and Host-known by the pipeline's own predicate, evaluated by the harness on the resolver's DTO; the admission layer stays on the U door.
  Arm 2 inherits the archived mapping M1 and the corrected G4 v2 builder (which provisions a credential per fresh instance id of a proof holder). `TMS`'s `fresh` policy therefore models an attacker that can obtain a credential for
  each instance id it presents; this is the archived modelling, not a G6 choice.
* **Erratum found by an independent fact-check (the plan stays frozen; no number changes):** PLAN.md §7 and the first archived `summary.md` cite the G1 candidate's `TMS` / `TMSd` / `TMX` values as `G1_VERIFICATION.md` §6.2 item 5; the source is `G1_STUDY.md` §6.2 item 5. `report.php` and `expected/summary.md` were corrected (only that line changed); PLAN.md keeps the wrong file name.
* **Post-hoc material** (excluded from every gate): §3.4 and the explanation in F-G6-02.
* The runner passes `memory_limit=1G` to every php call from the first one; G6 does not run the memory-heavy CGN-B script.

## 7. What this means for the decision paper (`DERIVED`, not decisions)

| Decision (paper) | Effect of G6 |
| --- | --- |
| 5 — known device on the U door | A third measured option beside "bucket only" (51 / 51 / 51) and DEC-021's target: the UK lane, 48 / 47 / 48, no attacker cell higher, registered gate U1 **failed**. A variant that counts admission refusals as the device's failures is **not measured**; it is written as a gate-shaped item (paper §D5) |
| 8 — K2's replacement bounds | `TMS`, `TMSd`, `TMX` are now measured on `FULL2` (§4): the replacement bound for the multi-session cells is `P_acct` × accounts held, far above S0's. The gap is the Owner's Level-2 line |
| 1, 2, 3, 4, 6, 7, 9 | no new measurement; answered from merged archives or written as "needs a measurement" |

## 8. Limits

Reference stores and a controlled clock; no Redis, concurrency or failover; synthetic streams; study-code tokens and a Host model, not real cookies; the Host's "known" signal is trusted exactly as DEC-021 trusts it (the only measurement of
a Host that marks an attacker known is `BRU24-HV`); Login only (OTP and API not run); the `uk` state's migration and the interaction of the three allowance states are not measured; arm 2 inherits the proof-acquisition model M1;
`TMS`-family cells with real per-account cookies are not measured; the UK-lane value was measured at the archive's `T` 1800 s and `C` 4 only; test values only.
