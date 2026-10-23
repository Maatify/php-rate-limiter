# #160 — phase 2: the mechanisms of T-CLAIM and T-ALLOW (pre-registered measurement, no fix)

> **Status:** PRE-REGISTRATION, plan **v1**. Committed alone, **before** any harness code, workflow or result. Evidence only: no change to `src/`, `tests/`, `composer.json`, `phpunit.xml`, `ci.yml`; `investigations/flaky-160/` phase-1 files and `investigations/flaky-157/` are read, never edited.
> **Head:** the branch is cut from `main@d0739a5` (PR #162 merged). V-HEAD (per run, per arm): `src/`, `tests/`, `composer.json`, `phpunit.xml` byte-identical to `d0739a5` and a clean tree. Note `d0739a5` changed `EvaluationPipeline::addBoundedSnapshot` and the two mixed-version tests; the two tests of this phase do not go through that method's set-creating path in a way that matters (not relied on; V-HEAD pins the bytes).
> **Assignment:** the Lead's #160 phase-2 assignment (2026-10-23); the Owner's decision that T-CLAIM and T-ALLOW go first (REPORT #161 §6 (b)); T-K4 and T-HAND are a separate assignment.
> **Rules kept:** plan before harness; every LT ruled before the build; no registered condition changes after it has run; one commit per step; the PR stays Draft; my own checks are a self-review, never a Lead review; the Owner approves the measured cost on the PR before any dispatch.

## 0. Questions

1. **C (T-CLAIM).** Is the failure at L1097 exactly the straddle of a whole-second boundary between the clock reading of the block call and that of the claim call (`LIFECYCLE_CLAIM` judging `expiresAt > nowSeconds`)? Deterministically (C-DET) and at the natural rate (C-NAT).
2. **A (T-ALLOW).** In a failing run of `testLoginOverlapSplitsIntoRecordedConflictAndEarlyBlockExactly`, which worker is one unit short, and in which branch of `EvaluationPipeline`: a branch that takes an allowance unit and returns without a K5 `+2` and without `RateLimitConcurrencyException`, or a command that takes two units? (A-REC), and at what rate does the test fail under `--filter` without the tap (A-RATE, descriptive).
3. **Decision.** Per test: defect in the store / pipeline, or assumption in the test; and what must be measured before any fix.

## 1. What the code does (read, not measured)

### 1.1 T-CLAIM (`tests/Integration/Redis/RedisFullCapabilityStoreIntegrationTest.php` L1070–L1100)

The sequence of store calls, each with **one** clock read in the public method (`RedisFullCapabilityStore.php` L1394–L1599, L1785, L1840, L1909, L1955): MUTATE (`mutateGenerationBoundScore`, ms read L1840), BLOCK (`blockWithPunishmentLifecycleTracking`, **seconds** read L1909, duration 1), READ L1092 (`readGenerationBoundScoreState`, ms read L1785), CLAIM L1097 (`claimPostPunishmentReentry`, ms read L1955). The store's clock is its third constructor parameter (`$clock ?? SystemClock(UTC)`, L1389), so it is injectable without touching `src/`.

* BLOCK writes `expiresAt = now + durationSeconds` (`HARD_BLOCK`, L1287) with its own second.
* `LIFECYCLE_CLAIM` treats the key as blocked, returning 0, only while the block key's `PTTL > 0` and `expiresAt > nowSeconds` with the claim's own reading (L520–L529; `compareDecimalStrings(rawExpires, nowSecondsStr) > 0`).
* So claim L1097 returns `true` whenever `second(claim reading) > second(block reading)` (the one-second block ends at the next whole second), and also if more than the physical `EXPIRE` of the block key (1 s) elapses between the calls. A boundary that falls **before** READ L1092 makes READ see no block, so `postPunishmentReentry` is not null and L1095 fails first.
* Expected per-run probability ≈ (claim reading − block reading) ÷ 1 s, plus the READ-side split.

### 1.2 T-ALLOW (`tests/Integration/Redis/KnownDeviceAllowanceConcurrencyIntegrationTest.php` L112–L137)

* `runOverlapping()` forks 16 workers per wave (L168 `pcntl_fork`), two waves (`first`, `second`). Each worker opens its own `RespRedisCommandExecutor` and builds its pipeline with `FixedClock('@' . $now)` where `$now` is read once per wave in the parent (L172, L241); a barrier on `INCR` (L195–L202) releases all workers together.
* The allowance unit is taken in `EvaluationPipeline::processUpdates()` L1649–L1668 (`incrementBudgetAcrossRotation(auxiliaryAccountKey(… 'known_device_allowance' …))`); with `$allowance->count > $limit` the failure is also scored on K4. The recorded outcome of a worker is `ALLOW` / `HARD_BLOCK` / … or `conflict` (a caught `RateLimitConcurrencyException`, e.g. L1741 / L2168).
* The invariant asserted at L136: `k5 = 2 × (consumed − conflicts)`. Failures so far are always one unit short (`consumed` = the final `known_device_allowance` count; phase-1 REPORT §4): either one command took an allowance unit and ended with neither a K5 `+2` nor a conflict, or one command took two units. **The branch is not named by reading; A-REC names it from the log.**

## 2. Environments and cost

| Id | Where | Role |
| --- | --- | --- |
| **E-CI** | GitHub Actions `ubuntu-latest`, PHP 8.5, `redis:7.0.15-alpine` from `docker/redis-integration/compose.yaml` read as data (as #157/#160). Redis and PHP share the runner's kernel clock | every counted number of A, and C-NAT |
| **E-LOCAL** | scratch worktree on the Mac, Redis on a port ≠ 6379 | detectors and C-DET smoke only; **no counted number**. If E-CI cannot run, the work stops and returns to the Owner |

* **Workflow.** Restored from phase 1 by `git revert 469505d` as its own commit, then a separate commit that adapts it (modes `ref | detectors | c | a`), `permissions: contents: read`, no secrets, `harness/env.sh` in every job, push guarded to this branch, deleted in its own commit before Ready (LT-160B-07).
* **Cost (registered, then measured).** My estimate is ≈ 3 s per `--filter` run under the tap; N = 5,000 ≈ 5 runner-hours over 20 shards. **The measured per-run cost of one `--filter` run under the full-record tap on E-CI is the number the Owner approves on the PR before any dispatch** (§7.3). Free account: shards < 2 h each, no re-dispatch without the Owner's word, no other CI running during a dispatch, no fallback to E-LOCAL for counted numbers.

## 3. Arm C — T-CLAIM

### 3.1 C-DET (deterministic, 20 + 20 per variant)

A harness PHP script (not a PHPUnit test) builds `RedisFullCapabilityStore($executor, $ns, $clock)` with a harness `SettableClock` (implements the project's `ClockInterface`; the value is set by the script between calls) and runs the test's four calls with the test's exact arguments (L1071–L1097), one fresh namespace per trial, T = the Redis `TIME` second read once per trial.

| Variant | Clock value for MUTATE / BLOCK | READ | CLAIM | Prediction (registered) |
| --- | --- | --- | --- | --- |
| **C-DET-1** | T | T | **T + 1** | claim returns `true` **20 / 20** (the assertion `assertFalse` at L1097 would fail); READ returns `postPunishmentReentry === null` |
| **C-DET-0** (control) | T | T | T | claim returns `false` **0 / 20** `true` |
| **C-DET-2** | T | **T + 1** | T + 1 | READ's `postPunishmentReentry` is **not null** 20 / 20 (the failure would surface at L1095, not L1097); claim returns `true` 20 / 20 |

A trial counts only if the BLOCK transition applied (`applied === true`) and the block key existed with `PTTL > 0` at the READ; otherwise it is reported as an invalid trial (not a hit and not a miss). Any other value is a **finding against §1.1**.

### 3.2 C-NAT (natural, no injected values)

* **K = 100,000** repetitions of the four calls, one fresh namespace per repetition, one fresh container, no tap, E-CI.
* The store's clock is a **pass-through recorder**: it returns `SystemClock(UTC)->now()` unchanged and records, per call label (MUTATE, BLOCK, READ, CLAIM), the microsecond reading. Whether a pass-through recorder counts as "without injection" is **LT-160B-02**.
* Recorded per repetition: the four readings (s, µs), `applied`, READ's `postPunishmentReentry` null/not, CLAIM's value, and the failure position the test would show (L1095 if READ's reentry is not null, else L1097 if CLAIM is `true`, else none).
* **N-C1 (per call, exact):** CLAIM returns `true` **iff** `second(CLAIM reading) > second(BLOCK reading)` (or the BLOCK→CLAIM elapsed time exceeds 1 s, in which case the physical key has expired; both recorded). Every discordant repetition is a **finding against §1.1 / against `LIFECYCLE_CLAIM`**.
* **N-C2 (rate):** the number of repetitions with `second(CLAIM) > second(BLOCK)` is inside the Poisson 95 % interval around `Σ (CLAIM − BLOCK gap in s)` over all repetitions; and the split between L1095 and L1097 matches the split of the gap into (BLOCK→READ) and (READ→CLAIM) in expectation (a boundary in the first part → L1095; in the second part → L1097), stated with the counts.
* **Power (disclosed):** a gap of ≈ 0.5 ms gives ≈ 50 straddles at K = 100,000; N-C1 is checked on every repetition whatever the count.

### 3.3 What separates (registered)

If N-C1 holds with no discordant repetition, the behaviour is the **store's correct contract** (a one-second block ends at the next whole second) and the assumption is in the **test** (it asserts a block still holds after a step it does not control the clock of). Any discordant repetition means a defect in `LIFECYCLE_CLAIM` (or in the way it reads its inputs), reported with its readings.

## 4. Arm A — T-ALLOW

### 4.1 A-REC (recorded `--filter` runs, full-record tap)

* **Run:** `vendor/bin/phpunit --filter testLoginOverlapSplitsIntoRecordedConflictAndEarlyBlockExactly tests/Integration/Redis/KnownDeviceAllowanceConcurrencyIntegrationTest.php` (exact class file and `--filter` fixed by the harness), through the #157 RESP tap in a **full-record mode**: every command and its reply on every connection, with the connection's client pid. Fresh Redis container per run; `--do-not-cache-result`; empty cache directory.
* **Tap extension (LT-160B-01, LT-160B-03):** #157's tap recorded only R1-key commands and keyspace-wide commands. The extension records every command/reply pair per connection (V-FRAME as #157: command and reply counts must pair, no unmatched, no parse error). The attribution of connections to workers is by the client's pid, resolved by the tap from `/proc/net/tcp` and `/proc/<pid>/fd` at accept time (Linux/E-CI), because each worker opens two connections after the fork (its own executor and the store's). V-WORKER: every attributed pid has exactly one barrier `INCR` and one outcome `SET`; 16 pids per wave; otherwise the run is invalid.
* **Per run, per worker, extracted:** `U` = the number of increments of the `known_device_allowance` key (identified by the HMAC key string of `auxiliaryKey('login_protection','login-owner','test_secret')` or its sha256, as the store embeds it — V-KEY checks the identification below), `K` = the number of applied K5 mutations by `+2` (the K5 key HMAC of the test's `key('login_protection','k5','login-owner:concurrent-fp')`), and the worker's recorded outcome (`outcome` `SET` value).
* **V-KEY:** the sum of `U` over all workers equals the `consumed` the test read (the reply of the parent's budget read) in every run; otherwise the run is invalid.
* **Prediction (registered):** in every **failing** run exactly one worker has `U − K` greater than every other worker's by one unit and an outcome that is not `conflict` (one unit taken, no K5 `+2`, no exception); every passing run has `U − K = 0` for every non-`conflict` worker, and `U = 1` or the worker is an early-block. **The responsible branch of `EvaluationPipeline` is named from the record, not predicted:** the worker's command sequence is mapped to the branch by reading `processUpdates()` (L1649 onward) and what follows; the mapping rule is in the REPORT with the commands that decide it.
* **Detector (V-FRAME, as #157):** a synthetic run with one line removed from the record must be **invalidated**; a synthetic run with a worker's `INCR` of the allowance key swapped in order is still valid. Shown before any counted run.
* **Pass/fail meaning:** a branch that takes the unit and returns without recording K5 and without an exception is a **defect in the pipeline**; one command taking two units for one account is a **defect in the budget counting**; anything else is reported as found.

### 4.2 A-RATE (descriptive)

* Failure rate of the same `--filter` run **without** the tap, on E-CI, with the same container and cache rules. Descriptive only: U measured in-suite (4 / 3,300 = 0.12 %), and no `--filter` rate exists.
* **N = 5,000 runs** (registered, fixed), **cap** for A-REC = 5,000 recorded runs. A-REC stops at the **first of**: ≥ 5 failing recorded runs, or the cap, no earlier stop below the cap.
* **Power (disclosed, Poisson):** at 0.12 % per run, N = 5,000 gives 6.0 expected failures, P(≥ 5) = 71.5 %, P(≥ 1) = 99.8 %; at 0.06 % (half): 3.0, 18.5 %, 95.0 %; at 0.24 % (double): 12.0, 99.2 %, ~100 %. If fewer than 5 failing recorded runs are caught at the cap, the REPORT says A-REC did not reach its target and the branch is **not named**.

## 5. Validity

* **V-HEAD:** as above. **V-PORT:** container port recorded, never 6379. **V-CONFIG:** `redis_version`, `maxmemory`, `maxmemory-policy` per container. **C-1 / C-2:** `composer.lock`, PHP and PHPUnit versions per job; `envset.php` reports whether all jobs ran one resolved set.
* **V-REF:** one reference run per dispatch on E-CI of the two test files' classes in the `--filter` form, passing, before any counted run.
* **V-CLOCK (arm C, E-CI):** one kernel clock on the runner; the recorder returns the unchanged reading (the pass-through is verified by a run with the recorder and a run without, giving equal results for a fixed-clock control).
* Per-run verdict lines and per-run saved records follow phase 1's layout (`logs/`, `SHA256SUMS` per folder). A run with a failure keeps its full `phpunit.out` and full tap record; a passing run keeps a verdict line and its per-worker `U`, `K`, outcome summary.

## 6. Questions for the Lead (LT-160B-xx), to be ruled before any build

* **LT-160B-01 — full-record tap.** The assignment says the tap in phase 1 recorded R1 keys only and this arm needs every command and reply per connection. I propose extending a **copy** of #157's tap into `phase2/harness/` (the #157 harness stays unedited) with a `full` mode. Is a copy under `phase2/harness/` acceptable, and is it recorded here as an extension of the instrument (it is: it changes what is logged, never a byte of the stream)?
* **LT-160B-02 — pass-through recorder in C-NAT.** The recorder wraps `SystemClock` and logs readings without changing any value the store sees. Is that "without injection of values" for N-C1/N-C2? Without the recorder only the failure count could be measured, not the gap.
* **LT-160B-03 — worker attribution by pid via `/proc`.** E-CI is Linux; the tap resolves a connection's client pid at accept time. If the pid cannot be resolved for a connection, that run is invalid (V-WORKER). Acceptable, or must attribution use another rule (e.g. by the order of the barrier `INCR` replies, which is weaker)?
* **LT-160B-04 — C-DET-2.** The second deterministic variant (T + 1 before READ) predicts the failure at L1095. It is added to the assigned C-DET (20 + 20) as a third row with its own control reuse (C-DET-0). OK to count it with the same 20?
* **LT-160B-05 — naming the branch.** Branch naming from the Redis command sequence rests on my reading of `processUpdates()`. If the record shows two candidate branches the command sequence does not separate, is it acceptable to add a **second** A-REC pass with an additional PHP-side recorder (a decorating store, wired only by a harness bootstrap, never in `tests/`) that logs which `EvaluationPipeline` store method was called per worker? Default: no, report both candidates and what would separate them.
* **LT-160B-06 — N and the stop rule.** N = 5,000 and the "≥ 5 failing recorded runs or the cap" rule, with the powers in §4.2. Confirm or give another N; the cost per run (measured, §7.3) is shown to the Owner before the dispatch.
* **LT-160B-07 — workflow.** `git revert 469505d` as its own commit, then the adaptation commit, as §2; deleted before Ready.
* **LT-160B-08 — K for C-NAT.** K = 100,000 repetitions (≈ 50 expected straddles). Confirm, or give another K.

## 7. Order and outputs

1. This commit alone (PLAN). **I stop and do not push until the Lead rules on §6.**
2. Harness (`phase2/harness/`) with the detectors running (§4.1 V-FRAME detector, C-DET against E-LOCAL for smoke).
3. Measured cost of one `--filter` run under the tap on E-CI (§2); a PR comment with the number; the Owner's word.
4. Dispatch (C first: minutes; then A-RATE and A-REC).
5. REPORT: for each arm the prediction and the result with Wilson 95 %, the branch named for T-ALLOW from the record, and a per-test preliminary decision (defect or test assumption) with what must still be measured before any fix.

Outputs: `investigations/flaky-160/phase2/{PLAN,REPORT}.md`, `harness/`, `logs/` with `SHA256SUMS`. The PR is opened Draft and stays Draft until the Lead's review and the Owner's decision; any confirmed finding blocks Ready; a failure of any of the six methods on the normal CI is reported before any re-run.
