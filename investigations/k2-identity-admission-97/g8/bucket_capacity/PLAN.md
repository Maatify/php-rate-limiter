# G8 bucket capacity `C` — pre-registered plan (#97, before stage 5-c, DEC-025 decision 2)

> **Status:** PRE-REGISTRATION, plan **v1**. Committed **before** any G8 harness code and any G8 result.
> Evidence only. **Not** a decision on `C`, an ADR, a policy, a production value or a public-API shape. No change to `src/`, to any existing file under `tests/`, to `composer.json`, presets, public contracts,
> DEC-015 / DEC-019 / DEC-021 / DEC-022 / DEC-023 / DEC-024 / DEC-025 / DEC-026 draft, `DECISIONS_INDEX.md`, or any earlier study or archive.
> **Baseline:** the branch is cut from `main@05922dd` (PR #147, stage 5-b3b merged). `run.sh` checks that `src/`, `tests/` and `composer.json` are byte-identical to `05922dd9ebff00d770397af124db789bc12c1607` and clean.
> **Owner decision:** [PR #150 comment 6298114042](https://github.com/Maatify/php-rate-limiter/pull/150#issuecomment-6298114042) (2026-10-20): OD-10 is not settled on paper; the alternatives of `C` are measured first. OD-3 … OD-9 wait for the same moment.
> **References:** `../../BUDGETS_DECISION.md` D2 and N-2 (L38, L117–L128, L142, L348); G4 v2 `../../g4/integrated_v2/PLAN.md` §6.3; G5 v2 F-G5-04 (`../../G5_V2_IDENTITY_LAYER.md` L100); the archives `../../g4/integrated_v2/expected/`,
> `../../g7/cross_account_p/expected/`, `../../g3/admission_budgets/expected/`, `../../g3/admission_budgets_v2/expected/` (read-only, read at run time, never re-typed); the 5-b3b harness under `tests/Support/Admission/` (`EngineLab::budgets()`,
> `StreamReplay::fullBudgets()`, `StreamGates`, `FixtureReplays`, `ReplaySession`, `ArchiveData`), used unchanged.

## 0. Question (registered by the Lead)

Does `C` = 2 or `C` = 3 at `T` = 1,800 s (refund on success unchanged) bring the best-response cells back to the S0 values or close to them, and at what cost to the benign user and to the known device that lost its credential?

`C` is the capacity of the per-account bucket on the **unproven (U) door** only: `AdmissionRuntime::unprovenScopes()` reserves one `account_bucket` take (`AdmissionScopeDTO` kind `BUCKET`, capacity `C`, `ttlSeconds` `T`) per U admission of an
account, and `outcomeScopes()` refunds it on a U success. The proven (P) door has no bucket scope (`provenScopes()`). The bound over 24 h of an account that is never refunded is `C + ⌊86,400 / T⌋`.

## 1. Pre-plan disclosure

No G8 pilot was run. Known before this plan and used by it, all `MEASURED` in the archives at `C` = 4 (the 5-b3b replays reproduce them through the production engine):

* best response, `FULL2`, `pre+out`: `X1` / `BRU` / `BRD` 4 / 5 / 5, `MIX` attack 8, `ABA_A` 8, `SP3` 8, `SP1n` 4, `SP6` 24 (G4 v2 `expected/summary.md`; 5b3b `AdmissionArchiveStreamsTest`); S0: 2 / 3 / 3, 0, 3, 7, 4, 20 (`g3/admission_budgets/expected/s00_s0.jsonl`);
* fixtures, `NOUA2` = `PX4`: `BRU24` 51 (recount 51), `REC` wait 60 s at δ = 0 and 0 s at the other offsets, `LOST` 51 / 51 / 51 (intervals 600 / 300 / 60 s, no P lane), `TOKEN` 45 / 44 / 45 (all P) (`g7/cross_account_p/expected/g7_fixtures.G5V2.jsonl`);
* the archived `PX4` rows of `TMS`, `TMSd`, `TMX` with **no instance id** (`ids` = `none`) are on the U lane and carry `cap:acct_tb` refusals; the `fresh` / `const` rows of `TMS`, `TMSd`, `TPAIRT` are on P. This is why gate PG (§5) is written as
  "the first divergence is on the U door" and not as row identity.
* the archived "no bucket" arm `R-NO-TB` of G4 v2 is a **study** arm (its per-account counter `cap:acct` is not the production bucket): `BRU24` 2,304; `pre` `L1` 72, `D2` 24, `BRU` 80, `X1` 17. It is context for the detector, not its reference.

A timing probe was run once on this baseline at `C` = 4, `PX4` (the archive arm, 210 stream rows and the fixtures): it printed wall time and memory only (about 45 s and 34 MB per arm and value of `C`); no G8 value was printed or read.

## 2. Arms

All arms are the production composition of 5-b3b: `EngineLab` (the real `RateLimiterEngine`, `EvaluationPipeline`, `DeviceIdentityResolver`, `AdmissionRuntime`, the in-memory reference admission store), the study Host model (`StudyHost`),
the replay `ReplaySession` (IN on every row). The values reach the runtime only through the `AdmissionBudgetsDTO` the harness builds; **no study code decides a request**.

| Arm | Budgets (`AdmissionBudgetsDTO`) | Role |
| --- | --- | --- |
| `FULL2`, `C` ∈ {2, 3, 4} | `EngineLab::budgets(bucketTakes: C, crossAccountFailureCap: 1_000_000)` (= `StreamReplay::fullBudgets()` with `C`) — no D8 | the G4 v2 / G5 v2 arm (`NOUA2` in the G7 archive) |
| `PX4`, `C` ∈ {2, 3, 4} | `EngineLab::budgets(bucketTakes: C)` — D8 `F` 4 | the production budgets of DEC-025 (G7 `PX4`) |
| `FULL2` / `PX4`, `C` = 1,000,000 | as above | **detector** `D-NOTB`: a bucket that never refuses |

Every other value is the archive's: `T` 1,800 s, window 600 s, `U_src` 24, `F` 4 (net, U), set R (`P_acct` 10 / 60, `P_proof` 6 / 30, `N_P` 10), `D` 30 s, `G` 600 s. `C` = 4 is the reference arm and the harness check.

## 3. What is replayed (for every arm and every `C`)

* **Streams:** every tuple of the archive dump (`ArchiveData::streams()`: the 22 G3 streams, every strategy and id policy, with and without ids = 91 tuples) and of `ArchiveData::g7Streams()` (`N12T`, `TPAIRT`, `TMS`, `TMSd`, `TMX` = 14 tuples),
  flows `pre+out` **and** `pre`: 210 rows per arm and `C`, each with the fields of the G7 archive rows (`dec`, `reach`, `kinds`, `lanes`, `m`, `issued`, `tainted`, causes by study code, `refusedPx`, `reachU`, `reachP`, `reachFirst600`,
  `reachPerAccountMax`, `accountsReached`) plus `cz`, one character per request naming the refusal cause (`.` admitted or reached; a fixed map of the production causes, `?` for any other).
* **Fixtures:** every procedure of `FixtureReplays` (HAR-B, HAR-N, CLEAR, BRU24, BRU24-HV, REC, CROSS, E2, K5U, BOOT, BOOT-X, STEAL, UAX-NP, FORGE, MINT, LOST, TOKEN, UAX-ASM, MIXPRES-ASM), unchanged.
* **LOST / TOKEN detail** (F-G5-04): the `FixtureReplays` session over the dumped rows `LOST:i` and `TOKEN:i` (i = 600, 300, 60 s), recording per interval: reach in 24 h, P lane count, the time of the first refusal, refusals in the first window
  `[0, 600)` s and in the first bucket period `[0, 1,800)` s, refusals in 24 h, and the refusal causes.

Best response (the definitions the archive and 5-b3b use): for the G3 streams, `StreamGates::best()` = max over strategies and id policies **with ids** (the 57 tuples of G4 v2 §6.3) of `reachForeign + reachProof` and of `legitDenied`; the values over all 91 tuples
(the no-id variants included) are reported beside them. For `N12T`, `TPAIRT`, `TMS`, `TMSd`, `TMX`: max over every tuple of the stream (the G7 definition).

## 4. Harness validity (V) and detector (D) — a failure means the study is invalid (exit 5)

* **V1:** at `C` = 4, the 210 stream rows of `FULL2` equal the archived `NOUA2` rows and those of `PX4` the archived `PX4` rows of `g7/cross_account_p/expected/g7_streams.G5V2.jsonl`, **field for field**, with the comparison of
  `AdmissionArchiveG7Test` (decision, reach, kinds, lanes, metrics, Host counts, capacity causes by name and the other denials by count, `refusedPx` and the `g6TmsRow` measures where the archive has them).
* **V2:** at `C` = 4, every fixture row equals the archived row of the same arm (`NOUA2` / `PX4`; BOOT and BOOT-X against `…+R-NO-BIND`) of `g7_fixtures.G5V2.jsonl`, with the normalisation of `AdmissionArchiveFixturesTest`.
* **V3:** at `C` = 4, the 57 `FULL2` `pre+out` rows equal the `FULL2` rows of `g4/integrated_v2/expected/v2_streams.G4.jsonl` in `dec`, `reach`, `kinds`, `lanes`, `m`, except `UAC|none|fresh` (the declared G5 v2 cost, as 5-b3b RP-G4).
* **IN:** the input assertion of `ReplaySession` runs on every row of every replay (exit 6 on a violation).
* **D (`D-NOTB`, must fire):** at `C` = 1,000,000, on both arms, `BRU24` admitted **> 52** (the bound of `C` = 4 is exceeded: no daily ceiling) with the recount equal to the admissions, **and** the best-response `L1` legitimate refusals in `pre` = **72**
  (the known `pre` value). Reported beside it: `BRU24-HV`, `D2` `pre`, `X1` / `BRU` / `BRD` in both flows, and the study arm `R-NO-TB` values read from the G4 v2 archive.

## 5. Gates (for `C` = 2, 3 and 4, on both arms unless stated; flow `pre+out`)

Each gate is evaluated and reported **per `C` and per arm**, PASS or FAIL with the failing items. No gate selects a value of `C`.

* **B1 (N-2, bound):** `BRU24` and `BRU24-HV` admitted ≤ `C + ⌊86,400 / T⌋` (50 / 51 / 52), and each equal to the independent recount of bucket takes in the admission store (`FixtureReplays::bru()`, `tbTakesRecount`). The values are recorded.
* **B2 (N-2, recovery):** `REC`: for every offset δ ∈ {0, 60, 300, 900, 1,800, 3,600} s the owner is admitted, and the wait from arrival is ≤ `max(0, T + 60 − δ)` (N-2's wording, `BUDGETS_DECISION.md` L120 and L142; it implies the assignment's
  form "≤ `T` + 60"). The six waits are recorded.
* **A1 (N-2, benign):** every benign G3 stream except `MIX` and `UAC` (`StreamGates::BENIGN` minus `MIX` and `StreamGates::DECLARED_COSTS`) has best-response legitimate refusals ≤ S0 and ≤ S24 (`StreamGates::a1()`, S0 from `s00_s0.jsonl`, S24 from
  `v2_streams.CAND.jsonl`, read at run time). The difference from `C` = 4 is recorded per stream for `L1`, `D2`, `ABA_B`, `KD`, `OFF12`, `N12`, `Y6b`, `Nu4` (and `L3`, `D1`, `UAC`, `MIX` legit).
* **A3:** `SP1n`, `SP3`, `SP6`, `ABA_A` best-response reach ≤ S12 (`StreamGates::a3()`, S12 from `g3/admission_budgets_v2/expected/v2_fc.CAND.jsonl`).
* **PG — the P door and G7 (arm `PX4`; `C` = 2 and 3 against `C` = 4):**
  * **PG-1:** for each of the 210 rows, either the row equals the `C` = 4 row in `dec`, `reach`, `lanes` and `cz`, or the **first** request where any of the four differs is on the **U** lane in both rows (everything after a U divergence may follow
    from it: the Host issues a credential only after an admitted attempt).
  * **PG-2:** the best-response values of `TMS` (reach), `TMSd` (reach) and `TPAIRT` (office legitimate refusals and attacker reach) equal the `C` = 4 values, in `pre+out` **and** `pre`.
  * Reported, not gated: the same PG-1 / PG-2 check on `FULL2`; the number of identical rows and of U-first divergences per `C`; `TMX` and `N12T`.

### Recorded values (not gates)

* **OD-10 cells**, per `C` and arm, `pre+out` (and `pre` reported): `X1` / `BRU` / `BRD` reach (S0 2 / 3 / 3), `MIX` attack reach (S0 0), `ABA_A` (S0 3), `SP3` (S0 7), `SP1n` (S0 4), `SP6` (S0 20), each against S0 and against `C` = 4.
* **Known device that lost its credential (F-G5-04):** `LOST` and `TOKEN` detail of §3 per `C` and arm (reference at `C` = 4: `LOST` 51).
* Every fixture row per `C` and arm, with its difference from `C` = 4 where there is one.

## 6. Outcome rules (fixed now)

1. V1, V2, V3, IN or D failing ⇒ the study is invalid; no table is reported as evidence (exit 5 / 6).
2. Otherwise the report states, for each `C` and arm, which of B1, B2, A1, A3, PG pass and which fail, with the items, and gives the table of §7. There is **no verdict on `C`** and no security recommendation: the recommendation is the Lead's, the decision
   the Owner's, and a change of `C` is a record superseding DEC-025 under DEC-025's own rule.
3. No gate is re-scoped after a result; nothing here is `POST-HOC`. A change needs a new plan version committed before a new run.
4. `C` = 1,000,000 is a detector only; its values never enter the table as an option.

## 7. Report

`../../G8_BUCKET_CAPACITY.md`, the structure of the G3–G7 reports: question, arms, gates, the table, findings, what the study does not say. **The table:** one row per `C` ∈ {2, 3, 4} and arm, columns `X1`, `BRU`, `BRD`, `MIX` atk, `ABA_A`, `SP3`, `L1`, `D2`, `ABA_B`, `KD`,
`LOST`-24h, `REC`, `BRU24`, with the S0 row and the `C` = 4 row as references.

## 8. What this cannot prove

Real Redis (the reference store is the 5-b2 lockstep model of the Redis Lua; NM-6 is the store-level evidence), concurrency, failover, DEC-015 degraded mode; real Hosts and cookies; Hosts that drop outcomes (only `pre+out` and `pre` are run);
`T` other than 1,800 s; other values of `U_src`, `F`, set R, D8; OTP and API; mass password resets; attacker strategies outside the archived streams and fixtures.

## 9. Reproduction

```bash
bash investigations/k2-identity-admission-97/g8/bucket_capacity/run.sh
```

Checks the baseline, runs the replay scenario under `PHP_INI_SCAN_DIR=` and `-d memory_limit=1G` (**every** `php` call), then the report, writes `SHA256SUMS` over the outputs, and diffs against `expected/`. Exit 0 = valid run (the gate outcomes are in
`gates.json` and `summary.md`); 5 = V or D failed; 6 = IN failed; 4 = baseline; 1 = an output differs or a scenario fails. `UPDATE=1` regenerates `expected/`. Two consecutive runs must be byte-identical; their logs are kept in `logs/`.
