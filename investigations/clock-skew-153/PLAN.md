# #153 — bounded correlation snapshot under caller clock skew: pre-registered measurement plan (phase 1)

> **Status:** PRE-REGISTRATION, plan **v1**. Committed **before** any harness code and any result. Evidence only: no fix, no fix recommendation, no decision record, no change to `src/`, to any existing file under `tests/`, to `composer.json`, presets, public contracts, decision records, `DECISIONS_INDEX.md` or any earlier study.
> **Baseline:** the branch is cut from `main@1e951ee` (PR #150, stage 5-c, admission layer active). The runner checks that `src/`, `tests/` and `composer.json` are byte-identical to `1e951ee3c3b29ecc49ce08ceff809869bccf139d` and clean before every run.
> **Assignment:** the Owner's #153 assignment (two phases; this file is phase 1). Phase 2 (the fix) starts only after this measurement and an Owner decision; nothing below pre-judges it.
> **References:** issue #153 and its reproduction; DEC-016 (`docs/decisions/DEC-016_REDIS_CLOCK_AUTHORITY_RECONCILIATION.md` L35–L41, L57–L59); `BoundedCorrelationResultValidator::snapshot()` L58–L59; `EvaluationPipeline::addBoundedSnapshot()` L862–L918; `RedisFullCapabilityStore` `LUA_TIME_PRELUDE` L49–L98, `BOUNDED_SNAPSHOT` L763–L799, `ROTATED_SNAPSHOT` L801–L965.
> **Line citations** are `main@1e951ee`.
> **Rules kept from every earlier stage:** plan before harness; no registered condition is changed after it has run; a contract or scope question is raised as an LT before the build; no existing test is edited; a confirmed finding blocks Ready and merge; the PR stays Draft.

## 0. Questions (from the assignment)

1. **Second boundary, no skew.** With the application clock and Redis on the same time, how often does a request throw because the caller's `now()` (L867) and the script's `TIME` fall in different seconds? Measured over N real requests, with the distribution of the gap between the two readings.
2. **Skew matrix.** 0 / sub-second / 1 s / 2 s (application behind Redis), on `login_protection`, `otp_protection`, and a custom policy that reaches the distributed observation.
3. **Inventory.** Every value derived from Redis `TIME` in a script and then compared in PHP with the caller's clock, and whether the governing contract receives a caller `now` (DEC-016).
4. **Does the exception reach the Host?** Verified by running it: the type that leaves `limit()` / `checkOnly`, and the failure-mode effect (circuit, fallback, failure signal).
5. **Detectors.** A clock read from Redis `TIME` gives zero exceptions; a clock one second behind gives 100 %.
6. **Report** `investigations/clock-skew-153/REPORT.md`: table, measured rate, inventory; no fix recommendation.

## 1. What the code does (read, not measured)

Everything in this section is `READ` from the code at `1e951ee`. The consequences in §1.3 are `DERIVED` and are exactly what §5 measures; none of them is reported as a result.

### 1.1 The path

* `EvaluationPipeline::checkDistributedAccountAttack()` (L674–L772) runs on a pre-check of a policy with `PolicyCapabilityEnum::DISTRIBUTED_ACCOUNT` (`isDistributedAccountPolicy`, L2453–L2456; gate at L307: `$request->isPreCheck && … && ! $this->isProvenLane($lane)`) when the context has an account and a fingerprint and K4 / K5 exist (L682–L687). The built-in presets with the capability are `LoginProtectionPolicy` (L37) and `OtpProtectionPolicy` (L37).
* It takes two bounded snapshots through `addBoundedSnapshot()`:
  * the **device window**, key = HMAC of the account's K4, member = the device's K5, `ttl` 600 s, capacity 4 (L689–L690). Every qualifying pre-check reaches it;
  * the **occurrence history**, key = HMAC of K4, member = HMAC of the device window's `expiresAt`, `ttl` 86,400 s, capacity 3 (L713–L719). Reached only when the device window qualifies (count ≥ 4, or count 3 and the watcher ≥ 2, L691–L711).
* `addBoundedSnapshot()` reads the caller clock **before** the store call: `$now = $this->clock->now()->getTimestamp();` (L867, an integer: the floor of the caller's time), then calls `addDistinctBoundedWithSnapshot()` (no previous generation, L875) or `addDistinctBoundedWithSnapshotAcrossRotation()` (a previous key secret or a previous fingerprint generation, L900; `hasPreviousGeneration()` L2687–L2690), then validates with that `$now`.
* Neither store method receives a time argument. The script reads `TIME` (`LUA_TIME_PRELUDE` L50–L52: `nowStr` = the integer seconds of Redis time) and, **only when it creates the key**, stores and returns `expiresAt = nowStr + ttl` (`BOUNDED_SNAPSHOT` L795, L798; `ROTATED_SNAPSHOT` L846–L849, L899). On a key that exists it returns the stored value (L780, L787, L791, L798; L833, L840, L844, L849), and during a rotation with a live previous key it returns the **previous** key's stored expiry (L820, L857, L859, L964).
* `BoundedCorrelationResultValidator::snapshot()` rejects `expiresAt <= $now` (L58) and `expiresAt > $now + $ttlSeconds` (L59) with `RateLimiterException('Bounded correlation store returned an invalid snapshot.')` (L62). There is no tolerance.

### 1.2 The condition, in clock terms

Let `N` = the caller's floor second at L867, and `M` = the Redis floor second at which the returned `expiresAt` was written (`M = expiresAt − ttl`, for the value of `ttl` that wrote it). Then the validator throws exactly when

* **UP:** `M > N` (the returned expiry was written in a Redis second later than the caller's second), or
* **LO:** `M + ttl ≤ N` (the caller believes the returned window is already over).

### 1.3 Consequences derived from §1.2 (to be measured, not results)

* **DV-1, no skew, creating request.** A request that creates the key has `M` = the Redis second **during** the script, which runs after L867. If both clocks are the same clock, UP holds when a second boundary falls between L867 and the script's `TIME`: probability ≈ gap / 1 s, where gap = time from L867 to the script (the store call's PHP work, one network leg, queueing). If Redis is ahead by `θ` (0 ≤ `θ` < 1 s), ≈ (gap + `θ`) / 1 s.
* **DV-2, no skew, reusing request.** A request that finds the key returns an `expiresAt` written by an **earlier** request; UP needs that earlier write to fall in a second later than this request's `N`, i.e. another request must have created the key between this request's L867 and its script. Sequential requests cannot produce that; concurrent first requests on one account can. So at no skew the risk is on creating requests and on requests racing a creation.
* **DV-3, application behind by `s` ≥ 1 s.** Every creating request throws (UP). A reusing request throws while `M_creation > N`, i.e. during about `s` seconds after the key was created; after that it passes. The issue's 100 % is per fresh key (its reproduction creates a fresh namespace every time).
* **DV-4, application ahead by `a`.** UP never holds at creation. LO holds for a reusing request that arrives in the last ~`a` seconds of the window (600 s for the device window). This direction is in neither the issue nor the assignment matrix (LT-153-02).
* **DV-5, rotation.** With a live previous key, `ROTATED_SNAPSHOT` returns the previous key's expiry, written when the previous key was created; UP needs that creation within `s` seconds before `N`.
* **DV-6, benign exposure.** For a benign user the device window is created by the first pre-check of the account in each 600 s window. So at no skew the exposure is "the first pre-check of an account per window", at rate DV-1; behind by ≥ 1 s it is every such first pre-check plus anything within `s` seconds after it.

## 2. Pre-plan disclosure

* Nothing was run for this plan: no pilot, no timing probe, no measurement. Docker was not running at plan time.
* Known before this plan, from the issue only (real Redis 7.0.15 in Docker, `FixedClock` = Redis second − skew, a fresh namespace per run, one request each): skew 0 → `ALLOW`; 1 s and 2 s → the exception. That table is three single requests, not a rate.
* Found while reading for this plan, not in the issue or the assignment: DV-2 (no skew: only creating requests are at risk), DV-4 (the ahead direction throws at the end of a window), H-T (§9, LT-153-03), and the non-throwing caller-clock / Redis-time mixes of §6.1 (LT-153-07).

## 3. Environments

| Id | PHP | Redis | Clock relation | Role |
| --- | --- | --- | --- | --- |
| **E-DOCKER** | host PHP 8.5.9 (macOS) | `redis:7.0.15-alpine` from `docker/redis-integration/compose.yaml`, started by `scripts/ci/run-with-redis-service.sh` (the CI image) | **separate clocks**: Redis runs in the Docker Desktop Linux VM; the offset `θ` to the host clock is unknown and is measured (§4.3) | the package's own integration environment; the case of an application server and a Redis host on different machines |
| **E-SAME** | host PHP 8.5.9 | host `redis-server` 8.10.0 (`/opt/homebrew/bin`), started by the runner on a free port with `--save "" --appendonly no`, stopped after the run | **one clock** (same kernel): `θ` ≡ 0 by construction | the clean boundary case of question 1 (same time source, only the gap) |

Both are recorded in every output file (PHP version, Redis `INFO server` `redis_version`, OS, the measured `θ` distribution). Neither needs a download beyond what the test suite already pulls (LT-153-04).

## 4. Instrumentation (no change to `src/` or `tests/`)

The harness lives in `investigations/clock-skew-153/harness/` and uses the package only through its public builder (`RateLimiterBuilder::fromFullCapabilityStore(...)->withClock(...)->build()`, `limit(..., RateLimitCommand::checkOnly(...))`), `RedisFullCapabilityStore`, and the existing test support classes `RespRedisCommandExecutor` and `RecordingFailureSignalEmitter`, unchanged.

### 4.1 Recording wrappers

* **`RecordingClock`** implements `ClockInterface`, delegates to the arm's clock (§5.2) and appends every `now()` result (microsecond precision) to an ordered event log.
* **`RecordingExecutor`** implements `RedisCommandExecutorInterface`, delegates to `RespRedisCommandExecutor`, and appends every command to the same log with host `microtime(true)` immediately before and after. For an `EVAL` whose script text equals `BOUNDED_SNAPSHOT` or `ROTATED_SNAPSHOT` (identified by comparing the sent text with the private constants read once through `ReflectionClassConstant`, read only), it also logs the reply tuple (`count`, `accepted`, `added`, `expiresAt`) and the `ttl` argument.
* The **L867 reading** of a snapshot call is the last `now()` event before that `EVAL` in the log (L867 is immediately followed by the store call, L875 / L900; no other `now()` lies between). Its floor is `N`. `M = expiresAt − ttl`.

### 4.2 Per-request record (JSONL)

`env, arm, policy, population, clock, skew, i, outcome` (`ALLOW` / `DENY` / … or the exception class and message), and for every snapshot call of the request: `site` (`device` / `occurrence`), `script` (`bounded` / `rotated`), `callerNow` (µs), `N`, `tSend`, `tRecv` (host µs), `expiresAt`, `ttl`, `M`, `added`, `count`, `UP`, `LO`.

### 4.3 Clock offset probe (`θ`)

Before and after each run, 1,000 `TIME` round trips on a dedicated connection: `θ = redisTime − (tSend + tRecv) / 2`, `rtt = tRecv − tSend`. Reported: min / p1 / median / p99 / max of `θ` and `rtt`. In E-SAME `θ` must lie within ± `rtt` / 2 of 0 (a check on the probe itself, gate V-2).

## 5. Arms

### 5.1 Policies

| Id | Policy | How registered |
| --- | --- | --- |
| `LOGIN` | `login_protection` (`LoginProtectionPolicy`, the S0 preset) | the builder's default registration |
| `OTP` | `otp_protection` (`OtpProtectionPolicy`) | the builder's default registration |
| `CUSTOM` | a harness-local policy `custom_distributed` declaring `DISTRIBUTED_ACCOUNT` through `PolicyCapabilityProviderInterface` (DEC-008), with the same thresholds as `LoginProtectionPolicy` | `withPolicy()` |

The opt-in variant `LoginProtectionAdmissionPolicy` is not an arm of the matrix (the assignment names the three above); whether its unproven lane reaches the snapshot is answered in the inventory (§6) by reading, with the citation.

### 5.2 Clocks

| Id | `now()` returns | Used in |
| --- | --- | --- |
| `SYS` | `SystemClock(UTC)` (the builder's default, L176 / L241), wrapped by `RecordingClock` only | question 1 |
| `BEHIND(s)` | `SYS` − `s`, `s` ∈ {0.25, 0.50, 0.75, 1.00, 2.00} s | question 2 |
| `ALIGN-GATED` | the current Redis `TIME` (seconds and microseconds, read on a separate connection at every `now()` call); each request is started only when the Redis microseconds are below 500,000 | detector D-0 (LT-153-01) |
| `ALIGN` | the current Redis `TIME`, no gating | reported only (LT-153-01) |
| `ALIGN-BEHIND1` | `ALIGN` − 1 s | detector D-100 |

### 5.3 Populations

| Id | Requests | What it exercises |
| --- | --- | --- |
| **F** (fresh) | every request uses a new account id, a new device (`['device' => 'd' . i]`) and a new IPv4 address from the benchmarking range `198.18.0.0/15` (so no IP-scoped rule can deny before the snapshot); the issue's UA | every request **creates** the device window (DV-1, DV-3 at creation) |
| **R** (reuse) | one account and one device; requests back to back for 5.0 s of wall time; 20 repetitions, each on a new account | reuse after creation (DV-2, DV-3 after creation), with `t − t_creation` per request |
| **Q** (qualify) | one account, devices `d1 … d4` in order, then `d1` again; 200 repetitions, each on a new account | the occurrence snapshot (`ttl` 86,400), the second call site (L719) |
| **ROT** | population F and population R, with `RateLimiterConfig(..., previousKeySecret: …, previousFingerprintSecret: …)` | `ROTATED_SNAPSHOT` with no live previous key (L824–L849) |
| **ROT-P** | per repetition: one request under the old configuration (creates the previous key), then the rotated configuration, then requests back to back for 5.0 s; 20 repetitions | `ROTATED_SNAPSHOT` returning the **previous** expiry (L851–L964, DV-5) |

Concurrent first requests on one account (the race of DV-2) are not an arm; REPORT.md lists that case as unmeasured.

Every request is `RateLimitCommand::checkOnly(policy)` through `limit()`. A request that is denied by an earlier step of the pipeline does not reach the snapshot; such requests are counted and reported separately (`reached = false`), never as passes.

### 5.4 Matrix and sample sizes

| Clock | Populations | Policies | N per cell | Environments |
| --- | --- | --- | --- | --- |
| `SYS` | F | LOGIN, OTP, CUSTOM | 10,000 | E-SAME, E-DOCKER |
| `SYS` | R, Q, ROT, ROT-P | LOGIN | as §5.3 | E-SAME, E-DOCKER |
| `BEHIND(s)`, 5 values | F | LOGIN, OTP, CUSTOM | 2,000 | E-SAME, E-DOCKER |
| `BEHIND(s)`, 5 values | R, Q, ROT, ROT-P | LOGIN | as §5.3 | E-SAME |
| `ALIGN-GATED`, `ALIGN`, `ALIGN-BEHIND1` | F | LOGIN | 2,000 | E-SAME, E-DOCKER |

Each cell runs in a fresh store namespace. Cells run sequentially in the order of this table; the order is fixed in `run.sh`.

## 6. Inventory (question 3)

The inventory is `READ` from the code and is reported in REPORT.md with a citation for every line. It covers every value that is (a) derived from Redis `TIME` in a script of `RedisFullCapabilityStore` or `RedisAdmissionOperations`, or from the PHP-side `TIME` call (L1913), (b) returned to PHP, and (c) compared with, subtracted from, or combined with a caller-clock value anywhere in `src/`; for each: whether the governing contract receives a caller `now` (DEC-016), and whether the comparison can throw, change a decision, or only change a reported field.

### 6.1 Preliminary inventory (`READ` at `1e951ee`, every line spot-checked by the executor; REPORT.md carries the complete citation set)

Redis `TIME` is read by `LUA_TIME_PRELUDE` (L49–L98; used by `SCORE_INCREMENT` L100, `SCORE_SET` L170, `BLOCK_SET` L541, `BUDGET_INCREMENT` L576, `BUDGET_GET` L618, `BUDGET_SEED` L650, `DISTINCT_ADD` L705, `WATCH_INCREMENT` L730, `BOUNDED_SNAPSHOT` L763, `ROTATED_SNAPSHOT` L801, `LEASE` L967, the watch-rotation script L1403), directly by `LIFECYCLE_MUTATE` L178, `LIFECYCLE_READ` L335, `LIFECYCLE_CLAIM` L425, `BLOCK_GET` L569 and `HARD_BLOCK` L985 (used only when a lifecycle generation is passed, L1036–L1041; otherwise the caller's `ARGV[5]`), and in PHP at L1913 (a representability check; never returned). `RedisAdmissionOperations` takes caller `now` in every script.

| # | Value returned to PHP | Contract receives caller `now`? (DEC-016) | Compared in `src/` with the caller clock | Effect of a skew |
| --- | --- | --- | --- | --- |
| A | bounded snapshot `expiresAt` (§1.1) | **no** (`BoundedCorrelationSnapshotStoreInterface`, `…RotationStoreInterface`) | `BoundedCorrelationResultValidator` L58, L59 with `$now` from `EvaluationPipeline` L867 | **throws** (L62); the only throwing comparison found |
| B | score `updatedAt` (`get()`) | no (`RateLimitStoreInterface`) | decay: `DecayCalculator` L44–L45, L84–L85 (`max(0, now − updatedAt − paused)`) | decision (decay amount → threshold / level) and `retryAfter`; caller behind is clamped to 0, no throw |
| C | generation-bound state `expiresAt`, re-entry `validUntil` (`readGenerationBoundScoreState`) | no (`PunishmentLifecycleStoreInterface`) | `EvaluationPipeline` L280–L283 (`validUntil > $now && expiresAt > $now`) | decision (re-entry served, K4 score set to 0 at L288) |
| E | block `expiresAt` (`checkBlock()`), written from Redis time by `BLOCK_SET` L543 and the lifecycle `HARD_BLOCK` (L1188 with L1037), from caller time by the cycle `HARD_BLOCK` (L1188 with L1040); read through `BLOCK_GET`, which filters with Redis `TIME` (L569) | no | `EvaluationPipeline` L550, L559 (`expiresAt > $now`), L571 (`expiresAt − $now`) | decision (an L2+ block honoured or not) and `retryAfter` |
| F | budget `epochStart` (`getBudget` / `incrementBudget*`) | no | `BudgetTracker` L56 (`epochStart + EPOCH_DURATION > $now`) | decision (budget exceeded or not) |
| I | decay pause state, computed in `PAUSE_READ` with caller `now` (L1214) over stored pause intervals that the lifecycle path writes from Redis time | **yes** (`HardBlockCycleStoreInterface::readDecayPauseState`) | `DecayCalculator` L45, L85, L95 | decision (decay amount) and `retryAfter` |
| — | mixed inside Lua, not in PHP: `HARD_BLOCK` L1138 compares a stored block expiry with `now` (caller or Redis, by path); `LIFECYCLE_CLAIM` L475 compares a stored expiry (which the cycle path writes from caller time, L1040, L1188) with Redis `TIME` | mixed | — | decision inside the store; reported as observed, not measured |

Values with no caller-clock comparison (mutation results, cycle `pauseUntil`, lifecycle transition fields, watch / distinct counts, probe lease, re-entry claim, every admission value) are listed in REPORT.md with their citations.

**Two facts from the inventory that bear on the measurement:**

* **Only A throws.** B, C, E, F and I change a decision or a reported field without an exception (clamped by `max(0, …)` or a boolean comparison). Measuring them is not in this plan (LT-153-07).
* **No existing test can exhibit any of this.** There is no reference store in `src/`; the in-memory test stores take the same injected clock as the runtime (`tests/Support/RateLimiter/InMemoryRateLimitStore.php` L47, `tests/Support/Correlation/StatefulInMemoryCorrelationStore.php` L23, `tests/Support/FullCapability/FullCapabilityInMemoryStore.php` L25–L28), so one clock drives both sides. Only a real Redis with the system clock (or an offset clock) can, which is why every arm of §5 runs on real Redis.

### 6.2 Where the exception goes (`READ`; §7.4 verifies it by running)

`RateLimiterEngine::limit()` wraps the pipeline in one `try` (L323–L335) with two catches: `RateLimitConcurrencyException` (L336, returns a `HARD_BLOCK`) and `BackendFailureException` (L348, circuit `reportFailure`, failure resolver, local fallback). The validator throws the base `RateLimiterException` (`src/Exception/RateLimiterException.php` L12), which is neither (`RateLimitConcurrencyException` extends it, L13; `BackendFailureException` extends `SystemMaatifyException`, L18). So by reading: it leaves `limit()` unchanged, `reportSuccess` (L333) is skipped, `reportFailure` is not called, no fallback runs. The snapshot is reached only by `checkOnly` (`isPreCheck`, gate L307); `recordFailure` and `recordSuccess` never reach it. `LoginProtectionAdmissionPolicy` reaches it on the unproven lane and while its fence is closed, not on the proven lane (`isProvenLane`, L307).

## 7. Gates and measures (registered)

### 7.1 Validity

* **V-1 baseline.** `src/`, `tests/`, `composer.json` byte-identical to `1e951ee`; the runner refuses otherwise.
* **V-2 offset probe.** In E-SAME, the median `θ` lies within ± median `rtt` / 2 of 0.
* **V-3 attribution.** For every request that reached a snapshot: the request threw the validator's exception **iff** UP or LO holds for one of its snapshot calls (computed from the logged `N`, `expiresAt`, `ttl`). Required: 100 % agreement. Any other exception, or any disagreement, is reported by row and invalidates the cell.
* **V-4 L867 identification.** For every snapshot `EVAL`, exactly one `now()` event lies between the previous Redis command and that `EVAL`.

### 7.2 Detectors (question 5)

* **D-0:** `ALIGN-GATED` × F × LOGIN: **0** exceptions in 2,000, in both environments.
* **D-100:** `ALIGN-BEHIND1` × F × LOGIN: **2,000 / 2,000** exceptions, in both environments.
* A detector that fails invalidates the whole run; nothing else is reported as a result until the cause is found and recorded.

### 7.3 Measures (no pass / fail; reported with a Wilson 95 % interval)

* **Rate** of the exception per cell = exceptions / requests that reached a snapshot.
* For `SYS` × F: the distribution (min / p50 / p90 / p99 / max) of gap = `tSend` − `callerNow`, of the EVAL's `rtt`, and of `M − N`; the rate split by the caller's sub-second fraction at L867 (deciles), which is where DV-1 predicts the throws.
* For R and ROT-P: the rate as a function of `t − t_creation` (100 ms buckets).
* For Q: the rate at each call site separately (device, occurrence).
* `ALIGN` (ungated): the rate, against the DV-1 prediction rtt / 1 s.

### 7.4 Host surfacing (question 4)

One harness script, `host_surface.php`, run in both environments with `ALIGN-BEHIND1` × F × LOGIN (deterministic exception) and asserting, for 100 requests:

* **H-1** the throwable that leaves `limit()` is exactly `Maatify\RateLimiter\Exception\RateLimiterException` (not `BackendFailureException`, not a subclass), with the validator's message;
* **H-2** `RecordingFailureSignalEmitter` recorded no event;
* **H-3** the policy's circuit-breaker state read through the store's `load()` is identical before and after the 100 requests;
* **H-4** no `RateLimitResultDTO` is produced (no fallback decision) — the call does not return;
* **H-5** the same four facts for `otp_protection` and `custom_distributed`.

The engine's catch sites that make H-1 … H-4 the expected outcome are in §6.2. The script prints each assertion and exits non-zero on the first failure. It lives in the harness, not under `tests/` (LT-153-05).

## 8. Outputs

* `investigations/clock-skew-153/harness/` — the wrappers, the custom policy, the runner `run.sh`, `report.php`.
* `investigations/clock-skew-153/logs/` — full run logs, one per environment.
* `investigations/clock-skew-153/expected/` — per-cell JSONL, `summary.md` produced by `report.php`, `SHA256SUMS`.
* `investigations/clock-skew-153/REPORT.md` — the tables of §7, the inventory, the answers to questions 1–5; **no fix recommendation** (the recommendation is the Lead's; the decision is the Owner's).

Timing values are not reproducible run to run (they depend on the machine); `report.php` is deterministic over the JSONL. The REPORT says which numbers are timing-dependent.

## 9. Questions for the Lead before the harness (LT)

* **LT-153-01 — detector D-0 as written cannot be zero.** The assignment's detector is "a clock read from `TIME` itself gives zero exceptions". By DV-1, a clock that reads Redis `TIME` at L867 still crosses a second boundary whenever one falls between its own `TIME` and the script's `TIME` (one round trip plus the store call's PHP work): a non-zero rate of about rtt / 1 s, by the very mechanism question 1 measures. **Proposal:** D-0 = `ALIGN-GATED` (each request starts in the first half of a Redis second, so a sub-millisecond gap cannot reach the boundary; the gate is valid while the measured gap stays far below 500 ms, reported); `ALIGN` (ungated) is reported, not gated.
* **LT-153-02 — the ahead direction.** DV-4: an application clock **ahead** of Redis by `a` throws on reuse in the last ~`a` seconds of each 600 s window (LO). Not in the issue or the assignment. **Proposal:** add probe P-TAIL in E-SAME: 20 accounts, each window created at a recorded Redis time, then one request per account at Redis time `M + 600 − a/2` with `AHEAD(a)`, `a` ∈ {0.5, 1, 2}; predicted: all LO. Wall time ≈ 11 min (the 600 s window is a hard-coded constant, L690, and cannot be shortened without changing `src/` or seeding state; seeding is not proposed).
* **LT-153-03 — H-T, a Redis-only boundary in the same scripts, not a caller-clock comparison.** `expiresAt` is written as Redis floor seconds + `ttl` (L795), while the key's physical life is `EXPIRE ttl` from the Redis **millisecond** clock (L795). For a key created at Redis time `t0` with fraction `f`, a request in [`⌊t0⌋ + ttl`, `t0 + ttl`) still finds the key (`EXISTS` 1) but `expires ≤ nowStr`, and the script returns `malformed bounded metadata` (L781; the same shape at L834, L874, and in `DISTINCT_ADD` L721 and `WATCH_INCREMENT` L745). That needs no skew and no caller clock, so it is outside question 3 as worded. **Proposal:** probe P-HT in the same 11 min wait as P-TAIL (20 accounts created at Redis fractions ≥ 0.5, one request each at `⌊t0⌋ + 600 + f/2`), recorded as a separate observation; or the Lead rules it out of #153 and it becomes its own issue.
* **LT-153-04 — environments.** E-DOCKER (the CI image) and E-SAME (host Redis 8.10.0, a different major version than CI) are proposed together because the first has an unknown `θ` and the second has none. No image other than the suite's own is pulled. A Linux run with PHP in a container next to Redis (production-like co-location) would need a PHP image download and is **not** proposed unless the Lead asks.
* **LT-153-05 — where the question 4 check lives.** In the harness (§7.4), not under `tests/`: a test under `tests/` that pins today's behaviour (the exception) would have to be edited by phase 2, and "no existing test is edited without a ruling" would then apply to a test written only to be reversed. Phase 2's red tests are written fresh.
* **LT-153-06 — sample sizes.** 10,000 per `SYS` × F cell (question 1's example) and 2,000 per other F cell. At a true rate of 0.5 % the 95 % interval of 10,000 is about ± 0.14 points; at 50 % the interval of 2,000 is about ± 2.2 points.
* **LT-153-07 — the non-throwing mixes (B, C, E, F, I in §6.1).** They change decisions (decay, block honoured, budget, re-entry served) under skew without any exception. The assignment asks for their inventory, not their measurement, and this plan inventories them only. **Proposal:** keep phase 1 to the throwing path; if the Lead wants their size, a separate registered measurement after this one.

## 10. Commit order

1. This plan (alone).
2. After the Lead's rulings on §9: a dated amendment to this file recording them (no other change), then the harness.
3. The run: JSONL, logs, `summary.md`, `SHA256SUMS`.
4. REPORT.md.

Each commit alone; the plan is not edited after commit 2 except by a dated note that says what changed and why.

## 11. Lead rulings (2026-10-21, recorded before the harness)

Source: [PR #154 comment 6310105647](https://github.com/Maatify/php-rate-limiter/pull/154#issuecomment-6310105647). All seven LTs accepted; §1–§10 above are kept as written and are read together with this section. Where they differ, this section governs.

| LT | Ruling | Effect on the plan |
| --- | --- | --- |
| 153-01 | Accepted | D-0 = `ALIGN-GATED`. The measured gap (L867 reading → snapshot `EVAL` sent, and the `EVAL` round trip) is reported, and the validity condition is stated with its value: the gate is valid only while the largest measured gap of the cell is **below 50 ms** (one tenth of the 500 ms gated half-second); otherwise D-0 is reported as invalid, not as passed. `ALIGN` (ungated) is reported. D-100 unchanged. |
| 153-02 | Accepted | P-TAIL added in E-SAME: `a` ∈ {0.5, 1, 2} s ahead, 20 accounts, one 600 s window wait, no state seeding, no change to `src/`. |
| 153-03 | Accepted as a reported observation in this PR, and ruled an **independent defect** | P-HT runs in the same wait as P-TAIL. A separate issue is opened now ("Bounded metadata expiry floors Redis seconds while EXPIRE uses milliseconds: malformed state at the key's last fraction of a second") citing P-HT and L721, L745, L781, L834, L874. Its fix is decided outside #153. |
| 153-04 | Accepted | E-DOCKER + E-SAME, no new PHP image. REPORT.md states that the two Redis versions differ (7.0.15 / 8.10.0) and **compares** the environments; it never merges their numbers into one figure. |
| 153-05 | Accepted | The question 4 check stays in the harness; phase 2's red tests are written new. |
| 153-06 | Accepted | 10,000 per `SYS` × F cell, 2,000 for the other F cells, Wilson 95 % intervals. |
| 153-07 | Accepted for phase 1 **with a later condition** | Inventory only in this PR. A registered measurement of the size of the non-throwing mixes (B, C, E, F, I) is required **before the phase 2 decision**, as a separate PR after this one (option (a) of phase 2 would cover them together; option (b) would not). |

The Lead's verification recorded with the rulings: `limit()` catches only `RateLimitConcurrencyException` (L336) and `BackendFailureException` (L348; and L438 in `claimPostPunishmentReentry`), and the Lua lines L475 and L1138 compare an `expiresAt` with a time from either clock, as inventoried.

**Executor's value for 153-01.** The ruling asks for the "gap ≪ 500 ms" condition with its value; the 50 ms figure above is the executor's, registered here before any run. The Lead may change it before the harness commit; after that commit it is not changed.

## 12. Harness-time note (2026-10-21, before the recorded run)

Written after the harness was built and **before** any recorded run. Each item says what changed against §1–§11 and why. No registered value (N, offsets, detectors, gates' thresholds) is changed.

1. **Smoke and dry runs, disclosed.** To check the wiring, the harness was run on a throwaway local Redis 8.10.0 (scratch directory, outside `expected/`, not committed): a smoke run of about forty requests; the matrix at 1 % and at 5 % scale (`run.php` sixth argument; written into every cell's `meta`, and `report.php` refuses a cell whose scale is not 1); and `host_surface.php` in full (100 requests per policy). What they showed is the basis of items 2–5. Their counts are not results. They were in line with §1.3 (no exception at `SYS` in small samples, every request at `BEHIND(1)` and `ALIGN-BEHIND1`).
2. **Identifying the validated calls (§4.1 refined).** The script text alone does not identify the two validated sites. The same `BOUNDED_SNAPSHOT` / `ROTATED_SNAPSHOT` scripts are also run by `addDistinctBounded()` and the rotation variants (store L1365–L1368, L1379–L1388), which drop `expiresAt` and validate only the count:
   * `EphemeralBucket` L93, L115: `(900, 10)` and `(900, 50)`;
   * `EvaluationPipeline::addBoundedCorrelation()` (L1380–L1422): `(600, 6)` L1276, `(600, 3)` L1368, `(600, 5)` L1565, and the IPv6 scopes `(600, 2 / 4 / 8)` L963–L1021.

   The harness therefore identifies a validated call by its `(ttl, max)` arguments: `(600, 4)` is the device window (L690) and `(86400, 3)` is the occurrence history (L719). Only the IPv6 /40 scope shares `(600, 4)`, and every population uses IPv4. The other calls are counted per request (`ob`) and are not compared with the caller clock anywhere. They are exposed to the boundary of issue #155, which REPORT.md notes.
3. **V-4 replaced by V-4′ (stricter).** V-4 as registered ("exactly one `now()` between the previous Redis command and the snapshot `EVAL`") is false by construction for `login_protection` and `otp_protection`. `EvaluationPipeline` L280 (the post-punishment re-entry check, `PostPunishmentReentryPolicyInterface`) calls `now()` with no Redis command between it and L867. **V-4′:** the harness records the call site of every `now()` (`debug_backtrace`). For every validated `EVAL`, the last `now()` before it must have been called from `EvaluationPipeline.php:867`; required 100 %. This checks the identification rule of §4.1 directly, instead of through a count.
4. **`CUSTOM` declares the bounded fallback of Login.** Registration refuses an authentication policy without a bounded fallback with the `ACCOUNT` and `IP_PREFIX` dimensions (`RateLimiterEngine` L112–L115). `custom_distributed` therefore also implements `FailureFallbackConfigurationProviderInterface` and returns `LoginProtectionPolicy`'s configuration (`AUTHENTICATION_PRIMARY`), as a Host would.
5. **Q uses one IPv4 address per step.** §5.3 does not fix Q's addresses. With one address per repetition, an IP-scoped rule blocks the third device before the distributed observation, and the occurrence site (L719) is never reached. With one address per step, the fourth device reaches both sites. The fifth request (`d1` again) is then blocked before any snapshot and counts as `reached = false`, as §5.3 already says.
6. **P-TAIL size, as read.** "20 accounts" (§9, §11) is run as 20 accounts **per value** of `a` (60 accounts), a superset of the other reading.
7. **D-0 gap, as measured.** "L867 → snapshot reply" is measured on the host clock, from the moment `now()` was called at L867 to the receipt of the snapshot reply. The request start → snapshot reply time is also reported. The validity condition stays < 50 ms (§11).
8. **Runtime settings.** PHP 8.5.9 with `XDEBUG_MODE=off` and `memory_limit=1G`. The local `php.ini` loads Xdebug, and mode `off` removes its overhead from the timings. Every cell namespace carries a per-run random token.
