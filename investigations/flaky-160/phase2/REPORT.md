# #160 — phase 2 REPORT: the mechanisms of T-CLAIM and T-ALLOW (evidence only, no fix)

> **Plan:** `PLAN.md` v2 (v1 `156c6d5`, rulings §8 `26eb231`). **Head under test:** `main@d0739a5`; V-HEAD held in every run (`src/`, `tests/`, `composer.json`, `phpunit.xml` byte-identical, tree clean). The branch head used for all dispatches is `b29dbdb`; **normal CI on `b29dbdb`: 16 / 16 green** (run 39992535803), before any dispatch. No push and no other CI during any dispatch. **Environment:** E-CI only for every counted number (`ubuntu-latest`, PHP 8.5.12, PHPUnit 11.5.57, `redis:7.0.15-alpine`; one resolved `composer.lock`, `99b482d1…`, in all 26 jobs). No E-LOCAL number is counted.

## 1. Results at a glance

| Arm | Registered prediction | Result | Holds |
| --- | --- | --- | --- |
| C-DET-1 (claim at T + 1) | claim `true` 20 / 20, READ reentry null | 20 / 20, 20 / 20 (Wilson 95 % [83.9 %, 100 %]) | yes |
| C-DET-0 (control, all T) | claim `true` 0 / 20 | 0 / 20 | yes |
| C-DET-2 (T + 1 from READ on) | READ reentry **not** null 20 / 20 (failure at L1095), claim `true` 20 / 20 | 20 / 20, 20 / 20 | yes |
| **C-NAT N-C1** (K = 100,000) | claim `true` iff `second(claim) > second(block)` on every repetition | **0 discordant / 100,000** | yes |
| **C-NAT N-C2** | straddles inside the Poisson 95 % band around Σ gap | 46 observed, band [33, 59], Σ gap 45.56 s | yes |
| C-NAT split L1095 / L1097 | 23 / 23 vs bands [17, 37] / [11, 28] | inside both | yes |
| **A-REC P-PASS** (N = 5,000, all valid) | a passing run has no anomalous worker | 4,999 / 4,999 | yes |
| **A-REC P-FAIL** | the failing run has exactly one worker that took a unit with **no K5 `+2` and no exception** | the one failing run has a worker with the unit **and** K5 `+2` **and** a `RateLimitConcurrencyException` | **NO — refuted (§3)** |
| A-REC naming target (≥ 5 failing runs) | reached | **1 failing run: not reached** | no |
| A-RATE (no tap, N = 1,000, descriptive) | — | 4 / 1,000 = 0.40 %, Wilson [0.156 %, 1.024 %] | — |

## 2. Arm C — T-CLAIM

* **C-DET (E-CI, `logs/c/E-CI/cdet.rows.jsonl`):** 20 + 20 + 20 trials, all valid (the block applied and the physical block key had `PTTL > 0` before the claim). The control returned `false` 20 / 20; with the claim reading one second later it returned `true` 20 / 20; with the later second from READ on, the failure moves to L1095 (`postPunishmentReentry` not null) 20 / 20.
* **C-NAT (`logs/c/E-CI/cnat.rows.jsonl.gz`):** K = 100,000 repetitions of the test's four calls, the store clock a pass-through recorder of `SystemClock`. Every repetition has exactly one clock read per call and the block applied. **N-C1: 0 discordant**, so the claim returned `true` in exactly the repetitions where its second was later than the block's second (and in no other). Straddles: 46 / 100,000 = 0.046 % (Wilson 95 % [0.0345 %, 0.0613 %]); Σ(block→claim gap) = 45.56 s gives the Poisson 95 % band [33, 59] (**N-C2 inside**). Gap block→claim: min 371 µs, p50 451 µs, p99 636 µs, max 2,455 µs. Split by where the boundary falls: 23 repetitions fail at L1095 (boundary before READ; expected sum 26.4, band [17, 37]) and 23 at L1097 (expected 19.1, band [11, 28]).
* **Reading.** The registered reading (PLAN §1.1, §3.3) holds on every measure: a one-second block ends at the next whole second, the store honours that, and the test asserts a block still holds after steps whose clock it does not control. In the suite, T-CLAIM's three observed failures were all at L1097 (none at L1095); with the split above (about even) the chance of 0 / 3 at L1095 is 12.5 %, so this is not in conflict with the measurement. The natural rate here (0.046 %) is the same order as the suite's 3 / 3,300 = 0.09 % (Wilson [0.031 %, 0.265 %], computed); I do not claim they are equal.

## 3. Arm A — T-ALLOW

### 3.1 A-REC (full-record tap, 5,000 runs, 20 shards × 250, fixed, no early stop)

All 5,000 runs valid (V-FRAME, V-WORKER with **0 unresolved pids**, V-KEY: the sum of the workers' allowance increments equalled the `consumed` the test read in every run). One run failed: **run 787** (shard 4), `Failed asserting that 28 is identical to 26.` at L136. Failure rate 1 / 5,000 = 0.020 %, Wilson [0.0035 %, 0.113 %].

**The registered P-FAIL is refuted by that run.** Its 32 workers: `k5` read 28 = 2 × Σ K (Σ U = 16, Σ K = 14), `conflicts` = 3, so the test's expected value is 2 × (16 − 3) = 26. Of the three workers whose outcome was `conflict`, two have U = 1, K = 0 (as the test assumes) and **one has U = 1, K = 1**: the allowance unit **and** a K5 `+2` were applied, and the worker still ended in `RateLimitConcurrencyException`. No worker took a unit without K5 and without an exception (the registered anomaly class `unit_without_k5_no_conflict`: 0 in 5,000 runs).

**The command sequence of that worker (pid 19030, `logs/arec/E-CI/failures/arec-787/tap.jsonl.gz`)**, after its allowance `BUDGET_INCREMENT`:

1. `LIFECYCLE_MUTATE` on K4 → `[0]` (not applied), `LIFECYCLE_READ`, `LIFECYCLE_MUTATE` → `[0]`, `LIFECYCLE_READ`, `LIFECYCLE_MUTATE` → `[1, …]` applied on the third attempt (seq 510, 541, 563);
2. `SCORE_INCREMENT` on the K5 key, amount `2` → `[22, …]` (seq 586): **K5 committed**;
3. `HARD_BLOCK` (lifecycle publication on the K4 block) → `[0]`, `LIFECYCLE_READ`, `HARD_BLOCK` → `[0]`, `LIFECYCLE_READ`, `HARD_BLOCK` → `[0]`, `LIFECYCLE_READ` (seq 646–682), then nothing further from that worker; its outcome is `conflict`.

The two other conflict workers show three `LIFECYCLE_MUTATE` replies `[0]` and **no** K5 increment, i.e. they stopped at the K4 generation-mutation budget (`EvaluationPipeline` L1741) before K5, which is the case the test's invariant assumes.

### 3.2 The branch is **not named** (rule: target of ≥ 5 failing runs not reached)

By the Lead's ruling the branch is not named below the target. I record what the single instance shows and nothing more: its sequence (three publication attempts each answered `[0]`, then no further command, outcome `conflict`, K5 already committed) is consistent with `EvaluationPipeline` L2133–L2168 (`for ($attempt = 0; $attempt < 3; …)` over `blockWithPunishmentLifecycleTracking`, then `throw new RateLimitConcurrencyException('K4 punishment publication conflict budget exhausted.')` when none applied). That is a reading of one instance against the code, not a measured branch. The second pass with a PHP-side recorder (LT-160B-05) is **not run**: the Redis command sequence did not leave two candidates it cannot separate, and no prediction for such a pass is written.

### 3.3 A-RATE (descriptive, no tap, E-CI, 1,000 runs, 4 shards × 250)

* 1,000 / 1,000 valid; **4 failures** (ids 39, 230, 321, 878), all at L136: `30 is identical to 28` ×2, `32 is identical to 30`, `32 is identical to 28`. Rate 0.40 %, Wilson 95 % [0.156 %, 1.024 %]. Poisson P(≥ 1 failure) at 0.12 % per run would have been 69.9 %; this is descriptive, not a criterion.
* **Not in these 1,000, observed too:** the single V-REF run of shard 19 of the A-REC dispatch (untapped, same test) failed with `28 is identical to 26` (`logs/arec/E-CI/ref-failure/`). With it the untapped observations are 5 failures in 1,024 runs; the 4 / 1,000 above is the registered figure.
* **Tap effect on this test (descriptive, from A-RATE versus A-REC).** Tapped 1 / 5,000 [0.0035 %, 0.113 %] against untapped 4 / 1,000 [0.156 %, 1.024 %]: the intervals do not overlap. The full-record tap makes the test **about 3× slower** (wall per `--filter` run, median 1.52 s tapped against 0.47 s untapped; measured before the dispatch 1.19–1.37 s against 0.40–0.43 s), which changes the interleaving of the 16 workers. So the failure rate and the number of instances A-REC could catch are both depressed by the instrument; the registered rate must be read from A-RATE only. Phase 1's finding "the tap is not the cause" concerned the in-suite prefix runs of the other tests and does **not** carry over to this test under `--filter`.
* **Why A-REC did not reach its naming target:** at 0.02 % per tapped run, ≥ 5 instances need about 25,000 tapped runs; at the untapped 0.4 % they would need about 1,250 untapped runs if a recorder that does not slow the test could be used.

## 4. Correction to phase 1 (finding on my own text)

Phase 1 (`REPORT.md` §4 and `PLAN.md` §1.4) read "30 is identical to 28" as `k5 = 28` against an expected `30` and concluded "one command whose allowance unit is counted without its K5 `+2`". PHPUnit prints `Failed asserting that <actual> is identical to <expected>`: in `assertSame(2 * ($consumed - $conflicts), $k5)` the **first** number is `k5`. Run 787 confirms it from the record (`k5` read 28, expected 26). So every failure so far has `k5` **one unit (2 points) above** the formula, not below: a `conflict` worker whose K5 update was already applied, as in §3.1. The phase-1 description of the direction, and the registered P-FAIL derived from it, were wrong; the interleaving class "one command takes a unit and ends without K5" was refuted by 5,000 recorded runs (0 instances). Phase 1's evidence files are untouched; this is recorded here.

## 5. Preliminary decision per test

* **T-CLAIM — assumption in the test, not a store defect.** N-C1 held on 100,000 / 100,000 repetitions and the three C-DET variants hold; `LIFECYCLE_CLAIM` judges `expiresAt > nowSeconds` exactly as the contract of a one-second block says. **Before any fix** the REPORT still lacks: which change to the test (a block longer than the span between the calls, or a shared held clock as in the #162 fix) keeps the test's intent; that is a design question for the Lead, not something this phase measured.
* **T-ALLOW — a test assumption about when a conflict happens, not proven either way as store/pipeline defect.** The failing runs have `k5` above the formula because a `RateLimitConcurrencyException` can be thrown **after** K4 and K5 have been committed (the instance of §3.1), while the test's invariant `k5 = 2 × (consumed − conflicts)` assumes a conflict means no K5 update. Whether throwing a concurrency exception after committed writes is the intended contract (then the invariant must be relaxed) or a pipeline defect (the exception should not follow committed writes) is a contract question this phase cannot answer. **Before any fix:** (a) name the branch with ≥ 5 instances: the cheapest way I see is a PHP-side recorder (decorating store from the harness bootstrap) run **without** the slow tap on E-CI, about 1,250–2,000 runs, with its prediction written into the PLAN first (LT-160B-05 conditions); (b) a Lead ruling on the intended contract of `RateLimitConcurrencyException` after committed writes.

## 6. Deviations and what was not done (disclosed)

* **V-REF per shard was recorded but not enforced.** PLAN §5 says V-REF passes before a counted run; `run.sh` writes the V-REF verdict but does not stop the shard. In the A-REC dispatch the V-REF of shard 19 failed with the very phenomenon under study (§3.3); shard 19's 250 counted runs passed every other validity check and are included. 23 of the 24 V-REF runs passed.
* **V-CLOCK's "recorder vs no recorder" fixed-clock control (PLAN §5) was not run.** N-C1 on 100,000 repetitions would show a recorder that changed the reading as a discordance; it showed none. That is not the registered control.
* **Local detector run:** on macOS the first attempt of the base run D4 failed once (the tap calls `lsof` per connection and slows the test beyond the 10 s barrier; hypothesis, not tested); the second passed. On E-CI the first attempt passed. Local detectors are not counted evidence (`logs/detectors-local/`).
* **T-K4 and T-HAND** are not in this phase (separate assignment). **Not measured:** the T-ALLOW rate inside the full suite after the pipeline's other tests; any run on another PHP version.

## 7. Files

* `harness/` (the tap copy with `PROVENANCE.txt`, the unified `-U0` diff `PROVENANCE-tap.diff` and `provenance_check.sh`, F-163-01 fixed in `b29dbdb`), `logs/detectors/E-CI/` (13 / 13, cost), `logs/detectors-local/`, `logs/c/E-CI/`, `logs/arec/E-CI/` (verdicts of all 5,000 runs, `report.json`, `failures/arec-787/`, `ref-failure/`), `logs/arate/E-CI/` (verdicts of all 1,000 runs, `report.json`, the four failing runs' `phpunit.out` and `junit.xml`). Each folder has `SHA256SUMS`.
* Runs: detectors 39991507996; c 39994364577; arec 39994700397; arate 39997592270; normal CI 39992535803.
* The temporary workflow `.github/workflows/flaky-160.yml` is deleted in its own commit before Ready.
