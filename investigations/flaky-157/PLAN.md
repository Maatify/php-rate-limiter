# #157 — `testMalformedStoredGenerationFailsAcrossReadMutateClaimAndPublication` flaked once on CI: pre-registered reading and reproduction plan (phase 1)

> **Status:** PRE-REGISTRATION, plan **v1**. Committed **before** any harness code and any result. Evidence only: no fix, no fix recommendation, no change to `src/`, to any file under `tests/`, to `composer.json`, to `phpunit.xml`, to the CI workflow on `main`, or to any earlier study.
> **Baseline:** the branch is cut from `main@c8b8fd7` (PR #158 merged). Line citations are `main@c8b8fd7` unless marked **`@fe8775c`** (the head of the failing CI run; its `src/` and `tests/` are byte-identical to `main@1e951ee`, checked with `git diff --stat 1e951ee fe8775c -- src tests`: empty).
> **Assignment:** the Lead's #157 assignment (two phases; this file is phase 1). Phase 2 starts only after the REPORT and a ruling.
> **Rules kept:** plan before harness; no registered condition is changed after it has run; every LT is ruled before the build; one commit per step; the PR stays Draft.

## 0. Questions (from the assignment)

1. **Inventory.** Everything that could remove the current score key between READ (L1545) and MUTATE (L1546) in the CI environment: `setUp()`/`tearDown()`, every `FLUSHDB`/`FLUSHALL`, parallel processes on the same database, the `EXPIRE 600` itself, Redis memory policy, a service restart, any `DEL` in READ.
2. **Registered reproduction loop.** N = 500 runs of the test **inside the suite**, on `redis:7.0.15-alpine` with the CI settings, with an external recording (not the test) of `PTTL`, `TIME` and the key count before every MUTATE. Rate with a Wilson interval.
3. **Targeted arm.** Delete the key on purpose between READ and MUTATE and show that this produces exactly the observed failure ("accepted" at the MUTATE line). This proves the mechanism, not the cause.
4. **REPORT** `investigations/flaky-157/REPORT.md`: inventory, rate, mechanism proof, what is not proven. No fix recommendation.

## 1. What the code does (read, not measured)

### 1.1 The test (L1536–L1550)

* `$now = redisNow()` (one `TIME`, integer seconds, L1538), then for each of `'0'`, `'-1'`, `'not-a-number'`: `HSET score value 8 updatedAt $now generation <g> expiresAt $now+600` (L1542), `EXPIRE score 600` (L1543), then READ (L1545), MUTATE (L1546), CLAIM (L1547), PUBLICATION (L1548), each wrapped in `assertOperationFails()` (L3736–L3750).
* `assertOperationFails()` passes only on `RateLimiterException`. A call that **returns** reaches `self::fail('Malformed persisted Redis state was accepted.')` (L3749). A Redis error reply is turned into `RateLimiterException` by `RespRedisCommandExecutor::readReply()` (`tests/Support/Redis/RespRedisCommandExecutor.php` L53), so it counts as "failed" (= pass).
* The failing CI run reported L3736 and L1540 **`@fe8775c`**: the same two statements (`self::fail` and the MUTATE line). On `main@c8b8fd7` the same failure would report **L3749 and L1546**.
* The failure message does not say which of the three cases failed, nor what MUTATE returned.

### 1.2 How MUTATE can return without an exception

**On `main@c8b8fd7`** (`LIFECYCLE_MUTATE` L214–L381): the source is chosen by `PTTL` only (L306–L317). If the current key exists (`PTTL` > 0), the stored generation must pass `validPositiveInteger` (L338); `'0'`, `'-1'` and `'not-a-number'` all fail it, so MUTATE throws. If the key is absent (`PTTL` = −2) and there is no previous key and no expected source, MUTATE **creates generation 1 and returns `{1, …}`** (L318–L327). That is the only non-throwing path for these three cases. **Path P1 = the key is absent at MUTATE.**

**`@fe8775c`** (`LIFECYCLE_MUTATE` L177–L332 there) had P1 as well (L259–L280), and a second path for `'-1'` only, as #157 describes: `validInteger(observedGeneration, false)` accepted `-1` (L291), so MUTATE threw only through the expiry check at L310 (`expiresAt × 1000 < nowMs + PTTL` → `inconsistent generated score expiry`); otherwise L311 returned `{0}` (not applied) without an exception. **Path P2.** #158 closed P2 on `main` (LT-P2-11).

### 1.3 P2 needs timing, not a clock fault (derived, to be measured — §5.4)

Let `t0` = the test's `TIME` at L1538 (`@fe8775c` L1532), `t0s` its integer second, and `te` the Redis millisecond at which the `'-1'` case's `EXPIRE 600` ran. Then the key's absolute expiry is `te + 600,000` and, inside the script, `nowMs + PTTL` equals it to within about 1 ms (the script's `TIME` and its `PTTL` are read microseconds apart). The check at L310 does **not** fire when `(t0s + 600) × 1000 ≥ te + 600,000 (± ~1 ms)`, i.e. when

> `te − t0s × 1000 ≲ 1 ms`: `t0` fell in the first part of its second **and** everything from `t0` to the `'-1'` case's `EXPIRE` (index 0's HSET, EXPIRE, READ, MUTATE, CLAIM, PUBLICATION, then index 1's HSET, EXPIRE: eight round trips by reading, four of them `EVAL` carrying a multi-kilobyte script) took less than the remaining part of that millisecond.

A backward step of the Redis clock between `t0` and `te` would also satisfy it. #157 called P2 "implausible"; by this reading it is rare, not excluded, and needs **no** clock fault. Whether the span can fall under 1 ms on a CI runner is not known. So **which path the CI failure took cannot be decided from the log**, and the question "how did a key with 600 s left disappear?" (P1) may have no answer because it may not have happened. This is LT-157-01.

## 2. Inventory (question 1; `READ` unless marked `LOG`)

`LOG` = the failing job's log (run 39570493869 attempt 1, job 119123135875, fetched with `gh api …/jobs/119123135875/logs`).

| # | Candidate | Finding | Verdict |
| --- | --- | --- | --- |
| I-1 | `setUp()` `FLUSHDB` (L54) | runs before the test body; one PHPUnit process, tests are sequential | cannot fall between L1545 and L1546 |
| I-2 | Other `FLUSHDB`: this class L3183, L3296 (inside other test methods); `setUp()` of `CallerTimeAuthorityRedisTest` L73, `RedisSimpleFixedWindowIntegrationTest` L46, `RedisFullCapabilityStoreExtRedisIntegrationTest` L55, `AdmissionRedisGateTestCase` L46, `AdmissionStoreContractRedisTest` L25, `AdmissionSettlementOptInContractRedisTest` L27, `AdmissionSettlementContractRedisTest` L27. No `FLUSHALL` anywhere in `tests/`, `src/`, `scripts/` | all run in the same single process, before or after this test | cannot interleave |
| I-3 | `tearDown()` / `*AfterClass` | none in this class | — |
| I-4 | Parallel PHPUnit on the same database | CI `tests` job runs `composer test:core` → `scripts/ci/run-phpunit-with-redis.sh --exclude-group archive` → one `vendor/bin/phpunit` process. No `paratest` in `composer.json`; no process isolation in `phpunit.xml`; no `RunInSeparateProcess` in `tests/`. `run-with-redis-service.sh` starts **one** container per job under a random compose project (`maatify-rate-limiter-${RANDOM}-${RANDOM}-$$`); each matrix entry is a separate runner VM | no second suite shares the database |
| I-5 | Forked workers | `pcntl_fork` in this class (`runConcurrentWorkers` L3657–L3685) and `KnownDeviceAllowanceConcurrencyIntegrationTest::runOverlapping` (L168–L195). Workers open their own connections (`workerStore()` L3687; KnownDevice L212) and the parent `pcntl_waitpid`s every pid before the test returns | no worker outlives its test in a run where that test passed (`LOG`: one failure only) |
| I-6 | `proc_open` | this class L2605 runs `run-phpunit-with-redis.sh` with `tests/Support/Redis/fake-docker.sh` and `REDIS_INTEGRATION_TEST_STATUS` set, so the command is `bash -c 'exit N'`: no Redis connection; `proc_close` waits. Release-verification tests run `git`/`composer` only | no Redis traffic |
| I-7 | `EXPIRE 600` (L1543) and natural expiry | `LOG`: the PHPUnit phase lasted `Time: 07:02.775` (PHPUnit's monotonic timer); the runner's wall-clock stamps from the PHPUnit banner (08:36:27.384) to the `Time:` line (08:43:30.160) span 422.776 s. The two agree to ~1 ms, so there was no net forward step of the wall clock; the whole run is shorter than 600 s. The `F` is the 1,306th test, in the progress line stamped 08:36:40.000 (previous line 08:36:38.872), ~13 s after PHPUnit started. Redis runs in a container on the same kernel clock | a key set with 600 s cannot expire by time in this run; a forward-then-back clock step inside one ~1 s progress interval is not excluded by the log but has no known source |
| I-8 | Eviction (`maxmemory`, `maxmemory-policy`) | `docker/redis-integration/compose.yaml` passes only `--save "" --appendonly no`; Redis 7.0 defaults are `maxmemory 0` (no limit) and `noeviction` (with `noeviction` a full memory refuses writes, it never deletes) | excluded by reading; the harness confirms with `CONFIG GET maxmemory*` and `INFO stats evicted_keys` on the CI image |
| I-9 | Service restart | no `restart:` policy in the compose file; a restart drops the test's TCP connection → `BackendFailureException('Redis reply ended unexpectedly.')` → the message would be "received BackendFailureException", not "accepted", and the 526 tests after it would fail. `LOG`: one failure, message "accepted" | excluded by the log; the harness records `run_id` and `uptime_in_seconds` |
| I-10 | `DEL` in READ | `LIFECYCLE_READ` L382–L469 contains no `DEL`, `UNLINK`, `EXPIRE`, `PEXPIRE`, `HSET` or `HDEL` (grep); `@fe8775c` L334–L423 likewise (#157) | READ does not remove the key |
| I-11 | Reply misalignment on the shared connection | the test and the store use one `RespRedisCommandExecutor` (L53–L55); every `execute()` writes one command and reads exactly one complete reply; a short or timed-out read throws `BackendFailureException` | excluded by reading; the harness records MUTATE's own reply |
| I-12 | Key collision | key = `maatify:rate-limiter:v1:sha256(integration:test):score:sha256('r1-malformed-generation-<i>')`; no other test uses these logical names | — |
| I-13 | Caller clock in MUTATE (`main`) | the source is chosen from `PTTL` only (L306–L317); the caller's `now` does not enter that choice | the caller clock cannot make the key "absent" |

**Inventory result (READ + LOG):** no actor that could remove the key between L1545 and L1546 was found, and natural expiry, eviction and restart are excluded. P1 has no known cause; P2 (`@fe8775c` only) needs only timing (§1.3).

**CI history (`LOG`, all attempts of every `ci.yml` run since the test was added in `5e40583`, 2026-09-27):** 300 runs; 280 contain the test; 50 failed test jobs (Test Suite 8.4: 13, Test Suite 8.5: 15, Lowest: 17, Integration: 5). Only one of those 50 logs mentions this test: job 119123135875. Jobs whose PHPUnit step completed (success or failure) and so executed the test: **1,082** (Integration 272, Test Suite 8.5 271, Test Suite 8.4 270, Lowest 269); 35 cancelled and 3 skipped jobs are not counted; one jobs-listing call returned HTTP 502, so the count may miss one attempt's jobs. **Historical rate: 1 / 1,082 = 0.092 %, Wilson 95 % [0.016 %, 0.52 %]**, all job types and all heads pooled (most of these heads still had P2; the rate is not P1's alone).

## 3. Pre-plan disclosure

Run for this plan (no harness, no instrumentation):

* the CI history queries above (`gh api`), and the failing job's log;
* `vendor/bin/phpunit --exclude-group archive --list-tests` on `main` (the test is listed 1,329th of 1,855 listed methods; data providers expand the count at run time);
* one plain local run of the core suite (`bash scripts/ci/run-phpunit-with-redis.sh --exclude-group archive`, macOS, PHP 8.5.9, Docker Desktop 29.8.2) to size the loop: see the numbers below.

Numbers:

* **Local core suite:** `OK (1855 tests, 25416 assertions)`, PHPUnit `Time: 11:00.799`, 664.7 s wall including the container. In its junit log the target test is the 1,183rd test, reached after 91.6 s of summed test time. The local result cache (`.phpunit.cache/test-results`, git-ignored) holds 57 recorded defects, and `executionOrder="depends,defects"` runs those first, so **local order is not CI order** (CI starts with no cache; file order itself is sorted by `phpunit/php-file-iterator` 5.1.1, `Facade.php` L48). The harness runs every PHPUnit invocation with a fresh, empty cache directory.
* **CI denominator:** §2 (1,082 executions, 1 failure).
* **Power of the assigned loop (DERIVED from the historical rate, all job types pooled):** if P1 still occurred at 0.092 % per run, 500 runs would show ≥ 1 failure with probability 37 % (7.8 % at the Wilson lower bound, 92.7 % at the upper). A 0/500 result has a Wilson 95 % upper bound of 0.76 %, above the historical point rate, so it could not tell "gone" from "unchanged". 95 % power at 0.092 % needs N ≥ 3,240.

Nothing else was run. No reproduction was attempted.

## 4. Environments

| Id | Where | PHP | Redis | Clock relation | Role |
| --- | --- | --- | --- | --- | --- |
| **E-CI** | GitHub Actions `ubuntu-latest`, the same `actions/checkout` and `shivammathur/setup-php` pins as `ci.yml`, `php-version: '8.5'`, `extensions: redis`, `composer update` as in `ci.yml` | 8.5.x (whatever setup-php resolves; recorded) | `redis:7.0.15-alpine` via `scripts/ci/run-with-redis-service.sh` (the CI path) | one kernel clock | the CI conditions the assignment asks for |
| **E-LOCAL** | this Mac | 8.5.9 | the same image, Docker Desktop Linux VM | **separate** clocks (VM) and an extra network hop | supplementary only (LT-157-02) |

E-CI needs a workflow that does not exist on `main`: a temporary file `.github/workflows/flaky-157.yml` on this Draft branch only, triggered by `workflow_dispatch` and by `push` to this branch with a commit-message guard, removed in its own commit before Ready (LT-157-02).

## 5. Instrumentation and arms

### 5.1 The RESP tap (external; no change to `src/` or `tests/`)

The only way to read `PTTL`/`TIME`/the key count **before** MUTATE, or to delete the key between READ and MUTATE, without editing the test is to sit between PHPUnit and Redis. The harness starts a small RESP proxy (`investigations/flaky-157/harness/tap.php`, `stream_select`, many concurrent client connections because the suite forks workers) on a free local port and runs the suite with `REDIS_INTEGRATION_PORT` pointing at it. The proxy forwards every byte unchanged. For one class of command only it acts:

* **MUTATE of an R1 key** = `EVAL` whose script body's SHA-1 equals SHA-1 of `RedisFullCapabilityStore::LIFECYCLE_MUTATE` (read by reflection from the class at the tested head, no `src/` change) **and** `KEYS[1]` is one of the three R1 keys (§2 I-12).
* Before forwarding it, the tap runs on its **own** connection: `PTTL key`, `EXISTS key`, `TIME`, `DBSIZE`, `INFO server` (`run_id`, `uptime_in_seconds`), `INFO stats` (`expired_keys`, `evicted_keys`), and writes one JSONL record. After the reply it records the reply (error text, or the array) in the same record.
* It also records, for every R1 key, the test's own `TIME` reply at L1538 (`t0`) and the forwarding time of each HSET/EXPIRE/READ/MUTATE (hrtime), so every failure is attributed to a case and a reply.

The tap adds one local hop to every command. That does not affect P1 (a removal of the key) but **does** lengthen the span of §1.3, so the tap is not used where P2's rate is measured (§5.4).

### 5.2 Arms on `main@c8b8fd7`

| Arm | What | N | Expected if the reading is right |
| --- | --- | --- | --- |
| **L — loop** (question 2) | full core suite (`--exclude-group archive`) through the tap, one fresh Redis container per run | **500 runs** | 0 failures; every MUTATE record of an R1 key has `PTTL` > 0, `EXISTS` 1, constant `run_id`, `evicted_keys` 0 |
| **D — deletion** (question 3) | the tap sends `DEL key` on its own connection after READ's reply and before forwarding MUTATE; three sub-arms, one per case (`'0'`, `'-1'`, `'not-a-number'`: only that case's key is deleted) | **20 runs per sub-arm** (LT-157-06) | 20/20 per sub-arm: the test fails with exactly `Malformed persisted Redis state was accepted.` at L3749 / L1546; the tap records MUTATE's reply `{1, '9', <s>, <s+600>, 1}` (new generation 1); `PTTL` before MUTATE = −2 |
| **C — tap control** | one run without the tap, then one with it and no action | 1 + 1 | identical test, assertion and failure counts |

### 5.3 Validity (registered)

* **V-HEAD:** before each run the runner checks `src/`, `tests/`, `composer.json`, `phpunit.xml` byte-identical to the tested head and the tree clean.
* **V-CACHE:** every PHPUnit invocation uses a fresh, empty `--cache-directory`, as on CI (§3).
* **V-ORDER:** in each L run the target test executes (junit) and every R1 MUTATE that the test reaches is recorded by the tap (3 records per passing run; fewer only if the test failed earlier in the loop). A run with a missing record is invalid and counted.
* **V-CONFIG:** once per E-CI job: `CONFIG GET maxmemory`, `CONFIG GET maxmemory-policy`, `INFO server` `redis_version`, recorded.
* A run that fails for another reason (another test, infrastructure) is reported separately and does not count as a reproduction.

### 5.4 Arm H — the historical path P2 (proposed, LT-157-01)

At **`@fe8775c`** (src/tests = `1e951ee`), on E-CI, **without** the tap:

* **H-PROBE:** a probe script (not PHPUnit) that runs the test's exact command sequence through the same `RespRedisCommandExecutor` and `RedisFullCapabilityStore` classes of that head: `TIME`, then for each case HSET, EXPIRE, READ, MUTATE, CLAIM, PUBLICATION. **M = 100,000** sequences, a fresh `FLUSHDB` before each. Recorded per sequence: `t0` (the `TIME` reply, µs), hrtime span from `t0`'s reply to the `'-1'` case's MUTATE call, and MUTATE's outcome for each case (exception text or reply). Measure: rate of a non-throwing MUTATE for `'-1'`, with Wilson 95 %, and its relation to `t0`'s sub-second phase.
* **H-FORCED:** the same, but the probe polls `TIME` until the sub-second part is < 200 µs before taking `t0` (so only the span decides). **M = 10,000.** If §1.3 is right, every `'-1'` MUTATE whose span stays below the remaining part of that millisecond returns `{0}`; if spans are never that short on E-CI, 0 and the span distribution say so.
* **H-SUITE** (only if the Lead wants a rate inside the suite): N full-suite runs at `fe8775c` without the tap, failures counted from junit. The case would not be known (no tap), so this is weaker than H-PROBE for attribution.

### 5.5 Measures

* L: failures / 500 with Wilson 95 %; the distribution of the recorded `PTTL` before MUTATE; any record with `PTTL` ≤ 0, `EXISTS` 0, a changed `run_id`, or `evicted_keys` > 0, listed individually.
* D: per sub-arm, failures / 20 and the exact message and stack lines.
* H (if ruled): non-throwing `'-1'` MUTATE / M with Wilson 95 %, by `t0` phase bucket.
* **Power, stated in the REPORT, not used as a gate:** with the CI history rate (1 / denominator of §3), the probability of seeing ≥ 1 failure in 500 runs if that rate were P1's.

## 6. Outputs

`investigations/flaky-157/`:

* `PLAN.md` (this file), `REPORT.md` (question 4);
* `harness/` (tap, runners, the H probe if ruled, the report generator);
* `logs/` (per-run JSONL from the tap, junit summaries, `SHA256SUMS`), `expected/summary.md` generated from the logs.

## 7. Questions for the Lead before the harness (LT)

* **LT-157-01 — premise and arm H.** The assignment treats P1 as the only remaining path. On `main` that is right. But the failure happened **`@fe8775c`**, where P2 needed only timing (§1.3), not a clock fault, and the log cannot tell P1 from P2. If the failure was P2, the loop on `main` will find 0, and the inventory's "no actor" is the expected answer, not a gap. **Recommendation:** add H-PROBE and H-FORCED (§5.4) on E-CI, so the REPORT can say whether P2 was reachable on a CI runner. Without H, the REPORT can only say "P1 not reproduced on `main`".
* **LT-157-02 — environment.** "CI conditions" means E-CI: a temporary workflow file on this Draft branch only (`.github/workflows/flaky-157.yml`, `workflow_dispatch` + guarded `push`), removed in a separate commit before Ready. It changes no file on `main` and the normal `ci.yml` still runs on the PR. **Recommendation:** E-CI for L and H; E-LOCAL only as a supplementary D run (the mechanism does not depend on the environment). Needs the Lead's word because it executes new code on the repository's runners.
* **LT-157-03 — N, full suite vs prefix, and cost.** The assigned N = 500 has 37 % power at the historical rate (§3), so a 0/500 cannot separate "gone" from "unchanged". On CI the full core suite took 7 min 03 s: N = 500 ≈ 59 runner-hours (20 parallel jobs × 25 runs ≈ 3 h wall, holding the repository's job concurrency for those 3 h); N = 3,300 full runs ≈ 390 runner-hours, not practical. A **prefix suite** (a harness-side PHPUnit configuration listing the same test files, in the same sorted order, up to and including this class; nothing after it) keeps everything that runs **before** the test in the same process; tests after it cannot reach it (one process, sequential, forks waited: I-1…I-6). On CI the test is reached ~13 s after PHPUnit starts (§2 I-7), so a prefix run costs ≈ 20 s with the container: N = 3,300 ≈ 18 runner-hours, 20 jobs × 165 runs ≈ 1 h wall. **Recommendation:** L on the prefix with **N = 3,300** (95 % power at the historical point rate), plus the assigned **500 full-suite runs** only if the Lead wants the literal condition kept as well; the REPORT states each arm's power.
* **LT-157-04 — the tap.** Instrumentation is a RESP proxy (§5.1) because the test builds its own executor and must not be edited. It identifies MUTATE by the SHA-1 of the script body read by reflection. Accept the tap as "external recording"?
* **LT-157-05 — stopping rule.** N is fixed in advance by LT-157-03; no early stop on a first failure (a failure is logged with its tap record and the run continues). The REPORT gives the rate with Wilson 95 % and the power statement of §5.5.
* **LT-157-06 — D size and placement.** 20 runs per case. Inside the full suite (as L), or the single test with `--filter` (mechanism only)? **Recommendation:** `--filter`, on E-CI and E-LOCAL; the message and lines do not depend on what ran before.
* **LT-157-07 — line numbers.** The assignment cites L1540 (`@fe8775c`). On `main` the same statement is L1546 and the `self::fail` is L3749; D's detector uses the `main` lines.

## 8. Commit order

1. This plan (no code) — Draft PR, LT questions posted.
2. The Lead's rulings recorded in a new §9 of this file (no other change).
3. Harness (tap, runners, workflow file if LT-157-02 allows it, H probe if LT-157-01 allows it).
4. Outputs (logs, summaries, `SHA256SUMS`).
5. `REPORT.md`.
6. Removal of the temporary workflow file (if added), before Ready.

## 9. Lead rulings and Owner decision (2026-10-22, recorded before the harness)

Source: the two comments on PR #159 (Lead rulings, 19:21Z; Owner decision relayed by the Lead, 20:04Z). Recorded as ruled; where this section adds a definition the rulings leave open, it says so.

### 9.1 Rulings

| LT | Ruling |
| --- | --- |
| 01 | **Accepted.** Arm H at `fe8775c206d7209fc62cefe0b6a94beb8c0206ce` on E-CI: H-PROBE M = 100,000, H-FORCED M = 10,000. **H-SUITE out.** Prediction registered before running (§9.3). |
| 02 | **Accepted, Owner approved the cost.** `.github/workflows/flaky-157.yml` on this branch only, `workflow_dispatch` + `push` guarded to this branch, removed in its own commit before Ready, nothing on `main`. It runs **nothing** outside `investigations/flaky-157/` and the pinned `vendor/bin/phpunit`; no secrets; `permissions: contents: read` only. Whether `workflow_dispatch` is offered for a branch-only file is checked at build time; if not, the guarded `push` is the mechanism (recorded in §10). |
| 03 | **Accepted, Owner approved the cost.** L = prefix suite, **N = 3,300** (≈ 18 runner-hours, 20 jobs × 165 runs). Full-suite N = 500 **rejected**. Result cache disabled. Validity **V-ORD** (§9.2). |
| 04 | **Accepted.** The tap only records (plus D's deletion); no change under `src/` or `tests/`; MUTATE identified by SHA-1 of the script body read by reflection; per call: timestamp, key, arguments, reply; every command touching the target key between READ and MUTATE. A dropped or reordered frame makes the run invalid, counted and reported (**V-FRAME**, §9.2). Tap overhead (p50/p99 added latency) measured once before L. |
| 05 | **Accepted.** Fixed N, no early stop, every failure logged with its tap record, Wilson 95 % and the power statement. |
| 06 | **Accepted.** D with `--filter`, 20 runs per case on E-CI and on E-LOCAL; counted in no rate. |
| 07 | **Accepted.** D's detector uses `main@c8b8fd7` lines L1546 / L3749; the REPORT cites both heads' lines with their commits (`fe8775c`: L1540 / L3736). |

Process: stop after the harness commit with the detectors shown firing; report before any N run. The PR stays Draft.

Assignment conditions added with the build go (Owner/Lead message): local work in this separate worktree only, Redis on a port other than 6379, nothing run from the `main` checkout; if E-CI fails for any reason the measurement is **not** moved to E-LOCAL: stop and return to the Owner.

### 9.2 Validity definitions (registered)

* **V-ORD (LT-157-03).** A reference run of the **full** core suite (`--exclude-group archive`, no tap, empty result cache) is executed once on E-CI at `c8b8fd7`; its junit gives the reference ordinal `k_ref` of the target test (1-based index among all `<testcase>` elements in document order). The prefix file list is taken from the same junit: the distinct `file` attributes, in first-appearance order, up to and including `RedisFullCapabilityStoreIntegrationTest.php`. An L run is valid for L only if its junit shows the target test at ordinal `k_ref`. Invalid runs are counted and reported with their ordinal. (Descriptive, not a criterion: whether the whole testcase sequence up to the target equals the reference.)
* **V-FRAME (LT-157-04).** The tap parses every command and every reply on every connection. A run is invalid if (a) any connection ends with a command that has no reply, or a reply with no command; (b) the tap meets bytes it cannot parse as RESP; or (c) for any R1 key, the commands naming that key (in tap arrival order, all connections) are not a prefix of `HSET, EXPIRE, EVAL(READ), EVAL(MUTATE), EVAL(CLAIM), EVAL(PUBLICATION)` that is complete for every case the test passed and ends at the failing call for the case it failed. A command from another connection, or a keyspace-wide command (`FLUSHDB`, `FLUSHALL`, `SWAPDB`, `SELECT`, `DEBUG`, `CONFIG`, `SHUTDOWN`, `SCRIPT`), between that key's READ and MUTATE is **not** an invalidity: it is a recorded finding.
* **V-HEAD**, **V-CACHE**, **V-CONFIG** as §5.3. V-CACHE is implemented with `--do-not-cache-result` plus an empty `--cache-directory` per invocation.
* **V-PORT (E-LOCAL).** Every local Redis container's published port is recorded and must not be 6379.

### 9.3 H prediction (LT-157-01, registered before any H run)

Per H sequence the probe records `t0` = the `TIME` reply exactly as `redisNow()` reads it, with its microseconds `u0`; `span` = hrtime from receiving that reply to just before sending the `'-1'` case's `EXPIRE`, in µs; and, **after** the whole sequence (so the timed part is the test's sequence unchanged), `TIME` and `PTTL` of the `'-1'` key, giving the key's absolute expiry `X` in Redis milliseconds (±1 ms) and `D = X − (t0s + 600) × 1000`. If that key's hash differs from what the probe wrote, `D` is marked unavailable for that sequence.

If §1.3 holds:

* **H-1 (necessary, the Lead's wording).** The `'-1'` MUTATE returns without an exception **only if** `u0 + span < 1000 µs`. Any non-throwing `'-1'` MUTATE with `u0 + span ≥ 1000 µs` is a **finding against §1.3**.
* **H-2 (Redis side).** `D ≤ −3` ⇒ the `'-1'` MUTATE returns `{0}`; `D ≥ 3` ⇒ it throws `inconsistent generated score expiry`. `|D| ≤ 2` is the rounding band (the script's `TIME` vs its `PTTL` time base, and the ±1 ms of the after-the-fact estimate) and is reported, not judged. Any sequence outside the band that contradicts H-2 is a **finding against §1.3**.
* **H-3.** For `'0'` and `'not-a-number'` every MUTATE throws, and READ, CLAIM and PUBLICATION throw for all three cases. Any non-throwing call there is a finding.
* Why H-2 is added: `span` is measured by the client, so the Redis-side interval is longer by two one-way latencies. H-1 can therefore only be a necessary condition; the Lead's "exactly when" needs the Redis-side quantity, which `D` provides without adding a command inside the timed part.

H's runner-hour estimate is recorded in §10 from the local detector run, before any H run on E-CI.
