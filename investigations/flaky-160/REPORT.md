# #160 — phase 1 REPORT: untapped rate of the six methods (U), the S0 / `EvaluationPipeline` mechanism (M), a preliminary decision

> **Status:** evidence only. No fix, no change to `src/`, `tests/`, `composer.json`, `phpunit.xml` or `ci.yml`. Registered conditions: `PLAN.md` §4, §5, §12 (rulings), §13 (harness-time notes, BC-1…BC-5, CI history). Nothing below changes a registered condition after it ran.
> **Heads.** Every counted run is at **`b14b30d`** (harness incl. BC-5; `src/`, `tests/`, `composer.json`, `phpunit.xml` byte-identical to `main@8b79e9b`, V-HEAD checked per run).
> **Runs (E-CI):** M [39947130034](https://github.com/Maatify/php-rate-limiter/actions/runs/39947130034); U [39948046268](https://github.com/Maatify/php-rate-limiter/actions/runs/39948046268). Order as ruled: BC-5 commit → normal CI 16 / 16 (after the one authorised re-run, §4.2) → M → U. Fixed N, no early stop, no re-dispatch, every job succeeded; no push to the branch and no other workflow on the repository while U ran (the branch head stayed `b14b30d` from M's dispatch to U's last shard).

## 1. Validity and resolved set

* **V-REF:** U's own reference (full core suite, no tap, `OK (1855 tests)`, PHPUnit 7:13.1) gave 75 prefix files equal to #157's, `k_ref` 1329, `sequence_sha256` `fccd09eb…`; ordinals of the six: T-ALLOW 1268, T-S0B 1271, T-S0A 1272, T-CLAIM 1311, T-K4 1340, T-HAND 1341.
* **U:** **3,300 / 3,300 valid** (V-ORD, V-PORT, V-HEAD; no problem line), **shortfall 0, every shard 165 / 165**, no duplicated or out-of-range id. V-CONFIG on every container: `redis_version` 7.0.15, `maxmemory` 0, `maxmemory-policy` noeviction (`config.jsonl` has 3,320 lines because each shard artifact repeats the reference container's line); no published port 6379.
* **C-1:** `envset.php` over all 21 U jobs: one resolved set, lockset `4fc648b3…` (the same as #157's L) with PHPUnit 11.5.57, PHP 8.5.12; `differs_from_reference: []`. M's three jobs: the same set.
* **Cost as run:** U 24.7 runner-hours (21 jobs; shards 57.3–85.6 min; wall 93 min) against the projected 29.6; M 11 runner-minutes.

## 2. Arm U — untapped prefix suite (registered question)

Prefix suite (75 files, 1,394 tests) in the reference order, **no tap, no proxy**, one fresh Redis container per run, empty result cache, N = 3,300 (20 × 165), E-CI.

| Measure | Value |
| --- | --- |
| Valid runs | 3,300 |
| Runs with a failure in one of the six (pooled, registered) | **7** |
| **Pooled rate, Wilson 95 %** | **0.212 %, [0.103 %, 0.437 %]** |
| #157 L (tapped), Wilson 95 % | 11 / 3,300 = 0.333 %, [0.1862 %, 0.5959 %] |
| **Registered rule: inside iff k ∈ {7, …, 19}** | **k = 7 → inside** (the lowest value that is inside) |
| Registered error rates of the rule | P(outside \| unchanged) = 8.7 %; P(inside \| half the tapped rate) = 31.4 % |
| Two-sample Fisher exact, U vs L (descriptive) | p = 0.48 |
| Runs with any failure (six + seventh) | 7 (no seventh-method failure; the #157 target never failed) |
| Run wall, container to container | p50 28.9 s (min 19.8, max 52.0) |

**Reading.** By the registered rule the untapped pooled rate lies inside L's interval, so the observed tapped rate is **not shown to be a tap effect for the six as a group**. The rule is weak at its edge: k = 7 is the first inside value, and with a true rate of half the tapped one the rule would still say "inside" 31 % of the time; the Fisher comparison (p = 0.48) does not separate U from L either. The pooled number therefore supports "the six fail on `main` without the tap, at a rate of the same order", not "unchanged".

**Per method** (descriptive; the counts are small):

| Method | U (no tap) | Wilson 95 % | L (tap) | Fisher U vs L |
| --- | --- | --- | --- | --- |
| T-K4 (L1847) | 0 | [0, 0.116 %] | 4 | 0.125 |
| T-CLAIM (L1097) | **3** | [0.031 %, 0.267 %] | 2 | 1.000 |
| T-S0A + T-S0B (`invalid snapshot`) | 0 | [0, 0.116 %] | 2 | 0.500 |
| T-ALLOW (L136) | **4** | [0.047 %, 0.311 %] | 2 | 0.687 |
| T-HAND (L1956) | 0 | [0, 0.116 %] | 1 | 1.000 |

Post hoc, **not registered and not a test**: the rows whose §1.4 reading depends on latency inside one call or between two reads (T-K4, T-HAND, T-S0A/B) went from 7 / 3,300 tapped to 0 / 3,300 untapped (Fisher p = 0.016); T-CLAIM and T-ALLOW went from 4 to 7 (p = 0.55). The grouping was chosen after seeing the data, so it only orders phase 2.

**The seven failing runs** (each kept with `phpunit.out` and `junit.xml`):

| Run | Method | Assertion |
| --- | --- | --- |
| 335, 1616, 2068 | T-CLAIM | L1097 "Failed asserting that true is false." |
| 455, 551, 2729 | T-ALLOW | L136 "Failed asserting that 30 is identical to 28." |
| 2799 | T-ALLOW | L136 "Failed asserting that 32 is identical to 30." |

## 3. Arm M — mechanism (all registered predictions hold)

### 3.1 Deterministic sub-arms (20 trials each; a trial counts only if the TTL-600 device-window snapshot ran)

| Sub-arm | Registered prediction | Result |
| --- | --- | --- |
| **M-DET** (S0 clock `@T`, store fallback `@T+1`) | `invalid snapshot` thrown at `BoundedCorrelationResultValidator.php:62` with `S0EvaluationPipeline.php:800`, `:608`, `:278` in the trace, 20 / 20 | **20 / 20**, valid 20 |
| M-DET control (both `@T`) | 0 / 20 | **0 / 20** (`process()` returned) |
| **M-EP-DET** (`newWorker()` construction, step clock T → T + 1, reads at L867 and L881) | 20 / 20 at validator L62 with `EvaluationPipeline.php:884` | **20 / 20** |
| M-EP-DET control | 0 / 20 | **0 / 20** |
| **M-EP-BUILDER-DET through `limit()`** | 0 / 20 | **0 / 20**; one clock read per call (`RequestScopedClock.php:40`); every snapshot's caller time was the held T in 20 / 20 |
| **M-EP-BUILDER-DET outside `holding()`** | 20 / 20 | **20 / 20**; the pipeline was found in 20 / 20 and its clock is a `RequestScopedClock` in 20 / 20 |

So the S0 fixture's double read and the **latent double read of `EvaluationPipeline::addBoundedSnapshot()`** (L867 then L881/L911) both produce `invalid snapshot` when the two reads fall in different seconds, and **the builder's `RequestScopedClock` + `limit()`'s `holding()` guard it**: through the documented construction the call reads the clock once.

### 3.2 Natural rate (K = 100,000 calls each; recorders, not injection)

The recorders return every reading unchanged (Lead ruling LT-160-06) and add one bounded backtrace per read (≈ 1–2 µs). **The natural rate is per set-creating call**: only a call that creates the device window computes `expiresAt = now + ttl`; a call that joins an existing window returns the stored expiry and cannot straddle. Every call here used a fresh namespace, account and fingerprint, so every call created its window (BC-2).

| | M-NAT (S0 worker, the test's clocks) | M-EP-NAT (`newWorker()`, `RedisTimeClock`) |
| --- | --- | --- |
| Valid calls (one complete pair each) | 100,000 | 100,000 |
| Gap `g` between the two reads (µs): min / p01 / p50 / p99 / max | 24 / 29 / 35 / 52 / 1,605 | 46 / 73 / 89 / 131 / 1,988 |
| Mean `g` | 35.6 µs | 91.5 µs |
| Straddles = `invalid snapshot` | **3** | **8** |
| Rate per set-creating call, Wilson 95 % | 0.0030 %, [0.0010 %, 0.0088 %] | 0.0080 %, [0.0041 %, 0.0158 %] |
| **N-1** (exception ⇔ straddle), discordant calls | **0** (holds) | **0** (holds) |
| **N-2** expected `Σ g / 10⁶`, Poisson 95 % band | 3.56, [0, 8] → **holds** | 9.15, [4, 16] → **holds** |
| Phase of the first reading (100 ms buckets) | all 3 in the last 100 ms of the second | all 8 in the last 100 ms |

`g` differs between runners: the detector job measured a mean of 80 µs for NAT at K = 2,000 (`logs/detectors/E-CI/`), this job 35.6 µs; N-2 judges each job against its own `g`.

## 4. Separate observation: T-ALLOW failed untapped on the normal CI (outside U's rate)

`Test Suite (PHP 8.5)` on `b14b30d` ([job 120347593175](https://github.com/Maatify/php-rate-limiter/actions/runs/39933593795/job/120347593175)), the **full** core suite, no tap: T-ALLOW at **L136**, "Failed asserting that 28 is identical to 26." The one authorised re-run of that job (attempt 2) passed and the CI read 16 / 16 (PLAN §13.5). This was the first untapped observation of any of the six; U then added four more for T-ALLOW.

**Against §6's prediction for T-ALLOW (an interleaving class, not time), descriptively.** Every T-ALLOW failure so far misses by exactly one unit of `consumed − conflicts` (2 points of `k5`): L "30 vs 28" ×2; CI "28 vs 26"; U "30 vs 28" ×3 and "32 vs 30" ×1. The absolute values move with the run (26, 28, 30 recorded K5 points), the shortfall does not: one command whose allowance unit is counted without its K5 `+2`, as §1.4 reads it. Every worker uses `FixedClock` at its wave's second, so no second boundary enters by reading. Nothing here tests the class; §6 (b) names the branch. **This observation and U's 4 / 3,300 weaken the "tap effect" reading for T-ALLOW**: it fails on `main` without the tap, in the prefix and in the full suite.

## 5. Preliminary decision

1. **T-S0A / T-S0B: the S0 fixture fix is necessary but, by M, not sufficient.**
   * Passing the fixture's single `$now` into the store (one reading in `S0EvaluationPipeline::addBoundedSnapshot()`) removes the path that M-DET reproduced 20 / 20 and that M-NAT measures at 3 per 100,000 set-creating calls.
   * The same two tests also drive `newWorker()`, an `EvaluationPipeline` built **directly** with `RedisTimeClock`, outside the builder's guard. That path reads the clock twice too (M-EP-DET 20 / 20; M-EP-NAT 8 per 100,000 set-creating calls at a mean gap of 91.5 µs). By reading, T-S0A has a set-creating `newWorker()` call per run (the `new` fleet's first check), so the S0 fix alone leaves a live path to `invalid snapshot` in T-S0A.
   * Removing that path needs one of: (a) `EvaluationPipeline::addBoundedSnapshot()` reading the clock once (a `src/` change; it also covers any Host that bypasses the builder), or (b) the tests building their new worker through a `RequestScopedClock` and `holding()`. Routing is the Owner's.
   * U observed 0 / 3,300 for these two methods. That is consistent with the M rates: about 3 set-creating S0 calls and 1 set-creating new-worker call per run, both counts by reading and not measured, give about 0.56 expected failures in 3,300 runs, so P(0) ≈ 0.57.
2. **T-K4 and T-HAND: not seen without the tap** (0 / 3,300 against 4 and 1 tapped). That fits §1.4's latency reading but proves nothing. §6 (b)/(c) (the `PEXPIRETIME` probe and the forced `X ≡ 1` / `X ≡ 500` groups) is what can decide it; the prediction stays as registered.
3. **T-CLAIM: fails without the tap** (3 / 3,300, all at L1097, none at L1095). That fits §1.4's reading that a 1-second block ends at the next second boundary between the block and the claim. Phase 2 needs §6 (b), the deterministic store-clock step for 20 + 20 trials, before any change to the test.
4. **T-ALLOW: fails without the tap** (4 / 3,300 in U, plus the CI failure of §4), always one unit short. Phase 2 needs §6 (b): `--filter` runs recorded by the #157 tap, enough to catch failing runs and name the branch of `EvaluationPipeline` that takes a unit without a K5 update or without throwing `RateLimitConcurrencyException`.

## 6. Not proven

* Which of the two §1.4 classes causes T-ALLOW, and in which branch.
* The mechanisms of T-K4, T-HAND and T-CLAIM; §6 has not run.
* The per-run counts of set-creating calls in T-S0A / T-S0B (by reading only), and so the expected per-run rate after an S0-only fix.
* Whether the untapped pooled rate equals the tapped one. "Inside" at k = 7 does not establish it (§2).

## 7. Files

* `logs/m/E-CI/`: rows of every sub-arm (NAT and EP-NAT gzipped), `m-report.json`, `envset.json`, `env/`, `SOURCE.txt`.
* `logs/u/E-CI/`: `verdicts.jsonl` (3,300 lines, sorted by id), `summary.json` (`--expect=u:1-3300:165`), `envset.json`, `config.jsonl`, `kref.json`, `vref.json`, `prefix.xml`, `reference.phpunit.out`, `env/`, `failures/u-<id>/` (`phpunit.out`, `junit.xml.gz`), `SOURCE.txt`.
* `logs/detectors/` (PLAN §13).

Each folder has `SHA256SUMS`.
