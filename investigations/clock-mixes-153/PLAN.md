# #153 LT-153-07 — non-throwing caller-clock / Redis-`TIME` comparisons under skew: pre-registered measurement plan

> **Status:** PRE-REGISTRATION, plan **v1**. Committed **before** any harness code and any result. Evidence only: no fix, no fix recommendation, no decision record, no change to `src/`, to any existing file under `tests/`, to `composer.json`, presets, public contracts, decision records, `DECISIONS_INDEX.md`, phase 1 (`investigations/clock-skew-153/`) or any earlier study.
> **Assignment:** the Lead's condition on phase 1 (LT-153-07, [PR #154 comment 6310105647](https://github.com/Maatify/php-rate-limiter/pull/154#issuecomment-6310105647); phase 1 PLAN §11): measure the size of the non-throwing comparisons B, C, E, F, I of phase 1 REPORT §6.1 **before** the phase 2 decision, as a separate PR. Phase 2 options (a) caller `now` into the store scripts and (b) a validator tolerance are not evaluated here; nothing below pre-judges them.
> **Baseline:** the branch is cut from `main@1e951ee` (PR #150). The runner will check that `src/`, `tests/` and `composer.json` are byte-identical to `1e951ee3c3b29ecc49ce08ceff809869bccf139d` and clean before every run (V-1). **Line citations** are `main@1e951ee`.
> **Phase 1 references** are at PR #154's head `fe8775c` (not yet on `main`): `investigations/clock-skew-153/PLAN.md` §1, §5, §11, §12; `REPORT.md` §3, §4, §6; `harness/lib.php`.
> **Path.** `investigations/clock-mixes-153/`: its own directory because it is its own PR, harness and outputs; phase 1's directory stays closed. "Mixes" is phase 1's word for these comparisons (PLAN §2, §9).
> **Rules kept from every earlier stage:** plan before harness; no registered condition is changed after it has run; a contract or scope question is raised as an LT before the build; no existing test is edited; a confirmed finding blocks Ready and merge; the PR stays Draft.

## 0. Questions

1. **What each comparison changes, and for whom.** For B, C, E, F, I (and the comparisons found while reading for this plan, §1.4): which user-visible decision the comparison feeds, which writer put the Redis-side value there, and which request population reaches the comparison inside its exposure window.
2. **The law.** For a caller clock offset `σ` from Redis (`σ = −s` behind, `σ = +a` ahead), the exact set of Redis times at which the comparison's outcome differs from the `σ = 0` outcome. Measured by targeted probes whose predicted outcome is 100 % or 0 %.
3. **The size.** Over registered request streams (mostly `recordFailure` followed by `checkOnly` over minutes), how many decisions differ from an identical stream with `σ = 0`, and how much the stream's outcome changes (admitted attempts, denied visits, time to first `ALLOW`).
4. **Where it shows.** Whether a difference appears as a decision, as a level or `retryAfter` only, or as stored state that differs and acts later.
5. **Report** `investigations/clock-mixes-153/REPORT.md`: tables with Wilson 95 % intervals; no fix recommendation.

## 1. What the code does (read, not measured)

Everything in §1 is `READ` at `1e951ee`, except the lines marked `DERIVED`, which are what §5 measures. None of them is a result.

**Notation.** `R` = Redis time, `C = R + σ` = caller time at the same instant, `⌊·⌋` = floor. Every timestamp in these comparisons is an integer second: Lua scripts use `TIME`'s seconds; PHP uses `getTimestamp()`. A boundary `b` is an integer.

**One identity used throughout (`DERIVED`).** For an integer `b`: `⌊R − s⌋ < b ⟺ R < b + s`, and `⌊R + a⌋ ≥ b ⟺ R ≥ b − a`, for any real `s, a ≥ 0`. So a comparison of a stored integer with `⌊C⌋` switches, in Redis time, exactly `s` later (behind) or `a` earlier (ahead) than with `⌊R⌋`. The exposure is an interval of length `|σ|` at each switch. This is phase 1's measured law (REPORT §3.1: exposure as a share of time is exactly `s`) applied to a stored boundary.

### 1.1 The five comparisons

| # | Redis-side value and its writer | Caller-side read | Comparison | Contract receives caller `now`? (DEC-016) |
| --- | --- | --- | --- | --- |
| **B** | score `updatedAt` = Redis second: `SCORE_INCREMENT` L117, L130; `SCORE_SET` L172; generation-bound K4 `LIFECYCLE_MUTATE` L278, L327. Read back by `SCORE_GET` L167 and `LIFECYCLE_READ` L421 | `DecayCalculator` L44, L84 | `elapsed = max(0, (now − updatedAt) − paused)` L45, L85; decay = `⌊elapsed / I⌋` L51; `I` = 600 / 300 / 180 s (account / device / IP, L15–L17), doubled while an L2+ block is current (L107) | no (`RateLimitStoreInterface`, `PunishmentLifecycleStoreInterface`) |
| **C** | K4 generation-bound score `expiresAt` = ⌈(Redis ms + 86,400 s) / 1 s⌉ at the generation's **first** write (`LIFECYCLE_MUTATE` L276–L278; TTL 86,400 from `EvaluationPipeline` L1714), carried unchanged by later mutations (L319–L325); re-entry `validUntil` = that `expiresAt` (`HARD_BLOCK` L1197) | `EvaluationPipeline` L280 | `validUntil > now && expiresAt > now` L282–L283 → re-entry served, K4 score set to 0 (L288), pre-check `ALLOW` carries the re-entry metadata (L502–L512) | no |
| **E** | L2+ block `expiresAt`. By reading, the pipeline writes L2+ blocks only through `HARD_BLOCK` (L2097–L2167): the **lifecycle path** (K4 of a `PostPunishmentReentryPolicyInterface` policy) with Redis seconds (L1037, L1188); the **cycle path** with the caller's `now` (L2161 → L1040, L1188). `BLOCK_SET` receives level < 2 only (L2170). Read through `BLOCK_GET`, which drops and deletes a block whose `expiresAt ≤` Redis second (L569–L571) | `EvaluationPipeline` L531 | `level ≥ 2 && expiresAt > now` L550, L559 → `HARD_BLOCK` returned before any scoring (L218); `retryAfter = expiresAt − now` L571 | no (`checkBlock`); the cycle-path writer does (`blockWithCycleTracking` L1507) |
| **F** | budget `epochStart` = Redis second (`BUDGET_INCREMENT` L613, `BUDGET_SEED` L700, or a carried seed L694). `BUDGET_GET` drops an epoch whose end `≤` Redis second (L643–L645) | `BudgetTracker` L55 | `count ≥ limit && epochStart + 86,400 > now` L54–L56 → budget active (L2226–L2229) → budget cooldown path (L2231–) | no |
| **I** | decay pause intervals `start:finish`, written by `HARD_BLOCK` with its `now` (lifecycle path Redis seconds L1037; cycle path caller seconds L1040), pause 600 s (L1172, L59), activated at ≥ 2 cycles in 21,600 s (L1171, L57–L58) | `EvaluationPipeline` L2765 → `PAUSE_READ` `ARGV[2]` L1214 | overlap of `[from, now]` with each interval L1236–L1248 → `elapsedPausedSeconds` into L45 / L85; `activePauseUntil − now` into `retryAfter` L95 | **yes** (`readDecayPauseState` L1539); the lifecycle-path writer does not (`blockWithPunishmentLifecycleTracking` L1686, `now` = 0 at L1697) |

### 1.2 Derived laws, decisions and populations (`DERIVED`; §5 measures each)

**B — decay.** The `σ = 0` arm reaches decay step `k` at the Redis second `b_k = u + P + kI`. By the identity, the caller arm reaches it at `b_k + s` (behind) or `b_k − a` (ahead).

* **DV-B1.** A read at `R ∈ [b_k, b_k + s)` (behind) or `[b_k − a, b_k)` (ahead) sees one point more or one point less. That is a different decision when step `k` moves the decayed score across a threshold of that key: K4 5 / 8 / 12 for `login_protection`.
* **DV-B2.** A write (`recordFailure`) in the same window stores `decayed + delta` (L1703, L1714) one point higher or lower. The two arms then differ in stored state until both decay to 0.
* **DV-B3, the retryAfter-honouring client.** On a failure that leaves a score level without a fresh lifecycle block, `retryAfter` is computed as if the score had been written at `⌊C⌋` (`lastUpdateTimestamp = ⌊C⌋`, L1780, L1771–L1786). The stored `updatedAt` is the Redis second `u′` of the same request. A client that waits exactly that `retryAfter` (an integer, on the wall clock) returns when L44 computes `⌊C_ret⌋ − u′ = retryAfter + (⌊C⌋ − u′)`:
  * behind: `⌊C⌋ − u′` is `−s` for whole seconds, and `−1` with probability ≈ `s` for sub-second skews. The client arrives before the decay it was promised and is refused again;
  * ahead: it arrives after the decay, so there is no refusal;
  * `σ = 0`: `⌊C⌋ = u′` except at a split, so the arrival is exact.

  Example (`login_protection`): two failures, K4 = 6 ≥ 5, an L1 refusal with a decay `retryAfter`. Behind by 1 s, the predicted outcome is that 100 % of clients returning on time are refused again.

**C — re-entry.** `LIFECYCLE_READ` already blanks the evidence when `validUntil ≤ ⌊R⌋` and while an L2+ block is active by Redis time (L403, L419). The PHP check repeats the same comparison against `⌊C⌋`.

* **DV-C.** Behind: Redis blanks the evidence at `⌊R⌋ ≥ E_s`, before PHP's condition would fail, so nothing changes. Ahead: re-entry is refused during `[E_s − a, E_s)`, where `E_s` is the K4 score's `expiresAt`, 86,400 s after the generation's first write.
* No `retryAfter` points a client at `E_s`. The exposed population is a punished account that sends a pre-check in the last `a` seconds of that day.

**E — L2+ block honoured.**

* **DV-E1, lifecycle-written** (`E = ⌊R0⌋ + D`).
  * Behind: `BLOCK_GET` drops the block at `⌊R⌋ ≥ E`, before PHP's condition would fail, so nothing changes.
  * Ahead: the block is not honoured during `[E − a, E)`; it ends `a` seconds early. If `a ≥ D` it is never honoured (**saturation**: L2 `D` = 60 s, `PenaltyLadder` L13).
* **DV-E2, cycle-written** (`E = ⌊R0 + σ⌋ + D`).
  * Behind: `BLOCK_GET` deletes the block at Redis second `E`, which is `⌊R0⌋ − ⌊R0 − s⌋` seconds earlier than in the `σ = 0` arm (`s` for whole seconds; 0 or 1 for sub-second, 1 with probability ≈ `s`).
  * Ahead (whole seconds): PHP's `E > ⌊R + a⌋ ⟺ ⌊R0⌋ + D > ⌊R⌋`, the same as `σ = 0`, and the key's `EXPIRE` (L1189) is on Redis's clock. So no change, except sub-second floors.
* **DV-E3, the polling client.** The checkOnly `retryAfter` is `E − ⌊C⌋` (L571).
  * Lifecycle-written, ahead: it is `a` shorter. A client that polls during a block and waits that `retryAfter` lands in `[E − a, E)`: predicted 100 % in the window.
  * Lifecycle-written, behind: it arrives `s` late, so there is no refusal change.
  * After a fresh lifecycle block, the failure's own `retryAfter` is the ladder duration (L1767), not clock-derived. A client honouring it arrives after `E` in both arms, so no change is predicted.
* **What the change leads to.** An un-honoured block lets the request reach scoring. The decision can still be `HARD_BLOCK` from the score. During `[E − a, E)` Redis still sees the block as active, so the re-entry evidence is blanked (L403, L419), the K4 score is not zeroed, and a new block may be issued.
* **E′ — found for this plan, same operands.** `currentBlockLevel` (L2737–L2753) compares the same `expiresAt` with `now` (L2740, L2741, L2747). Its result doubles the decay interval for the whole elapsed time (L107). So when the block stops counting, the decay amount jumps, and that jump moves by the same `|σ|` window.

**F — budget.** `BUDGET_GET` already drops a finished epoch by Redis time.

* **DV-F.** Behind: `BUDGET_GET` drops the epoch first, so nothing changes. Ahead: during `[end − a, end)` an exceeded budget is not active. That is a different decision for an account at or over its threshold (`login_protection` 20, `LoginProtectionPolicy` `getBudgetConfig`) that sends a request in the last `a` seconds of the 86,400 s epoch. No `retryAfter` points at the epoch end: the budget's `retryAfter` is the cooldown marker's remaining time (L2324, L2340; 3,600 s for `login_protection`).

**I — pause.**

* **DV-I1, Redis-written intervals (lifecycle path).** Inside a pause, the net elapsed time `(C − u) − (C − start) = start − u` does not depend on `C`. At the pause edges and after the pause, the timeline moves by `σ` exactly as in B. So I adds nothing beyond B's law.
* **DV-I2, caller-written intervals (cycle path, `start = ⌊R1 + σ⌋`).** The frozen net elapsed `start − u` differs from the `σ = 0` arm by `σ` (floored) for the **whole** 600 s pause. The decay amount differs by one step during the pause when a multiple of `I` lies between the two values: probability ≈ `|σ| / I` for a score of uniform age at pause start, and ≈ 0 when the pause starts in the same request that wrote the score.
* `activePauseUntil − ⌊C⌋` shifts `retryAfter` by `σ` (L95), a reported field only.

**A would mask B–I.** Phase 1 measured that, behind by 1 s or more, 100 % of the pre-checks that create a device window throw at the bounded snapshot (A), on every policy with `DISTRIBUTED_ACCOUNT` (REPORT §3.1). A stream on such a policy would show A's exception at the first pre-check of every 600 s window, before any of B–I could act. The capability is read only at registration (`RateLimiterEngine` L190–L193, L278; `RateLimiterBuilder` L406–L432) and at the snapshot's gate (L307, `isDistributedAccountPolicy` L2453–L2456). A policy without it therefore never reaches the snapshot, and none of B–I reads it. Hence B–I are measured on harness policies that mirror `login_protection` without `DISTRIBUTED_ACCOUNT` (§5.1). The preset itself runs as a reference, with A counted separately.

**Populations.** The exposure of each comparison is a window of `|σ|` seconds at a stored boundary, except DV-I2 (a whole pause). So the population is whoever is evaluated inside that window. A random visit almost never is: the share is `|σ|` over the time between boundaries. A client whose timing is **steered to the boundary** always is: DV-B3 (behind) and DV-E3 (ahead). The plan therefore measures three things: the law (targeted probes), random-timed streams, and the steered clients.

### 1.3 Mixed-clock comparisons inside Lua (`READ`; observed, not targeted, LT-M-01)

| Id | Line | Stored value (writer) | Compared with | Effect |
| --- | --- | --- | --- | --- |
| L-1 | `HARD_BLOCK` L1138 | an existing block's `expiresAt` (either path) | `now` of the current path (Redis on the lifecycle path L1037, caller on the cycle path L1040) | `active` → new cycle or not (L1142–L1143) → cycle count → pause activation (L1171) |
| L-2 | `LIFECYCLE_CLAIM` L475 | a block's `expiresAt` (either path) | Redis second (L425–L427) | re-entry claim refused (`return 0`) while the block is unexpired; bounded by the physical TTL check (L470) |
| L-3 | `BLOCK_GET` L569 (with `DEL` L570) | cycle-written `expiresAt` (caller time) | Redis second | DV-E2: the block ends early under behind |
| L-4 | `LIFECYCLE_READ` L403, L419 | a block's `expiresAt`; evidence `validUntil` | Redis second | re-entry evidence blanked or served; mixed when the K4 block was cycle-written |
| L-5 | `HARD_BLOCK` L1122, L1161, L1168 | cycle members and pause intervals (either path) | `now` of the current path | 21,600 s cycle window, 86,400 s retention, active pause: edges move by `σ` |

L-1 and L-2 are the two named in phase 1 (PLAN §6.1 last row). L-3, L-4 and L-5 were in phase 1's E and I rows as text, and are listed separately here.

### 1.4 Other caller-clock / Redis-time comparisons found while reading for this plan (`READ`)

| Id | Line | Redis-side value | Effect | Proposed treatment (LT-M-01) |
| --- | --- | --- | --- | --- |
| E′ | `EvaluationPipeline` L2740–L2747 | block `expiresAt` | decay interval doubled or not → decision | measured with E (same operands) |
| G | `EvaluationPipeline` L2324, L2340 | cooldown marker `updatedAt` (`SCORE_INCREMENT`, L2348) | `retryAfter` only (`max(0, …)`) | recorded, not a decision |
| H | `RateLimitOperationalReader` L167–L190, L278, L346 | block, score, pause, cooldown, budget | Host-facing operational read; reported values only | inventoried, not measured |

Caller-only comparisons (both sides from the caller clock, so no mix): `EvaluationPipeline` L1315, L1672–L1675; `CircuitBreaker`; `LocalFallbackLimiter`; `FixedWindowSimpleRateLimiter`. `RedisAdmissionOperations` takes caller `now` in every script (phase 1 PLAN §6.1).

## 2. Pre-plan disclosure

* Nothing was run for this plan: no pilot, no timing probe.
* Known before this plan: phase 1's results (REPORT at `fe8775c`), in particular the floor law at sub-second skews (§3.1), the reuse law `M0 + s` (§3.2) and P-TAIL (§4). Phase 1 did not measure B, C, E, F or I (REPORT §6.1, §10).
* Found while reading for this plan, not in phase 1: A masks B–I on every `DISTRIBUTED_ACCOUNT` policy behind by ≥ 1 s (§1.2, hence `LOGIN-NA` / `CYCLE-NA`); DV-B3 (behind: a `retryAfter`-honouring client is refused again), DV-E1 / DV-E2 (each write path loses `|σ|` of block in exactly one direction), DV-E3 (ahead: a polling client lands in the window), DV-I2 (a caller-written pause shifts decay for the whole pause), E′, G, H.

## 3. Environments

| Id | PHP | Redis | Clock relation | Role |
| --- | --- | --- | --- | --- |
| **E-SAME** | host PHP 8.5.9 | host `redis-server` 8.10.0, started by the runner as in phase 1 (`--save "" --appendonly no`, a free port) | one clock: `θ ≈ 0` (phase 1 V-2: median 0.040 ms) | everything in §5; the only environment in which the host clock stands in for Redis time (§4.3) |
| **E-DOCKER** | host PHP 8.5.9 | `redis:7.0.15-alpine` (the CI image, `scripts/ci/run-with-redis-service.sh`) | separate clocks, `θ` measured and moving (phase 1 REPORT §9) | targeted probes at `σ = ±1 s` only, twin comparison only (LT-M-04): a check that the laws hold on the CI Redis version |

The two environments are compared and never pooled (phase 1 LT-153-04 ruling).

## 4. Instrumentation (no change to `src/` or `tests/`)

The harness lives in `investigations/clock-mixes-153/harness/`. It uses the package only through `RateLimiterBuilder::fromFullCapabilityStore(...)->withClock(...)->withPolicy(...)->build()`, `limit()` with `RateLimitCommand::checkOnly` / `recordFailure` / `recordSuccess`, `claimPostPunishmentReentry()` (`PostPunishmentReentryClaimInterface` L21), `RedisFullCapabilityStore`, and the test support classes `RespRedisCommandExecutor` and `RecordingFailureSignalEmitter`, all unchanged.

### 4.1 Reused from phase 1 (`fe8775c`, LT-M-08)

`EventLog`, `RecordingClock` (every `now()` with its call site), `RecordingExecutor` (every command with host send / receive times), `RedisTime`, `scriptNames()`, the delegation pattern of `CustomDistributedPolicy` (for §5.1's policies), the offset-clock arms, and the E-SAME / E-DOCKER runner shape with V-1.

### 4.2 Added

* **One reading per `now()`.** The offset clock takes one host reading `h` and returns `h + σ`, logging both. Phase 1's `BEHIND` read `microtime()` twice. One reading makes the counterfactual of §4.3 exact. The `σ = 0` arm uses the same clock with `σ = 0`.
* **Operands.** For every `now()`, the clock also logs the arguments of the calling frame (`debug_backtrace` with arguments, read only), so `calculateDecay` / `secondsUntilBelowThreshold` record `currentScore`, `lastUpdateTimestamp`, `currentBlockLevel`, `scope`, `elapsedPausedSeconds` and `activePauseUntil` directly.
* **Replies.** The executor parses and logs the arguments and replies of `SCORE_INCREMENT`, `SCORE_GET`, `SCORE_SET`, `BLOCK_SET`, `BLOCK_GET`, `BUDGET_INCREMENT`, `BUDGET_GET`, `BUDGET_SEED`, `LIFECYCLE_MUTATE`, `LIFECYCLE_READ`, `LIFECYCLE_CLAIM`, `HARD_BLOCK` and `PAUSE_READ`.
* **Recovery rule.** For each comparison site (L44, L84, L280, L531 / L550 / L559 / L571, L2740, L55, L2765), the rule that recovers the Redis-side operand from the log (for example "the `LIFECYCLE_READ` reply immediately before the `EvaluationPipeline.php:280` `now()`") is written in the harness-time note before any recorded run, as phase 1 §12 did, and checked by V-SITE.

### 4.3 The counterfactual (comparison level, E-SAME only)

In E-SAME, the host reading `h` at a `now()` is Redis time to within `θ` (V-2). For each comparison event in a `σ` arm, the harness evaluates the comparison twice with the logged operands: once with `⌊h + σ⌋` (what the code did) and once with `⌊h⌋` (what Redis time gives). A **skew flip** is an event where the two outcomes differ. In the `σ = 0` arm the two are identical by construction, which is a self-check of the harness, not a result.

### 4.4 Twins (decision level, both environments)

Every stream runs as a **pair**: the `σ` twin and a `σ = 0` twin. The two have identical request sequences, account ids, devices, IPs, user agents and `RateLimiterConfig`, but separate store namespaces, so no key is shared. They run in one process, step by step, back to back, with the order alternating by step parity. A step is one call (`checkOnly`, `recordFailure`, `recordSuccess` or a claim) at a scheduled time, plus the wait that follows it.

* **Split.** A pair step is *split* when a Redis-written value (for example `updatedAt` or `expiresAt`) differs between the twins without a caller-clock cause, i.e. a Redis second boundary fell between the twins' commands.
* A split is noise of the pairing, not of the skew. It is classified from the logged values (V-ATTR) and reported, never counted as a change.

### 4.5 Per-step record (JSONL)

`env, family, policy, sigma, pair, twin, step, scheduledAt, hostStart, hostEnd, command, decision, blockLevel, retryAfter, reentry (id present or not), claimResult, exception`, plus the step's comparison events. Each comparison event carries: `site`, operands, `⌊h + σ⌋`, `⌊h⌋`, outcome, counterfactual outcome, flip. The stored values written by the step's scripts are also recorded.

## 5. Arms

### 5.1 Policies

Both harness policies delegate thresholds, deltas, failure mode, budget, bounded fallback and known-device allowance to `LoginProtectionPolicy`, in the same way phase 1's `CustomDistributedPolicy` did. Registration needs nothing else: `PostPunishmentReentryPolicyInterface` is a marker (`extends BlockPolicyInterface {}`), and no line of `src/` tests for the preset's class or name.

| Id | Policy | Why |
| --- | --- | --- |
| `LOGIN-NA` | harness policy `login_na`: `LoginProtectionPolicy`'s interfaces and values, capabilities `CREDENTIAL_SPRAY` and `TRUSTED_AUTHENTICATION`, **without** `DISTRIBUTED_ACCOUNT` | K4 L2+ blocks on the **lifecycle** path (Redis-written), re-entry (C), budget (F), without A (§1.2) |
| `CYCLE-NA` | harness policy `cycle_na`: as `LOGIN-NA`, **without** `PostPunishmentReentryPolicyInterface` | K4 L2+ blocks on the **cycle** path (caller-written): DV-E2, DV-I2 |
| `LOGIN` | `login_protection`, the S0 preset, unchanged | reference only (S-BEN and S-RA, §5.6): how A and B–I combine on the preset Hosts run. A's exceptions are counted as cause A (§7.1) |

`otp_protection` uses the same lifecycle path as `LOGIN` (`OtpProtectionPolicy` L25) with other thresholds. `LoginProtectionAdmissionPolicy` is opt-in. Neither is an arm (LT-M-05).

### 5.2 Skews, and why each value

| `σ` | Directions | Reason |
| --- | --- | --- |
| 0.25 s, 0.5 s | behind and ahead | sub-second: acts only through the floor, with exposure = `|σ|` as a share of time (phase 1 §3.1). Two values check that the share is linear below one second |
| 1 s | behind and ahead | the smallest skew that moves **every** integer comparison by exactly one second at every phase: deterministic predictions (100 % / 0 %) |
| 2 s | behind and ahead | linearity above one second (exposure 2 against 1) |
| 10 s | behind and ahead, P-B and P-E only | linearity at a scale where a decay window (600–1,200 s) or an L3 block (300 s) loses a visible share (1–3 %); executor's value (LT-M-02) |
| 60 s | behind and ahead, P-B and P-E only | **= the L2 block duration**: DV-E1 predicts saturation (ahead: a lifecycle L2 block is never honoured), detector D-SAT; and 20 % of an L3 block |
| 0 | twin of every pair, and the A/A pair | reference; the A/A pair is the pairing noise floor (D-AA) |

0.75 s (in phase 1's matrix) is dropped: phase 1 showed the floor law at three sub-second values, and 0.25 / 0.5 test linearity here. Larger skews for the 86,400 s windows (C, F, L6) are not needed: the law is the same at any `σ`, and those windows need their own wait (§5.5).

### 5.3 Targeted probes (the law)

One probe per stream. A probe is a single `checkOnly`, because a pre-check can write state. Each probe's predicted outcome (flip or no flip) is computed from **that stream's logged values** with the laws of §1.2, then compared with the observed one.

| Id | Setup per stream (policy) | Boundary probed | In-window prediction | Control prediction |
| --- | --- | --- | --- | --- |
| **P-B** | 2 failures, 10 s apart (`LOGIN-NA`): K4 = 6 | K4 decay 6 → 4 at `b = u + 1,200` (two account intervals; L1 threshold 5) | flip at L44 in `[b, b + s)` / `[b − a, b)`: **100 %** | flip in the adjacent equal-length bands before and after: **0 %** |
| **P-E2-LC** | 3 failures (`LOGIN-NA`): K4 = 9 → lifecycle L2 block, `D` = 60 s | `E = ⌊R0⌋ + 60` | ahead: flip at L550 in `[E − a, E)` **100 %**; behind: **0 %** everywhere | **0 %** |
| **P-E2-CY** | 3 failures (`CYCLE-NA`): cycle L2 block | `E = ⌊R0 + σ⌋ + 60` | behind: block absent (L569) in `[E, E₀)` where `E₀` is the `σ = 0` twin's expiry: **100 %**; ahead (whole `σ`): **0 %** | **0 %** |
| **P-E3-LC / -CY** | 3 failures (L2 block); a fourth after the L2 block ends: K4 = 12 → L3 block, `D` = 300 s (no decay step completes in the ≈ 70 s) | as above | as above | as above |
| **P-SAT** | P-E2-LC at `σ = +60` | any time in the block | not honoured: **100 %** | — |
| **P-I** | `CYCLE-NA` and `LOGIN-NA`: 3 failures (L2 block, cycle 1); after the block ends, 1 failure (L3 block, cycle 2, pause 600 s activated, L1171); probe uniform in the pause or in `[finish, finish + 2I]` | pause interval | flip at L45 predicted per probe from its logged operands (DV-I1 / DV-I2) | — |
| **P-RA** | DV-B3 / DV-E3 steered clients: (i) `LOGIN-NA`, 2 failures, the client returns after the failure's decay `retryAfter`; (ii) `LOGIN-NA` and `CYCLE-NA`, 3 failures, then a `checkOnly` 30 s into the block, then the client returns after **that** `retryAfter` | as steered | (i) behind ≥ 1 s: refused again **100 %**; ahead: **0 %**. (ii) LC ahead ≥ 1 s: lands in `[E − a, E)` **100 %**; LC behind: **0 %**; CY: **0 %** (whole `σ`) | — |

**Setups are `DERIVED`.** The stored state each setup should produce (score, level, write path, cycle count, pause) is checked on the logged replies of every stream before its probe. A stream whose setup produced another state is reported by row and excluded from its family's detector. If that happens to more than 1 % of a family's streams, the family's setup is corrected in a dated note before the recorded run (from smoke runs, disclosed as such).

**Placement.** In-window probes are uniform inside the predicted window. Control probes are split equally between the band of the same length just before and just after it, clipped to the state's life. At `σ` < 1 s the windows are under one second: the probe is placed by Redis time (phase 1's `waitRedisUntil`), and the prediction uses the logged execution time, not the scheduled time.

**Decision outcome.** For each probe the twins' decisions are also compared. The flip is the law; whether the flip **changes the decision** is the measured consequence (for P-E: whether the score re-blocks).

### 5.4 Streams (the size)

Request times come from a registered seeded PRNG (`mt_srand` seed fixed in `run.sh`). The schedule is the same for every `σ` and for both twins; it depends on decisions only where the population says so.

| Id | Per stream | Duration | What it measures |
| --- | --- | --- | --- |
| **S-BEN** (benign) | `k` = 1, 2, 3 failures (by stream index), each preceded by a `checkOnly`, 5–20 s apart; then visits at 3 times uniform in `[0, 3,600]` s after the last failure, each a `checkOnly`. At the first `ALLOW`, a `recordSuccess`, and the stream stops | 1 h | a person who mistypes and comes back later: randomly timed reads of decaying scores (B, I, E′) |
| **S-ATK** (fixed-pace attacker) | every `g` = 30 s (phase uniform): `checkOnly`; if `ALLOW`, `recordFailure` | 40 min | admitted failures per stream; block ends (E), cycles (L-1), pauses (I), on a timing not steered by `retryAfter` |
| **S-RA** (`retryAfter`-honouring attacker) | `checkOnly`; if `ALLOW`, `recordFailure`; otherwise wait exactly the last response's `retryAfter` (wall clock from receipt; 1 s if none or 0) and retry. On `LOGIN-NA` and `LOGIN`, a pre-check `ALLOW` carrying re-entry metadata is followed by `claimPostPunishmentReentry` with its id, then the `recordFailure` (LT-M-06) | 40 min | the steered population: DV-B3, DV-E3, C and L-2 / L-4 on the claim |

Each stream uses its own account, device and an IPv4 address from `198.18.0.0/15`, with one `/24` per stream inside a cell, so no IP or prefix rule couples streams.

**After the first divergence.** S-RA twins can follow different timings once a `retryAfter` differs, so their steps are paired only up to that point. Beyond it, only the stream outcomes are compared (§6.3).

### 5.5 The 24-hour windows: C, F, and L5 / L6 (LT-M-03)

C, F and L6 have their boundary 86,400 s after a write; L5 is at 21,600 s. Measuring them without seeding state needs a real wait. **Proposed arm P-LONG** (E-SAME, one run of about 24 h 15 min, nothing else in the run):

* At `t0`, for `σ = +1 s` and `+2 s` (C and F change only ahead, DV-C, DV-F) with `σ = 0` twins, 20 pairs per value and family:
  * **P-C.** `LOGIN-NA` accounts punished to a lifecycle L2 block, whose re-entry evidence is then served once (not claimed).
  * **P-F.** `LOGIN-NA` accounts brought to the budget threshold.
* At `E_s − a/2` and `end − a/2` respectively, one pre-check per pair. Predicted: re-entry not served / budget not active, **100 %** in the `σ` twin, **0 %** in the `σ = 0` twin.
* The schedule that brings an account to 20 counted failures (counting rule L1798–L1836; refusals at L218 count nothing) is fixed in the harness-time note, with its duration. If it needs more than 2 h, that is raised before the run.

L5 / L6 blocks are not constructed. The block law is measured at L2 and L3 (P-E) and holds for any `D` by §1.2.

**Alternatives** for the Lead:

* (ii) C and F reported as `DERIVED` only, marked as such;
* (iii) seeding Redis hashes directly. Not proposed: phase 1 refused seeding (PLAN §9 LT-153-02), and a seeded hash is not a state the package produced.

### 5.6 Matrix, N and wall time (E-SAME unless stated)

| Family | Policies | `σ` values | N | Wall time (estimate) |
| --- | --- | --- | --- | --- |
| P-B | LOGIN-NA | ±0.25, ±0.5, ±1, ±2, ±10, ±60 | per `σ`: 100 in-window + 100 control pairs | ≈ 22 min |
| P-E2, P-E3 | LOGIN-NA (LC), CYCLE-NA (CY) | as P-B | per `σ` × policy × level: 100 in-window + 100 control pairs | ≈ 8 min |
| P-SAT | LOGIN-NA | +60 | 100 pairs | in P-E |
| P-I | LOGIN-NA, CYCLE-NA | ±0.25, ±0.5, ±1, ±2 | 200 pairs per `σ` × policy | ≈ 15 min |
| P-RA (i), (ii) | (i) LOGIN-NA; (ii) LOGIN-NA, CYCLE-NA | ±0.25, ±0.5, ±1, ±2 | 100 pairs per `σ` × policy × variant | ≈ 25 min |
| S-BEN, S-ATK, S-RA | LOGIN-NA, CYCLE-NA | ±0.25, ±0.5, ±1, ±2, plus the A/A pair | 200 pairs per `σ` × policy × population | 1 h + 40 min + 40 min, per policy |
| S-BEN, S-RA (reference) | LOGIN (preset) | ±1, ±2, plus the A/A pair | 200 pairs per `σ` × population | 1 h + 40 min |
| P-LONG | LOGIN-NA | +1, +2 | 20 pairs per `σ` × family | ≈ 24 h 15 min (if accepted) |
| E-DOCKER | as P-B, P-E2, P-RA | ±1 | 100 + 100 pairs | ≈ 1 h |

Every cell runs in fresh namespaces carrying a per-run random token. Cells run in the order of this table, fixed in `run.sh`. The E-SAME total without P-LONG is about 7.5 h (probes ≈ 70 min, streams ≈ 6 h 20 min), run as one family per invocation. A run's step lateness (start minus scheduled time) is recorded and reported. It does not invalidate a probe, because predictions use the executed time.

## 6. What counts as a change

### 6.1 Comparison level (E-SAME): skew flips

The number of comparison events whose outcome differs between `⌊h + σ⌋` and `⌊h⌋` (§4.3), per site (L44, L84, L280 / L282–L283, L550 / L559, L571, L2741 / L2747, L56, PAUSE_READ via L2765), per `σ`, per family. This is how often the mix is **exercised**; nothing more is claimed from it.

### 6.2 Decision level (both environments): changed decisions

A pair step is a **changed decision** when the twins differ in the decision (`ALLOW` / `SOFT_BLOCK` / `HARD_BLOCK`), or in whether a pre-check `ALLOW` carries re-entry metadata (the Host acts on it), or when one twin throws and the other does not. Recorded separately, as **secondary**:

* same decision but a different `blockLevel`;
* same decision and level, but `retryAfter` differs by more than `⌈|σ|⌉` s;
* a different claim result (L-2).

For each stream: the step and cause of the **first divergence**, the number of changed decisions until the twins' timings diverge, and the sum over all paired steps.

### 6.3 Stream outcomes (both environments)

Per stream, `σ` twin minus `σ = 0` twin:

* S-ATK, S-RA: admitted `recordFailure` calls; refusals on arrival (S-RA: arrived when told and still refused).
* S-BEN: denied visits; whether the stream reached `ALLOW`.

Reported as distributions: share of streams with a non-zero difference, mean and range.

### 6.4 State divergence

At the end of each stream, the twins' stored values are compared field by field (score value, block level and `expiresAt` relative to the twin's own write time, cycle count, pause intervals, budget count, re-entry evidence present). A divergence with no changed decision is reported as latent.

### 6.5 Attribution

Every first divergence (§6.2) is attributed to one or more causes:

* the skew flips of the `σ` twin at that step or earlier in the stream, by site;
* DV-B2 state from an earlier flip;
* a Lua mix (L-1 … L-5) read from differing script replies;
* a split (§4.4).

The attribution rule per cause is fixed in the harness-time note before the recorded run.

## 7. Gates and detectors (registered)

### 7.1 Validity

* **V-1 baseline.** `src/`, `tests/`, `composer.json` byte-identical to `1e951ee`; the runner refuses otherwise.
* **V-2 offset probe.** As phase 1: in E-SAME the median `θ` lies within ± median `rtt` / 2 of 0, before and after each run. The counterfactual (§4.3, §6.1) is reported only for runs that pass V-2.
* **V-PAIR.** The twins of every pair send identical arguments (context, command, policy) at every paired step; checked on the log. Required 100 %.
* **V-SITE.** Every `now()` call site seen in any run is in the registered list:
  * `DecayCalculator.php:44` and `:84`;
  * `BudgetTracker.php:55`;
  * `EvaluationPipeline.php` `:280`, `:531`, `:1315`, `:1672`, `:1675`, `:1693`, `:1776`, `:1780`, `:2161`, `:2324`, `:2340`, `:2740`, `:2765`, and `:867` on the `LOGIN` reference only;
  * plus `CircuitBreaker.php` `:72`, `:131`, `:170`, `:214`, `:235`, `:243`, `:331`, `LocalFallbackLimiter.php` `:145`, `:176` and `AdmissionRuntime.php` `:461`, recorded as caller-only.

  Any other site is reported by row and flags the cell: it would be a comparison not in the inventory. For each comparison site, the operand-recovery rule must succeed on 100 % of events.
* **V-ATTR.** Every first divergence has at least one cause (§6.5). An unexplained divergence is reported by row and invalidates the cell (as phase 1's V-3 did).
* **Exceptions.** Every exception is recorded by class and message.
  * On `LOGIN-NA` and `CYCLE-NA` the bounded snapshot is never reached (§1.2). Any validator exception there, and any exception other than a `RateLimitConcurrencyException` that `limit()` already turns into a result, is reported by row and invalidates the cell.
  * On the `LOGIN` reference, phase 1's exception (A) is expected. The affected step is a changed decision with cause A, reported apart from B–I. After an exception the stream behaves as a Host that refuses the attempt: no `recordFailure`, the next step after 1 s (LT-M-11).

### 7.2 Detectors

* **D-AA.** A/A pairs (both twins `σ = 0`) in every stream cell: **0** changed decisions attributed to anything but a split, and 0 skew flips. Splits are reported with their count.
* **D-LAW-IN.** For each targeted family (P-B, P-E2/E3-LC, P-E2/E3-CY, P-RA) and each `σ` with a 100 % prediction: **100 %** of in-window probes flip as predicted.
* **D-LAW-OUT.** **0 %** of control probes, and of probes whose prediction is 0 %, flip, for every family and `σ`.
* **D-SAT.** P-SAT: **100 / 100** not honoured.
* A detector that fails invalidates its family. Nothing from that family is reported as a result until the cause is found and recorded.

Sub-second `σ` probes whose prediction depends on a floor within 2 ms of a boundary are reported as **ambiguous**, not as pass or fail. The 2 ms is the executor's value, larger than the E-SAME gap measured in phase 1 (REPORT §2.3, p99 under 0.4 ms). Their number is reported.

## 8. Sample sizes and intervals

Every rate is reported with a Wilson 95 % interval. With the N of §5.6:

| N | all positive: lower bound | none: upper bound | at 50 %: half-width |
| --- | --- | --- | --- |
| 20 (P-LONG) | 83.89 % | 16.11 % | ± 20.1 points |
| 100 (probe in-window / control, P-RA) | 96.30 % | 3.70 % | ± 9.6 points |
| 200 (P-I, streams) | 98.12 % | 1.88 % | ± 6.9 points |

* **Small rates.** A stream rate of the size the law predicts for random timing (for example two boundaries × 2 s in 3,600 s ≈ 0.1 %) is below what 200 streams can resolve: 0 / 200 still allows up to 1.88 %. The plan does not try to estimate such rates from streams. The law gives the exposure per boundary exactly (D-LAW). Streams are there to find effects the law does not predict (state divergence, cycle and pause chains, the steered clients), and to size the steered clients, where the predicted rates are near 0 % or 100 %.
* **Why 100 and 200.** 100 per probe cell puts the lower bound of an all-positive result above 96 %. 200 streams keep an all-zero result under 2 %, and the S-ATK cell (≈ 160 requests per stream instance) within one process's capacity at the phase 1 request times (≈ 3 ms per request in E-SAME).

## 9. Outputs

* `investigations/clock-mixes-153/harness/`: the reused and added classes, `run.sh`, `report.php`.
* `investigations/clock-mixes-153/logs/`: full run logs.
* `investigations/clock-mixes-153/expected/`: per-cell JSONL (gzip), `summary.md` produced by `report.php`, `SHA256SUMS`.
* `investigations/clock-mixes-153/REPORT.md`:
  * per comparison: the law as measured, the decisions it changed, the populations and sizes;
  * the Lua mixes as observed;
  * the two environments compared;
  * **no fix recommendation.**

## 10. Questions for the Lead before the harness (LT)

* **LT-M-01 — scope.** Measure B, C, E, F, I and E′ (L2740–L2747: same operands as E, found for this plan). Record G (cooldown `retryAfter`) without targeting it. Inventory H (`RateLimitOperationalReader`) without measuring it. Count the Lua mixes L-1 … L-5 as **observed** (differing replies in streams and probes; P-E and P-I exercise L-1 naturally), with no arm of their own. **Proposal:** as stated. Alternative: a targeted arm for L-1 (a cycle-path block issued in the last `|σ|` seconds of a lifecycle block on the same K4 key), which needs a K4 cycle-path writer on a lifecycle policy and is not constructed here.
* **LT-M-02 — skews.** ±0.25 / 0.5 / 1 / 2 s for every family, plus ±10 s and ±60 s for P-B and P-E (60 s = the L2 duration, D-SAT). 10 and 60 are the executor's values. **Proposal:** as §5.2; the Lead may change them before the harness commit.
* **LT-M-03 — the 24-hour windows (C, F).** **Proposal:** P-LONG, one ≈ 24 h 15 min E-SAME run, no seeding (§5.5). Alternatives: `DERIVED` only, or seeding (not proposed).
* **LT-M-04 — environments.** E-SAME for everything. The counterfactual needs `θ ≈ 0`. E-DOCKER for P-B, P-E2 and P-RA at `σ = ±1 s` with twins only, as a check on Redis 7.0.15. No new image.
* **LT-M-05 — policies.** B–I are measured on two harness policies that mirror `login_protection` without `DISTRIBUTED_ACCOUNT`: `LOGIN-NA` (lifecycle path) and `CYCLE-NA` (cycle path). Without that capability, phase 1's exception (A) cannot mask B–I (§1.2). The unchanged preset runs as a reference in S-BEN and S-RA at ±1 / ±2 s, with A counted separately. Not `otp_protection` (same path as `login_protection`) and not `LoginProtectionAdmissionPolicy` (opt-in). **Proposal:** as stated. Alternative: the preset only, where behind arms at ≥ 1 s report mostly A.
* **LT-M-06 — the Host's re-entry claim in S-RA.** A Host that receives a pre-check `ALLOW` with re-entry metadata (`RateLimitMetadataDTO::$postPunishmentReentry`, L27) consumes it through `claimPostPunishmentReentry` (`PostPunishmentReentryClaimInterface` L21). **Proposal:** S-RA claims immediately after such a pre-check and before its `recordFailure`, which brings C (L280) and L-2 / L-4 (claim and read) into a steered population. The alternative (no claim) leaves L-2 unexercised.
* **LT-M-07 — what counts as a changed decision (§6.2).** **Proposal:** decision enum, re-entry metadata present or not, and exception or not count; level, `retryAfter` and claim result are secondary.
* **LT-M-08 — reuse of the phase 1 harness.** PR #154 is not merged, so `investigations/clock-skew-153/harness/` is not on `main`. **Proposal:** copy the classes of §4.1 into this study's harness unchanged, with their source path and the SHA-256 of `lib.php` at `fe8775c` recorded in the harness commit. This PR then does not depend on #154's merge order, and phase 1's directory stays untouched. Alternative: wait for #154 and `require` its `lib.php`.
* **LT-M-09 — sample sizes.** 100 + 100 pairs per probe cell, 200 pairs per P-I and stream cell, 20 per P-LONG cell (§8). **Proposal:** as stated.
* **LT-M-10 — the 2 ms ambiguity band (§7.2)** for sub-second probe predictions near a floor boundary: executor's value. **Proposal:** as stated.
* **LT-M-11 — the `LOGIN` reference after an exception.** What a Host does with phase 1's exception is the Host's (phase 1 REPORT §7). **Proposal:** the stream treats it as a refused attempt (no `recordFailure`) and continues after 1 s. Alternative: the stream stops at its first exception and reports the step.

## 11. Commit order

1. This plan, alone.
2. After the Lead's rulings on §10: a dated amendment to this file recording them, and nothing else.
3. The harness, with a dated harness-time note in this file (as phase 1 §12), written before any recorded run, including any smoke or dry run disclosed as such.
4. The run: JSONL, logs, `summary.md`, `SHA256SUMS`.
5. REPORT.md.

Each commit alone. The plan is not edited after commit 2 except by a dated note that says what changed and why.

## 12. Lead rulings and Owner authorisation (2026-10-21, recorded before the harness)

Sources:
* the Lead's rulings, [PR #156 comment 6312297146](https://github.com/Maatify/php-rate-limiter/pull/156#issuecomment-6312297146);
* the Lead's harness permission, [comment 6314681728](https://github.com/Maatify/php-rate-limiter/pull/156#issuecomment-6314681728);
* the Owner's authorisation of the harness, relayed by the #153 session.

§1–§11 above are kept as written and are read together with this section. Where they differ, this section governs.

**Base.** #154 is squash-merged as `main@39dfbf8`, and this branch is rebased onto it. `src/`, `tests/` and `composer.json` are byte-identical between `1e951ee` and `39dfbf8` (`git diff --stat 1e951ee 39dfbf8 -- src tests composer.json` is empty). So V-1 keeps the baseline `1e951ee3c3b29ecc49ce08ceff809869bccf139d`, and every line citation stays valid.

| LT | Ruling | Effect on the plan |
| --- | --- | --- |
| M-01 | As proposed, **plus** | B, C, E, F, I, E′ measured; G recorded; H inventoried; L-1 … L-5 observed with no arm of their own. **Added:** if L-1 appears in P-E or P-I with a non-zero count, REPORT gives the count and the context of each occurrence, so a targeted arm can be decided later. |
| M-02 | As §5.2 | ±0.25 / 0.5 / 1 / 2 s for every family; ±10 / ±60 s for P-B and P-E; 60 s accepted as the saturation test. |
| M-03 | **`DERIVED` now; P-LONG deferred** | C, F and L6 are reported as `DERIVED`, with the explicit derivation below. P-LONG stays registered (§5.5) as an optional arm, to be run only if the Owner chooses phase 2 option (b), because option (a) removes the comparison itself. |
| M-04 | As proposed | E-SAME for everything; E-DOCKER for P-B, P-E2 and P-RA at `σ = ±1 s`, twins only; no new image; the environments are never pooled. |
| M-05 | As proposed, **plus** | `LOGIN-NA`, `CYCLE-NA`, and the preset as a reference at ±1 / ±2 s with A counted separately. **Added:** REPORT states that the opt-in variant (`LoginProtectionAdmissionPolicy`) is not measured here, and why. |
| M-06 | As proposed | S-RA claims right after a pre-check `ALLOW` carrying re-entry metadata, and before its `recordFailure`. |
| M-07 | As proposed | Primary: the decision, re-entry metadata present or not, and an exception. Secondary, reported: level, `retryAfter`, claim result. |
| M-08 | **The alternative: wait for #154, then `require` its `lib.php`** | No class is copied. The harness `require`s `investigations/clock-skew-153/harness/lib.php` from `main` as it is. The harness commit records its blob SHA: `git rev-parse main:investigations/clock-skew-153/harness/lib.php` = `6c8297a093ea413c0c230e4a2a7c227c33ba782f`. Where §4.1 / §4.2 need behaviour that `lib.php` does not have (one reading per `now()`, operands, more scripts' replies), the harness adds its own classes beside it and changes nothing in phase 1's directory. |
| M-09 | As proposed | 100 + 100, 200, 20, with Wilson intervals. |
| M-10 | As proposed, **plus** | **Added:** REPORT gives, per cell, the number of probes that fell in the 2 ms band. |
| M-11 | As proposed, **plus** | **Added:** REPORT gives the number of exceptions per stream. |

### 12.1 The `DERIVED` derivations for C, F and L6 (LT-M-03)

Notation as §1. Every quantity is an integer second, and `σ` is constant over the life of the state.

**C (re-entry, `EvaluationPipeline` L280–L283).**

* The store returns evidence at Redis time `R` only if `E_s > ⌊R⌋` and no L2+ block is active by Redis time (`LIFECYCLE_READ` L403, L419). Here `E_s` = the K4 generation-bound score's `expiresAt` = `validUntil` (`HARD_BLOCK` L1197); it is set once at the generation's first write (`LIFECYCLE_MUTATE` L276–L278) and carried unchanged (L319–L325).
* PHP then serves re-entry iff `E_s > ⌊R + σ⌋`.
* `served_σ(R) = served_0(R) ∧ [E_s > ⌊R + σ⌋]`.
* Behind (`σ = −s`): `⌊R − s⌋ ≤ ⌊R⌋ < E_s` whenever `served_0(R)`, so `served_σ = served_0` at every `R`. **No change.**
* Ahead (`σ = +a`): the outcomes differ iff `E_s > ⌊R⌋ ∧ E_s ≤ ⌊R + a⌋ ⟺ R ∈ [E_s − a, E_s)`, by the identity of §1 (and only while `served_0` holds).
* **Exposure:** `a` seconds once per K4 generation, at 86,400 s after its first write.
* **Changed decision there:** re-entry not served, so the K4 score is not zeroed (L288), and a pre-check `ALLOW` carries no re-entry metadata (L502–L512).
* Share of a uniformly timed pre-check inside the evidence's life: at most `a` / 86,400 = 1.16 × 10⁻⁵ per second of skew.

**F (budget, `BudgetTracker` L54–L56).**

* The store returns the epoch only if `end > ⌊R⌋`, where `end = epochStart + 86,400` (`BUDGET_GET` L643–L645); `epochStart` is a Redis second (`BUDGET_INCREMENT` L613).
* PHP: `active_σ(R) = [count ≥ limit] ∧ [end > ⌊R + σ⌋]`.
* Behind: no change, by the same argument as C.
* Ahead: differs iff `R ∈ [end − a, end)`.
* **Exposure:** `a` seconds per epoch whose count reached the limit.
* **Changed decision there:** budget not active (L2226–L2229), so no budget cooldown path (L2231–).

**L6 (block of 86,400 s, `PenaltyLadder` L17).** DV-E1 and DV-E2 hold for any `D`, since `D` enters only as the additive constant in `E`:

* lifecycle-written: ahead, not honoured in `[E − a, E)`; behind, no change;
* cycle-written: behind, deleted `⌊R0⌋ − ⌊R0 − s⌋` seconds early; ahead (whole seconds), no change.

The laws are measured at `D` = 60 and 300 (P-E2, P-E3). The L6 (and L5, 21,600 s) instances are `DERIVED` from them. Exposure share: `|σ|` / `D` = 2.3 × 10⁻⁵ per second of skew at L6.

Each of these three is reported in REPORT under `DERIVED`, never with a measured interval.

## 13. Harness-time note (2026-10-21, before the recorded run)

Written after the harness was built and **before** any recorded run. Each item says what changed against §1–§12 and why. No registered value changes: N, skews, policies, families, detectors and thresholds stay as registered.

1. **Phase 1's `lib.php` is required as it is** (LT-M-08).
   * Blob `6c8297a093ea413c0c230e4a2a7c227c33ba782f` at `main@39dfbf8`, recorded in the harness commit `0c09d0b`; the runner logs the blob it loads.
   * Used from it: `EventLog`, `RedisTime`, `scriptNames()`, `dt()`, `context()`, `ip()`, `baseConfig()`, `writeRow()`.
   * Added beside it in `harness/mixlib.php`:
     * `OffsetClock`: one host reading per `now()`, with the call site and the scalar arguments of the calling frame;
     * `MixExecutor`: every `EVAL` with `KEYS`, `ARGV`, reply, the writer clock of each block key and pause member, the read-only pre-reads for L-1 … L-5 and the `PAUSE_READ` rerun;
     * `LoginNaPolicy`, `CycleNaPolicy`, `Twin`, `comparisons()`.
   * Nothing in phase 1's directory changed, and no class was copied.
2. **Smoke and dry runs, disclosed.** None of their counts is a result; none of their outputs is committed.
   * **Scratch cells.** Before the runner existed, single cells ran on a throwaway Redis 8.10.0 (scratch directory) with 4–12 pairs:
     * P-B, P-E2, P-E3, P-SAT, P-I, P-RA1, P-RA2;
     * S-ATK, S-BEN and S-RA with a 180 s duration cap.

     P-B, P-I and P-RA1 were stopped before their probes, when the scratch server was shut down.
   * **`run.sh` dry runs at 5 % scale.** Run with the dry-run controls `MIX_SCALE`, `MIX_GROUPS`, `MIX_OUT` and `MIX_SMOKE_CAP`; the scale and cap are written into every cell's meta, and `report.php` refuses such a cell.
     * E-SAME: `probes-a` and `probes-b`, 101 cells;
     * E-SAME: `streams-atk` and `streams-ben-ra`, 64 cells, streams capped at 120 s;
     * E-DOCKER: 12 cells.
   * **What they showed:** the wiring and the setups of §5.3. They also exposed five defects, all fixed before the harness commit `0c09d0b`:
     1. the L1780 caller reading was looked up as the event just before L84, but `currentBlockLevel()` (L2740) runs between them; it is now the latest L1780 reading (item 4);
     2. the P-RA2 observable now uses the flip of item 3 (item 6);
     3. the detectors are now reported per environment;
     4. every stream of a cell started at the cell's sub-second phase, which split the twins systematically when that phase was near a second boundary (dry P-B at −60 s: 6 / 10 setups split); each stream now starts with a seeded sub-second jitter (item 7);
     5. P-RA2's ambiguity rule now flags an edge lying between the predicted and the actual return, instead of any lateness above 50 ms (item 6).
   * After those fixes no detector failed in the dry runs. No dry-run count is reported anywhere as a result.
3. **The flip, made precise (§4.3, §6.1).**
   * A comparison event flips when its stored operand's **writer clock** differs from the clock the comparison reads, and the outcome with the reading clock (`⌊h + σ⌋` for PHP and for a cycle-path `now`, `⌊h⌋` for Redis `TIME`) differs from the outcome with the writer's clock at the same instant.
   * Writer clocks: `BLOCK_SET` and the lifecycle `HARD_BLOCK` write Redis time; the cycle `HARD_BLOCK` writes caller time.
   * B, C and F always read a Redis-written operand, so their flip is `outcome(⌊h + σ⌋) ≠ outcome(⌊h⌋)`.
   * E and E′ use the block's writer: a cycle-written block read by PHP is consistent, and its mix is counted at L-3 instead.
   * I is the `PAUSE_READ` rerun at `⌊h⌋`; it flips when the elapsed paused seconds differ. It is reported with the interval writers, and with whether `from` came from the caller (L1776, the `retryAfter` path, secondary).
   * `B84` (the L84 `retryAfter`) is secondary. When its `lastUpdateTimestamp` is the L1780 caller reading, the counterfactual uses that reading's `⌊h⌋`.
4. **Operand recovery (§4.2).**

   | Site | Operand recovered from |
   | --- | --- |
   | L44, L84 | the calling frame's arguments; for L84 also the latest L1780 reading, which L1778 evaluates before `currentBlockLevel()` (L2740) |
   | L280 | the `LIFECYCLE_READ` reply immediately before |
   | L531 | the `BLOCK_GET` replies that follow, up to the next other command |
   | L2740 | the `BLOCK_GET` reply immediately before |
   | L55 | the `BUDGET_GET` reply immediately before |
   | L2765 | the `PAUSE_READ` that follows, with its rerun |

5. **Twins** are labelled `S` (carries `σ`) and `Z` (`σ = 0`), with namespaces `m153-<run>-<cell>-S|Z` (`+` written as `p`).
6. **The law as evaluated by `report.php` (D-LAW).** Host times are moved to Redis time with the `θ` that `run.php` measures at the start of each cell (median of 51 `TIME` round trips).
   * **P-B.** The `S` twin reaches the 6 → 4 step when `h + θ ≥ uS + 1,200 − σ`; `Z` when `h + θ ≥ u0 + 1,200`. Observed: the K4 (`account`-scope) decay amounts at L44 differ.
   * **P-E2, P-E3, P-SAT.** Honoured at `h` iff `h < min(phys, E, E − σ)`, where `phys` = the `HARD_BLOCK` midpoint + `θ` + `D`. Observed: the K4 block honoured at L550 differs between the twins.
   * **P-RA1.** Predicted refused iff `⌊C⌋ < u′` at the failure (the L1780 reading against the `LIFECYCLE_MUTATE` `updatedAt`). Observed: the `S` twin's decision on return is not `ALLOW`.
   * **P-RA2.** Predicted landed iff the K4 block is Redis-written and the predicted return (poll receipt + its `retryAfter`) lies in `[E − σ, min(E, phys))`. Observed: the L550 event for the K4 block flips (item 3) at the `S` twin's return. A caller-written block cannot flip at L550 by item 3; its mix is L-3. This matches §5.3's "CY: 0 %".
   * **Exclusions and ambiguity.**
     * Setups that did not produce the registered state are excluded (§5.3): P-B K4 ≠ 6, or `uS ≠ u0`; P-E a missing or wrong-level block.
     * A setup **split** (the twins' block written in different Redis seconds) is counted in its own column.
     * Ambiguous: within 2 ms of an edge (`phys` also ± half the `HARD_BLOCK` round trip). For P-RA2, ambiguous when an edge lies between the predicted and the actual return (± the same bands), and only where a window exists.
7. **Placement.**
   * Probes are placed in Redis time (`θ`).
   * Setups are spread over 300 s per cell, not ≈ 60 s, to bound the load with many cells running at once.
   * Each stream adds a sub-second jitter (`U[0, 1)`, from its own seeded PRNG) to its start. All later times of the stream keep that phase.
   * Bands: `j` even → in-window; `j ≡ 1 (mod 4)` → before; `j ≡ 3 (mod 4)` → after.
   * An in-window band shorter than 1 ms (for example LC behind, or CY behind at a sub-second `σ`, §1.2) is placed in `[lo − |σ|, lo)` and named `in-empty`; its prediction is evaluated per probe like any other.
   * P-SAT probes are uniform over the block's life. P-I probes are uniform in `[cycle-2 block write, pause finish + 1,200 s]`.
8. **Order and concurrency (§5.6).** Each cell is one process. The cells of a group run concurrently, and the groups run in a fixed order:
   1. `probes-a`: P-E2, P-E3, P-SAT, P-RA2;
   2. `probes-b`: P-B, P-I, P-RA1;
   3. `streams-atk`;
   4. `streams-ben-ra`: S-BEN and S-RA with the `LOGIN` reference.

   This replaces "one family per invocation" in §5.6 to fit the wall time. Load lateness is recorded per step (`due`, `h0`); predictions use executed times.
9. **Streams.**
   * In S-BEN and S-ATK, a failure is recorded only after an `ALLOW` pre-check, because the Host refuses the attempt otherwise.
   * S-RA waits the `retryAfter` of its last response: that of its `recordFailure` if it made one, else that of its pre-check. It waits 1 s when that value is 0 or absent, or after an exception (LT-M-11).
   * S-RA twins are paired by attempt (the n-th pre-check), not by step index.
   * Per-stream PRNG: `Mt19937` seeded with `crc32(family | policy | stream)`, so the schedules are identical for every `σ` and for both twins.
10. **Split (§4.4) as computed.** The twins' Redis-written seconds in the step differ, or the union of their spans (`θ`-adjusted) crosses a second boundary.
11. **State divergence (§6.4).** Compared fields: `value`, `level`, `count`, `generation`, `reentryId` present, and `ZCARD` of the cycle and pause sets, per key written during the stream. Time fields are left out.
12. **Redis `maxclients` 4,096** in E-SAME. A cell holds seven connections, and up to 65 cells run at once (`probes-a`).
