# G8 bucket capacity — `C` ∈ {2, 3, 4} at `T` 1,800 s on the production code (#97, before stage 5-c, DEC-025 decision 2)

> **Status:** G8 INVESTIGATION EVIDENCE for OD-10. Not a decision on `C`, not an ADR, not a policy, not a production value, not a public-API shape, and not a security recommendation: the recommendation is the Lead's and the decision the Owner's
> (a change of `C` is a record superseding DEC-025, under DEC-025's own rule).
> No change to `src/`, to any existing file under `tests/`, to `composer.json`, presets, public contracts, decision records, `DECISIONS_INDEX.md`, or any earlier study or archive (all read-only).
> **Pre-registration:** [`g8/bucket_capacity/PLAN.md`](g8/bucket_capacity/PLAN.md) (v1, `a474b16`), committed **before** the harness (`da46e24`, runner fix `3b14509`, §6) and before any G8 result. Gates, values and outcome rules were not changed afterwards.
> **Owner decision:** [PR #150 comment 6298114042](https://github.com/Maatify/php-rate-limiter/pull/150#issuecomment-6298114042) (2026-10-20). **References:** [`BUDGETS_DECISION.md`](BUDGETS_DECISION.md) D2 and N-2 (L38, L117–L128, L142, L348).

## 0. The question

Does `C` = 2 or `C` = 3 at `T` = 1,800 s (refund on success unchanged) bring the best-response cells back to the S0 values or close to them, and at what cost to the benign user and to the known device that lost its credential?

## 1. Baseline, arms and validity

| Item | Value |
| --- | --- |
| Source baseline | `main@05922dd` (5-b3b merged); `run.sh` checks that `src/`, `tests/` and `composer.json` are byte-identical to it and clean |
| Composition | the production engine through the 5-b3b harness, unchanged: `EngineLab` (`RateLimiterEngine`, `EvaluationPipeline`, `DeviceIdentityResolver`, `AdmissionRuntime`, in-memory reference admission store), `StudyHost`, `ReplaySession` (IN on every row), `FixtureReplays`. `C` reaches the runtime only through `AdmissionBudgetsDTO` (`accountBucketTakes`), read back from the DTO into every output file |
| Arms | `FULL2` (no D8, = G7 `NOUA2`) and `PX4` (D8 `F` 4, the DEC-025 budgets), each at `C` 2, 3, 4 and the detector `D-NOTB` (`C` 1,000,000); every other value is the archive's (`T` 1,800 s, `U_src` 24, `F` 4, set R, `D` 30, `G` 600) |
| Replayed | per arm and `C`: 210 stream rows (91 G3 tuples and 14 G7 tuples × `pre+out`, `pre`), the 19 fixtures of `FixtureReplays`, and the `LOST` / `TOKEN` detail |
| New code | [`lib/g8.php`](g8/bucket_capacity/lib/g8.php), [`scenarios/g8_replays.php`](g8/bucket_capacity/scenarios/g8_replays.php), [`report.php`](g8/bucket_capacity/report.php), [`run.sh`](g8/bucket_capacity/run.sh); no request is decided by study code |
| Reproduction | `bash investigations/k2-identity-admission-97/g8/bucket_capacity/run.sh`: `PHP_INI_SCAN_DIR=` and `-d memory_limit=1G` on every php call; two consecutive runs byte-identical (18 output files, [`expected/SHA256SUMS`](g8/bucket_capacity/expected/SHA256SUMS)); full logs in [`logs/`](g8/bucket_capacity/logs/). PHP 8.5.9 |

### 1.1 Harness validity and detector (PLAN.md §4)

| Check | FULL2 | PX4 |
| --- | --- | --- |
| **V1** — 210 stream rows at `C` 4 equal the G7 archive field for field | **PASS** (210 / 210, 0 differ, against `NOUA2`) | **PASS** (210 / 210, 0 differ) |
| **V2** — 19 fixture rows at `C` 4 equal the G7 archive | **PASS** (0 differ) | **PASS** (0 differ) |
| **V3** — 57 `FULL2` `pre+out` rows at `C` 4 equal G4 v2 except `UAC\|none\|fresh` | **PASS** (only `UAC\|none\|fresh` differs: the declared G5 v2 cost) | — |
| **IN** | PASS (every row of every replay) | PASS |
| **D** (`D-NOTB`) — BRU24 > 52 with recount equal, `L1` `pre` 72 | **fires**: BRU24 5,760, recount 5,760, `L1` `pre` 72 | **fires**: same |

**The study is valid.** At `C` 4 the production engine returns the archived rows; with the bucket made unbounded, nothing else on the U door refuses a BRU24 attempt (5,760 of 5,760 admitted; `X1` / `BRU` / `BRD` 25 / 100 / 100).

## 2. Gates per `C` and arm (PLAN.md §5, `pre+out`)

| Gate | `C` 4 (reference) | `C` 3 | `C` 2 |
| --- | --- | --- | --- |
| **B1** — BRU24 / BRU24-HV ≤ `C + 48`, recount equal | PASS: 51 / 51 of 52 | PASS: 50 / 50 of 51 | PASS: 49 / 49 of 50 |
| **B2** — REC wait ≤ `max(0, T + 60 − δ)` at δ 0 / 60 / 300 / 900 / 1,800 / 3,600 s | PASS: 60 / 0 / 0 / 0 / 0 / 0 | PASS: same | PASS: same |
| **A1** — benign (with ids, `MIX` and `UAC` apart) ≤ S0 and ≤ S24 | PASS | PASS | PASS |
| **A3** — `SP1n`, `SP3`, `SP6`, `ABA_A` ≤ S12 | PASS | PASS | PASS |
| **PG** — P door (gated on `PX4`) | reference | **FAIL** (PG-1 pass, PG-2 fails on `TPAIRT` `pre`) | **FAIL** (same) |

`FULL2` and `PX4` give the same result in every cell above, `PG` included (reported on `FULL2`). The `PG` failure is analysed in F-G8-08: all of it comes from one U-lane tuple that the registration should have excluded. It stays FAIL (PLAN.md §6.3).

## 3. The table (PLAN.md §7)

Best response with ids, `pre+out`. Reach for `X1` … `SP3`; legitimate refusals for `L1` … `KD`. `LOST`-24h = admissions of the known device in 24 h at intervals 600 / 300 / 60 s. `REC` = owner wait from arrival at δ 0 (max over the six offsets). `BRU24` = admissions in 24 h (bound).

| Row | X1 | BRU | BRD | MIX atk | ABA_A | SP3 | L1 | D2 | ABA_B | KD | LOST-24h | REC | BRU24 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| **S0** | 2 | 3 | 3 | 0 | 3 | 7 | 0 | 117 | 118 | 9 | — | — | — |
| `FULL2` `C` 4 (reference) | 4 | 5 | 5 | 8 | 8 | 8 | 0 | 24 | 0 | 9 | 51 / 51 / 51 | 60 (60) | 51 (52) |
| `FULL2` `C` 3 | 3 | 4 | 4 | 8 | 8 | 8 | 0 | 24 | 0 | 9 | 50 / 50 / 50 | 60 (60) | 50 (51) |
| `FULL2` `C` 2 | **2** | **3** | **3** | 8 | 8 | 8 | 0 | 24 | 0 | 9 | 49 / 49 / 49 | 60 (60) | 49 (50) |
| `PX4` `C` 4 (reference) | 4 | 5 | 5 | 8 | 8 | 8 | 0 | 24 | 0 | 9 | 51 / 51 / 51 | 60 (60) | 51 (52) |
| `PX4` `C` 3 | 3 | 4 | 4 | 8 | 8 | 8 | 0 | 24 | 0 | 9 | 50 / 50 / 50 | 60 (60) | 50 (51) |
| `PX4` `C` 2 | **2** | **3** | **3** | 8 | 8 | 8 | 0 | 24 | 0 | 9 | 49 / 49 / 49 | 60 (60) | 49 (50) |

Full tables (both flows, `SP1n`, `SP6`, the no-id variants, every benign stream, every fixture): [`g8/bucket_capacity/expected/summary.md`](g8/bucket_capacity/expected/summary.md).

## 4. Findings (`MEASURED` unless labelled)

| ID | Finding |
| --- | --- |
| **F-G8-01** | **`C` sets the single-account first-window cells exactly:** `X1` / `BRU` / `BRD` = `C` / `C`+1 / `C`+1 in both flows and arms. **`C` 2 gives 2 / 3 / 3 = S0**; `C` 3 gives 3 / 4 / 4. Every attacker refusal in these cells is `cap:acct_tb` (`X1` 57 → 58 → 59, `BRU` 95 → 96 → 97 at `C` 4 → 3 → 2). BRU24 = `C` + 47 (51 / 50 / 49), one under the bound `C` + 48, recount equal |
| **F-G8-02** | **`MIX` attack, `ABA_A`, `SP3` do not depend on `C`:** 8 / 8 / 8 at every `C` (`pre+out`), 27 / 48 / 48 in `pre`; `SP1n` 4 and `SP6` 24 also unchanged. Their attacker refusals are `cap:fail_src` (`MIX` 192, `ABA_A` 112, `SP3` 992), the U door's net failure cap `F`, not the bucket. **No measured `C` closes these OD-10 cells** (S0 0 / 3 / 7); they belong to `F` / `U_src` (DEC-025 decision 1). A3 holds at every `C` (S12: `ABA_A` 24, `SP3` 24, `SP1n` 12, `SP6` 72) |
| **F-G8-03** | **Benign streams with ids, `pre+out` (the A1 definition): no change.** `L1`, `D2`, `ABA_B`, `KD`, `OFF12`, `N12`, `Y6b`, `Nu4`, `L3`, `D1`, `UAC`, `MIX` legit have a difference of 0 from `C` 4 at `C` 3 and 2, on both arms. **Without ids (reported, not gated), `C` 2 adds refusals:** `KD` 9 → **10** (S0 9), `UAC` 9 → 10, `Nu4` 0 → 3 (S0 47), all `cap:acct_tb`. At `C` 3 `KD` and `UAC` stay at 9, but all nine are `cap:acct_tb`; at `C` 4 they were a mix of `soft`, `penalty` and `cap:acct_tb`. `D2` stays at 24 at `C` 2, with 9 of its refusals now `cap:acct_tb` instead of `cap:src`. A1 is registered over the 57 tuples with ids (G4 v2 §6.3, 5-b3b RP-G4) and passes. Over all 91 tuples, `KD` at `C` 2 (10) is above S0 (9) |
| **F-G8-04** | **`pre` (`checkOnly`-only Hosts, reported): every lower `C` refuses benign users earlier,** because nothing refunds the bucket. With ids: `D2` 61 → 73 → 85 and `MIX` legit 61 → 73 → 85 (`C` 4 → 3 → 2); `L1` 72, `ABA_B` 94 unchanged. Without ids: the office `N12T` 1 → 13 → 25 of 61, `N12` 1 → 13 → 25, `Nu4` 41 → 45 → 49, `L1` 122 → 124 → 126, `ABA_B` 112 → 114 → 116, `Y6b` 26 → 27 → 28, `KD` and `UAC` 8 → 9 → 10 |
| **F-G8-05** | **Known device that lost its credential (F-G5-04):** `LOST` admits 51 / 50 / 49 in 24 h at every interval (= `C` + 47). The first refusal comes earlier: interval 600 s at 3,000 / 2,400 / 1,200 s; 300 s at 1,200 / 900 / 600 s; 60 s at 180 / 180 / 120 s. Refusals in the first 1,800 s: 0 / 0 / 1, 2 / 3 / 4, 27 / 27 / 28 (same interval order). `TOKEN` (the same device with its credential, on P) is unchanged: 45 / 44 / 45 |
| **F-G8-06** | **A credential that expires during a typo burst locks the owner out at `C` ≤ 3 (`K5U`).** The owner's credential reaches its absolute lifetime at 600 s. The owner fails on U at 630, 750 and 870 s, then succeeds at 900 s. At `C` 4 these four takes fit, the 900 s success re-issues the credential, and the owner is back on P from 960 s with 0 refusals. At `C` 3 and 2 the bucket refuses the 900 s attempt, so the success that would re-issue never happens. The owner stays on U and is refused on every attempt until the fixture ends at 2,340 s: **25 (`C` 3) and 26 (`C` 2) refusals, all `cap:acct_tb`**. `identicalFrom960` goes true → false. *`DERIVED` from the schedule:* the fixture uses exactly four takes, so `C` 4 is its edge too; an owner with `C` failures on U within `T` before the re-issuing success is shut out at any `C`. `MIXPRES-ASM` (two devices of one owner, one without an id): the no-id device is refused one attempt earlier at `C` 2 (`AAASSS` → `AASSSS`); device A's decisions are unchanged |
| **F-G8-07** | **Attacker reach that goes through U also falls with `C`:** `HAR-B` / `HAR-N` 4 → 3 → 2; `STEAL` thief U after the password change 27 → 26 → 25; `FORGE` thief U 27 → 26 → 25; `TMX` 26 → 22 → 18 (`pre+out`); no-id `TMSd` 16 → 12 → 8, `TMS` 29 → 29 → 25, `TPAIRT` attacker 32 → 24 → 16. Attacker reach through P does not change: `TMS` 29 and `TMSd` 40 with ids; `TPAIRT` attacker 72 and office 21 in `pre+out` |
| **F-G8-08** | **`PG`: PG-1 passes, PG-2 fails on a U-lane tuple, from a registration defect.** PG-1: on both arms, 113 (`C` 2) and 122 (`C` 3) of the 210 rows are identical to `C` 4; in the other 97 / 88 rows the first divergence is on U; **0 rows diverge first on P**. PG-2 fails only on `TPAIRT` office refusals in `pre`: 28 (`C` 2) and 18 (`C` 3) against 19. All of it is the `TPAIRT` tuple with no ids, which runs on U (`C` 2: `cap:acct_tb` 22 and `cap:src` 6; `C` 4: `cap:src` 19). PG-2 took the G7 best response over every tuple, so it included the U-lane no-id tuple that PLAN.md §1 itself names as U. Under §6.3 the gate is not re-scoped and stays **FAIL**. *`POST-HOC`, not a gate:* the 14 `TMS` / `TMSd` / `TPAIRT` / `N12T` rows with ids, and the 18 rows that never use the U lane, are identical in every field at `C` 2, 3 and 4 on both arms (recount from `expected/g8_streams.*.jsonl`). `TPAIRT` `pre` at `C` 3 (18) is below `C` 4 (19): the cell is not monotone in `C` |
| **F-G8-09** | **Detector `D-NOTB`:** with no effective bucket, BRU24 and BRU24-HV admit all 5,760 attempts, so `U_src` and `F` refuse none of them. `X1` / `BRU` / `BRD` reach 25 / 100 / 100; `D2` `pre` 24. The G4 v2 study arm `R-NO-TB` (2,304; 17 / 80 / 80) is a different arm (its `cap:acct` counter) and is shown for context only |

## 5. What the study does not say

* Which `C` to choose, or whether F-G8-01's gain outweighs F-G8-03 / -04 / -06. That is the Owner's decision on the Lead's recommendation.
* Anything about real Redis (the reference store is the 5-b2 lockstep model of the Lua; NM-6 is the store-level evidence), concurrency, failover or DEC-015 degraded mode.
* Anything for `T` ≠ 1,800 s or other values of `U_src`, `F`, set R or D8, for OTP or API, or for attacker strategies outside the archived streams and fixtures.
* How long the `K5U` lockout lasts beyond the fixture's end (2,340 s), or how a Host could re-issue without a success (DEC-025 decision 6 is out of scope here).
* That the no-id and `pre` costs (F-G8-03, F-G8-04) are gates: they were registered as reported values. A1 and A3 are the only benign gates.

## 6. Order and deviations

* Order: PLAN `a474b16` → harness `da46e24` → runner fix `3b14509` → results (this commit). The plan is unchanged since `a474b16`.
* **Runner fix `3b14509`.** The first invocation of `run.sh` after `da46e24` ran all eight scenarios, but the runner stopped before the report. Under bash 3.2, `command php … || rc=$?` inside a background function ends that subshell before it writes the exit code. Only the output file sizes were listed. No value was read, no report was produced, and the outputs were deleted. The fix resolves the php binary once in `run.sh`; no replay, gate or report line changed. The two recorded runs followed.
* **Registration defect in PG-2** (F-G8-08): written up, not corrected after the run.
