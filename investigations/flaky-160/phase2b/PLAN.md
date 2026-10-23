# #160 — phase 2b: the branch behind T-ALLOW, and whether the block already exists (pre-registration, no fix)

> **Status:** PRE-REGISTRATION, plan **v2** (v1 = `ff7bb6d`; v2 only appends §8, the Lead's rulings on LT-160C-01..08, and amends nothing above it that already ran). v1 committed alone, **before** any harness code, workflow or result. Evidence only: no change to `src/`, `tests/`, `composer.json`, `phpunit.xml`, `ci.yml`; `investigations/flaky-160/` phases 1 and 2 and `investigations/flaky-157/` are read, never edited.
> **Head:** the branch is cut from `main@2cf8565` (phase 2 merged, PR #163). `git diff d0739a5 2cf8565 -- src tests composer.json phpunit.xml` is empty, so the code under test is the one phase 2 measured. V-HEAD (per run, per arm): `src/`, `tests/`, `composer.json`, `phpunit.xml` byte-identical to `2cf8565` and a clean tree.
> **Assignment:** the Lead's #160 phase-2b assignment (2026-10-23): "تسمية فرع T-ALLOW وسؤال الحجب موجود أصلًا".
> **Rules kept:** plan before harness; every LT ruled before the build; no registered condition changes after it has run; one commit per step; the PR stays Draft; my own checks are a self-review, never a Lead review; the Owner approves the measured cost on the PR before any dispatch; **the REPORT presents the contract question and does not choose.**

## 0. Questions

1. **Branch (A-DET, A-NAT).** In the one recorded failure (phase 2 REPORT §3.1, run 787) a worker applied its allowance unit, a K4 mutation and a K5 `+2`, then threw `RateLimitConcurrencyException('K4 punishment publication conflict budget exhausted.')`. Is that the site `EvaluationPipeline` L2168 (`if (! $applied) throw …`), and is it the cause of **every** failing run of the test: one `conflict` worker whose K5 `+2` was already committed?
2. **Block already there (A-NAT).** At the moment such a worker throws, does an active K4 block of level ≥ 2 already exist (published by another worker)? That is: is the exception raised in a state where the protection the publication was meant to create is already in place?
3. **Contract (not decided here).** Is a `RateLimitConcurrencyException` after committed writes intended (then the test's formula `k5 = 2 × (consumed − conflicts)` is the assumption to relax) or a defect (then the publication should be re-asked, or the existing block recognised, instead of throwing)? The REPORT lays out the evidence and what each path needs; the Owner chooses.

## 1. What the code does (read, not measured)

`EvaluationPipeline::persistWinningCandidates` (L2052–L2190), per winning K4/K5 block key, in the insertion order of `$blocks`:

* For a key in `lifecycleEligibleKeys` with level ≥ 2 on a `PostPunishmentReentryPolicyInterface` policy (L2117–L2168): up to **three** attempts at `CallerTimeStore::blockWithPunishmentLifecycleTracking($store, key, previousKey, expectedGeneration, …)`. Each non-applied answer is followed by `readGenerationBoundScoreState`; the loop `break`s (without applying) if **(b1)** `$latest?->generation === null` or **(b2)** the recomputed `latestLevel < 2`; otherwise it adopts the fresh generation / level / duration and retries (L2137–L2161).
* After the loop, `if (! $applied) throw new RateLimitConcurrencyException(…)` (L2166–L2168). So the throw has **three** routes: **(r3)** three attempts all answered not-applied; **(b1)**; **(b2)**. Phase 2's single instance showed three attempts and three reads (r3).
* The throw leaves the `foreach ($blocks …)` loop: **keys after the throwing key in `$blocks` are not persisted** and the command returns no decision (the worker's outcome becomes `conflict`). The REPORT names, from the record, which other block keys (if any) were behind the K4 key in that run.
* The K4 mutation and the K5 `SCORE_INCREMENT` that feed this step are committed earlier in the same command (`processUpdates`); they are not rolled back by the throw.

`KnownDeviceAllowanceConcurrencyIntegrationTest` (L112–L137, `runOverlapping` L168, `runWorker` L209): the store and its executor are built **in the test** with `new RedisFullCapabilityStore(new RespRedisCommandExecutor(…), ns)` in `store()` (L257–L259), both `final`; the catch of `RateLimitConcurrencyException` that records `conflict` is in `runWorker` (L224). Nothing is injected. This fixes how a PHP-side recorder can be wired (§2, LT-160C-01).

## 2. Instrument: PHP-side recorder (harness bootstrap only)

**The problem.** The assignment asks for a decorating store from the harness bootstrap, not in `tests/`. The test constructs the concrete `final` store itself, so a decorator **object** cannot be substituted; only the **class definition** the autoloader serves can differ.

**Proposed wiring (W1).** The harness bootstrap (`--bootstrap`, which replaces `vendor/autoload.php` as PHPUnit's bootstrap and requires it first) registers a **prepended** autoloader for exactly one class, `Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore`. It evaluates the **unchanged on-disk file text** with exactly two token replacements: `final class RedisFullCapabilityStore` → `class RedisFullCapabilityStoreInner`, and no other byte; then defines `final class RedisFullCapabilityStore extends RedisFullCapabilityStoreInner implements` (the same interfaces are inherited) overriding only the public methods listed in §2.1 with `record(); return parent::…()`. `src/` on disk is unchanged (V-HEAD holds); the method bodies the pipeline executes are the original ones.

* **V-TRANSFORM:** the harness saves the evaluated text, and a checker proves it differs from the file in the two tokens only (`diff` recorded in `harness/`); `instanceof` of every interface the pipeline tests (`PunishmentLifecycleStoreInterface`, `HardBlockCycleStoreInterface`, …) holds on the subclass (run in the detectors).
* **V-CLOCK-2 (control, on a fixed clock):** a sequential, single-process run of the test's command through the pipeline (`FixedClock`) **with** and **without** the recorder gives the same end state: `known_device_allowance` count, K4 value, K5 value, block level, decision, and the same sequence of Redis commands through the phase-2 tap in `full` mode (the tap is used here, in a control, never in a counted arm). A difference invalidates the arm.
* **Recorder output:** per worker (pid), a JSONL file `rec-<run>-<pid>.jsonl` written at the worker's exit by a shutdown function registered in the bootstrap before the forks (inherited by each child); per record: monotonic ns timestamp, method, the key identity (K4/K5/allowance by the HMAC strings the test computes, matched by the harness), and the return summary. Writes happen in memory and are flushed once at exit, so no I/O is added between calls.

### 2.1 Recorded methods and what is read at a non-applied publication

Recorded in call order: `incrementBudget*` (allowance), `mutateGenerationBoundScore*` (applied or not, resulting value), `increment*` (score; amount and key, K5 `+2`), `blockWithPunishmentLifecycleTracking*` (applied, attempt index), `readGenerationBoundScoreState*` (generation null or not), `checkBlock*`, `block*`. The **attempt number** and the **exit route** of the loop (r3 / b1 / b2) are derived from the record: after the last non-applied publication the next record is either a read followed by a third attempt, or the worker's final store call.

**Block-existence read ("inside the catch").** The catch is in `tests/`, so the recorder cannot run inside it. Instead, **immediately after each non-applied `blockWithPunishmentLifecycleTracking*`**, the recorder calls the store's own `checkBlock` for the same key (`parent::checkBlock`, a read) and records level and `expiresAt`; the **last** such record of a conflict worker is the state at its throw, **earlier by one `readGenerationBoundScoreState` and a few PHP statements** than the `throw` (disclosed, in µs). In addition, at the worker's exit (shutdown function, after the test's `SET outcome`), the recorder reads `checkBlock` of K4 and K5 once more: the later state, reported separately and never used for (ii). The extra reads are part of what the overhead measurement (§5.3) covers.

## 3. Arm A-DET (deterministic, local or E-CI, minutes)

A harness PHP script (not PHPUnit), `FixedClock`, the **same policy, keys and device** as the test (`LoginProtectionPolicy`, account `login-owner`, device `concurrent-fp`, `secret test_secret`, namespace per trial), the pipeline built as in the test's `pipeline()`; the store is the W1 class whose recorder has an **injection switch** (only in this arm): when armed for the final command, `blockWithPunishmentLifecycleTracking*` returns `new PunishmentLifecycleTransitionDTO(false, null, null, null)` without calling Redis, each of the three times. The preceding commands run **without** injection until the account's K4 sits at the state where the next command's K4 value reaches level ≥ 2 and the K4 key is `lifecycleEligible` (the harness derives the count from the policy and records it; the test's own number of preceding commands is `8` allowance units by its formula `k4 = 3·max(0, consumed − 8 − conflicts)`).

| Variant | Setup | Registered prediction |
| --- | --- | --- |
| **A-DET-1** (inject) | final command with the switch armed | `RateLimitConcurrencyException` with message `K4 punishment publication conflict budget exhausted.`, `getLine()` = the L2168 line of `EvaluationPipeline`, **20 / 20**; in Redis after the exception: allowance count +1, K4 value mutated (applied), K5 **+2** (committed), **no** K4 block key; exactly three `blockWithPunishmentLifecycleTracking*` calls recorded |
| **A-DET-0** (control, switch off) | same state, no injection | **0 / 20** exceptions; the decision is returned; the K4 block exists with level ≥ 2 |
| **A-DET-b** (route b2) | switch armed for the first attempt only and the K4 score lowered (below level 2) by a prior state change in the harness so that `latestLevel < 2` after the first read | exception at L2168 after **one** attempt, **20 / 20**; named so that the REPORT shows the routes differ in the number of publication attempts |

A trial is invalid (reported, not counted as hit or miss) if the pre-state did not reach the publication branch (no `blockWithPunishmentLifecycleTracking*` call recorded in the control). Any other value is a **finding against §1**. A-DET names the branch **by construction**: the throw site and the committed state a conflict worker can have, not that this is what happens in the natural runs; that is A-NAT's question.

## 4. Arm A-NAT (natural, E-CI, no tap, N = 2,000 on 8 shards of 250)

* **Run:** exactly the phase-2 A-RATE command (`--filter` `/::testLoginOverlapSplitsIntoRecordedConflictAndEarlyBlockExactly$/` of `tests/Integration/Redis/KnownDeviceAllowanceConcurrencyIntegrationTest.php`, one fresh `redis:7.0.15-alpine` container per run, `--do-not-cache-result`, empty cache dir), **with** the W1 bootstrap and no RESP tap. A failing run keeps its `phpunit.out`, `junit.xml` and all 32 recorder files; a passing run keeps its verdict line and the per-worker summary (outcome, U, K, publication attempts, exit route).
* **Target:** ≥ 5 failing runs (the naming target phase 2 did not reach). **Not a stop rule** (shards do not coordinate); all 2,000 run.
* **Power (disclosed, Poisson, N = 2,000):** at the A-RATE point 0.40 %: 8.0 expected, P(≥ 5) = 90.0 %; at 0.20 %: 4.0, 37.1 %; at the A-RATE lower bound 0.156 %: 3.1, 20.5 %; at 1.0 %: 20, ~100 %. If fewer than 5 failing runs are caught the REPORT says the target was not reached and the branch is stated only as far as the instances go.

### 4.1 Registered predictions (written before any run)

* **(i)** In **every** failing run, exactly one worker has outcome `conflict` with **K = 1** (a committed K5 `+2`), and its exception comes from the publication branch **after** the K5 `SCORE_INCREMENT` (the recorded sequence ends with non-applied `blockWithPunishmentLifecycleTracking*` calls and no later store call). In every failing run the number of `conflict` workers with K = 1 exceeds the number the test assumes by exactly one, i.e. the `k5` the test reads equals `2 × (consumed − conflicts) + 2`. Further: the exit route is **r3** (three attempts) in every instance; **b1/b2: 0** (`level` does not fall in a fixed-clock run and the generation is never null once the K4 mutation applied). The route is a reading from the record, and a b1/b2 instance would be a finding.
* **(ii)** At the throw of such a worker, **an active K4 block with `level ≥ 2` already exists** (per the last post-attempt `checkBlock` record), published by another worker. Registered as written by the Lead; the count is reported with Wilson 95 % over the number of instances and, if the number of instances is 5 or fewer, the interval is stated as wide as it is.
* **(iii)** `unit_without_k5_no_conflict` (a worker that took an allowance unit and has neither a K5 `+2` nor a `conflict`) stays **0** in all runs (phase 2: 0 / 5,000).
* **(iv)** (mine) In a passing run no `conflict` worker has K = 1. If one does, the formula can hold while the contract question exists, and the REPORT counts such runs separately (they show the phenomenon without the failure; they cannot be seen by the test).

### 4.2 Validity

* **V-HEAD, V-PORT (never 6379), V-CONFIG (`redis_version`, `maxmemory`, `maxmemory-policy` per container), C-1 / C-2 (`composer.lock`, PHP and PHPUnit per job, `envset.php`) as in phase 2.**
* **V-REF (enforced, unlike phase 2):** one `--filter` run per shard, with the same bootstrap, before any counted run. A failure at the test's L136 assertion (`assertSame(2 * ($consumed - $conflicts), $k5)`) is recorded as **"failure on the phenomenon"**: the shard continues and the run is counted as a failure of the arm. **Any other V-REF failure stops the shard** and is reported.
* **V-CLOCK (the control of §2):** the fixed-clock with/without recorder run is executed on E-CI before the counted runs; the arm does not start on a difference.
* **V-RECORD:** per run, the recorder files of all 32 workers exist and parse; the sum of the workers' allowance increments equals the `consumed` the test read (as phase 2's V-KEY); otherwise the run is invalid, counted and reported. Invalid runs for recorder reasons above **2 %** stop the arm (as LT-160B-03).

## 5. Environments, overhead and cost

### 5.1 Environments

| Id | Where | Role |
| --- | --- | --- |
| **E-CI** | GitHub Actions `ubuntu-latest`, PHP 8.5, `redis:7.0.15-alpine` from `docker/redis-integration/compose.yaml` read as data | every counted number of A-NAT; A-DET and V-CLOCK are run here too |
| **E-LOCAL** | scratch worktree on the Mac, Redis on a port ≠ 6379 | build and smoke only (W1 transform, detectors); **no counted number** |

### 5.2 Workflow

`git revert fa3bbce` literally as **its own commit** (restores `.github/workflows/flaky-160.yml`), then a separate adaptation commit (modes `ref | adet | anat`, `permissions: contents: read`, no secrets, push guarded to this branch, `harness/env.sh` in every job), deleted in its own commit before Ready. Free account: shards < 2 h each, no re-dispatch without the Owner's word, no other CI during a dispatch, no E-LOCAL for counted numbers.

### 5.3 Overhead of the recorder (measured once, shown before any dispatch)

* **Span of the test:** the test `--filter` run **20 times without** and **20 times with** the W1 bootstrap on E-CI, alternating, wall per run; the phase-2 untapped median is 0.47 s and the full-tap median 1.52 s (≈ 3×).
* **Registered acceptance (mine, to be ruled, LT-160C-04):** median with recorder ≤ **1.25 ×** the median without (the tap was 3× and depressed the failure rate 20-fold), and the recorder-only call cost measured once by 100,000 calls of a recorded method against the bare one. If the 1.25 × is exceeded the arm does not run; the instrument is revised and the number reported to the Owner.
* **Cost estimate for the Owner:** ≈ 0.5 s per run plus container start ≈ 2 s ⇒ 2,000 runs ≈ 1.4 runner-hours over 8 shards; the **measured** per-run number goes on the PR before any dispatch.

## 6. Questions for the Lead (LT-160C-xx), to be ruled before any build

* **LT-160C-01 — wiring of the PHP-side recorder (W1).** The test builds the `final` store and `final` executor itself, so a decorator object cannot be substituted from the bootstrap. W1 serves the store class from a **prepended autoloader that evaluates the unchanged file text with exactly `final class RedisFullCapabilityStore` renamed to an `…Inner` class, and a same-name `final` subclass that records and calls `parent::`**; `src/` bytes on disk are unchanged. Is W1 acceptable as "a decorating store wired from the harness bootstrap, never in `tests/`", with V-TRANSFORM and V-CLOCK-2 as its validity? The alternatives I considered: (W2) the same trick on the **executor** (a `tests/Support` class) — it sees Redis commands, not store methods, and cannot name the exit route or inject in A-DET; (W3) a harness re-implementation of the test's fork/barrier loop with a real decorator — it is no longer *the* test and its rate is not comparable to A-RATE.
* **LT-160C-02 — block-existence read.** The catch is in `tests/`, so (ii) is read by the recorder at the **last non-applied publication**, earlier than the throw by one read and a few statements (§2.1), plus a separate read at worker exit. Acceptable as "inside the catch" for (ii), with the offset disclosed?
* **LT-160C-03 — V-CLOCK.** In phase 2 V-CLOCK controlled the arm-C pass-through recorder. The only recorder in this phase is the store recorder, so I read V-CLOCK as the **fixed-clock with/without recorder control of §2 (V-CLOCK-2)**, not a re-run of arm C. Confirm, or say whether arm C's control is also wanted here.
* **LT-160C-04 — overhead acceptance.** The assignment says "measure once so it is shown that it does not slow like the tap". I propose the number **median ≤ 1.25 ×** (§5.3) as the registered acceptance. Confirm or give another threshold.
* **LT-160C-05 — A-DET-b.** The route b2 (`latestLevel < 2` after a re-read) is added as a third row to name its difference from r3 (one attempt against three). It needs a harness-made state change on the K4 score between attempts. OK, or leave A-DET to the two assigned rows?
* **LT-160C-06 — prediction (iv) and passing runs.** I report conflict workers with K = 1 in **passing** runs too (phenomenon without a failure). It is a descriptive addition, not a criterion. OK?
* **LT-160C-07 — N and shards.** N = 2,000, 8 shards × 250, target ≥ 5 failing runs, no early stop, powers in §4. Confirm or give another N.
* **LT-160C-08 — workflow.** `git revert fa3bbce` as its own commit, then the adaptation commit, as §5.2; deleted before Ready.

## 7. Order and outputs

1. This commit alone (PLAN). **I stop and do not push until the Lead rules on §6.**
2. Harness (`phase2b/harness/`): W1 bootstrap and the recorder, V-TRANSFORM checker, V-CLOCK-2 control, A-DET script, detectors (a recorder run with a record line removed is **invalid**; a synthetic L2168 trace is classified r3), smoke on E-LOCAL.
3. Workflow restoration (`git revert fa3bbce`) and adaptation, one commit each; normal CI on the head before any dispatch.
4. Overhead measured on E-CI (§5.3) and posted on the PR; the Owner's word.
5. Dispatch: V-CLOCK-2 and A-DET (minutes), then A-NAT.
6. REPORT: the branch named with the commands and calls that decide it; (ii) with Wilson 95 %; the contract question as the Owner's decision with, for each path, the change it needs and the measurement it needs (see below); no choice.

**REPORT shape for §0.3 (registered now so it cannot drift):** path **A, the exception after committed writes is intended** — the test's formula is relaxed (to count `conflict` workers by what they committed, which the recorder shows, or to assert inequalities); needs: a test-only change and a measurement that the relaxed formula still detects the double-count failures it was written for (a fault-injection of its own). Path **B, it is a defect** — the pipeline re-asks or recognises the existing block instead of throwing; needs: a change in `EvaluationPipeline` (a src change, DEC-gated), the decision what a publication that loses to an existing block returns (the block's decision), and a measured check that no admission test changes (the 5-b2 lesson: measure user cost before store/pipeline changes). Outputs: `investigations/flaky-160/phase2b/{PLAN,REPORT}.md`, `harness/`, `logs/` with `SHA256SUMS`. The PR is opened Draft and stays Draft until the Lead's review and the Owner's decision; any confirmed finding blocks Ready.

## 8. Rulings (Lead, 2026-10-23, on #160) and what they change

Where §8 and an earlier section differ, **§8 governs**. No `src/` or `tests/` change; the free-account limits stand; the T-CLAIM fix branch is not touched.

| LT | Ruling | Consequence |
| --- | --- | --- |
| 01 | W1 accepted as a harness-only instrument, **four conditions** | (a) the two mechanical edits (remove `final`; rename to `…Inner`) are recorded as a **diff in `harness/`**, and V-TRANSFORM proves **at run time** that the on-disk `src/` file hashes equal its hash at `main@2cf8565` and that the evaluated text equals the original **plus exactly that diff**; (b) the subclass has **no logic**: every override calls `parent::` and records only; the armed A-DET switch lives in a **separate** subclass used in A-DET alone; (c) the bootstrap is passed on the **command line** (`--bootstrap`) and is never written into `phpunit.xml`, `tests/` or `composer.json`; (d) **V-CLOCK-2** (fixed clock with and without the recorder: same end state, same decisions) passes **before any counted run**. W2 and W3 rejected |
| 02 | accepted | (ii) read at the last non-applied publication plus a read at worker exit, gap stated; **added: a descriptive read at the first non-applied publication** |
| 03 | accepted | V-CLOCK in this phase = V-CLOCK-2 |
| 04 | accepted | median with recorder <= 1.25 x without (20 + 20 alternating on E-CI, plus the 100,000-call cost). **If exceeded: the dispatch is the Owner's decision, and the REPORT says the number of instances is low and the rate comes from phase 2's A-RATE only; naming from caught instances stays valid** |
| 05 | accepted | A-DET-b (route b2) is the third row; **b1 only if it can be built without changing what the pipeline reads**; r3 is the registered route for natural cases; b1/b2 in A-NAT are findings |
| 06 | accepted, descriptive | prediction (iv) is descriptive |
| 07 | accepted | N = 2,000, 8 x 250, fixed, no early stop, target >= 5 |
| 08 | accepted | `git revert fa3bbce` literally, then the adaptation commit |

**Order (Lead):** push + Draft PR -> harness with the detectors (a record line removed invalidates the run; a synthetic L2168 trace is classified r3) -> V-TRANSFORM, V-CLOCK-2 and overhead on E-CI -> the measured cost on the PR -> the Owner's word -> A-DET -> A-NAT -> REPORT. The closing of the REPORT (two paths, no choice) is accepted as registered.
