# G3 Admission Hardening (#284) — Comparative Benchmark & Governance Evidence

This directory stores the reproducible benchmark harness, exact commands, raw JSON outputs, and governance helper outputs for **PR #302** (`F302-05` / `F302-R2-05`).

## 1. Safety & Fail-Closed Guardrails (`bench.php`)

- **No default localhost fallback**: `bench.php` refuses to run unless both `REDIS_INTEGRATION_HOST` and numeric `REDIS_INTEGRATION_PORT` are explicitly provided.
- **Explicit destructive opt-in**: `FLUSHDB` is refused unless `BENCH_ALLOW_FLUSHDB=1` is set on an isolated disposable Redis instance.
- **Semantic assertions inside every loop**: every `checkOnly`, outcome settlement, `decision`, `cause`, and `bindingEpoch` is asserted per iteration; any divergence aborts the benchmark.
- **Exact source provenance**: every JSON output records `source_root`, `source_sha`, `source_tree`, and `source_dirty`.

## 2. Environment & Fixture Parameters

- **Base Commit (`origin/main`)**: `1d87036c87773baf2432a0e01f3202a99bb4930b` (`origin/main^{tree}` = `8f54734bb4243492b0539ce2fffd076a2c98e65ce`)
- **PHP Runtime**: `8.5.13` (`PHP_INT_SIZE = 8`)
- **Redis**: `redis:7.0.15-alpine` via `scripts/ci/run-with-redis-service.sh`
- **Workload Parameters**: `warmup = 15`, `iters = 150` per scenario, `FLUSHDB` before each scenario.
- **Scenario #4 Naming (`opted_lane_p_previous_configured`)**: configures `previousKeySecret` on a fresh Redis store (no pre-populated prior-generation state); it measures the dual-secret configuration path rather than an in-flight migration.

## 3. Raw Artifact Index

- Harness source: [bench.php](bench.php)
- R1/R2 single-run raw JSONs (captured from the `501c3dcf` working tree before commit):
  - [raw/bench-baseline.json](raw/bench-baseline.json)
  - [raw/bench-g3.json](raw/bench-g3.json)
- R2 3x interleaved controlled runs (`baseline`, `g3` × 3 repetitions with `php_work_ms` vs `redis_wait_ms` decomposition):
  - [raw/bench-controlled-runs.json](raw/bench-controlled-runs.json)
- Governance helper raw JSONs:
  - [raw/governance-base.json](raw/governance-base.json) (`1d87036c`)
  - [raw/governance-head.json](raw/governance-head.json)

## 4. Controlled 3x Interleaved Results (`baseline` `1d87036c` vs `g3`, PHP vs Redis Decomposition)

To separate host/OS scheduling variance from actual PHP CPU work and Redis network/Lua latency, [raw/bench-controlled-runs.json](raw/bench-controlled-runs.json) interleaves `baseline` and `g3` across 3 alternating runs (`rep 1..3`) inside one isolated Redis container and instruments `TimingRedisCommandExecutor` to split wall time into `redis_wait_ms` and `php_work_ms = wall_total_ms - redis_wait_ms`:

| Scenario | Arm | CPU Total (ms) [min, med, max] | PHP Work (ms) [min, med, max] | Redis Wait (ms) [min, med, max] | p50 (µs) median | Redis Cmds / iter |
|---|---|---|---:|---:|---:|---:|
| `non_opted_login` (control) | baseline | 871.88 / **986.81** / 1045.99 | 473.47 / **539.45** / 575.27 | 4492.38 / **4493.89** / 4519.72 | 32998.7 | 62 |
| `non_opted_login` (control) | g3 | 1007.73 / **1035.97** / 1048.85 | 550.44 / **567.58** / 579.63 | 4492.47 / **4497.56** / 4499.12 | 33306.4 | 62 |
| `opted_lane_u` | baseline | 675.06 / **685.92** / 703.94 | 399.99 / **401.47** / 411.52 | 2903.85 / **2904.75** / 3030.83 | 21837.5 | 42 |
| `opted_lane_u` | g3 | 718.85 / **719.35** / 721.08 | 435.37 / **435.88** / 437.84 | 2832.45 / **2844.79** / 2865.26 | 21601.1 | 41 |
| `opted_lane_p` | baseline | 517.32 / **535.92** / 571.21 | 313.32 / **325.39** / 350.73 | 2123.42 / **2124.59** / 2133.68 | 16373.8 | 32 |
| `opted_lane_p` | g3 | 590.70 / **600.29** / 605.91 | 381.67 / **388.03** / 391.07 | 2065.93 / **2077.88** / 2078.15 | 16318.5 | 31 |
| `opted_lane_p_previous_configured` | baseline | 714.58 / **778.78** / 824.02 | 411.88 / **451.82** / 480.89 | 3360.64 / **3367.21** / 3382.91 | 25296.1 | 50 |
| `opted_lane_p_previous_configured` | g3 | 808.32 / **858.55** / 860.94 | 498.08 / **532.08** / 533.99 | 3299.53 / **3300.01** / 3338.36 | 25267.9 | 49 |
| `account_revocation_and_epoch` | baseline | 25.35 / **54.68** / 55.37 | 16.61 / **37.48** / 38.45 | 104.49 / **108.09** / 108.88 | 913.9 | 2 |
| `account_revocation_and_epoch` | g3 | 23.33 / **25.20** / 28.03 | 14.89 / **16.66** / 18.57 | 104.10 / **109.71** / 121.86 | 788.1 | 2 |

## 5. Evidence-Backed Interpretation of CPU and Latency

1. **Non-opted control (`non_opted_login`) & `account_revocation_and_epoch`:**
   - The earlier single-run `+12.5%` CPU difference on `non_opted_login` and `+28.5%` on `account_revocation_and_epoch` do not hold under interleaved repetitions: `non_opted_login` ranges overlap (`871.88–1045.99 ms` vs `1007.73–1048.85 ms`, median `+4.98%`) with identical Redis commands (`62`), identical bytes (`1340971 / 16348`), and identical `redis_wait_ms` (`4493.89` vs `4497.56 ms`), while `account_revocation_and_epoch` median CPU is actually lower in G3 (`25.20 ms` vs `54.68 ms`, minimum `23.33 ms` vs `25.35 ms`).
2. **Real, bounded PHP CPU cost on opted Admission paths (`+0.23` to `+0.54 ms/iter`):**
   - Across all 3 repetitions, `php_work_ms` in G3 is consistently higher on opted paths:
     - `opted_lane_u`: `+34.41 ms` over 150 iterations (`+0.23 ms/iter`, `+8.6%` PHP work; `+4.9%` median total CPU).
     - `opted_lane_p`: `+62.64 ms` over 150 iterations (`+0.42 ms/iter`, `+19.3%` PHP work; `+12.0%` median total CPU).
     - `opted_lane_p_previous_configured`: `+80.26 ms` over 150 iterations (`+0.54 ms/iter`, `+17.8%` PHP work; `+10.2%` median total CPU).
   - This PHP-side work comes directly from G3's hardening operations: length-prefixed `KeyPreimageFraming::encodeTuple()` (`pack('N', ...)` + SHA-256) on `a1` keys, domain-separated credential/context verification in `AdmissionCredentialCodec` / `AdmissionKeyDeriver`, and permit-bound routing/context checks (`DEC-050`).
3. **Net end-to-end latency offset (`-47` to `-67 ms` Redis wait):**
   - Because G3 executes 1 fewer Redis command per iteration on opted paths (`41` vs `42`, `31` vs `32`, `49` vs `50`), `redis_wait_ms` drops by `46.71–67.20 ms` per 150 iterations, keeping median end-to-end `p50` latency equal or slightly lower (`21.84 -> 21.60 ms`, `16.37 -> 16.32 ms`, `25.30 -> 25.27 ms`).
4. **TTL Hygiene (`scopedKeysWithoutTtl = 0` across all runs):**
   - All scoped keys carry positive TTLs (`594–86400 s`); only durable control keys (`adm:a1:cfg`, `adm:a1:fence`, `adm:a1:epoch`) intentionally persist (`TTL = -1`).
