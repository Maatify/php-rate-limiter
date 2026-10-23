# #157 — phase 1 REPORT: inventory, rate, mechanism proof, the historical path, what is not proven

> **Status:** evidence only. No fix, no fix recommendation, no change to `src/`, `tests/`, `composer.json`, `phpunit.xml` or to `ci.yml`. Registered conditions: `PLAN.md` §5, §9 (rulings), §10.7 (the two conditions before L). Nothing below changes a registered condition after it ran.
> **Heads.** Arms pre, D, L at **`main@c8b8fd7`** (`src/`, `tests/`, `composer.json`, `phpunit.xml`; V-HEAD checked per run). Arm H at **`fe8775c206d7209fc62cefe0b6a94beb8c0206ce`** (the failing CI run's head). Harness for every N run: **`83a7ac7`**.
> **Runs (E-CI):** pre [39862995934](https://github.com/Maatify/php-rate-limiter/actions/runs/39862995934), D [39863137688](https://github.com/Maatify/php-rate-limiter/actions/runs/39863137688), H [39863694941](https://github.com/Maatify/php-rate-limiter/actions/runs/39863694941), L [39865930971](https://github.com/Maatify/php-rate-limiter/actions/runs/39865930971). D on E-LOCAL at `83a7ac7` (`logs/d/E-LOCAL/harness-head`). Order kept: pre → D → H → L; no early stop; every job succeeded.

## 1. The two heads' lines

| Head | MUTATE statement | `self::fail('Malformed persisted Redis state was accepted.')` |
| --- | --- | --- |
| `fe8775c` (the CI failure, run 39570493869 job 119123135875) | **L1540** | **L3736** |
| `main@c8b8fd7` (arms D and L; D's detector) | **L1546** | **L3749** |

## 2. Inventory (question 1)

Unchanged from `PLAN.md` §2 (I-1…I-13, READ + LOG): no actor that could remove the key between READ and MUTATE was found; natural expiry, eviction and restart are excluded. L now measures it (§4): in 9,900 MUTATE records, `EXISTS` was 1 every time, `PTTL` 599,997–600,000 ms, `evicted_keys` 0, `run_id` constant in every run; V-CONFIG on all 3,301 containers of the L run (3,300 runs + the reference; `config.jsonl` has 3,320 lines because each shard's artifact repeats the reference's line): `redis_version` 7.0.15, `maxmemory` 0, `maxmemory-policy` noeviction.

## 3. Same resolved set, expected N (the two conditions, `PLAN.md` §10.7)

* **C-1.** `envset.php` over every artifact: the **reference and all 20 L shards** ran the same resolved set (lockset `4fc648b3…`, 31 packages) with **PHPUnit 11.5.57** and PHP 8.5.12: `differs_from_reference: []`, no conflicts (`logs/l/E-CI/envset.json`). The same set and versions in pre (1 job), D (3 jobs; E-LOCAL: the same set and PHPUnit, PHP 8.5.9) and H (14 jobs; the `fe8775c` tree resolved to the same set and PHPUnit too). No job is reported as different.
* **C-2.** Expected against verdict lines: L **3,300 / 3,300, shortfall 0, every shard 165 / 165**, no duplicated or out-of-range id; D 20 / 20 per case on both environments, shortfall 0; H-PROBE 4 × 25,000 and H-FORCED 10 × 1,000, every shard complete (summary line present), shortfall 0.

## 4. Arm L — loop on `main@c8b8fd7` (question 2)

**Scope:** prefix suite (75 files in the reference's order up to and including the target class, 1,394 tests), through the tap, one fresh Redis container per run, fixed N = 3,300 (20 × 165), on E-CI.

**P2 is not observable in L:** the tap adds +1,175 / +1,407 µs to the span from `t0` to the `'-1'` EXPIRE (p50 / p99, pre, K = 2,000), more than the whole sub-millisecond window P2 needs; P2 is measured by H, without the tap (§6). On `main` P2 no longer exists (#158), so L measures P1 only.

| Measure | Value |
| --- | --- |
| Runs / valid (V-ORD, V-FRAME, V-PORT, V-HEAD) | 3,300 / **3,300** (target ordinal 1,329 = `k_ref` in every run; `sequence_sha256` `fccd09eb…` = the detector runs') |
| Target reproduced ("accepted" at L3749 / L1546) | **0** |
| **Rate, Wilson 95 %** | **0 / 3,300 = 0 %, [0, 0.116 %]** |
| Power (≥ 1 failure in 3,300 runs) at the CI history rate 1 / 1,082 | **95.3 %** at the point rate; 41.6 % at its Wilson lower bound (0.016 %); > 99.99 % at its upper (0.52 %) |
| Tap findings (another connection or a keyspace-wide command between READ and MUTATE) | 0 |
| `PTTL` before MUTATE, 9,900 records | min 599,997, p50 599,999, max 600,000 ms; `EXISTS` 1 in all |
| Cost | 27.2 runner-hours (21 jobs), 1 h 52 min wall |

**Other failures (not counted in the rate; each run otherwise valid, target passed).** 11 / 3,300 runs (0.33 %, Wilson [0.19 %, 0.60 %]) had exactly one failure in **another** test. Output and tap logs: `logs/l/E-CI/other-failures/`.

| Test (`main@c8b8fd7`) | Assertion | Runs |
| --- | --- | --- |
| `RedisFullCapabilityStoreIntegrationTest::testLifecycleMutationPreservesPhysicalAndAuthoritativeExpiryAcrossAllK4Boundaries` | L1847 `assertTrue($legacyHandoff->applied)` | 133, 425, 1904, 2809 |
| `RedisFullCapabilityStoreIntegrationTest::testGenerationBoundLifecycleIsAtomicAndClaimDoesNotConsumeEvidence` | L1097 `assertFalse(claimPostPunishmentReentry(…))` | 722, 1281 |
| `KnownDeviceMixedVersionIntegrationTest::testAlternatingWorkersOnTheTrustedBurst` / `::testS0WorkerHonorsANewK5BlockForThatDeviceOnly` | `RateLimiterException: Bounded correlation store returned an invalid snapshot.` (`BoundedCorrelationResultValidator.php` L62) | 1500 / 1935 |
| `KnownDeviceAllowanceConcurrencyIntegrationTest::testLoginOverlapSplitsIntoRecordedConflictAndEarlyBlockExactly` | L136 "30 is identical to 28" | 2086, 2758 |
| `RedisFullCapabilityStoreIntegrationTest::testConcurrentPreviousHandoffHasNoDoubleSeedAndGenerationMutationsHaveNoLostUpdates` | L1956 "0 is identical to 1" | 3233 |

These ran **under the tap**, which lengthens every command by ≈ 0.06–0.09 ms; whether they also fail without it was not measured. They are outside #157 and are carried by **Issue #160**.

**Runs 1500 and 1935 (invalid snapshot), confirmed mechanism:** the S0 fixture reads the clock twice on one host. `S0EvaluationPipeline` (`tests/Fixtures/S0Baseline/`) takes `$now` at L785, then calls the older `addDistinctBoundedWithSnapshot` without a `now` at L793, so the store reads the time a second time from its `fallbackClock` (`RedisFullCapabilityStore` L1549 → `fallbackNow()` L2090). When a second boundary falls between the two reads, the snapshot carries `expiresAt = now + 601` against the pipeline's `$now`, and `BoundedCorrelationResultValidator` rejects it (`expiresAt > now + ttl`, L59; thrown at L62). The other nine runs are not diagnosed (#160).

## 5. Arm D — mechanism proof (question 3)

The tap deletes one case's key after READ's reply and before forwarding MUTATE; `--filter` on the target.

| Environment | `'0'` | `'-1'` | `'not-a-number'` |
| --- | --- | --- | --- |
| E-CI | 20 / 20 | 20 / 20 | 20 / 20 |
| E-LOCAL | 20 / 20 | 20 / 20 | 20 / 20 |

Every run (120 / 120): the test fails with exactly `Malformed persisted Redis state was accepted.` at **L3749 / L1546**; `PTTL` after the deletion −2; MUTATE's reply `{1, "9", <now>, <now+600>, 1}` (new generation 1) for the deleted case; shortfall 0. **An absent key (P1) produces the CI failure's exact signature.** D proves the mechanism, not the cause; and since P2 produces the same message and lines, the signature cannot tell P1 from P2.

## 6. Arm H — the historical path P2 at `fe8775c` (no tap)

| | H-PROBE (M = 100,000) | H-FORCED (M = 10,000; `t0` at < 200 µs into its second) |
| --- | --- | --- |
| `'-1'` MUTATE returned `{0}` (no exception) | **6** | **1,387** |
| Rate, Wilson 95 % | 0.006 %, [0.0027 %, 0.013 %] | 13.9 %, [13.2 %, 14.6 %] |
| `t0` in the first millisecond (u0 < 1,000 µs) | 96 | 10,000 |
| span `t0` → `'-1'` EXPIRE (min / p01 / p50 / p99, µs) | 512 / 549 / 1,358 / 1,795 | 720 / 805 / 1,440 / 2,066 |
| **H-1** (non-throw ⇒ u0 + span < 1,000 µs) | holds | **FINDING: 5 sequences** |
| **H-2** (D ≤ −3 ⇒ `{0}`; D ≥ 3 ⇒ throw) | holds | holds |
| **H-3** (every other call throws) | holds | holds |

D × outcome, both modes (`d_c1_outcome` in the reports): **D = 0: 1,388 / 1,388 returned `{0}`; D = 1: 5 / 8,030 returned `{0}`; D ≥ 2: 0 / 100,582.** No `D ≤ −3` (no backward Redis clock) in 110,000 sequences.

**H-1 finding (registered, against §1.3 as worded by the Lead).** Five H-FORCED sequences (`h1_violations` in `logs/h/E-CI/h-forced.report.json`; with their shard in `h-forced.rows.jsonl`) returned `{0}` with u0 + span = 1,396–1,437 µs (u0 27–99 µs, span 1,330–1,377 µs); all five have **D = 1**, inside the ±2 band H-2 does not judge. By §1.3, MUTATE returns `{0}` only if the key's expiry `X` ≤ `(t0s + 600) × 1000` as the script sees it; the client measured more than 1 ms from `t0` to the EXPIRE, and the Redis-side interval is at least that long. The mechanism, read from the Redis 7.0.15 source: `expireCommand` passes `mstime()` (`expire.c` L679–681), `ttlGenericCommand` computes `expire − mstime()` (`expire.c` L712), and `timeCommand` is a direct `gettimeofday` (`server.c` L4386–4394); none of them uses a command-time snapshot. Each command therefore floors the current wall-clock millisecond on its own, and the two reads of each side straddle a millisecond:

* the MUTATE script at `fe8775c` reads `TIME` first (`nowMs`) and `PTTL` later, so `nowMs + PTTL` is `expireAt` or `expireAt − 1`; when it is `expireAt − 1` and `expireAt = (t0s + 600) × 1000 + 1`, the check `expiresAt × 1000 < nowMs + PTTL` does not fire and MUTATE returns `{0}`;
* the probe reads `PTTL` and then `TIME` inside one `EXEC`, so its estimate is `expireAt` or `expireAt + 1`, i.e. D is exact or one too high.

The client measured more than 1 ms from `t0` to the EXPIRE, so the true D of these rows is at least 1; with the two floors, a true D of 1 is enough for `{0}`. The five rows lie inside the registered ±2 band; no row is reclassified, and H-1 stays recorded as above. It does not touch H-2 or H-3.

**P2 is reachable on a CI runner without a clock fault**: 6 / 100,000 natural sequences, and 1,387 / 10,000 when `t0` falls at the start of its second.

## 7. Preliminary decision: store defect or environment race?

**Preliminary: a store defect at `fe8775c` (P2), not an environment race (P1).** The evidence that separates them:

1. **P1 was looked for directly and not found.** On `main`, where only P1 can fail the test, 0 / 3,300 valid in-suite runs, with every MUTATE seeing the key present with 599,997–600,000 ms left and no foreign command in between (§4). At the CI history rate this N had 95 % power; the Wilson upper bound 0.116 % is close to the historical point 0.092 %, so a P1 at that rate is unlikely but not excluded (41.6 % power at the history's lower bound).
2. **P2 exists, at `fe8775c`, from the store's own check.** `validInteger(…, false)` accepted `'-1'`, so the only guard was the expiry comparison; MUTATE returned `{0}` with no exception in **every** sequence whose `EXPIRE` landed in the same Redis millisecond as the second boundary (D = 0: 1,388 / 1,388), plus 5 of 8,030 with D = 1, on E-CI, with no clock fault and no external actor (§6). That is a defect in the store's validation at that head, triggered by timing, and closed on `main` by #158.
3. **The signature does not decide it** (§5): both paths give "accepted" at the MUTATE line.

**What weakens this:** the CI failure's own path is still not observed. H gives P2's rate only for a uniformly placed `t0` (0.006 %, [0.0027 %, 0.013 %]); the CI history gives 1 / 1,082 (0.092 %, [0.016 %, 0.52 %]). The intervals do not overlap, so **H alone does not account for the historical rate**. Inside the suite `t0` is not uniform: L's tap recorded the target's `t0` in all 3,300 runs, 78 % in the second half of the second and **none** in its first millisecond (min u0 1,004 µs). That distribution was measured under the tap and on `main`, so it is not the untapped `fe8775c` one; it shows only that the in-suite phase is structured, so H's rate cannot be carried over to the suite in either direction. Decisive for this CI failure would be the untapped in-suite `t0` phase at `fe8775c`, which no arm measured (H-SUITE was ruled out, LT-157-01).

## 8. Not proven

* Which path the single CI failure (run 39570493869) took.
* Whether the 11 other failures in L (§4) happen without the tap, and the cause of the nine not diagnosed (#160).
* The untapped in-suite `t0` phase at either head; P1 below 0.116 %.

## 9. Files

`logs/pre/E-CI/` (overhead K = 2,000), `logs/d/E-CI/`, `logs/d/E-LOCAL/`, `logs/h/E-CI/` (both reports with `--expect`, all 10,000 H-FORCED rows; H-PROBE rows only in the run's artifacts, 30-day retention), `logs/l/E-CI/` (`verdicts.jsonl` with every MUTATE record, `summary.json` with `--expect=l:1-3300:165`, `envset.json`, `kref.json`, `prefix.xml`, the reference output, `other-failures/`). Each folder has `SHA256SUMS`.
