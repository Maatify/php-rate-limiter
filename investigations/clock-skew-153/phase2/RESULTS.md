# #153 phase 2 — one time authority: RESULTS

**Status: self-review only (executor), Draft.** Two items block Ready under the Owner's rule (every confirmed finding blocks):

1. The #156 probe condition of §4.6 (registered as "0 flips at every σ", applied as "0 primary decision changes between the twins") **FAILS** in 29 E-SAME cells and 4 E-DOCKER cells (§4).
   * Every change is predicted by whole-second quantization of each twin's own clock, and none is unexplained.
   * The cause is not caller-versus-Redis skew. Ruling requested: **LT-P2-13** (§9).
2. **F-P2-03** (open): the inline watch-rotation script does not apply LT-P2-03 to its current window, contrary to FAILURE_SEMANTICS §2.5 (§8). Ruling requested: **LT-P2-14**.

Everything else registered passes:
* #154 in full, P-TAIL and P-HT, RED-1c, and the two-clock controls;
* §4.5 S0 non-regression;
* the suites in both modes and PHPStan in both modes;
* CVH twice, NM-6, and RED-1…10.

## 1. Tested tree

* **Code tested by the suites, PHPStan and CVH:** `src/`, `tests/` and `composer.json` at `0773b8a` (base `main@f5897b0`). The later commits `defdba2` (logs and outputs) and this file touch `investigations/` only.
* **Phase 2 commits, in order:**
  * the plan `68b73df`;
  * Lead / Owner rulings `373ee36`;
  * red tests `7938249`;
  * rulings §10 `c6b0315`;
  * RED-10 `c653e7a`;
  * build-time note §11 `2297c07`;
  * implementation `29d13e7`;
  * declared test edits `b933da6`;
  * comment fix `6dd7eb0`;
  * documents `5dfb3f8`;
  * acceptance harness `5201632`;
  * report addition `1939c09`;
  * acceptance outputs `8a8082a`;
  * F-P2-02 fix `f4aec1c`;
  * §4.5 harness `0773b8a`;
  * §4.5 outputs and logs `defdba2`.
* **The #154 / #156 / two-clock acceptance ran on `5201632`.**
  * `src/` is byte-identical to `0773b8a` (`git diff 5201632 0773b8a -- src` is empty).
  * `tests/` differs only by `f4aec1c`: the held-time `WeakMap` accessor in `tests/Support/RateLimiter/InMemoryRateLimitStore.php` and `tests/Support/Correlation/StatefulInMemoryCorrelationStore.php`.
  * No acceptance harness loads either file (searched: `investigations/clock-skew-153/harness`, `investigations/clock-mixes-153/harness`, `phase2/acceptance`).
* **§4.5 ran on `0773b8a`.**
* **Environment:** PHP 8.5.9, Darwin 27.0.0.
  * E-SAME: Redis 8.10.0 local, `redis-server` started fresh by the runner.
  * E-DOCKER and every suite: Redis 7.0.15 (`docker/redis-integration`, `scripts/ci/run-with-redis-service.sh`).
  * Each dependency mode had a fresh install (`rm -rf vendor composer.lock`, then `composer install` or `composer update --prefer-lowest --prefer-stable`).

## 2. Gates

| Gate | Registered (PLAN §4) | Before (`f5897b0`) | After | Evidence |
| --- | --- | --- | --- | --- |
| RED-1a | 0 / 100 | 100 / 100 validator | 0 / 100 — pass | suite, `CallerTimeAuthorityRedisTest::testFreshPreChecksDoNotThrowWhenTheApplicationIsOneSecondBehindRedis` |
| RED-1b | 0 / 100 | 100 / 100 | 0 / 100 — pass | `…WhenTheCallerReadsTheClockJustBeforeARedisSecondBoundary` |
| RED-1c | 0 / 10,000 | phase 1: 0–2 / 10,000 | 0 / 10,000 per policy (`SYS_F_LOGIN`, `_OTP`, `_CUSTOM`), both environments | acceptance §4 |
| RED-2 (B) | 0 / 100 | 100 / 100 refused again | pass | `…RetryAfterHonouringClientIsAdmittedOnTimeWhenTheApplicationIsBehind` |
| RED-3 (E) | `ALLOW` + re-entry 100 / 100 | 100 / 100 `HARD_BLOCK`, no re-entry | pass | `…PolledReturnAfterALifecycleBlockGetsReentryWhenTheApplicationIsAhead` |
| RED-4 (#155) | 0 / 20 (seeded at 5 sites) | `malformed …` at all 5 | pass; engine-level P-HT 0 / 20 | `…LogicallyExpiredButPhysicallyPresentStateIsRecreatedNotMalformed`; acceptance |
| RED-5 (P-TAIL) | 0 / 60 | validator exceptions | pass; engine-level P-TAIL 0 / 60 | `…PreCheckInTheLastSecondsOfAWindowDoesNotThrowWhenTheApplicationIsAhead`; acceptance |
| RED-6 | no consistency error | guard (passes by design) | pass | `…LifecycleStateWrittenBehindAndUsedAheadRaisesNoConsistencyError` |
| RED-7 (BC) | still loads and runs | guard (passes by design) | pass | `…StoreImplementingOnlyTheExistingContractsStillLoadsAndRuns` |
| RED-8 | one `now` per call | 5–12 Host-clock reads per call | 1 per `limit()` and per claim — pass | `…TheHostClockIsReadOncePerLimitAndPerClaim` |
| RED-9 (added, LT-P2-09) | `retryAfter` = 60 | 62 | 60 — pass | `…SimpleFixedWindowRetryAfterIsTheWindowWhenTheApplicationIsBehind` |
| RED-10 (LT-P2-12) | dump before = after, `expiry not representable` | 8 / 8 red | 8 / 8 pass | `…RedisRejectedExpiryDeletesEverythingTheCallCreated` |
| S0-PARITY §4.2 | existing tests unchanged, or a ruling | — | 1959 tests OK in both modes. The only changed existing tests are those ruled (§5); goldens pass byte-identical | `logs/suite-*.log` |
| §4.3 suites | both modes, full logs | — | normal: PHPUnit 11.5.57, `OK (1959 tests, 26227 assertions)`, 14:55. Lowest: PHPUnit 11.5.50, `OK (1959 tests, 26139 assertions)`, 14:59. No error, warning, risky, skipped or incomplete test | `logs/suite-normal.log`, `logs/suite-lowest.log` |
| §4.3 PHPStan max | normal and lowest | — | 2.3.1 `[OK] No errors`; 2.0.0 `[OK] No errors` (after F-P2-02) | `logs/phpstan-*.log`; first run in `logs/8a8082a/` |
| §4.3 CVH twice | PASS | — | clean run #1 PASS, clean run #2 PASS (verification and cleanup) | `logs/cvh.log` |
| §4.3 NM-6 | admission contracts unchanged | — | `git diff f5897b0 0773b8a` on every `src/` file with "Admission" in its path: 0 lines. `RedisFullCapabilityStore` changes only the layout of its `implements` list there. `AdmissionStoreNm6RedisTest` (unchanged) passes in both suites | git, suites |
| §4.4 detectors | red tests red on `f5897b0` | 12 / 14 cases red (RED-6, RED-7 guards), RED-10 8 / 8 red | — | commits `7938249`, `c653e7a` |
| §4.5 S0 non-regression | equal except defect-attributed | — | **PASS**: 6 cells, 2,400 stream-twins; 0 unattributed differences, 0 exceptions after the fix; 2 differences attributed (S-BEN_LOGIN-NA_AA, pre-fix exceptions) | §6 |
| §4.6 #154 rerun | 0 validator exceptions every cell; P-HT 0 / 20; P-TAIL 0 / 60; D-100 0 / 2,000; V-3 = 0 exceptions, 0 UP / LO | — | **PASS** in every condition, both environments | §3 |
| §4.6 #156 probes | 0 flips at every σ | — | **FAIL** (33 cells): §4 | §4 |
| §4.7 two-clock arm | measured; controls clean | — | controls 0 / 2,420; δ = 1 s measured (§7) | §7 |

**List A** (Redis + `FixedClock` tests that now store the frozen time, PLAN §9.2): all twelve pass in both suites.
* `RedisFullCapabilityStoreIntegrationTest` L2189 changed outcome, as list A anticipated (a finding). It is edited by the Owner's ruling (F-P2-01, §5).
* The other eleven are unchanged:
* `RedisFullCapabilityStoreExtRedisIntegrationTest` L85, L113, L132;
* `RedisFullCapabilityStoreIntegrationTest` L1970, L2143, L2242;
* `RedisHealthStatusReplyRegressionTest` L39, L61;
* `KnownDeviceAllowanceConcurrencyIntegrationTest` L86, L112, L145.

## 3. Acceptance: #154 in full, P-TAIL, P-HT, RED-1c (`phase2/acceptance/`)

* **Mechanism (LT-P2-07).** `run.sh` checks V-1 on the fix head and requires every archived script it calls to equal `main` by blob. The blobs are logged in `logs/E-*.log.gz`:
  * `clock-skew-153/harness/lib.php` `6c8297a…`, `run.php` `353fbf8…`, `host_surface.php` `de8137b…`, `tail.php` `1a0a3a4…`;
  * `clock-mixes-153/harness/mixlib.php` `fdf6ce2…`, `run.php` `0fc389e…`, `offset.php` `e004081…`.
* **PASS conditions** are those of §4.6 and §10, written in the header of `report.php` before the run. The report is `expected/summary.md`. Offsets on E-SAME: median θ +0.04 ms, rtt 0.10–0.12 ms.

| Condition | E-SAME | E-DOCKER |
| --- | --- | --- |
| #154 cells: ≥ 1 validated snapshot reached, 0 validator exceptions, 0 other exceptions (setup included), 0 UP / LO, 0 script errors | 51 / 51 PASS | 26 / 26 PASS |
| D-100 inverted (`ALIGN-BEHIND1_F_LOGIN`) | 0 / 2,000 | 0 / 2,000 |
| RED-1c (`SYS_F_LOGIN`, `SYS_F_OTP`, `SYS_F_CUSTOM`) | 0 / 10,000 each | 0 / 10,000 each |
| P-TAIL (a = 0.5, 1, 2) | 60 targets in window, 0 exceptions, 0 script errors | (E-SAME only, as registered) |
| P-HT | 20 targets in window, 0 exceptions, 0 script errors | (E-SAME only) |

**Read with the table:**
* **Pre-fix values, same cells.** BEHIND 1.00 / 2.00 2,000 / 2,000, D-100 2,000 / 2,000, P-TAIL 60 / 60 validator, P-HT 20 / 20 `malformed bounded metadata`. The parser was checked against the archived pre-fix outputs before the run and reproduces these.
* **P-TAIL / P-HT outcomes are mostly `HARD_BLOCK`** (P-TAIL a = 2: 4 `ALLOW`, 16 `HARD_BLOCK`; the rest `HARD_BLOCK`).
  * The registered condition covers exceptions and script errors only. Pre-fix, the exception came before any decision.
  * The 80 probe accounts share one /24 of the benchmark range, and each was created with `ALLOW`.
  * The decision's cause was not measured in this run. It is reported as not attributed, not as a time effect.
* **Host surface (§7.4).** The archived `host_surface.php` asserts the pre-fix behaviour and stops at its first failure.
  * Its record in both environments: `FAIL … login_protection H-1 returned=100 other=[]`, i.e. `limit()` returned 100 / 100 instead of throwing.
  * `otp_protection` and `custom_distributed` are not reached by that script. Their cells above cover them.
* **V-4′ is not a condition.** After LT-P2-05 every read is `RequestScopedClock.php:40`, the single Host read per call.

## 4. Acceptance: #156 probe groups — FAIL, and what it is

**Registered condition (`report.php`, before the run):**
* 0 exceptions in either twin;
* 0 primary decision changes (decision | re-entry | exception, as #156) between twin S (σ) and twin Z (σ = 0) on every probe step (each band of each stream);
* for P-I, 0 on every paired step.

| Result | E-SAME (101 cells) | E-DOCKER (12 cells) |
| --- | --- | --- |
| Exceptions, either twin | 0 in every cell | 0 in every cell |
| Cells with 0 primary changes | 72 | 8 |
| Failing cells | 29: P-B LOGIN-NA (11 of 12 σ), P-E2 LOGIN-NA (9 of 12), P-E3 LOGIN-NA (9 of 12) | 4: P-B LOGIN-NA ±1, P-E2 LOGIN-NA ±1 |
| Changes per failing cell | fractional σ (±0.25, ±0.5): 50–101 of 200; integer σ (±1, ±2, ±10, ±60): 1–7 of 200 | 3–9 of 200 |
| CYCLE-NA, P-RA1, P-RA2, P-I, P-SAT | 0 changes in every cell | 0 |

**Mechanism (descriptive section added to `report.php` after the run, `1939c09`; it changes no verdict).**
* Each twin stores and judges with its own clock: S at host + σ (`OffsetClock`), Z at host. Times are stored in whole seconds.
* In host time, S's state therefore ends at (S's stored second) − σ, and Z's at (Z's stored second). The ends compared:
  * P-E: the block `E_S − σ` against `E_Z`, from the probe's `block` field;
  * P-B: the decay step `uS + 1200 − σ` against `u0 + 1200`.
* Prediction: a twin refuses while its request starts before its own end.
* Over every P-B / P-E probe step in both environments, Δ = end_S − end_Z takes only the values 0, ±frac(σ), ±(1 − frac(σ)) and ±1, with max |Δ| = 1.0 s (8,000 steps). ±1 occurs at integer σ, when the twins' writes, about 20 ms apart, straddle a second.
* **LOGIN-NA:** every observed change is predicted (changed and not predicted: **0** in every cell), and every predicted change is observed (same and predicted: 0). The remainder are within ± 2 ms of an end (ambiguous, listed per cell).
* **CYCLE-NA:** the same prediction holds (42–98 predicted per cell at fractional σ). The block hands over to the cycle pause, so both twins stay refused and the difference appears in `lv` / `retryAfter` only (all predicted steps). The primary decision does not change.

**Worked example:**
* Cell `P-E3_LOGIN-NA_+0.50`, stream 0, band `in`.
* Writes:
  * S wrote the L3 block at host 1792614628.929, its own time 629.429, so `E_S` = 929.
  * Z wrote at host 1792614628.913, its own second 628, so `E_Z` = 928.
* Probe at host 928.216:
  * S reads 928.716 < 929 and refuses.
  * Z reads 928.216 ≥ 928 and re-admits.
* Both block lengths in real time are within (D − 1, D] of their own clock (S 299.57 s, Z 299.09 s).

**Before and after, the failing families.** E-SAME. Each entry is probe steps with a primary change / probe steps with an `lv`- or `retryAfter`-only difference, of 200. Pre-fix = the archived #156 outputs read by the same `report.php`.

| σ | P-B LOGIN-NA before → after | P-E2 LOGIN-NA before → after | P-E3 LOGIN-NA before → after |
| --- | --- | --- | --- |
| −60 | 100/1 → 0/0 | 1/0 → 0/0 | 0/2 → 0/0 |
| −10 | 100/0 → 2/0 | 2/0 → 0/0 | 2/2 → 0/0 |
| −2 | 101/0 → 2/0 | 4/0 → 2/0 | 2/1 → 0/0 |
| −1 | 98/0 → 7/0 | 1/0 → 0/0 | 5/1 → 1/0 |
| −0.5 | 95/0 → 97/0 | 2/0 → 50/0 | 0/0 → 59/0 |
| −0.25 | 92/0 → 98/0 | 4/0 → 52/0 | 4/0 → 57/0 |
| +0.25 | 93/0 → 92/0 | 3/90 → 95/0 | 3/98 → 100/0 |
| +0.5 | 94/0 → 96/0 | 2/94 → 95/0 | 1/100 → 101/0 |
| +1 | 96/0 → 4/0 | 1/98 → 3/0 | 2/98 → 2/0 |
| +2 | 98/0 → 2/0 | 2/95 → 2/0 | 0/100 → 4/0 |
| +10 | 100/0 → 1/0 | 1/100 → 3/0 | 0/100 → 1/0 |
| +60 | 100/0 → 1/0 | 1/149 → 2/0 | 0/100 → 3/0 |

**Reading.**
* **P-B.** The skew law is gone: 92–101 / 200 at every σ before, and 1–7 at integer σ after.
* **Fractional σ** (P-B, and P-E on LOGIN-NA): 50–101 / 200 primary changes remain. They are not a function of the Host–Redis offset. They come from the phase of each Host's second grid: two Hosts whose clocks differ by a fraction of a second quantize the same moment into different seconds, so their own states end up to 1 s apart (|Δ| ≤ 1 s, 0 unexplained).
* **P-E on LOGIN-NA, said plainly.**
  * Pre-fix, both twins' blocks were judged on Redis's single second grid. The twins disagreed on `retryAfter` (90–149 / 200 at σ > 0) but rarely on the decision (0–5).
  * After the fix, `retryAfter` agrees (0 secondary differences). At fractional σ the decision differs within that ≤ 1 s band (50–101 / 200).
  * At integer σ, P-E primary changes are 0–5 before and 0–4 after.
* This is inherent to whole-second timestamps (DEC-017). It still fails the condition as registered. The executor does not reinterpret it. It is put to the Lead as **LT-P2-13**.

**Logs.** `mixlib.php:228` warned "Undefined array key \"h\"" 1,207,232 times on E-SAME and 100,884 times on E-DOCKER (each warning is printed twice). Cause:
* #156's descriptive PAUSE_READ rerun (its PLAN §4.3, for family I) expects a `now()` event immediately before each PAUSE_READ.
* After LT-P2-05 there is none: the clock is read once, at the start of the call.
* The rerun is read-only on a side connection (`PAUSE_READ` reads only), and its output is not used by any condition here.

## 5. Existing tests modified by ruling (precedent LT-22 / LT-30)

Rulings: PR #158 comment 6323629474 (lists B, C) and the Owner's ruling on lists D / F-P2-01 (PR #158). All edits are in commit `b933da6`; the complete before / after diff is Appendix A. No existing test was deleted.

| Test (file) | Line at `f5897b0` | List / ruling | Before → after, and why |
| --- | --- | --- | --- |
| `testLifecyclePublicationRejectsPhysicallyInconsistentScoreWithoutPartialWrites` (`RedisFullCapabilityStoreIntegrationTest`) | L1465 | B, LT-P2-02 | "fails" → "unapplied at the logical deadline", through `blockWithPunishmentLifecycleTrackingAt(…, $now + 1)`, deterministic. The removed `HARD_BLOCK` invariant was the only rejection |
| `testGeneratedPhysicalExpiryCorruptionFailsReadMutateClaimAndPublication` | L1477 | B, LT-P2-02 | four "fails" → read `null`, mutate / publication unapplied, claim `false`, each through its `*At` method at the deadline ms, deterministic |
| `testCurrentAbsentPublicationRunsFullStructuralValidationOnPreviousEvidenceAndPhysicalExpiry` (third case) | L1660 | B, LT-P2-02 | "fails" → `assertFalse(…->applied)`; the other two cases are unchanged |
| `testPreviousClaimIsReadOnlyAndWritesOnlyCurrentMarker` | L1748 | B, LT-P2-02 | `TTL ≤ 600` → `PTTL ≤ 601_000`, with `claimPostPunishmentReentryAt(…, $now * 1000)`. The bound is exact: the evidence's logical remainder at that caller time. Deterministic (Owner condition 3) |
| `testLifecycleMutationPreservesPhysicalAndAuthoritativeExpiryAcrossAllK4Boundaries` | L1825 | B, LT-P2-02 | four `≤ sourcePTTL + 25` → `≤ (expiresAt − now) · 1000 + PHYSICAL_LIFE_TOLERANCE_MS`, with the new constant 1,000 ms: the store's fallback clock (host) and the test's `redisNow()` (Redis) are two clocks |
| `testMalformedStoredGenerationFailsAcrossReadMutateClaimAndPublication` | L1530 | B, LT-P2-11 | **no edit**; passes unchanged |
| `testGenerationBoundScoreCreationEnforcesAtomicInScriptTtlGuard` docblock | L241–L253 | C | text only |
| `testPunishmentLifecycleRedisTimeDerivedBoundaryFailsBeforeAnyRedisMutation` | L443 | C | renamed `…CallerTimeDerived…`, and docblock |
| `testLifecycleCyclesUseRedisTimeExactlyOnceAndPauseIsNotRenewed` | L1103 | C | renamed `…UseTheCallerTimeExactlyOnce…` |
| `RedisSimpleFixedWindowIntegrationTest` class docblock | L24–L27 | C | text only |
| `KnownDeviceMixedVersionIntegrationTest` class docblock L34, `RedisTimeClock` docblock L228 | — | C (same kind, found while editing; declared in `b933da6`) | text only |
| `RateLimiterBuilderTest::testCustomClockIsSharedByAllClockDependentGraphNodes`, `testDefaultClockIsUtcSystemClock` | L87, L109 | D (Owner) | `assertSame($clock, node.clock)` ×5 → the five nodes hold one `RequestScopedClock` whose private `hostClock` is the configured clock (by reflection, no new accessor). **Also:** adding the `RequestScopedClock` import made the style fixer re-sort two existing `use` lines (`DeviceIdentityResolver`, `DeviceIdentityResolverInterface`); no other change |
| `testPublicBuilderPersistsHardBlockCycleAndPauseThroughLimitWorkflow` | L2189 | D, **F-P2-01** (Owner) | `+61 seconds` → `+301 seconds`. Pre-fix the test passed only because real Redis time (2026) expired a block dated in the `FixedClock`'s past (2025). Under one authority the first L3 block (300 s) holds until the clock passes it |

## 6. Gate §4.5 — S0 non-regression (`acceptance/s0_parity.*`, `expected/s0-summary.md`)

**Disclosure:** the first runner `run.sh` omitted this registered gate. The runner and the comparator were committed (`0773b8a`) before this run, with the rule in the comparator's header:
* per stream and twin, the sequence of (op, primary);
* a difference is attributed to the defect when the pre-fix sequence has an exception at or before the first differing step;
* PASS = 0 unattributed differences and 0 exceptions after the fix.

The comparator was checked against the pre-fix archive compared with itself: every sequence is equal.

The six cells registered for S-BEN and S-RA at σ = AA by #156's `run.sh` ran on E-SAME, 2026-10-21 22:41–23:45 UTC, concurrently. In #156 they ran within the 46-cell `streams-ben-ra` group.

| Cell | stream-twins | equal | differ, attributed | differ, unattributed | `lv` / `retryAfter` only | exceptions before (S, Z) | after |
| --- | ---: | ---: | ---: | ---: | ---: | --- | ---: |
| S-BEN_LOGIN-NA_AA | 400 | 398 | 2 | 0 | 15 | 1, 1 | 0 |
| S-RA_LOGIN-NA_AA | 400 | 400 | 0 | 0 | 1 | 0, 0 | 0 |
| S-BEN_CYCLE-NA_AA | 400 | 400 | 0 | 0 | 139 | 0, 0 | 0 |
| S-RA_CYCLE-NA_AA | 400 | 400 | 0 | 0 | 0 | 0, 0 | 0 |
| S-BEN_LOGIN_AA | 400 | 400 | 0 | 0 | 127 | 0, 0 | 0 |
| S-RA_LOGIN_AA | 400 | 400 | 0 | 0 | 0 | 0, 0 | 0 |

**Gate §4.5: PASS.** The `lv` / `retryAfter`-only column is descriptive and not gated. The two runs are separate in time, so a remaining wait computed from a stored second differs run to run by the second's phase. Not analysed further.

## 7. Two-clock arm (§4.7, LT-P2-04) — the measured residual of the Host duty

One Redis (E-SAME); the writer Host is ahead by δ = 1 s, the reader is at 0; same-clock control δ = 0 (`two_clock.php`, `expected/E-SAME/two-clock.jsonl.gz`).

| Cell | δ = 1 s | control δ = 0 |
| --- | --- | --- |
| TC-A1: reader's immediate pre-check after the writer created the device window | 1,993 / 2,000 validator exceptions (99.7 % [99.3, 99.8]) | 0 / 2,000 |
| TC-A2: reader back to back for 1.5 s after creation, 20 accounts | 20 / 20 accounts throw; 5,936 / 12,468 requests; last exception after creation: min 337 ms, p50 362 ms, max 860 ms | 0 / 10,859 |
| TC-B: decay (mirror policy); client waits the writer's `retryAfter`, reader decides | 199 / 200 refused (`SOFT_BLOCK`) | 0 / 200 (`ALLOW`) |
| TC-E: L2 block; client waits the writer's `retryAfter`, reader decides | 200 / 200 `HARD_BLOCK` | 0 / 200 (`ALLOW` with re-entry) |

**Agreement with FAILURE_SEMANTICS §2.5:**
* The reader throws until its clock reaches the creation second: here at most 0.86 s after creation, bound δ.
* A stored boundary moves by δ in the reader's time: a client honouring the writer's `retryAfter` is refused once more at the reader.

The Host duty, "a fleet that shares one Redis shares one time source", is the documented control. There is no tolerance in the validator (LT-P2-04).

## 8. Findings

| ID | Finding | State |
| --- | --- | --- |
| F-P2-01 | L2189 passed pre-fix only through Redis time expiring a block dated in the `FixedClock`'s past | ruled (Owner), edited in `b933da6` |
| F-P2-02 | My held-time `WeakMap` in the two in-memory support stores wrote static-property offsets that PHPStan 2.0.0 rejects (8 × `assign.propertyType`; 2.3.1 accepts). The lowest CI job would have failed | **fixed** in `f4aec1c` (typed accessor, local writes, no behaviour change); evidence `logs/8a8082a/phpstan-lowest.log`; suites, PHPStan and CVH rerun on `0773b8a` |
| F-P2-03 | The inline watch-rotation script (`incrementWatchFlagAcrossRotationAt`, PLAN row 12) checks a present current window's metadata, but not its logical `expiresAt` against the caller's time: it keeps counting. `WATCH_INCREMENT` and every other window, flag and bridge reset at the logical deadline (LT-P2-03), and FAILURE_SEMANTICS §2.5 states the reset for every watch flag | **open**; LT-P2-14 |
| A-P2-01 | The #156 probe condition fails by whole-second quantization of each twin's clock (§4) | **open**; LT-P2-13 |

**F-P2-03 evidence** (scratch probe on a temporary `redis-server` 8.10.0, disclosed; not committed):
* Setup: TTL 30 s, the second call at caller time T + 30 while both keys are still physically alive (a Host ahead of Redis).
* `incrementWatchFlagAt` returns `[1, 1]`: it resets.
* `incrementWatchFlagAcrossRotationAt` returns `[1, 2]`: it continues.
* Pre-fix the same script had no such check either. There, logical and physical life were both Redis time and coincided. After the fix they differ by the Host–Redis offset, so the window counts on for up to that offset past its logical end.
* The PLAN §9.3 correction addressed only row 12's malformed checks. The current-window reset was not raised then.

## 9. Questions for the Lead (LT)

* **LT-P2-13 — the #156 probe condition after one authority.**
  * Options:
    * (a) Rule that the condition is met when every remaining twin difference is explained by whole-second quantization of each twin's own clock, with the law and bound of §4 (|Δ| ≤ 1 s; 0 unexplained). FAILURE_SEMANTICS §2.5 then adds one sentence on the one-second resolution between Hosts.
    * (b) Keep the condition literal: whole-second state would need millisecond deadlines for blocks and decay steps. That is a state-shape change, which §5 of the plan places out of scope.
  * **Executor's recommendation:** (a). Its evidence is in §4, and the condition is reported as FAIL until ruled.
* **LT-P2-14 — F-P2-03.**
  * Options:
    * (a) Apply LT-P2-03 to row 12's current window: if present and `expiresAt` ≤ caller now, delete current value and metadata and start a new window, as `WATCH_INCREMENT` does. A red test first, then the fix, each commit alone.
    * (b) Keep the code and narrow FAILURE_SEMANTICS §2.5.
  * **Executor's recommendation:** (a).

## 10. New shapes and DEC-008 (BC1 no signature change, BC2 no implementer break, BC3 defaults preserve behaviour, BC4 additive documentation; defined in `investigations/k2-identity-admission-97/5b2.1/PLAN.md` L33)

| Shape | Kind | BC1 | BC2 | BC3 | BC4 |
| --- | --- | --- | --- | --- | --- |
| `CallerTimeRateLimitStoreInterface` (`incrementAt`, `setAt`, `blockAt`, `checkBlockAt`, `getBudgetAt`, `incrementBudgetAt`; `int $now`) | new public interface, extends `RateLimitStoreInterface` | no existing signature changes | existing implementers untouched (opt-in) | a store that does not implement it takes the existing method (`CallerTimeStore`) | USAGE_GUIDE Integration Boundaries, DEC-016 note |
| `CallerTimeBudgetSeedStoreInterface` (`incrementBudgetWithSeedAt`) | new, extends `BudgetSeedStoreInterface`, `CallerTimeRateLimitStoreInterface` | yes | yes | yes | yes |
| `CallerTimeCorrelationStoreInterface` (`addDistinctAt`, `incrementWatchFlagAt`) | new, extends `CorrelationStoreInterface` | yes | yes | yes | yes |
| `CallerTimeCorrelationRotationStoreInterface` (`addDistinctAcrossRotationAt` ms, `incrementWatchFlagAcrossRotationAt`) | new, extends `CorrelationRotationStoreInterface` | yes | yes | yes | yes |
| `CallerTimeBoundedCorrelationStoreInterface` (`addDistinctBoundedAt`) | new, extends `BoundedCorrelationStoreInterface` | yes | yes | yes | yes |
| `CallerTimeBoundedCorrelationRotationStoreInterface` (`addDistinctBoundedAcrossRotationAt` ms) | new, extends `BoundedCorrelationRotationStoreInterface` | yes | yes | yes | yes |
| `CallerTimeBoundedCorrelationSnapshotStoreInterface` (`addDistinctBoundedWithSnapshotAt`) | new, extends `BoundedCorrelationSnapshotStoreInterface` | yes | yes | yes | yes |
| `CallerTimeBoundedCorrelationSnapshotRotationStoreInterface` (`…WithSnapshotAcrossRotationAt` ms) | new, extends `BoundedCorrelationSnapshotRotationStoreInterface` | yes | yes | yes | yes |
| `CallerTimePunishmentLifecycleStoreInterface` (`readGenerationBoundScoreStateAt` ms, `mutateGenerationBoundScoreAt` ms, `blockWithPunishmentLifecycleTrackingAt` s, `claimPostPunishmentReentryAt` ms) | new, extends `PunishmentLifecycleStoreInterface`, `CallerTimeRateLimitStoreInterface` | yes | yes | yes | yes |
| `RequestScopedClock` | new `final` class, `@internal` (built by `RateLimiterBuilder`; not an extension point) | no public signature changes; `withClock()` unchanged | n/a | the Host clock is read once per call, not 5–12 times (LT-P2-05, the fix itself); a Host clock with side effects per read sees fewer reads | FAILURE_SEMANTICS §2.5 |
| `CallerTimeStore` | new `final` class of static dispatchers, `@internal` | none | n/a | `instanceof` → `*At`, else the existing method | — |
| `RedisFullCapabilityStore::__construct(…, ?ClockInterface $clock = null)` | trailing optional parameter, `final` class | additive | `final`: no subclass | **changed by decision (LT-P2-06):** a direct call of an existing method uses `SystemClock(UTC)`, no longer Redis `TIME` | DEC-016 note, USAGE_GUIDE |
| `AntiEquilibriumGate::__construct(…, ?ClockInterface $clock = null)`, `EphemeralBucket::__construct(…, ?ClockInterface $clock = null)` | trailing optional parameter, non-final classes | additive (PHP does not check constructor compatibility) | subclasses unaffected | `null` → the existing store method, unchanged | docblock |

**Special-purpose delegating test doubles on the old contracts (Owner condition 2)** take the fallback path (`CallerTimeStore` → existing method). Found by reflection over `tests/`:
* support classes:
  * `BaseOnlyInMemoryRateLimitStore`, `HardBlockCycleOnlyInMemoryRateLimitStore`, `InterleavedSeedRaceRateLimitStore`, `PartialFailureRateLimitStore`, `ThrowingRateLimitStore`;
  * `BoundedOnlyInMemoryCorrelationStore`, `NullCorrelationStore`, `SeparateSnapshotAndRotationInMemoryCorrelationStore`;
  * `FaultInjectingAdmissionStore`, `MergedOperationsStore`;
  * `LegacySignatureFullCapabilityStore`;
* classes declared in test files: `RecordingBaseOnlyCorrelationStore` (`CredentialSprayRotationTest`), `BoundedOnlyCorrelationStore` (`Ipv6AdaptiveAggregationTest`);
* anonymous classes:
  * `CorrelationStoreInterface` in `KeyDerivationInputValidationTest` L401, `RateLimiterBuilderCapabilityPreflightTest` L62 and `EphemeralBucketBoundedTest` L92;
  * three anonymous subclasses of `InMemoryRateLimitStore` in `PostPunishmentReentryLifecycleTest` (L354, L414, L444), which inherit the caller-time methods and keep their overrides (the `*At` methods call the existing methods on `$this`).

All of their tests pass in both suites. RED-7 runs on the same fallback path: `LegacySignatureFullCapabilityStore` implements only `f5897b0`'s `FullCapabilityStoreInterface` (27 methods), so every call is dispatched to an existing method.

## 11. Stated limits (no change)

* `WATCH_GET` is outside the inventory (PLAN §9.6, accepted): it reads the physical watch key only. A watch past its logical expiry stays readable for its physical remainder.
* Rotation and lifecycle legacy expiry: a score written before the upgrade without `expiresAt` takes caller ms + `PTTL` (PLAN §3; bounded by |σ| and its remaining life).
* Host stores that do not implement the nine interfaces keep the phase 1 / #156 behaviour for that store (USAGE_GUIDE).

## 12. Disclosures

* **Dry runs and probes outside the registered runs:**
  * `two_clock.php` dry run on the development Redis before its commit;
  * a Redis version probe on the development Redis during the first core run;
  * the `SET … EX` comparison of PLAN §11.2 (7.0.15, 8.10.0);
  * the F-P2-03 probe (§8).
* **`report.php` checked before the run** against the pre-fix archives and the dry-run output, with no fix-head data.
* **Added after the run, descriptive only:** the quantization section of `report.php` (`1939c09`). No verdict changed.
* **Added after the run, registered:** gate §4.5 was added after the first acceptance run, which omitted it. Its rule was committed before its run.
* **Logs:**
  * Acceptance logs are committed gzip-compressed (`gzip -n -9`). Their sha256 before compression:
    * `E-SAME.log` `1491b560…7b86`;
    * `E-DOCKER.log` `87cc9a85…7a2d`;
    * `E-SAME-s0.log` `f186fb56…6076`.
  * Trailing blanks were stripped from the plain suite logs. Originals' sha256 are in `logs/ORIGINAL_SHA256`.
  * `acceptance/expected/SHA256SUMS` covers every output.
* **Review status:** this is the executor's self-review. The Lead reruns the acceptance (#154 in full, the #156 probes, RED-1c) before the final review.

## Appendix A — declared test edits (`git show --format= b933da6 -- tests`; blank context lines are written without their leading space)

```diff
diff --git a/tests/Integration/Redis/KnownDeviceMixedVersionIntegrationTest.php b/tests/Integration/Redis/KnownDeviceMixedVersionIntegrationTest.php
index 0053875..b436b5d 100644
--- a/tests/Integration/Redis/KnownDeviceMixedVersionIntegrationTest.php
+++ b/tests/Integration/Redis/KnownDeviceMixedVersionIntegrationTest.php
@@ -31,8 +31,8 @@ use PHPUnit\Framework\TestCase;
  * DEC-021). The old worker is the pinned S0 pipeline
  * (S0EvaluationPipeline, provenance-checked) with the S0 Login preset; the new
  * worker is the current pipeline. Both share one real Redis namespace owned by
- * the test. Redis owns time for its primitives (DEC-016), so the workers'
- * clock reads Redis TIME; cases that need time to pass (rollback after decay,
+ * the test. The workers' clock reads Redis TIME, so the workers and the store
+ * judge at one time source (#153 phase 2); cases that need time to pass (rollback after decay,
  * previous generation) run in KnownDeviceMixedVersionSystemTest. Each case
  * states whether it asserts an invariant or an expected semantic difference.
  */
@@ -225,7 +225,7 @@ final class CustomK5Policy implements BlockPolicyInterface
 }

 /**
- * Clock that follows Redis TIME on every read, as the Redis primitives do.
+ * Clock that follows Redis TIME on every read, so the caller's time and the Redis clock agree.
  */
 final class RedisTimeClock implements ClockInterface
 {
diff --git a/tests/Integration/Redis/RedisFullCapabilityStoreIntegrationTest.php b/tests/Integration/Redis/RedisFullCapabilityStoreIntegrationTest.php
index d638048..f9e4535 100644
--- a/tests/Integration/Redis/RedisFullCapabilityStoreIntegrationTest.php
+++ b/tests/Integration/Redis/RedisFullCapabilityStoreIntegrationTest.php
@@ -35,6 +35,13 @@ final class RedisFullCapabilityStoreIntegrationTest extends TestCase

     private string $namespace = 'integration:test';

+    /**
+     * #153 phase 2: the store's fallback clock (the host) and this test's
+     * `redisNow()` (Redis) are different clocks; a physical-life bound computed
+     * from `redisNow()` allows this much clock disagreement and rounding.
+     */
+    private const PHYSICAL_LIFE_TOLERANCE_MS = 1_000;
+
     protected function setUp(): void
     {
         $host = getenv('REDIS_INTEGRATION_HOST');
@@ -239,17 +246,12 @@ final class RedisFullCapabilityStoreIntegrationTest extends TestCase
     }

     /**
-     * The PHP-side ensureGenerationTtlRepresentable() preflight reads Redis
-     * TIME once and can go stale before the EVAL that performs the actual
-     * mutation reads Redis TIME again. Proving the boundary is genuinely
-     * atomic requires showing that the LIFECYCLE_MUTATE script itself
-     * rejects an unrepresentable deadline using its own same-script TIME
-     * read, independent of whatever the PHP preflight decided. A decorator
-     * that fabricates the bare ['TIME'] reply consumed only by the PHP
-     * preflight (never the EVAL's internal redis.call('TIME')) makes the
-     * preflight accept a TTL that the real, current Redis TIME inside the
-     * script cannot represent: if the in-script guard were ever removed,
-     * this test would fail because the write would go through.
+     * An unrepresentable generation-bound deadline fails before any write.
+     * Since #153 phase 2 neither the PHP-side ensureGenerationTtlRepresentable()
+     * preflight nor the LIFECYCLE_MUTATE script reads Redis TIME: both judge
+     * the same caller time, and the script repeats the check atomically. The
+     * decorator still fabricates any bare ['TIME'] reply, which the store no
+     * longer sends, so a reintroduced Redis-time read would be caught here.
      */
     public function testGenerationBoundScoreCreationEnforcesAtomicInScriptTtlGuard(): void
     {
@@ -433,14 +435,13 @@ final class RedisFullCapabilityStoreIntegrationTest extends TestCase

     /**
      * G12-R03-B: `durationSeconds` is a large-but-ordinarily-valid
-     * positive PHP int. The punishment-lifecycle path uses real Redis
-     * TIME (DEC-016), not caller time, so this cannot be preflighted from
-     * PHP — the rejection must happen inside HARD_BLOCK itself, using the
-     * very Redis TIME read it performs, before any write, leaving a
-     * pre-existing valid generated score, its generation, and its
-     * lifecycle evidence completely untouched.
+     * positive PHP int. The punishment-lifecycle path judges at the caller's
+     * time (#153 phase 2) and PHP does not preflight `now + duration`, so the
+     * rejection must happen inside HARD_BLOCK itself, on the caller time it
+     * receives, before any write, leaving a pre-existing valid generated
+     * score, its generation, and its lifecycle evidence completely untouched.
      */
-    public function testPunishmentLifecycleRedisTimeDerivedBoundaryFailsBeforeAnyRedisMutation(): void
+    public function testPunishmentLifecycleCallerTimeDerivedBoundaryFailsBeforeAnyRedisMutation(): void
     {
         $mutation = $this->store->mutateGenerationBoundScore('g12-lifecycle-redis-time-overflow', null, null, 600, 8);
         self::assertTrue($mutation->applied);
@@ -1100,7 +1101,7 @@ final class RedisFullCapabilityStoreIntegrationTest extends TestCase
         self::assertNotNull($this->store->readGenerationBoundScoreState('lifecycle-current', null)?->postPunishmentReentry);
     }

-    public function testLifecycleCyclesUseRedisTimeExactlyOnceAndPauseIsNotRenewed(): void
+    public function testLifecycleCyclesUseTheCallerTimeExactlyOnceAndPauseIsNotRenewed(): void
     {
         $mutation = $this->store->mutateGenerationBoundScore('redis-time-cycle', null, null, 600, 8);
         self::assertTrue($mutation->applied);
@@ -1469,7 +1470,9 @@ final class RedisFullCapabilityStoreIntegrationTest extends TestCase
         $this->raw(['HSET', $score, 'value', '8', 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 1)]);
         $this->raw(['EXPIRE', $score, '600']);

-        $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking('publication-inconsistent', null, 1, str_repeat('a', 32), 2, 60, 21600, 2, 600, 86400));
+        // #153 phase 2 (LT-P2-02): the logical deadline governs; PTTL is checked as alive only.
+        // At the logical deadline the publication is an ordinary unapplied conflict.
+        self::assertFalse($this->store->blockWithPunishmentLifecycleTrackingAt('publication-inconsistent', null, 1, str_repeat('a', 32), 2, 60, 21600, 2, 600, 86400, $now + 1)->applied);
         self::assertSame(-2, $this->integer($this->raw(['PTTL', $this->key('block', 'publication-inconsistent')])));
         self::assertSame([], $this->raw(['ZRANGE', $this->key('cycle', 'publication-inconsistent'), '0', '-1']));
     }
@@ -1486,12 +1489,15 @@ final class RedisFullCapabilityStoreIntegrationTest extends TestCase
             }
             $this->raw(array_merge(['HSET', $score], $fields));
             $this->raw(['EXPIRE', $score, '600']);
-            $this->assertOperationFails(match ($operation) {
-                'read' => fn(): mixed => $this->store->readGenerationBoundScoreState($logical, null),
-                'mutate' => fn(): mixed => $this->store->mutateGenerationBoundScore($logical, null, new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, 8, $now, $now + 1, 1), 600, 9),
-                'claim' => fn(): mixed => $this->store->claimPostPunishmentReentry($logical, null, str_repeat('a', 32)),
-                default => fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking($logical, null, 1, str_repeat('a', 32), 2, 60, 600, 2, 600, 86400),
-            });
+            // #153 phase 2 (LT-P2-02): the logical deadline governs; PTTL is checked as alive only.
+            // At the logical deadline every operation sees expired state, not corruption.
+            $deadlineMs = ($now + 1) * 1000;
+            match ($operation) {
+                'read' => self::assertNull($this->store->readGenerationBoundScoreStateAt($logical, null, $deadlineMs)),
+                'mutate' => self::assertFalse($this->store->mutateGenerationBoundScoreAt($logical, null, new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, 8, $now, $now + 1, 1), 600, 9, $deadlineMs)->applied),
+                'claim' => self::assertFalse($this->store->claimPostPunishmentReentryAt($logical, null, str_repeat('a', 32), $deadlineMs)),
+                default => self::assertFalse($this->store->blockWithPunishmentLifecycleTrackingAt($logical, null, 1, str_repeat('a', 32), 2, 60, 600, 2, 600, 86400, $now + 1)->applied),
+            };
         }
     }

@@ -1681,7 +1687,9 @@ final class RedisFullCapabilityStoreIntegrationTest extends TestCase
         $this->raw(['HSET', $physicallyInconsistentPrevious, 'value', '8', 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 10)]);
         $this->raw(['EXPIRE', $physicallyInconsistentPrevious, '600']);
         $physicallyInconsistentBefore = $this->hashMap($physicallyInconsistentPrevious);
-        $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking('r5-physical-inconsistent-current', 'r5-physical-inconsistent-previous', 1, str_repeat('c', 32), 2, 60, 600, 2, 600, 86400));
+        // #153 phase 2 (LT-P2-02): the logical deadline governs; PTTL is checked as alive only,
+        // so this structurally valid Previous is an ordinary unapplied conflict.
+        self::assertFalse($this->store->blockWithPunishmentLifecycleTracking('r5-physical-inconsistent-current', 'r5-physical-inconsistent-previous', 1, str_repeat('c', 32), 2, 60, 600, 2, 600, 86400)->applied);
         self::assertSame($physicallyInconsistentBefore, $this->hashMap($physicallyInconsistentPrevious));
         self::assertSame(-2, $this->integer($this->raw(['PTTL', $this->key('score', 'r5-physical-inconsistent-current')])));
     }
@@ -1754,13 +1762,15 @@ final class RedisFullCapabilityStoreIntegrationTest extends TestCase
         $this->raw(['EXPIRE', $previous, '600']);
         $before = $this->hashMap($previous);

-        self::assertTrue($this->store->claimPostPunishmentReentry('claim-previous-only-current', 'claim-previous-only', $id));
+        self::assertTrue($this->store->claimPostPunishmentReentryAt('claim-previous-only-current', 'claim-previous-only', $id, $now * 1000));

         self::assertSame($before, $this->hashMap($previous));
         self::assertSame($id, $this->raw(['GET', $this->key('reentry-claim', 'claim-previous-only-current')]));
-        $markerTtl = $this->integer($this->raw(['TTL', $this->key('reentry-claim', 'claim-previous-only-current')]));
-        self::assertGreaterThan(0, $markerTtl);
-        self::assertLessThanOrEqual(600, $markerTtl);
+        // #153 phase 2 (LT-P2-02): the marker's physical life is relative and at most the
+        // evidence's logical remainder at the caller's time (601 s at `$now`).
+        $markerPttl = $this->integer($this->raw(['PTTL', $this->key('reentry-claim', 'claim-previous-only-current')]));
+        self::assertGreaterThan(0, $markerPttl);
+        self::assertLessThanOrEqual(601_000, $markerPttl);
         self::assertFalse($this->store->claimPostPunishmentReentry('claim-previous-only-current', 'claim-previous-only', $id));
     }

@@ -1836,7 +1846,8 @@ final class RedisFullCapabilityStoreIntegrationTest extends TestCase
         $legacyHandoff = $this->store->mutateGenerationBoundScore('ttl-contract-legacy-current', 'ttl-contract-legacy-previous', $legacyPreviousState, 86400, 5);
         self::assertTrue($legacyHandoff->applied);
         self::assertSame(1, $legacyHandoff->state?->generation);
-        self::assertLessThanOrEqual($legacyPreviousPttl + 25, $this->integer($this->raw(['PTTL', $this->key('score', 'ttl-contract-legacy-current')])));
+        // #153 phase 2 (LT-P2-02): the physical life is relative and at most the logical remainder.
+        self::assertLessThanOrEqual(($legacyHandoff->state->expiresAt - $now) * 1000 + self::PHYSICAL_LIFE_TOLERANCE_MS, $this->integer($this->raw(['PTTL', $this->key('score', 'ttl-contract-legacy-current')])));
         self::assertSame($legacyPreviousBefore, $this->hashMap($legacyPreviousKey));

         $generatedPrevious = $this->store->mutateGenerationBoundScore('ttl-contract-generated-previous', null, null, 600, 6);
@@ -1857,7 +1868,7 @@ final class RedisFullCapabilityStoreIntegrationTest extends TestCase
         self::assertTrue($generatedHandoff->applied);
         self::assertSame(4, $generatedHandoff->state?->generation);
         self::assertSame($generatedExpiry, $generatedHandoff->state->expiresAt);
-        self::assertLessThanOrEqual($generatedPreviousPttl + 25, $this->integer($this->raw(['PTTL', $this->key('score', 'ttl-contract-generated-current')])));
+        self::assertLessThanOrEqual(($generatedExpiry - $now) * 1000 + self::PHYSICAL_LIFE_TOLERANCE_MS, $this->integer($this->raw(['PTTL', $this->key('score', 'ttl-contract-generated-current')])));
         self::assertSame($generatedPreviousBefore, $this->hashMap($generatedPreviousKey));

         $current = $this->store->mutateGenerationBoundScore('ttl-contract-current', null, null, 600, 8);
@@ -1870,7 +1881,7 @@ final class RedisFullCapabilityStoreIntegrationTest extends TestCase
         self::assertTrue($currentMutation->applied);
         self::assertSame(2, $currentMutation->state?->generation);
         self::assertSame($currentExpiry, $currentMutation->state->expiresAt);
-        self::assertLessThanOrEqual($currentBeforePttl + 25, $this->integer($this->raw(['PTTL', $currentKey])));
+        self::assertLessThanOrEqual(($currentExpiry - $now) * 1000 + self::PHYSICAL_LIFE_TOLERANCE_MS, $this->integer($this->raw(['PTTL', $currentKey])));

         $subSecond = $this->store->mutateGenerationBoundScore('ttl-contract-sub-second', null, null, 600, 1);
         self::assertTrue($subSecond->applied);
@@ -1884,7 +1895,7 @@ final class RedisFullCapabilityStoreIntegrationTest extends TestCase
         $subSecondMutation = $this->store->mutateGenerationBoundScore('ttl-contract-sub-second', null, $subSecondState, 86400, 2);
         self::assertTrue($subSecondMutation->applied);
         self::assertSame(2, $subSecondMutation->state?->generation);
-        self::assertLessThanOrEqual($subSecondPttl + 25, $this->integer($this->raw(['PTTL', $subSecondKey])));
+        self::assertLessThanOrEqual(($subSecondMutation->state->expiresAt - $now) * 1000 + self::PHYSICAL_LIFE_TOLERANCE_MS, $this->integer($this->raw(['PTTL', $subSecondKey])));

         $noExpiryKey = $this->key('score', 'ttl-contract-no-expiry');
         $noExpiryNow = $this->redisNow();
@@ -2229,7 +2240,9 @@ final class RedisFullCapabilityStoreIntegrationTest extends TestCase

         $first = $limiter->limit($context, RateLimitCommand::recordFailure('public_hard_cycle'));
         self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $first->decision);
-        $clock->setNow($clock->now()->modify('+61 seconds'));
+        // #153 phase 2 (F-P2-01): past the first L3 block (300 s), so the second failure reaches
+        // cycle tracking; +61 s passed only while Redis time expired a block dated in the past.
+        $clock->setNow($clock->now()->modify('+301 seconds'));
         $second = $limiter->limit($context, RateLimitCommand::recordFailure('public_hard_cycle'));
         self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $second->decision);

diff --git a/tests/Integration/Redis/RedisSimpleFixedWindowIntegrationTest.php b/tests/Integration/Redis/RedisSimpleFixedWindowIntegrationTest.php
index 044abba..e9a3865 100644
--- a/tests/Integration/Redis/RedisSimpleFixedWindowIntegrationTest.php
+++ b/tests/Integration/Redis/RedisSimpleFixedWindowIntegrationTest.php
@@ -21,10 +21,10 @@ use RuntimeException;
  * non-mocked Redis server: persistence, stable expiry, the allowed/denied
  * boundary, rotation migration, and raw-subject key privacy.
  *
- * RedisFullCapabilityStore resolves budget-epoch validity from the Redis
- * server's own real-time clock (Lua TIME), not from an injected
- * ClockInterface, so this suite uses a real SystemClock and short real waits
- * for the boundary/expiry scenarios instead of a FixedClock.
+ * RedisFullCapabilityStore judges budget-epoch validity at the caller's time
+ * (#153 phase 2), while key lifetimes stay relative on the Redis clock, so
+ * this suite uses a real SystemClock and short real waits for the
+ * boundary/expiry scenarios instead of a FixedClock.
  */
 final class RedisSimpleFixedWindowIntegrationTest extends TestCase
 {
diff --git a/tests/Unit/Builder/RateLimiterBuilderTest.php b/tests/Unit/Builder/RateLimiterBuilderTest.php
index 93f03bb..e83d6a9 100644
--- a/tests/Unit/Builder/RateLimiterBuilderTest.php
+++ b/tests/Unit/Builder/RateLimiterBuilderTest.php
@@ -21,14 +21,15 @@ use Maatify\RateLimiter\Exception\RateLimiterException;
 use Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface;
 use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
 use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
-use Maatify\RateLimiter\Service\DeviceIdentityResolverInterface;
-use Maatify\RateLimiter\Service\DeviceIdentityResolver;
 use Maatify\RateLimiter\Service\BudgetTracker;
 use Maatify\RateLimiter\Service\CircuitBreaker;
 use Maatify\RateLimiter\Service\DecayCalculator;
+use Maatify\RateLimiter\Service\DeviceIdentityResolver;
+use Maatify\RateLimiter\Service\DeviceIdentityResolverInterface;
 use Maatify\RateLimiter\Service\EvaluationPipeline;
 use Maatify\RateLimiter\Service\FingerprintHasher;
 use Maatify\RateLimiter\Service\RateLimiterInterface;
+use Maatify\RateLimiter\Service\RequestScopedClock;
 use Maatify\RateLimiter\Builder\RateLimiterBuilder;
 use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
 use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
@@ -99,17 +100,23 @@ final class RateLimiterBuilderTest extends TestCase
         /** @var DecayCalculator $decayCalculator */
         $decayCalculator = $this->privateProperty($pipeline, 'decayCalculator');

-        self::assertSame($clock, $this->privateProperty($scoreEngine, 'clock'));
-        self::assertSame($clock, $this->privateProperty($pipeline, 'clock'));
-        self::assertSame($clock, $this->privateProperty($circuitBreaker, 'clock'));
-        self::assertSame($clock, $this->privateProperty($budgetTracker, 'clock'));
-        self::assertSame($clock, $this->privateProperty($decayCalculator, 'clock'));
+        // #153 phase 2 (LT-P2-05): the five nodes share one RequestScopedClock that wraps the Host clock.
+        $shared = $this->privateProperty($scoreEngine, 'clock');
+        self::assertInstanceOf(RequestScopedClock::class, $shared);
+        self::assertSame($clock, $this->privateProperty($shared, 'hostClock'));
+        self::assertSame($shared, $this->privateProperty($pipeline, 'clock'));
+        self::assertSame($shared, $this->privateProperty($circuitBreaker, 'clock'));
+        self::assertSame($shared, $this->privateProperty($budgetTracker, 'clock'));
+        self::assertSame($shared, $this->privateProperty($decayCalculator, 'clock'));
     }

     public function testDefaultClockIsUtcSystemClock(): void
     {
         $engine = $this->builder()->build();
-        $clock = $this->privateProperty($this->scoreEngine($engine), 'clock');
+        // #153 phase 2 (LT-P2-05): the shared RequestScopedClock wraps the default Host clock.
+        $shared = $this->privateProperty($this->scoreEngine($engine), 'clock');
+        self::assertInstanceOf(RequestScopedClock::class, $shared);
+        $clock = $this->privateProperty($shared, 'hostClock');

         self::assertInstanceOf(SystemClock::class, $clock);
         self::assertSame('UTC', $clock->getTimezone()->getName());
```
