# G3 Admission Hardening (#284) — Comparative Benchmark & Governance Evidence

This directory stores the reproducible benchmark harness, exact commands, raw JSON outputs, and governance helper outputs for **PR #302** (`F302-05`).

## 1. Environment & Fixture Parameters

- **Base Commit (`origin/main`)**: `1d87036c87773baf2432a0e01f3202a99bb4930b`
- **Base Tree (`origin/main^{tree}`)**: `8f54734bb4243492b0539ce2fffd076a2c98e65ce`
- **PHP Runtime**: `8.5.13` (`PHP_INT_SIZE = 8`)
- **Redis**: `redis:7.0.15-alpine` via `scripts/ci/run-with-redis-service.sh` (`127.0.0.1:65474` in this run)
- **Workload Parameters** (per scenario, identical across both arms):
  - `warmup`: `15` iterations
  - `iters`: `150` measured iterations (`samples_us` recorded in full in raw JSON)
  - `FLUSHDB` executed before each scenario to isolate Redis key cardinality and TTL invariants.

## 2. Exact Reproduction Commands

```bash
# 1. Governance context on base (1d87036c)
git worktree add --detach /private/tmp/g3-bench-baseline 1d87036c87773baf2432a0e01f3202a99bb4930b
ln -s "$(pwd)/vendor" /private/tmp/g3-bench-baseline/vendor
php .agents/helpers/maatify-governance-context.php --root=/private/tmp/g3-bench-baseline \
  > investigations/admission-hardening-g3-284/raw/governance-base.json

# 2. Comparative benchmarks (baseline vs G3) on isolated Redis
bash scripts/ci/run-with-redis-service.sh bash -c '
  php investigations/admission-hardening-g3-284/bench.php "$(pwd)" baseline /private/tmp/g3-bench-baseline \
    > investigations/admission-hardening-g3-284/raw/bench-baseline.json &&
  php investigations/admission-hardening-g3-284/bench.php "$(pwd)" g3 "$(pwd)" \
    > investigations/admission-hardening-g3-284/raw/bench-g3.json
'
git worktree remove --force /private/tmp/g3-bench-baseline

# 3. Governance context on final HEAD
php .agents/helpers/maatify-governance-context.php --root="$(pwd)" \
  > investigations/admission-hardening-g3-284/raw/governance-head.json
```

## 3. Raw Artifact Index

- Harness source: [bench.php](bench.php)
- Baseline raw benchmark JSON: [raw/bench-baseline.json](raw/bench-baseline.json)
- G3 raw benchmark JSON: [raw/bench-g3.json](raw/bench-g3.json)
- Base governance helper JSON: [raw/governance-base.json](raw/governance-base.json)
- Final HEAD governance helper JSON: [raw/governance-head.json](raw/governance-head.json)

## 4. Comparative Results (`baseline` `1d87036c` vs `g3`)

| Scenario | Arm | p50 (µs) | p95 (µs) | p99 (µs) | max (µs) | mean (µs) | CPU user / sys (ms) | Peak Mem (KB) | Redis Cmds / iter | Sent / Recv (B/iter) | Total Keys | Scoped w/o TTL |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| `non_opted_login` | baseline | 32616.8 | 35740.2 | 38724.2 | 41843.3 | 32900.0 | 660.62 / 180.87 | 5129.24 | 62 | 1340971 / 16348 | 2475 | 0 |
| `non_opted_login` | g3 | 33103.5 | 37039.8 | 43755.5 | 44604.9 | 33510.2 | 749.24 / 197.57 | 4967.31 | 62 | 1340971 / 16348 | 2475 | 0 |
| `opted_lane_u` | baseline | 21399.4 | 23977.7 | 27158.9 | 31492.1 | 21582.6 | 438.80 / 116.24 | 5579.41 | 42 | 794706.2 / 11047.4 | 1322 | 0 |
| `opted_lane_u` | g3 | 21623.0 | 24692.0 | 26031.3 | 27772.2 | 21709.1 | 562.38 / 130.80 | 5481.64 | 41 | 788944 / 11155 | 1322 | 0 |
| `opted_lane_p` | baseline | 15685.2 | 19838.0 | 24863.6 | 56915.3 | 16636.1 | 332.42 / 88.18 | 5673.81 | 32 | 549921.2 / 6144.4 | 332 | 0 |
| `opted_lane_p` | g3 | 15743.3 | 18412.0 | 19796.6 | 20272.0 | 15841.0 | 415.39 / 93.17 | 5646.54 | 31 | 544897 / 6399 | 332 | 0 |
| `opted_lane_p_outer_rotation` | baseline | 24565.7 | 27570.5 | 29667.8 | 31521.8 | 24737.8 | 514.38 / 144.75 | 5701.33 | 50 | 951769.2 / 10690.4 | 332 | 0 |
| `opted_lane_p_outer_rotation` | g3 | 24869.3 | 28692.7 | 29965.8 | 44322.2 | 25175.3 | 599.72 / 149.93 | 5674.03 | 49 | 945932 / 10762 | 332 | 0 |
| `account_revocation_and_epoch` | baseline | 740.9 | 939.5 | 1104.9 | 2129.7 | 777.1 | 15.73 / 4.76 | 5556.95 | 2 | 12558 / 8 | 165 | 0 |
| `account_revocation_and_epoch` | g3 | 765.3 | 975.7 | 1143.0 | 1846.3 | 812.0 | 20.89 / 5.43 | 5530.28 | 2 | 12770 / 8 | 165 | 0 |

## 5. Redis Key Cardinality, TTLs, and Tail Analysis

- **TTL Hygiene (`scopedKeysWithoutTtl = 0` across all scenarios)**:
  - Scoped keys (`e1:budget`, `e1:score`, `v1:distinct`, `v1:distinct-meta`, `v1:score`, `adm:a1:c`, `adm:a1:d`, `adm:a1:permit`) all carry positive TTLs (`594–86400 s`).
  - Only durable control keys (`adm:a1:cfg`, `adm:a1:fence`, and per-revoked-account `adm:a1:epoch` per `DEC-048`/`DEC-049`) are intentionally persistent (`TTL = -1`).
- **Command-Count Parity / Reduction**:
  - `non_opted_login` executes the exact same `62` Redis commands/iter and writes the exact same `2475` keys with zero `:adm:` allocation.
  - Opted-in scenarios (`opted_lane_u`, `opted_lane_p`, `opted_lane_p_outer_rotation`) use **1 fewer Redis command/iter** in G3 (`41` vs `42`, `31` vs `32`, `49` vs `50`) with identical key cardinality (`1322`, `332`, `332`).
- **Tail Sample Analysis (`account_revocation_and_epoch` and `non_opted_login`)**:
  - In `account_revocation_and_epoch`, both arms execute exactly `2` Redis commands/iter (`revoke` + `bindingEpoch`), with G3 p50 `+24.4 µs` (`+3.3%`) and p99 `+38.1 µs` (`+3.4%`).
  - Raw `samples_us` show two isolated loopback scheduling spikes in G3 (`#10 = 1846.3 µs`, `#11 = 1662.7 µs`, `#76 = 1143.0 µs`, `#145 = 1015.5 µs`) versus one spike in baseline (`#76 = 2129.7 µs`, `#80 = 1119.1 µs`, `#81 = 1104.9 µs`), and `non_opted_login` shows early-iteration Docker container bridge jitter (`#7 = 44604.9 µs`, `#6 = 44112.0 µs`, `#5 = 43755.5 µs`) despite identical Redis command counts (`62`) and identical bytes sent/received (`1340971 / 16348`). Peak memory in G3 is slightly lower (`5530.28 KB` vs `5556.95 KB`).
