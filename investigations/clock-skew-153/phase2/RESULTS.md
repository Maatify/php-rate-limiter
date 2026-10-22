# #153 phase 2 — one time authority: RESULTS

**v3 (2026-10-22), at the tested tree `3ead16a`, unchanged since v2. Status: Draft.**
* v1 and v2 were the executor's self-review.
* The Lead reviewed v2: PASS on condition of LT-P2-18 (V-GAP and this v3), [comment 6343997106](https://github.com/Maatify/php-rate-limiter/pull/158#issuecomment-6343997106).
* v3 adds the validity condition V-GAP and evaluates C-156-Q on the Lead's run (§4).

No open finding remains:
* **The original #156 probe condition of §4.6 stays FAIL as written** (LT-P2-13: no redefinition after the run): 29 E-SAME cells and 4 E-DOCKER cells.
* **The condition registered in its place, C-156-Q (PLAN §12.4, 2026-10-22), PASSES:** 101 / 101 E-SAME cells and 12 / 12 E-DOCKER cells (§4).
* **V-GAP, its validity condition (PLAN §12.5; LT-P2-18, LT-P2-19, LT-P2-20):**
  * The executor's run: PASS 101 / 101 and 12 / 12. 8 of its 35,500 compared steps are unpaired, at most 0.5 % in any cell. That run is the valid evidence for C-156-Q.
  * The Lead's four solo cells: PASS.
  * The Lead's concurrent run: UNPROVEN 101, FAIL 0. It is invalid as a twin measurement because of load: no evidence against the fix and none for it.
* **F-P2-03 is fixed in this PR:** red test `ea9a7c2`, fix `5d7debd` (§8).
* **The P-TAIL / P-HT `HARD_BLOCK` is attributed** (LT-P2-15, §3).

Everything registered passes on `3ead16a`:
* #154 E-SAME in full, P-TAIL and P-HT, RED-1c;
* §4.5 S0 non-regression;
* the suites in both modes (1960 tests) and PHPStan in both modes;
* CVH twice, NM-6, and RED-1…11;
* C-156-Q, and the two-clock controls (on the outputs that §12.2 keeps).

What remains before the Owner's decision: the Lead marks Ready.

The Lead's own rerun on `2f5bc1c` (code `3ead16a`) confirmed independently, on his machine:
* #154 E-SAME 51 / 51, P-TAIL 0 / 60, P-HT 0 / 20;
* the two-clock arm, matching the numbers in §7;
* the suites, 1960 OK in both modes;
* PHPStan in both modes, and CVH twice.

## 1. Tested tree

* **v2 tested tree:** `src/`, `tests/` and `composer.json` at **`3ead16a`** (base `main@f5897b0`).
  * The suites, PHPStan, CVH, gate §4.5 and #154 E-SAME ran on it.
  * Every later commit touches `investigations/` or `docs/` only: `562030f`, `18ff84b`, `25f673a`, `6ee1741` (`docs/FAILURE_SEMANTICS.md`), `0e8756b`, `e76e9a8`, the replay outputs, and this file.
  * `git diff 3ead16a HEAD -- src tests composer.json` is empty.
* **v2 commits after `38c5ce7`:**
  * the §12 rulings `07c81b9`;
  * RED-11 `ea9a7c2`;
  * F-P2-03 fix `5d7debd`;
  * runner mode `3ead16a`;
  * rerun outputs `562030f`;
  * §12.4 rulings and C-156-Q text `18ff84b`;
  * C-156-Q evaluation `25f673a`;
  * FAILURE_SEMANTICS sentence `6ee1741`;
  * cause instrument `0e8756b` and `e76e9a8`.
* **v1:** the first tested tree was `0773b8a` (logs in `logs/0773b8a/`).
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
* **Which head each acceptance output comes from (v2):**
  * **#154 E-SAME and gate §4.5** reran on `3ead16a` (LT-P2-14). Logs: `acceptance/logs/E-SAME-p154.log.gz`, `E-SAME-s0.log.gz`.
  * **#156 probes (both environments), the two-clock arm and #154 E-DOCKER** are the outputs of `5201632`. They were not rerun:
    * the #156 cells and the two-clock cells never reach the rotation path (PLAN §12.2);
    * the ruling ordered #154 on E-SAME only.
  * **`src/` difference between `5201632` and `3ead16a`:** the F-P2-03 fix only, two Lua lines in the watch-rotation script (`5d7debd`).
  * **`tests/` difference:** `f4aec1c` (the support stores' `WeakMap` accessor) and RED-11. No acceptance harness loads either.
  * **What is not established:** whether a #154 E-DOCKER cell reaches the watch-rotation path. Its rows do not record command names.
  * **P-TAIL / P-HT cause replay:** `0e8756b` / `e76e9a8`, whose `src/` and `tests/` are identical to `3ead16a`.
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
| RED-11 (F-P2-03, LT-P2-14) | rotated watch `[1, 1]` at the logical deadline, as the plain path | red on `38c5ce7`: `[1, 2]` | pass (with control `[1, 2]` before the deadline, and live previous unchanged) | `…RotatedWatchWindowRestartsAtItsLogicalDeadlineLikeThePlainWatch` |
| S0-PARITY §4.2 | existing tests unchanged, or a ruling | — | 1960 tests OK in both modes (on `3ead16a`). The only changed existing tests are those ruled (§5); goldens pass byte-identical | `logs/suite-*.log` |
| §4.3 suites | both modes, full logs | — | `3ead16a`: normal PHPUnit 11.5.57, `OK (1960 tests, 26452 assertions)`, 15:06; lowest PHPUnit 11.5.50, `OK (1960 tests, 26576 assertions)`, 15:14. No error, warning, risky, skipped or incomplete test. v1 (`0773b8a`): 1959 OK in both | `logs/suite-normal.log`, `logs/suite-lowest.log`; v1 in `logs/0773b8a/` |
| §4.3 PHPStan max | normal and lowest | — | `3ead16a`: 2.3.1 `[OK] No errors`; 2.0.0 `[OK] No errors` (after F-P2-02) | `logs/phpstan-*.log`; the F-P2-02 run in `logs/8a8082a/` |
| §4.3 CVH twice | PASS | — | `3ead16a`: clean run #1 PASS, clean run #2 PASS (verification and cleanup) | `logs/cvh.log` |
| §4.3 NM-6 | admission contracts unchanged | — | `git diff f5897b0 3ead16a` on every `src/` file with "Admission" in its path: 0 lines. `RedisFullCapabilityStore` changes only the layout of its `implements` list there. `AdmissionStoreNm6RedisTest` (unchanged) passes in both suites | git, suites |
| §4.4 detectors | red tests red on `f5897b0` | 12 / 14 cases red (RED-6, RED-7 guards), RED-10 8 / 8 red | — | commits `7938249`, `c653e7a` |
| §4.5 S0 non-regression | equal except defect-attributed | — | **PASS** on `3ead16a` (and on `0773b8a`): 6 cells, 2,400 stream-twins; 0 unattributed differences, 0 exceptions after the fix; 2 differences attributed (S-BEN_LOGIN-NA_AA, pre-fix exceptions) | §6 |
| §4.6 #154 rerun | 0 validator exceptions every cell; P-HT 0 / 20; P-TAIL 0 / 60; D-100 0 / 2,000; V-3 = 0 exceptions, 0 UP / LO | — | **PASS** in every condition: E-SAME on `3ead16a` (and `5201632`), E-DOCKER on `5201632` | §3 |
| §4.6 #156 probes | 0 flips at every σ | — | **FAIL as written** (33 cells; kept, LT-P2-13): §4 | §4 |
| C-156-Q (PLAN §12.4, registered 2026-10-22) | every remaining twin difference explained by whole-second quantization | — | **PASS**: E-SAME 101 / 101, E-DOCKER 12 / 12; 1,059 primary changes all explained, 0 unexplained; max \|Δ end\| 1.0 s; `retryAfter` differences 713 (i) + 660 (ii), 0 unexplained; 0 exceptions | §4 |
| V-GAP on C-156-Q (PLAN §12.5, registered 2026-10-22) | unpaired steps (> 100 ms, stream-level) left out; > 1 % unpaired = UNPROVEN | — | executor's run **PASS 101 / 101 and 12 / 12** (8 / 35,500 steps unpaired, ≤ 0.5 % per cell); Lead's solo cells PASS 4 / 4; Lead's concurrent run UNPROVEN 101, FAIL 0 | §4 |
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
* **P-TAIL / P-HT outcomes are mostly `HARD_BLOCK`** (first burst: 4 `ALLOW`, 16 `HARD_BLOCK`; every other target `HARD_BLOCK`). The registered condition covers exceptions and script errors only, and is unaffected.
  * **Cause (LT-P2-15, LT-P2-17), measured by `tail_cause.php`.** It replays the archived `tail.php` flow with the full result; the output is in `expected/E-SAME/tail-cause.jsonl.gz` and the table in `expected/summary.md`. Run on `e76e9a8`, whose `src/` and `tests/` equal `3ead16a`, on Redis 8.10.0.
  * **It is S0's fingerprint dilution rule** (`EvaluationPipeline::checkCorrelationRules`, `BOUNDED_SNAPSHOT(ttl 600, max 6)`: distinct K1 values per device fingerprint; watch at 5, confirmed in a second window, DECISION_MATRIX L704; KEY_STRATEGY "Same DeviceFP across many K1 prefixes → fingerprint dilution").
    * The probe design gives every account the same device (`d1`) and UA (`Mozilla/5.0`) with a distinct IP. So all 80 contexts share one fingerprint across many K1 values.
    * **Creation window:** the dilution count reached 5–6 and the watch was incremented once. Every creation stayed `ALLOW`, because the watch was not yet confirmed.
    * **Targets' window, 600 s later:** a fresh dilution window counts 1, 2, 3, 4 (`ALLOW`). At 5 the watch is incremented again and `HARD_BLOCK` follows (level 2, `retryAfter` 60). From 6 on, every target is `HARD_BLOCK`.
  * **What the other collective snapshots show.** At every target:
    * credential spray `(600, 5)`, distributed account `(600, 4)` and churn `(600, 3)` stay at count 1;
    * no admission script differs between `ALLOW` and `HARD_BLOCK` calls;
    * the P-HT accounts, on another /24, are blocked the same way.
  * **Result `metadata` is empty** for these blocks (no `signal`, `cause`, `reason` or `scope`). The rule is identified from the script sequence and its `(ttl, max, count)`.
  * **Against the Lead's expected reading:** confirmed that it is a collective S0 rule, not admission and not time. Refined on the shared factor: the device fingerprint, not the /24.
  * **The probe design** (many accounts on one device fingerprint) triggers S0's dilution rule. That does not touch the gate condition (exceptions only).
* **Host surface (§7.4).** The archived `host_surface.php` asserts the pre-fix behaviour and stops at its first failure.
  * Its record in both environments: `FAIL … login_protection H-1 returned=100 other=[]`, i.e. `limit()` returned 100 / 100 instead of throwing.
  * `otp_protection` and `custom_distributed` are not reached by that script. Their cells above cover them.
* **V-4′ is not a condition.** After LT-P2-05 every read is `RequestScopedClock.php:40`, the single Host read per call.

## 4. Acceptance: #156 probe groups — the original condition FAILS as written; C-156-Q PASSES

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
* This is inherent to whole-second timestamps (DEC-017). It fails the condition as registered, and that verdict stays (LT-P2-13: no redefinition after the run).

**C-156-Q, the condition registered in its place.** PLAN §12.4 records it with its date, 2026-10-22, after LT-P2-16 corrected one clause. It is evaluated by `report.php` (`25f673a`) over the same outputs, and the table is in `expected/summary.md`.

| C-156-Q clause | E-SAME (101 cells) | E-DOCKER (12 cells) |
| --- | --- | --- |
| 0 exceptions, either twin | 0 | 0 |
| primary changes: explained (P-B / P-E end-time rule or ± 2 ms) / unexplained (all families; P-I also paired steps) | 1,059 explained in total over both environments / **0** | (included) / **0** |
| max \|Δ end\| | 1.0 s | 1.0 s |
| `retryAfter` differences, equal decision: (i) exactly 1 s / (ii) CYCLE-NA L3 → pause handover / unexplained | 713 / 660 in total over both environments / **0** | (included) / **0** |
| cells PASS | **101 / 101** | **12 / 12** |

* The totals equal the counts written into PLAN §12.4 before the implementation.
* No `lv` difference occurs with an equal decision; `lv` is reported, not gated.
* FAILURE_SEMANTICS §2.5 now states the one-second resolution between Hosts, as ruled (`6ee1741`).

**V-GAP, the validity condition of C-156-Q (v3; PLAN §12.5; LT-P2-18, LT-P2-19, LT-P2-20).** C-156-Q compares twins that should run a step at nearly the same instant.
* **The Lead's run.** The Lead ran the acceptance on an Apple M1, 8 cores. There, the 65 concurrent cells of `probes-a` pushed the twin pairing gap to p99 400–650 ms, with a maximum of 921 ms, and C-156-Q gave 76 / 101.
* **The rule, applied per compared step:**
  * the gap is |h0_S − h0_Z|;
  * for P-RA's `return` steps, which `run.php` schedules for each twin alone at its own predicted time, it is each twin's lateness against that time;
  * a probe step also takes the largest gap of the earlier steps of its stream that both twins ran, because a late write moves the stream's stored end;
  * a step over 100 ms is unpaired and left out of every clause except exceptions;
  * a cell with more than 1 % unpaired steps is UNPROVEN, not FAIL.

| Run | Source | C-156-Q (§12.4) | C-156-Q + V-GAP (§12.5) |
| --- | --- | --- | --- |
| Executor, E-SAME | `acceptance/expected/E-SAME/p156` (`5201632`) | 101 / 101 | **PASS 101, UNPROVEN 0, FAIL 0**; 8 unpaired steps, one in each of 8 cells (0.5 %): P-E2 LOGIN-NA −10, −2, −0.5, +0.5, +10; P-E2 CYCLE-NA +0.25, +2; P-E3 CYCLE-NA +0.25. Each comes from an earlier write at 101.8–122.5 ms |
| Executor, E-DOCKER | `acceptance/expected/E-DOCKER/p156` (`5201632`) | 12 / 12 | **PASS 12, UNPROVEN 0, FAIL 0**; 0 unpaired steps |
| Lead, E-SAME, concurrent (`run.sh`, 65 + 36 cells) | outside the repo (below) | 76 / 101 | **PASS 0, UNPROVEN 101, FAIL 0** |
| Lead, four cells rerun alone, sequentially, private `redis-server` | outside the repo (below) | 4 / 4 | **PASS 4, UNPROVEN 0, FAIL 0** |

**How the rule was settled, on the data:**
* **Probe-step gap only.** The Lead's concurrent run gave PASS 39, UNPROVEN 57, FAIL 5.
  * The five FAILs (`P-B_LOGIN-NA_-0.25`, `P-E3_LOGIN-NA_-0.25`, `P-E3_CYCLE-NA_-0.25`, `P-E3_LOGIN-NA_+60.00`, `P-E3_CYCLE-NA_-10.00`) had probe gaps of 3–31 ms.
  * Each failing step traces back to an earlier write in its stream that ran 248–728 ms apart (|Δ end| 1.25 s, or a 2 s `retryAfter` difference). That led to LT-P2-20.
* **Before LT-P2-19.** Using |h0_S − h0_Z| for `return` steps made 13 E-SAME and 2 E-DOCKER P-RA2 cells of the executor's unloaded run UNPROVEN by design. Their 6,000 `return` rows are within 100 ms of their own schedule.

**A correction to the record.**
* The Lead's LT-P2-20 ruling records the executor's run as "PASS 101 / 101 and 12 / 12 with 0 unpaired".
* The verdicts are as recorded. The count is not: under the stream-level rule, 8 steps are unpaired, as above.
* The source of the mismatch is the executor's LT-P2-20 comment. It reported the stream-level verdicts as "unchanged" without the unpaired count; with the probe-step rule the count was 0.
* No verdict depends on it.

**Reading (Lead, LT-P2-20):**
* The executor's run is the valid evidence for C-156-Q.
* The Lead's concurrent run is invalid as a twin measurement because of load: no evidence against the fix and none for it.

**Independent re-implementation.** Every V-GAP number above was also computed by a separate Python re-implementation, written from the rule rather than from `report.php`. Under the probe-step rule it reproduced `report.php`'s counts on all three runs exactly. It is a scratch check, not committed.

**The Lead's files.**
* Read from `/private/tmp/claude-501/-Users-mohamedabdulalim-MEGA-Libraries-2026-php-rate-limiter/e3ef716a-b392-4fc7-bf93-830742fe8079/scratchpad/lead-acceptance-2f5bc1c/`, outside the repository, by the Lead's decision.
* Contents: 103 files in `p156/` (101 cells and two offset files), 4 in `solo/`, and `two-clock.jsonl.gz`.
* All 108 match his `SHA256SUMS`, whose own sha256 is `69112d973a0107acb31b51c5c6c3a8538cec2829dd950b6da26b20fa698aea7c`.
* His `README.txt`: run on 2026-10-22 at head `2f5bc1c` (code `3ead16a`); Apple M1, 8 cores, macOS; PHP 8.5.9; Redis 8.10.0.
* Evaluated with `report.php <root> <dir> p156`, through read-only symlinks in the executor's scratchpad.

**N-158-01 (not blocking).** `acceptance/README.md` states that twin results require a pairing gap of at most 100 ms, and that load invalidates the cell, not the fix.

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

The six cells registered for S-BEN and S-RA at σ = AA by #156's `run.sh` ran on E-SAME, concurrently. In #156 they ran within the 46-cell `streams-ben-ra` group. v1 ran on `0773b8a` (2026-10-21 22:41–23:45 UTC). v2 ran on `3ead16a` (2026-10-22), and its numbers are shown with v1's in brackets where they differ.

| Cell | stream-twins | equal | differ, attributed | differ, unattributed | `lv` / `retryAfter` only | exceptions before (S, Z) | after |
| --- | ---: | ---: | ---: | ---: | ---: | --- | ---: |
| S-BEN_LOGIN-NA_AA | 400 | 398 | 2 | 0 | 133 (v1: 15) | 1, 1 | 0 |
| S-RA_LOGIN-NA_AA | 400 | 400 | 0 | 0 | 1 (v1: 1) | 0, 0 | 0 |
| S-BEN_CYCLE-NA_AA | 400 | 400 | 0 | 0 | 130 (v1: 139) | 0, 0 | 0 |
| S-RA_CYCLE-NA_AA | 400 | 400 | 0 | 0 | 0 (v1: 0) | 0, 0 | 0 |
| S-BEN_LOGIN_AA | 400 | 400 | 0 | 0 | 148 (v1: 127) | 0, 0 | 0 |
| S-RA_LOGIN_AA | 400 | 400 | 0 | 0 | 0 (v1: 0) | 0, 0 | 0 |

**Gate §4.5: PASS** (v1 and v2). The `lv` / `retryAfter`-only column is descriptive and not gated. The two runs are separate in time, so a remaining wait computed from a stored second differs run to run by the second's phase. Not analysed further.

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
| F-P2-02 | My held-time `WeakMap` in the two in-memory support stores wrote static-property offsets that PHPStan 2.0.0 rejects (8 × `assign.propertyType`; 2.3.1 accepts). The lowest CI job would have failed | **fixed** in `f4aec1c` (typed accessor, local writes, no behaviour change); evidence `logs/8a8082a/phpstan-lowest.log`; suites, PHPStan and CVH rerun on `0773b8a` and on `3ead16a` |
| F-P2-03 | The inline watch-rotation script (`incrementWatchFlagAcrossRotationAt`, PLAN row 12) checks a present current window's metadata, but not its logical `expiresAt` against the caller's time: it keeps counting. `WATCH_INCREMENT` and every other window, flag and bridge reset at the logical deadline (LT-P2-03), and FAILURE_SEMANTICS §2.5 states the reset for every watch flag | **fixed** (LT-P2-14): RED-11 `ea9a7c2` red on `38c5ce7` (`[1, 2]`); fix `5d7debd` in the rotation script only (`expires ≤ now ⇒ DEL` of the current value and metadata before counting; the previous generation stays read-only); RED-11 passes; reruns on `3ead16a` all PASS |
| A-P2-01 | The #156 probe condition fails by whole-second quantization of each twin's clock (§4) | **ruled** (LT-P2-13 (a), LT-P2-16): the original condition stays FAIL as written; C-156-Q registered in its place PASSES (§4); FAILURE_SEMANTICS §2.5 states the bound |

**F-P2-03 evidence** (scratch probe on a temporary `redis-server` 8.10.0, disclosed; not committed):
* Setup: TTL 30 s, the second call at caller time T + 30 while both keys are still physically alive (a Host ahead of Redis).
* `incrementWatchFlagAt` returns `[1, 1]`: it resets.
* `incrementWatchFlagAcrossRotationAt` returns `[1, 2]`: it continues.
* Pre-fix the same script had no such check either. There, logical and physical life were both Redis time and coincided. After the fix they differ by the Host–Redis offset, so the window counts on for up to that offset past its logical end.
* The PLAN §9.3 correction addressed only row 12's malformed checks. The current-window reset was not raised then.

## 9. Rulings (Lead) on the questions of v1 and after

Sources: PR #158 comments [6334226943](https://github.com/Maatify/php-rate-limiter/pull/158#issuecomment-6334226943) and [6337114762](https://github.com/Maatify/php-rate-limiter/pull/158#issuecomment-6337114762), recorded in PLAN §12.

| LT | Ruling | Done |
| --- | --- | --- |
| LT-P2-13 | (a). The original condition stays FAIL as written; a new registered condition; FAILURE_SEMANTICS sentence | C-156-Q registered (§12.4), evaluated (`25f673a`): PASS; sentence `6ee1741` |
| LT-P2-14 | Fix F-P2-03 in this PR. Red test, then fix, then reruns (suites both modes, PHPStan, CVH, §4.5, #154 E-SAME). #156 and the two-clock arm only for a cell that exercises secret rotation (grep) | `ea9a7c2`, `5d7debd`; reruns on `3ead16a` PASS; grep in PLAN §12.2: no such cell |
| LT-P2-15 | Attribute the P-TAIL / P-HT `HARD_BLOCK` from the logs, descriptively | The logs lack the cause (PLAN §12.3), so it went to LT-P2-17 |
| LT-P2-16 | "0 unexplained `retryAfter` difference", with the two explained classes named and bounded. Raised because the ruled literal clause fails on the data | C-156-Q clause 4 |
| LT-P2-17 | Descriptive replay `tail_cause.php` | §3 |
| LT-P2-18 | V-GAP: the validity condition of C-156-Q, evaluated on both runs; RESULTS v3; no code ([6343074571](https://github.com/Maatify/php-rate-limiter/pull/158#issuecomment-6343074571), [6343997106](https://github.com/Maatify/php-rate-limiter/pull/158#issuecomment-6343997106)) | PLAN §12.5, `report.php`, this v3 |
| LT-P2-19 | `return` steps: lateness against each twin's own schedule ([6344441006](https://github.com/Maatify/php-rate-limiter/pull/158#issuecomment-6344441006)) | §12.5, `report.php` |
| LT-P2-20 | Stream-level V-GAP ([6345705953](https://github.com/Maatify/php-rate-limiter/pull/158#issuecomment-6345705953)) | §12.5, `report.php` |

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
* **v2 additions:**
  * C-156-Q was registered (PLAN §12.4) before its evaluation code. It re-reads the existing #156 outputs: nothing was rerun for it.
  * `run.sh` gained `ACCEPT_ONLY=p154` (`3ead16a`), so that the #154 rerun replaces only `p154/`.
  * Suite logs of `0773b8a` were moved to `logs/0773b8a/`.
* **`tail_cause.php` ran twice.**
  * The first replay (`0e8756b`) recorded script names only. It showed empty `metadata` and could not separate the collective rules.
  * `e76e9a8` adds `(ttl, max, count)` per bounded script, and the replay was run again. Only the second run's output is kept; the first's table is quoted in §3.
  * The second run was started with a shell `&`, not the tool's background mode. It ran to completion, checked by process and log.
* **`report.php` cause section fix.** In its first form it read `$row['x']` without a default. The archived P-HT creation rows have no `x`, so the regeneration printed 20 PHP warnings, with the table unaffected. Fixed in `9f97b06`; the final `summary.md` was regenerated with no warning.
* **Logs:**
  * Acceptance logs are committed gzip-compressed (`gzip -n -9`). Their sha256 before compression:
    * `E-SAME.log` `1491b560…7b86`;
    * `E-DOCKER.log` `87cc9a85…7a2d`;
    * `E-SAME-s0.log` `f186fb56…6076` (v1);
    * v2: `E-SAME-p154.log` `cff0b47f…04c2`, `E-SAME-s0.log` `1c785bfe…ca5f`, `E-SAME-tail-cause.log` `7476ddab…84d1`.
  * Trailing blanks were stripped from the plain suite logs. Originals' sha256 are in `logs/ORIGINAL_SHA256`.
  * `acceptance/expected/SHA256SUMS` covers every output.
* **Review status:**
  * v1 and v2 were the executor's self-review.
  * The Lead reran the acceptance on `2f5bc1c` and reviewed: PASS on condition of LT-P2-18, which this v3 meets.
  * The V-GAP rule was refined twice on the data before registration (LT-P2-19, LT-P2-20); each time the question was raised and nothing was registered until it was ruled.
* **Not blocking (Lead):**
  * N-158-01, the acceptance README;
  * N-158-02, the N-154-01 flaky-test issue, still open outside this PR.

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
