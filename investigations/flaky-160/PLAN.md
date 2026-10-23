# #160 — five failure rows (six test methods) outside #157: pre-registered untapped measurement and S0 fixture mechanism (phase 1)

> **Status:** PRE-REGISTRATION, plan **v1**. Committed **before** any harness code, any workflow, and any result. Evidence only: no fix, no change to `src/`, to any file under `tests/`, to `composer.json`, to `phpunit.xml`, to `ci.yml`, or to any earlier study (`investigations/flaky-157/` is read, never edited).
> **Head:** the branch is cut from `main@8b79e9b` (PR #159 merged). `git diff --stat c8b8fd7 8b79e9b -- src tests composer.json phpunit.xml` is empty, so every line cited below is the same on `c8b8fd7` (the head arm L of #157 ran) and on `8b79e9b`. V-HEAD checks this per run.
> **Assignment:** the Lead's #160 phase-1 assignment (2026-10-23). Phase 2 starts only after the REPORT and a ruling.
> **Rules kept:** plan before harness; every LT is ruled before the build; no registered condition changes after it has run; one commit per step; the PR stays Draft; my own checks are a self-review, never a Lead review.

## 0. Questions (from the assignment)

1. **U (main arm).** Without the tap, on E-CI, at N = 1,000 prefix runs, does the pooled failure rate of the tests in #160 fall inside or outside the Wilson 95 % interval of the tapped rate observed in #157 arm L, [0.19 %, 0.60 %]?
2. **M (mechanism, deterministic).** Does `S0EvaluationPipeline` with a clock at second T and a store fallback clock at T + 1 give `RateLimiterException: Bounded correlation store returned an invalid snapshot.` from `BoundedCorrelationResultValidator` L62 in 20 / 20, and 0 / 20 when both clocks agree? And, without injection, how often does one call straddle a second boundary between its two clock reads on E-CI (K = 20,000), against a registered prediction of about (gap between the reads) ÷ 1 s?
3. **The four other rows.** Read only (no run): for each, what phase 2 will measure and its prediction.
4. **REPORT.** The untapped pooled rate with Wilson 95 % and its position against [0.19 %, 0.60 %]; M's 20 / 20, 0 / 20 and the measured straddle rate; a preliminary decision on whether the S0 fixture fix is enough for its two tests and what the other four need.

## 1. What the code does (read, not measured)

### 1.1 Counting unit: five rows, six methods

The #159 REPORT §4 table and Issue #160 have **five rows**; the KnownDeviceMixed row holds **two methods**. The six methods, with the assertion each failed in #157 arm L (all `main@c8b8fd7` = `main@8b79e9b`):

| Id | Method | Failed at | L runs |
| --- | --- | --- | --- |
| T-K4 | `RedisFullCapabilityStoreIntegrationTest::testLifecycleMutationPreservesPhysicalAndAuthoritativeExpiryAcrossAllK4Boundaries` | L1847 `assertTrue($legacyHandoff->applied)` | 133, 425, 1904, 2809 |
| T-CLAIM | `RedisFullCapabilityStoreIntegrationTest::testGenerationBoundLifecycleIsAtomicAndClaimDoesNotConsumeEvidence` | L1097 `assertFalse(claimPostPunishmentReentry(…))` | 722, 1281 |
| T-S0A | `KnownDeviceMixedVersionIntegrationTest::testAlternatingWorkersOnTheTrustedBurst` | `invalid snapshot`, validator L62 ← fixture L800 ← L608 ← L278 ← test L160 ← L148 | 1500 |
| T-S0B | `KnownDeviceMixedVersionIntegrationTest::testS0WorkerHonorsANewK5BlockForThatDeviceOnly` | same frames, test L160 ← L128 | 1935 |
| T-ALLOW | `KnownDeviceAllowanceConcurrencyIntegrationTest::testLoginOverlapSplitsIntoRecordedConflictAndEarlyBlockExactly` | L136 `assertSame(2 * ($consumed - $conflicts), $k5)`, "30 is identical to 28" | 2086, 2758 |
| T-HAND | `RedisFullCapabilityStoreIntegrationTest::testConcurrentPreviousHandoffHasNoDoubleSeedAndGenerationMutationsHaveNoLostUpdates` | L1956 `assertSame(1, $handoffWinners)`, "0 is identical to 1" | 3233 |

"The fix for two of the five" (assignment) is therefore the fix for **T-S0A and T-S0B**; "the other four" are T-K4, T-CLAIM, T-ALLOW, T-HAND. LT-160-02.

### 1.2 T-S0A / T-S0B: the S0 fixture reads the time twice

* The test builds the S0 worker with `$this->clock = new RedisTimeClock($this->executor)` (`KnownDeviceMixedVersionIntegrationTest` L62, L175) and the store with **no clock** (L61), so the store's `fallbackClock` is `SystemClock(UTC)` (`RedisFullCapabilityStore` L1389). Two different clock objects; on E-CI both read the same kernel clock (Redis runs in a container on the runner).
* `RedisTimeClock::now()` (test file L234–L242) sends `TIME` and returns `'@' . $time[0]`: whole seconds only, the microseconds of the reply are dropped.
* `S0EvaluationPipeline::addBoundedSnapshot()` takes `$now` from that clock (L785), then, when `previousKey === null`, calls the legacy `addDistinctBoundedWithSnapshot()` without a time (L793). The store reads its own clock (`L1549` → `fallbackNow()` L2090) and passes that second to `BOUNDED_SNAPSHOT`.
* `BOUNDED_SNAPSHOT` (L845–L886): when the set does not exist yet, it stores and returns `expiresAt = now + ttl` with the **store's** second (stored L881, returned L885). When the set exists, it returns the stored `expiresAt` unchanged.
* `BoundedCorrelationResultValidator::snapshot()` rejects `expiresAt > $now + $ttlSeconds` (L59) with the fixture's `$now` and throws at **L62**.

So the exception needs: the call **creates** the set, and the store's second is later than the fixture's second. With one kernel clock that means a whole-second boundary between Redis executing `TIME` and the host reading its clock in `fallbackNow()`. Call that interval `g_S0` (the `TIME` reply's way back plus PHP between L785 and L1549). On a call that finds the set already present, the stored expiry was written by an earlier call at an earlier or equal second, so it cannot exceed `$now + ttl` (one kernel clock, no backward step).

The failure stack (`l-1500.phpunit.out`, `l-1935.phpunit.out`) goes through L278 (`process`, pre-check branch) → L608 (`checkDistributedAccountAttack`, the device window `addBoundedSnapshot($devices, 600, 4)`) → L800 (the `previousKey === null` return). By reading, the set-creating S0 pre-check calls per prefix run are few (the first S0 `checkOnly` per account: `m3-s0`, `m3-mixed` in T-S0A, `m2-owner` in T-S0B); this count is **not measured** and is not used for any criterion.

### 1.3 `EvaluationPipeline` reads the time twice as well (finding by reading; LT-160-01)

Issue #160 and the assignment say the real pipeline passes its single `now` through `CallerTimeStore` (L875, L902). By reading, it does not pass the **same** reading:

* `EvaluationPipeline::addBoundedSnapshot()` takes `$now = $this->clock->now()->getTimestamp()` at **L867**;
* the non-rotation branch calls `CallerTimeStore::addDistinctBoundedWithSnapshot(…, $this->clock->now())` (call L875, **second read L881**);
* the rotation branch calls `CallerTimeStore::addDistinctBoundedWithSnapshotAcrossRotation(…, $this->clock->now())` (call L902, **second read L911**);
* `CallerTimeStore` L120–L125 hands the second reading's `getTimestamp()` to `addDistinctBoundedWithSnapshotAt()`; the validator then judges with L867's `$now` (L884, L914).

So the structure that fails in the S0 fixture is present in the production pipeline: a whole-second boundary between L867 and L881 (or L911) on a set-creating call gives the same `invalid snapshot`. With `RedisTimeClock` (the new worker in `KnownDeviceMixedVersionIntegrationTest`, L170) the two reads are two `TIME` round trips; with a host `SystemClock` they are microseconds apart. Consequences for this phase:

* the fix the assignment names ("pass `$now` through `CallerTimeStore` as in `EvaluationPipeline` L875/L902") would copy a pattern that, by reading, has the same defect; the REPORT's preliminary decision cannot rest on it without a measurement of the production path;
* by reading, T-S0A (its `new` and `mixed` fleets) and the other new-worker calls in that class also have set-creating `EvaluationPipeline` calls, so fixing only the S0 fixture would not, by reading, remove every path to `invalid snapshot` in those two tests.

This is a **reading**; it is not confirmed until measured. LT-160-01 proposes the measurement (arm M-EP, §5.3).

### 1.4 The other four: evidence available and reading

**Evidence on hand.** `investigations/flaky-157/logs/l/E-CI/other-failures/` holds, per failing run, `l-<run>.phpunit.out` and `l-<run>.tap.jsonl`; **no junit file was kept** for those runs. Each tap log has 184 `cmd` records: by its configuration (`keys` = the three #157 R1 keys) the tap recorded the keyspace-wide commands (`FLUSHDB` from `setUp()`) and the R1 keys' commands only, **none** of the failing tests' own commands. The phase-1 reading of the four therefore rests on the `phpunit.out` (message, line, PHPUnit `Time:`) and the code. PHPUnit times of the eleven runs: 18.7–32.4 s.

**T-K4 (L1847) and T-HAND (L1956): the legacy remaining-life mapping, recomputed per call.** Both hand off from a **legacy** previous score (no `generation`, no `expiresAt`): T-K4 writes it with `PEXPIRE 1200` (test L1841), T-HAND with `EXPIRE 600` (L1932). For such a score, READ (`LIFECYCLE_READ` L435) and MUTATE (`LIFECYCLE_MUTATE` L356) each map the remaining life onto the caller's timeline: `expiry = ⌊(nowMs + PTTL + 999) / 1000⌋`, where `nowMs` is the store's fallback reading in ms (`fallbackNowMilliseconds()`, L1785, L1840) and `PTTL` is read inside the script. MUTATE returns `{0}` (not applied) unless its own mapping equals READ's (`effectiveObservedExpiry ~= ARGV[5]`, L358). With one kernel clock, `nowMs + PTTL = X − n`, where `X` is the key's absolute expiry in ms and `n ≥ 0` is the number of millisecond boundaries between the host's reading and the script's `PTTL`. The two calls' mappings differ exactly when a whole second `k·1000` satisfies `X − max(n_R, n_M) ≤ k·1000 < X − min(n_R, n_M)`, i.e. when `(X − 1) mod 1000` falls in a window of width `|n_R − n_M|`. Then MUTATE returns `{0}`: T-K4 fails at L1847; in T-HAND every worker's MUTATE returns `{0}` and the winner count is 0 (the observed "0 is identical to 1", not 2). By this reading the per-pair probability is `E|n_R − n_M| / 1000` for a uniformly placed `X`; with sub-millisecond latency `n ∈ {0, 1}` and the probability is at most 0.1 %. Not explained by this reading: T-K4's tapped 4 / 3,300 (0.12 %, Wilson [0.047 %, 0.31 %]) sits at the edge of that bound, and `testLegacyHandoffAndSameValueMutationAdvanceGenerationExactlyOnce` (L1797, two legacy READ/MUTATE pairs) had 0 / 3,300. A non-uniform in-suite phase of `X` (#159 found the in-suite `t0` phase structured) or `n ≥ 2` under the tap could account for it; phase 2 measures it.

**T-CLAIM (L1097): a one-second block ends at the next second boundary.** The test blocks with `durationSeconds = 1` (L1077–L1088); `HARD_BLOCK` stores `expiresAt = now + 1` with the block call's fallback second (L1287). `LIFECYCLE_CLAIM` treats the key as blocked only while `expiresAt > nowSeconds` (L529), with the claim's own fallback reading. So the "during block" claim at L1097 returns `true` whenever a whole-second boundary falls between the block call's clock reading and the claim's. The READ at L1092 lies in between; a boundary before that READ would surface at L1095 instead (`postPunishmentReentry` no longer null). Per-run probability ≈ (claim reading − block reading) ÷ 1 s.

**T-ALLOW (L136): not diagnosed.** Every worker's pipeline uses `FixedClock('@' . $now)` with `$now` taken once per wave in the parent (L172, L241), and `DecayCalculator`'s smallest interval is 300 s (device), so neither decay nor a second boundary enters by reading. `k5 = 28` against `2 × (consumed − conflicts) = 30` means one unit of `consumed − conflicts` has no applied K5 `+2`: either one command took an allowance unit and ended without a K5 update and without `RateLimitConcurrencyException`, or one command took two units. Both are interleaving classes; which one, and through which branch of `EvaluationPipeline`, is not read yet.

## 2. Pre-plan disclosure

Run for this plan (no harness, nothing on any runner, no Redis):

* `git diff --stat c8b8fd7 8b79e9b -- src tests composer.json phpunit.xml` (empty) and `git show --stat 1e1283b` (it deletes `.github/workflows/flaky-157.yml`, 234 lines);
* reading of the eleven `phpunit.out` and the record kinds of the eleven `tap.jsonl` (§1.4), `kref.json` (`k_ref` 1329, 75 prefix files, `sequence_sha256` `fccd09eb…`) and `prefix.xml` (the three classes are entries 72, 73 and 75 of its 75 `<file>` entries, i.e. inside the prefix);
* `gh issue view 160`;
* binomial and Wilson arithmetic (Python, §4.4).

## 3. Environments

| Id | Where | Role |
| --- | --- | --- |
| **E-CI** | GitHub Actions `ubuntu-latest`; `actions/checkout` and `shivammathur/setup-php` at the `ci.yml` pins, PHP 8.5, `extensions: redis`; `composer update` as `ci.yml`; `redis:7.0.15-alpine` started from `docker/redis-integration/compose.yaml` read as data (as #157). Redis and PHP share the runner's kernel clock | every counted number in U and M |
| **E-LOCAL** | a separate scratch worktree of this branch on the Mac, Redis on a port other than 6379 | detectors and smoke only; **no counted number**; if E-CI fails for any reason the work stops and returns to the Owner, it does not move to E-LOCAL. Docker Desktop runs Redis in a VM with its own clock, so M-NAT is not meaningful there |

E-CI needs the temporary workflow (LT-160-07): `git revert 1e1283b` on this branch as its own commit, then a separate commit that adapts it (modes `ref`, `detectors`, `u`, `m`), `permissions: contents: read`, no secrets, `harness/env.sh` in every job; deleted in its own commit before Ready.

## 4. Arm U — untapped prefix suite on E-CI

### 4.1 What runs

* The prefix suite of #157 arm L: the same **75 files in the same order** (up to and including `RedisFullCapabilityStoreIntegrationTest.php`, 1,394 tests), generated by the harness from a fresh reference run (§4.3), `--exclude-group archive`, through `vendor/bin/phpunit` only.
* **No tap, no proxy**: PHPUnit connects to the Redis container's published port directly.
* One fresh Redis container per run; `--do-not-cache-result` and an empty `--cache-directory` per run.
* **N = 1,000** (20 shards × 50 runs), fixed in advance, no early stop (LT-160-03 asks whether to raise it).

### 4.2 What each run keeps

* For **every** run: one verdict line (run id, shard, V-checks, PHPUnit `Time:`, counts, and for each of the six methods of §1.1 its status and time from junit), the container's V-CONFIG line.
* For **every run with any failure or error**: the full `junit.xml` and `phpunit.out`.

### 4.3 Validity (registered)

* **V-HEAD:** before each run, `src/`, `tests/`, `composer.json`, `phpunit.xml` byte-identical to `8b79e9b` (and therefore to `c8b8fd7`) and a clean tree.
* **V-REF (once per dispatch, before any U run):** one full core-suite run on E-CI (no tap, empty cache) at this branch. Its junit must give `k_ref = 1329` for the #157 target and `sequence_sha256 = fccd09eb496600e55e6c642c9c2693d1d6c9f5c8776dfd7f3d8024a4df39f3b3` over the first 1,329 cases, and the generated prefix file list (repository-relative paths) must equal the 75 files of #157's `prefix.xml`. If any of the three differs, **U does not start**; the difference is reported.
* **V-ORD (per run):** the run's junit testcase sequence (`class::name`, document order) has the reference's ordinal for each of the six methods and for the #157 target, and its `sha256` over the first 1,329 cases equals `fccd09eb…`. Otherwise the run is invalid, counted and reported.
* **V-PORT:** every container's published port is recorded and never 6379.
* **V-CONFIG:** per container `redis_version`, `maxmemory`, `maxmemory-policy`, recorded.
* **C-1 / C-2 (as #157 §10.7):** every job saves its `composer.lock`, PHP and PHPUnit versions; `envset.php` reports whether all shards and the reference ran one resolved set (and whether it is #157's lockset `4fc648b3…`, descriptively); `summarize.php --expect=u:1-1000:50` gives the shortfall per shard and the missing ids.

### 4.4 Measures and the registered question

* **Pooled rate (registered):** valid runs with at least one failure or error in **any of the six methods** of §1.1 ÷ valid runs, with Wilson 95 %. This is the definition #157 arm L's 11 / 3,300 used (each of those runs had exactly one failure, in one of the six).
* **Registered question:** is the pooled point rate inside **[0.1862 %, 0.5959 %]** (Wilson 95 % of 11 / 3,300, computed, two decimals in the assignment: [0.19 %, 0.60 %])? At N = 1,000 that means **inside iff k ∈ {2, 3, 4, 5}**; k ≤ 1 is below, k ≥ 6 is above (6 / 1,000 = 0.600 % > 0.5959 %).
* **Power (registered, as assigned):** at 0.333 % per run, P(k ≥ 1) = 96.5 %.
* **Operating characteristics of the rule (computed, disclosed, not a criterion):** if the untapped rate equals the tapped one (0.333 %), P(inside) = 72.5 %, P(below) = 15.4 %, P(above) = 12.1 %; if it is half (0.167 %), P(inside) = 48.9 %; a quarter (0.083 %), 20.3 %; double (0.667 %), 33.5 %. So "inside" does not establish "unchanged" and "outside" does not establish "tap effect"; the REPORT states which reading the result supports and with what error. LT-160-03.
* **Per method:** counts with Wilson 95 %, descriptive only (the counts are small and do not separate the methods).
* **Not in the pooled rate, reported individually as findings:** a failure in any **seventh** method (including the #157 target), never dropped; the REPORT gives a second, all-failures rate as well.
* Every failure: method, assertion line, message, run id, shard, and its saved junit and `phpunit.out`.

## 5. Arm M — the S0 mechanism

### 5.1 Common set-up

A harness-side PHP script (not a PHPUnit test; nothing under `tests/` changes) that loads `vendor/autoload.php` and builds the S0 worker exactly as `KnownDeviceMixedVersionIntegrationTest::s0Worker()` (L173–L176): `S0EvaluationPipeline($store, $store, new BudgetTracker($store, $clock), new AntiEquilibriumGate($store), new DecayCalculator($clock), new EphemeralBucket($store), 'test_secret', 'prod', $clock, null)`, and calls `process(new S0LoginProtectionPolicy(), new RateLimitContextDTO('198.51.100.80', 'Chrome/123', <account>, ['device' => 'stable']), RateLimitCommand::checkOnly('login_protection'), new DeviceIdentityDTO(<fp>, 'HIGH', true, false, 'chrome/123', true))`, the call of test L160 that failed. Each trial uses its own store namespace and a fresh account, so its device-window set does not exist yet (§1.2: only a set-creating call can fail). The store's clock is the existing third constructor parameter of `RedisFullCapabilityStore` (L1381–L1384): **the fallback clock is injectable**, no LT needed for that.

### 5.2 M-DET (deterministic, assigned)

* **Injected:** the S0 clock (pipeline, `BudgetTracker`, `DecayCalculator`) = `FixedClock('@T')`; the store = `new RedisFullCapabilityStore($executor, $ns, new FixedClock('@' . (T + 1)))`. `T` = the Redis `TIME` second read once at the start of the trial. `FixedClock` is `tests/Support/Clock/FixedClock.php`, used unchanged.
* **Control:** both clocks `FixedClock('@T')`.
* **20 trials each**, E-CI (and E-LOCAL as a detector only).
* **Prediction (registered):** injected: **20 / 20** throw `Maatify\RateLimiter\Exception\RateLimiterException` with the message exactly `Bounded correlation store returned an invalid snapshot.`, thrown at `src/Service/BoundedCorrelationResultValidator.php:62`, with `tests/Fixtures/S0Baseline/S0EvaluationPipeline.php:800`, `:608` and `:278` in the trace. Control: **0 / 20** throw; `process()` returns a `RateLimitResultDTO`. Any other exception, or the exception at another frame, is a **finding against §1.2** and is recorded with its trace.

### 5.3 M-EP (production pipeline; proposed, LT-160-01)

* **M-EP-DET:** an `EvaluationPipeline` built as `newWorker()` (L168–L171), with a harness clock that returns T for every read until `addBoundedSnapshot` has read `$now` and T + 1 from the next read on. Because counting reads by position inside `process()` is fragile, this sub-arm calls the private `addBoundedSnapshot()` directly by reflection, with a fresh `BoundedCorrelationObservationDTO(current, member, null, null, bridge)`, `ttl 600`, `max 4`: the clock's first read is L867, its second L881. Control: the clock returns T for both. 20 + 20. **Prediction:** 20 / 20 `invalid snapshot` from validator L62 with `src/Service/EvaluationPipeline.php:884` (the validator call of the non-rotation branch) in the trace; control 0 / 20.
* **M-EP-NAT:** as M-NAT below, with the new worker and `RedisTimeClock` (the test's configuration), so both reads are `TIME` round trips. Reported with the same rule and band.
* Without M-EP the REPORT can say what the S0 fix does for the S0 path only, not whether T-S0A / T-S0B lose every path to `invalid snapshot`.

### 5.4 M-NAT (no injection, assigned)

* **Clocks as in the test:** the S0 clock is a harness copy of the test's `RedisTimeClock` (same command, same returned value `'@' . seconds`) that also records the reply's microseconds and the hrtime of the reply; the store gets a pass-through wrapper around `SystemClock(UTC)` that returns the real reading unchanged and records it with microseconds. Neither changes a value the pipeline sees. Whether such recorders count as "without injection" is LT-160-06; without them only the exception count can be measured, not the gap.
* **K = 20,000** set-creating calls (§5.1), fixed, on E-CI, one fresh container for the arm, no tap.
* Per call recorded: `TIME` reply (s, µs), the store's reading (s, µs), `g = store reading − TIME reply` (µs; one kernel clock on E-CI), whether the second differed, the outcome (exception class, message, top frame, or the decision).
* **Predictions (registered):**
  * **N-1 (per call):** the call throws `invalid snapshot` **iff** the store's second > the `TIME` second. Every discordant call is a **finding against §1.2**.
  * **N-2 (rate):** the number of straddles is within the Poisson 95 % band around `Σ g_i / 10⁶` (the sum of the measured gaps in seconds); the straddle rate per call ≈ mean `g` ÷ 1 s.
  * The rate is reported with Wilson 95 %, with the `g` distribution (min / p50 / p99 / max) and by `TIME` µs phase bucket.
* **Power (disclosed):** at an assumed `g` of 50–250 µs, K = 20,000 gives an expected 1–5 straddles; N-2 then tests the order of magnitude only. N-1 is checked on every call whatever the count. LT-160-06 asks whether to raise K.

## 6. The four other rows: what phase 2 measures, and the predictions (registered now, **not run** in phase 1)

| Row | Phase 2 measures | Prediction |
| --- | --- | --- |
| **T-K4** | (a) the untapped rate from U (descriptive); (b) a probe, no tap, that runs the test's sequence (HSET, `PEXPIRE 1200`, READ, MUTATE) through the same store class and records, **after** the timed part, the key's `PEXPIRETIME` (`X`) and READ's returned `expiresAt`; same probe for the two legacy pairs of L1797 as comparators; (c) a forced variant that sets `PEXPIREAT` so that `X ≡ 1` or `X ≡ 500 (mod 1000)` | Every not-applied MUTATE has `(X − 1) mod 1000 < 10`; with `X ≡ 500`, 0 not-applied; with `X ≡ 1`, a positive not-applied rate equal to the observed share of `n_R ≠ n_M`; natural rate per pair ≈ `E|n_R − n_M| / 1000`, the same for T-K4's pair and the L1797 pairs. A not-applied MUTATE outside the window is a finding against §1.4 |
| **T-HAND** | as T-K4 (b), with the parent's READ and six forked workers' MUTATE (`EXPIRE 600`) | When READ's mapped expiry differs from the workers' mappings, all six return `{0}` (winners 0), never 2; otherwise exactly one winner |
| **T-CLAIM** | (a) U's rate; (b) a deterministic run with a store whose fallback clock returns T for MUTATE, block and READ and T + 1 for the claim, 20 + 20 with a control at T; (c) a natural probe recording each fallback reading | (b) injected: the L1097 claim returns `true` 20 / 20; control 0 / 20. (c) the claim returns `true` **iff** the claim's second > the block's second; failures split between L1095 and L1097 in proportion to the two intervals |
| **T-ALLOW** | (a) U's rate; (b) `--filter` runs of the test through the #157 tap in record mode (every command and reply per connection; each worker has its own connections), enough runs to catch a few events at the tapped rate | In every failing run exactly one worker's allowance-budget increments differ from its applied K5 `+2` mutations by one unit, and that worker's outcome is not `conflict`; failing runs show no dependence on the wave's second phase (all semantic time is `FixedClock`). The branch of `EvaluationPipeline` is named from the record, not predicted |

## 7. Detectors (shown firing before any counted run)

* V-HEAD against a different head → refused.
* V-REF: a reference junit with one prefix file dropped → U refused.
* V-ORD: a run's junit with the order changed → run invalid.
* Classifier self-test: synthetic junit files with (i) a failure in one of the six, (ii) a failure in a seventh method, (iii) an error, (iv) none → classified as pooled / finding / pooled / clean.
* Failure capture: a run that fails keeps `junit.xml` and `phpunit.out` (shown with the self-test's junit).
* M-DET and its control at small size on E-LOCAL and E-CI; M-NAT at small K (no rate drawn).
* One untapped prefix run on E-CI end to end, which is also the cost measurement (§9).

## 8. Outputs

`investigations/flaky-160/`: `PLAN.md` (this file), `REPORT.md`; `harness/` (runner, prefix generator, run checker, summarizer, `env.sh`/`envset.php`, the M script with its clocks); `logs/` per arm (`ref/`, `detectors/`, `u/`, `m/`) with verdicts, summaries, failure junit and `phpunit.out`, `SHA256SUMS` per folder. Harness files taken from `investigations/flaky-157/harness/` are **copied** with a header naming the source path and its blob at `8b79e9b`; the #157 files are not edited (LT-160-08).

## 9. Cost

**Estimate before measurement:** #157 arm L ran 3,300 tapped prefix runs in 27.2 runner-hours (≈ 29.7 s per run including the container and job set-up), and §10.5 there measured the tap's hop at ≈ 0.09 ms per command over ≈ 48,000 commands per run. An untapped run should be shorter; the assignment's ≈ 7 runner-hours for N = 1,000 is consistent with that. M: seconds to minutes. **Measured:** one untapped prefix run on E-CI during the detector stage; the REPORT and the PR state the measured per-run time and the projected runner-hours for U; **U is not dispatched until the Owner accepts the measured number on the PR.**

## 10. Questions for the Lead before the harness (LT)

* **LT-160-01 — premise of the fix; arm M-EP.** By reading (§1.3) `EvaluationPipeline::addBoundedSnapshot()` reads the clock at L867 and again at L881 / L911, so it has the same double reading the S0 fixture has; Issue #160's sentence "the real `EvaluationPipeline` passes its single `now`" does not hold by reading. **Recommendation:** add M-EP-DET (20 + 20) and M-EP-NAT (K as M-NAT) in this phase, no `src/` change, so the REPORT's preliminary decision covers the production path; if M-EP-DET confirms, the finding is the Owner's to route (Issue text, own issue, or #160 phase 2). Without M-EP, the REPORT's decision is limited to "the S0 path".
* **LT-160-02 — counting unit.** Five rows, six methods (§1.1). **Recommendation:** pooled rate over the six methods (the definition behind 11 / 3,300); per-method counts descriptive; a failure in any seventh method, the #157 target included, is a finding reported individually and in a second all-failures rate, never in the pooled comparison.
* **LT-160-03 — U's decision rule and N.** At N = 1,000 the rule is k ∈ {2..5} inside; if nothing changed, P(outside) = 27.5 %; if the untapped rate is half, P(inside) = 48.9 % (§4.4). **Options:** (a) N = 1,000 as assigned, the REPORT states these error rates; (b) N = 3,300 (20 × 165, the same as L): inside iff k ∈ {7..19}, P(outside | unchanged) = 8.7 %, P(inside | half) = 31.4 %, cost ≈ 3.3 × U's measured cost. Either way, add a two-sample exact comparison of U against L, descriptive only. **Recommendation:** (b) if the Owner accepts the measured cost; otherwise (a) with the disclosure.
* **LT-160-04 — the prefix and V-REF.** `prefix.xml` of #157 holds absolute runner paths; the harness regenerates the list from a fresh reference run and requires it to equal #157's 75 files, `k_ref` 1329 and `sequence_sha256` `fccd09eb…` (§4.3), or U does not start. Accept?
* **LT-160-05 — M-DET path.** `process()` with `checkOnly` and the exact objects of test L160 (§5.1), injected `FixedClock('@T')` / `FixedClock('@T+1')` through the existing constructor parameters, prediction with the exact frames L62 / L800 / L608 / L278 (§5.2). Accept?
* **LT-160-06 — M-NAT recorders and K.** The gap `g` can be measured only by recording both readings; the recorders return the real readings unchanged (§5.4). Do they count as "without injection"? **Recommendation:** yes, with per-call rule N-1 and band N-2; and **K = 100,000** instead of 20,000 (expected 5–25 straddles at `g` = 50–250 µs; cost minutes), unless the Lead keeps 20,000 with the power statement.
* **LT-160-07 — workflow.** `git revert 1e1283b` restores `.github/workflows/flaky-157.yml` verbatim in its own commit; the next commit renames it to `.github/workflows/flaky-160.yml` (so Actions runs are not mixed with #157's) and replaces its modes with `ref`, `detectors`, `u`, `m`, pointing only at `investigations/flaky-160/harness/` and the pinned `vendor/bin/phpunit`; same pins, `permissions: contents: read`, no secrets, `env.sh` in every job; dispatch by `gh workflow run --ref` (as #157 §10.1); deleted in its own commit before Ready. Accept the rename?
* **LT-160-08 — harness reuse.** Copy the needed #157 harness files (runner, prefix generator, run checker, summarizer, `env.sh`, `envset.php`, Redis helper) into `flaky-160/harness/` with provenance headers; no tap is copied for U or M (phase 2 may use it for T-ALLOW). Accept?

## 11. Commit order

1. This plan alone (no code) — Draft PR, LT questions posted.
2. The Lead's rulings recorded in a new §12 of this file (no other change).
3. `git revert 1e1283b` (own commit), then the workflow adaptation and the harness, with the detectors shown firing and the measured cost of one untapped run (§9). Stop and report.
4. After the Owner's word on the cost: U (and M), outputs with `SHA256SUMS`.
5. `REPORT.md`.
6. Deletion of the temporary workflow (own commit), before Ready.
