# #160 — phase 2b REPORT: the branch behind T-ALLOW, and whether the block already exists (evidence only, no fix)

> **Plan:** `PLAN.md` v2 (v1 `ff7bb6d`, rulings §8 `977b27e`, §9 deviations `8fe1340`). **Head under test:** `main@2cf8565` (V-HEAD held in every run: `src/`, `tests/`, `composer.json`, `phpunit.xml` byte-identical, tree clean). Dispatched on `099533a`; normal CI on that head: success. One dispatch at a time, no push and no other CI during any dispatch. **Environment:** E-CI only for every counted number (`ubuntu-latest`, PHP 8.5.12, PHPUnit 11.5.57, `redis:7.0.15-alpine`, `noeviction`; one resolved `composer.lock` `99b482d1…` in all 8 A-NAT jobs). No E-LOCAL number is counted. Runs: checks 40013634750; **A-DET 40023276998**; **A-NAT 40023415934**.

## 1. Results at a glance

| Question | Registered prediction | Result | Holds |
| --- | --- | --- | --- |
| A-DET-1 (inject, 20) | `RateLimitConcurrencyException` from L2168, K4 applied, K5 `+2`, no K4 block, 3 publication calls, 20 / 20 | 20 / 20 (Wilson 95 % [83.9 %, 100 %]) | yes |
| A-DET-0 (control) | 0 / 20 exceptions | 0 / 20; decision HARD_BLOCK, K4 block level 2, one applied publication, 20 / 20 | yes |
| A-DET-b (route b2) | exception at L2168 after **one** attempt, classified b2 | 20 / 20, `route=b2`, `pubs=1` | yes |
| A-NAT validity | — | 2,000 / 2,000 valid, 0 invalid, 8 / 8 V-REF pass | — |
| A-NAT failing runs (target ≥ 5) | ≥ 5 | **2 / 2,000** (ids 362, 756) — **target not reached** | no |
| (i) one `conflict` worker with K = 1 at the publication branch, route r3, in every failing run | all failing runs | 2 / 2; exit route r3, b1 / b2: 0 | yes (n = 2) |
| (ii) active K4 block (level ≥ 2) at the throw | present | **absent at the first and at the last non-applied publication in 2 / 2** (Wilson 95 % [0 %, 65.8 %]); present at the worker's exit in 1 / 2 | **no — refuted (n = 2)** |
| (iii) `unit_without_k5_no_conflict` | 0 | 0 / 2,000 runs | yes |
| (iv) conflict workers with K = 1 in **passing** runs (descriptive) | — | 0 / 1,998 | — |
| Failure rate, no tap, with recorder | descriptive | 2 / 2,000 = 0.10 %, Wilson 95 % [0.027 %, 0.364 %] | — |

## 2. A-DET (deterministic, E-CI, `logs/adet/E-CI/adet.rows.jsonl`)

V-TRANSFORM (all five conditions) and V-CLOCK-2 passed in the same job before the rows were produced. Harness-made pre-state through the store's own public methods (PLAN §9 D-1): allowance 8, K4 score 6, then one failure from the test's device (`concurrent-fp`), so K4 scores to 9 ≥ l2 and the publication branch is reached; the pipeline, policy, keys and `FixedClock` are the test's.

| Variant | Hit rule (registered) | Result |
| --- | --- | --- |
| **inject** (the publication answers "not applied" three times, `ArmedStore`) | exception message `K4 punishment publication conflict budget exhausted.`, `getLine()` equals the line of that `throw` in `EvaluationPipeline` (2168, found by scanning the file), route r3, 3 publication calls, worker K = 1; Redis: allowance +1, K4 = 9, K5 `+2`, **no K4 block** | **20 / 20**; every row `line = 2168` |
| **control** (no injection) | no exception, HARD_BLOCK, K4 block level ≥ 2, one applied publication | **20 / 20** |
| **b2** (first publication not applied, harness lowers the K4 score to 1 before the re-read) | same as inject but route b2, one publication call, K4 = 1 | **20 / 20**; every row `line = 2168`, `pubs = 1` |

**Branch named by construction:** `RateLimitConcurrencyException('K4 punishment publication conflict budget exhausted.')` at `EvaluationPipeline` L2168, raised by `persistWinningCandidates` after (a) the allowance unit, (b) the K4 mutation and (c) the K5 `SCORE_INCREMENT` `+2` are committed, when `blockWithPunishmentLifecycleTracking` answers "not applied" and the loop ends (r3: three attempts; b2 / b1: fewer, when the re-read shows level < 2 or a null generation). The state a thrown worker leaves is exactly the state the test's formula does not count: allowance +1, K4 mutated, K5 `+2`, no K4 block. **b1 was not built** (PLAN §8 LT-160C-05: only if it can be built without changing what the pipeline reads; making `generation` null means deleting the K4 score, which changes what is read). It is covered by the synthetic detector D3b and was never seen in A-NAT.

## 3. A-NAT (natural, E-CI, no tap, W1 recorder, 8 shards × 250, `logs/anat/E-CI/`)

### 3.1 Validity
* V-RECORD: every run has its 32 worker files, each complete (`#end` trailer, seq values exactly `0..n-1`), `ΣU = consumed`, and, for the two failing runs, the `k5` the test read equals `2 × ΣK` from the records and the test's expected value equals `2 × (ΣU − conflicts)` from the inferred conflicts. For the 1,998 passing runs `ΣK = ΣU − conflicts` held in every run. **0 invalid runs of 2,000** (bound 2 %); V-REF **8 / 8 pass** (the "failure on the phenomenon" path was not used and no shard stopped); V-TRANSFORM `ok` in all 8 jobs; 2,008 containers (one per run and per V-REF), port never 6379 (269 distinct published ports), Redis 7.0.15, `noeviction`.
* Wall per run: median 0.561 s (recorded, `--filter`). The untapped phase-2 median was 0.47 s and the full tap 1.52 s; the `checks` span ratio was 0.945 (see the PR).

### 3.2 The two failing runs

| Run | Shard | `k5` read / expected | ΣU / ΣK / conflicts (inferred) | The conflict worker with K = 1 | Route | Publication attempts (expected generations) |
| --- | --- | --- | --- | --- | --- | --- |
| 362 | 2 | 32 / 30 | 16 / 16 / 1 | pid 49314 | **r3** | 3 (3, 5, 6) |
| 756 | 4 | 28 / 26 | 16 / 14 / 3 | pid 5569 | **r3** | 3 (3, 4, 5) |

Both failures are at L136 with `k5` **above** the formula by 2 (one conflict worker whose K5 `+2` was committed), the direction found in phase 2 §4. In both runs the other conflict workers (0 in run 362, 2 in run 756) are K4-mutation-budget conflicts with K = 0, as the test assumes. The 1,998 passing runs contain 6,340 conflict workers, all of the K4-mutation kind with K = 0 (3.17 per run), none at the publication branch.

**Timeline of the publications on the K4 key (ms from the first publication record; `pub` = a publication call with its expected generation and result, `obs` = the recorder's read of the K4 block after a non-applied publication, `exit` = the read at a worker's exit):**

Run 362 (the throwing worker is 49314):

| t (ms) | pid | event |
| --- | --- | --- |
| 0.00 | 49314 | pub gen 3 → not applied |
| 0.71 | 49314 | obs #1 → **no block** |
| 1.02 | 49300 | pub gen 4 → not applied |
| 1.91 / 3.53 | 49314 | pub gen 5, gen 6 → not applied |
| 2.50 / 4.73 | 49314 | obs #2, #3 → **no block** (last observation) |
| 5.99 | 49300 | pub gen 8 → **applied** (the first K4 block) |
| 11.37 | 49314 | worker exit read → **block level 3** |

Run 756 (the throwing worker is 5569):

| t (ms) | pid | event |
| --- | --- | --- |
| 0.00 / 2.61 / 5.07 | 5569 | pub gen 3, 4, 5 → not applied |
| 1.64 / 3.79 / 5.77 | 5569 | obs #1, #2, #3 → **no block** (last observation) |
| 13.13 | 5569 | worker exit read → **no block** |
| 13.87 | 5584 | pub gen 6 → **applied** (the first K4 block) |
| 14.13 | 5579 | pub gen 6 → applied |

### 3.3 Prediction (ii) — refuted in both instances
At the **first** and at the **last** non-applied publication of the throwing worker the K4 block read returned none in 2 / 2 (Wilson 95 % [0 %, 65.8 %]; n = 2, so the interval is as wide as it is). At the worker's exit the block existed in 1 / 2 (run 362, ≈ 6 ms after the last observation) and not in 1 / 2 (run 756, where the block was applied 0.74 ms **after** the exit read). So the exception is raised **before** any K4 block exists; the block is published afterwards by workers that win the generation race later. Offset disclosed (PLAN LT-160C-02): the last observation precedes the `throw` by one `readGenerationBoundScoreState` and a few statements; the exit read follows it by the rest of the command and the `SET` of the outcome. The recorder's own observation read adds ≈ 6 µs per call on a call that already costs ≈ 160 µs.

### 3.4 Branch naming (the Lead's rule)
**Target ≥ 5 natural instances was not reached (2).** Under the rule, the branch is **not named from the natural runs**; the naming above is by construction (A-DET). What the natural record shows is consistent with it and adds nothing against it: 2 / 2 instances are the r3 route at the publication branch after a committed K5 `+2` (§3.2), as is the one tapped instance of phase 2 (run 787: three non-applied publications, then nothing, outcome `conflict`). Three recorded natural instances in total (two untapped with PHP-side calls, one tapped with Redis commands); no instance of b1, b2 or of any other site.

### 3.5 Rate
Untapped, recorded: **2 / 2,000 = 0.10 %**, Wilson 95 % [0.027 %, 0.364 %]. Phase-2 A-RATE (untapped, no recorder): 4 / 1,000 = 0.40 %, Wilson [0.156 %, 1.024 %]. The intervals overlap, and Fisher's exact test between the two proportions gives p = 0.10 (two-sided; not a criterion). Under 0.40 % the expected count was 8 and P(≤ 2) = 1.4 %; the overlap and the p-value say the two rates are not distinguishable from these runs, not that they are equal. I cannot tell from these runs whether the lower count is the recorder (the `checks` span says it is not slower), the run-to-run difference between jobs, or chance. Pooling untapped observations (phase-2 A-RATE and this arm) gives 6 / 3,000 = 0.20 %, descriptive only. Tapped (phase 2 A-REC): 1 / 5,000.

### 3.6 (iii) and (iv)
`unit_without_k5_no_conflict`: **0 / 2,000** here (0 / 5,000 in phase 2): no worker took an allowance unit and ended with neither a K5 `+2` nor a conflict. (iv): in passing runs **no** conflict worker has K = 1 (0 / 1,998), so the phenomenon appeared only in the two failing runs.

## 4. What is established and what is not
* **Established:** (a) the throw site is `EvaluationPipeline` L2168, by construction (20 / 20) and in 3 / 3 natural instances recorded so far; (b) at that site K4 and K5 are already committed and the later keys of the same persist step are not published; (c) in the two instances recorded with PHP-side calls the K4 block did **not** exist when the worker threw, so "the protection already exists" does not describe them; it appeared 1–10 ms later through other workers (both runs finished with a K4 block of level 3 in place).
* **Not established:** (d) the branch is not named from ≥ 5 natural instances (2 reached); (e) nothing was measured about a fix; (f) no measurement outside this test and this policy; (g) the rate under the recorder is 2 / 2,000 and is not a comparison with phase 2's untapped rate.

## 5. The contract question for the Owner (not chosen)

The test asserts `k5 = 2 × (consumed − conflicts)`, which treats a `RateLimitConcurrencyException` as "no K5 update". At L2168 it is thrown after the allowance unit, the K4 mutation and the K5 `+2` are committed (§2, §3.2).

**Path A — the exception after committed writes is intended; the test's equation is the assumption.**
* *Change:* test only (`KnownDeviceAllowanceConcurrencyIntegrationTest`): count each `conflict` worker by what it committed (K = 0 at the K4-mutation conflict, K = 1 at the publication conflict) instead of assuming 0, or assert inequalities. No change in `src/`.
* *Measurement needed:* (1) that the relaxed equation still detects the failures it was written for: a fault injection in the harness (an `ArmedStore` that applies the K5 `+2` twice, or skips it, for one command) must fail the relaxed test; (2) a run of the relaxed test on E-CI at a size that would have caught the phenomenon (≥ 1,500 runs at 0.2–0.4 % gives ≥ 3–6 expected publication conflicts) with 0 failures; (3) the Owner's confirmation that a caller seeing an exception after a K5 update is acceptable (a caller's retry would add a second K5 `+2` for one failure).

**Path B — it is a defect; the pipeline should not throw in that state.** The evidence of §3.3 matters for what "B" can mean: at the throw the K4 block did not exist (2 / 2), so "recognise the existing block instead of throwing" would have found nothing in these two instances; the available variants are:
* **B-1, re-ask:** more publication attempts, or a final attempt with a freshly read generation, before giving up. *Change:* `EvaluationPipeline` L2133–L2168 (`src/`), under the DEC process. *Measurement:* the same A-NAT recorded run on E-CI with the fix (the throw must disappear at a size that would have shown ≥ 3 instances), the publication latency and attempt count under 16 workers (A-DET and the recorder give it), and the 5-b2 lesson: a measured benign cost on the admission suites before any `src/` change.
* **B-2, recognise the existing block (when there is one):** if the block is present at the final attempt, return its decision instead of throwing. *Change:* same lines plus the decision to return. *Measurement:* as B-1, and the share of throws where a block is present at the final attempt (0 / 2 here, so this variant would change none of the observed instances; it needs a count at scale first).
* **B-3, publish the block from the committed state:** if the K4 score is still at level ≥ 2 after the last re-read, return the corresponding decision and leave persistence to the next successful publisher. *Change:* same lines plus a contract on who persists. *Measurement:* that a block is eventually published when no other worker is mutating (a single-worker race), which A-DET can build.

The REPORT does not choose. A later phase needs the Owner's path and, for B, the variant.

## 6. Deviations and what was not done (disclosed)
* Pre-state in A-DET and V-CLOCK-2 (PLAN §9 D-1), the class-loading correction found by the detectors (BC-1), the non-final recording class (D-2) and the V-REF line (D-3) are recorded in PLAN §9.
* The recorder adds one read (`checkBlock`) after every non-applied publication (an observation, not a record) and one read at each worker's exit (PLAN LT-160C-02); both are `parent::` reads, change no returned value, and are covered by the V-CLOCK-2 control. The `checks` run measured the recorder: median span ratio 0.945, +6.3 µs per call on a 158 µs call.
* The conflict classification of a worker (publication or mutation conflict) is inferred from the recorded call sequence, not read from the test's outcome keys. It is cross-checked in every run against the number the test itself computes (`ΣK`, `ΣU`, `k5`), and the two failing runs are consistent with it; it is not an independent observation of the exception.
* b1 was not built (§2). T-K4 and T-HAND are not in this phase. No run outside `--filter` of this test and no PHP version other than 8.5.12.
* Rates are descriptive; the A-NAT target (≥ 5) was not reached.

## 7. Files
`harness/` (`w1_bootstrap.php`, `w1_bootstrap_lib.php`, `store-transform.diff`, `store.sha256`, `lib.php`, `fixture.php`, `adet.php`, `vclock2.php`, `vtransform.php`, `anat_check.php`, `detectors.php` / `detectors.sh`, `overhead.php`, `run.sh`); `logs/checks/E-CI/` (V-TRANSFORM, V-CLOCK-2, detectors 15 / 15, overhead); `logs/adet/E-CI/`; `logs/anat/E-CI/shard-1..8/` (the verdict line of all 2,000 runs, V-REF, V-TRANSFORM, config; runs 362 and 756 with `phpunit.out`, `junit.xml` and all 32 recorder files). Each logs folder has `SHA256SUMS`. The temporary workflow `.github/workflows/flaky-160.yml` is deleted in its own commit before Ready.
