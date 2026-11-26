# G3 Admission Hardening (#284) — Comparative Benchmark & Governance Evidence

This directory stores the reproducible benchmark harness, exact commands, raw JSON outputs, and governance helper outputs for **PR #302** (`F302-05` / `F302-R2-05` / `F302-R3-03`).

## 1. Safety & Fail-Closed Guardrails (`bench.php`)

- **No default localhost fallback**: `bench.php` refuses to run unless both `REDIS_INTEGRATION_HOST` and numeric `REDIS_INTEGRATION_PORT` are explicitly provided.
- **Explicit destructive opt-in**: `FLUSHDB` is refused unless `BENCH_ALLOW_FLUSHDB=1` is set on an isolated disposable Redis instance.
- **Semantic assertions inside every loop**: every `checkOnly` (`decision` + `lane` verified from the signed permit payload on warmup), outcome settlement (`decision`, `cause`), and `bindingEpoch` is asserted per iteration; any divergence aborts the benchmark.
- **Source provenance**: every JSON output records `source_root`, `source_sha`, `source_tree`, `source_dirty`, and `src_tree_disk_sha256` (SHA-256 over all sorted `src/` files on disk).

## 2. Environment, Provenance & Fixture Parameters

- **Base Commit (`origin/main`)**: `1d87036c87773baf2432a0e01f3202a99bb4930b` (`origin/main^{tree}` = `8f54734bb4243492b0539ce2fffd076a2c98e65ce`)
- **Measured G3 Source (`src/`)**: `301380a0678e34b22aadf7bf127c129bbaa13502` (`src/` Git tree `3cc64e8e1ab0b322a8d6b9cad7611bc8b3c614b3`, `src_tree_disk_sha256` = `12bcd5942455...` matching `HEAD` `src/` bit-for-bit; `0` changes to `src/` after `501c3dcf`)
- **PHP Runtime**: `8.5.13` (`PHP_INT_SIZE = 8`)
- **Redis**: `redis:7.0.15-alpine` via `scripts/ci/run-with-redis-service.sh`
- **Workload Parameters**: `warmup = 15`, `iters = 150` per scenario, `FLUSHDB` before each scenario.
- **Scenario #4 Naming (`opted_lane_p_previous_configured`)**: configures `previousKeySecret` on a fresh Redis store (no pre-populated prior-generation state); it measures the dual-secret configuration path rather than an in-flight migration.

## 3. Exact Reproduction Commands

```bash
# 1. Governance context on base (1d87036c)
git worktree add --detach /private/tmp/g3-bench-baseline 1d87036c87773baf2432a0e01f3202a99bb4930b
php .agents/helpers/maatify-governance-context.php --root=/private/tmp/g3-bench-baseline \
  > investigations/admission-hardening-g3-284/raw/governance-base.json

# 2. Controlled 3x interleaved benchmarks (baseline vs G3) on isolated Redis
git worktree add --detach /private/tmp/g3-bench-head 301380a0678e34b22aadf7bf127c129bbaa13502
bash scripts/ci/run-with-redis-service.sh php -r '
$repo = getcwd();
$runs = [];
for ($rep = 1; $rep <= 3; $rep++) {
    foreach ([["baseline", "/private/tmp/g3-bench-baseline"], ["g3", "/private/tmp/g3-bench-head"]] as [$arm, $src]) {
        $out = shell_exec("BENCH_ALLOW_FLUSHDB=1 php " . escapeshellarg($repo . "/investigations/admission-hardening-g3-284/bench.php") . " " . escapeshellarg($repo) . " " . escapeshellarg($arm) . " " . escapeshellarg($src));
        $decoded = json_decode((string) $out, true, 512, JSON_THROW_ON_ERROR);
        $decoded["rep"] = $rep;
        $runs[] = $decoded;
    }
}
file_put_contents($repo . "/investigations/admission-hardening-g3-284/raw/bench-controlled-runs.json", json_encode($runs, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
'
git worktree remove --force /private/tmp/g3-bench-baseline
git worktree remove --force /private/tmp/g3-bench-head

# 3. Governance context on final HEAD
php .agents/helpers/maatify-governance-context.php --root="$(pwd)" \
  > investigations/admission-hardening-g3-284/raw/governance-head.json
```

## 4. Raw Artifact Index

- Harness source: [bench.php](bench.php)
- R1/R2 historical single-run raw JSONs (preserved from the `501c3dcf` working tree run):
  - [raw/bench-baseline.json](raw/bench-baseline.json)
  - [raw/bench-g3.json](raw/bench-g3.json)
- R3 3x interleaved controlled runs (`baseline`, `g3` × 3 repetitions; 4,500 measured samples total):
  - [raw/bench-controlled-runs.json](raw/bench-controlled-runs.json)
- Governance helper raw JSONs:
  - [raw/governance-base.json](raw/governance-base.json) (`1d87036c`)
  - [raw/governance-head.json](raw/governance-head.json)

## 5. Controlled 3x Interleaved Results (`baseline` `1d87036c` vs `g3`)

[raw/bench-controlled-runs.json](raw/bench-controlled-runs.json) interleaves `baseline` and `g3` across 3 alternating runs (`rep 1..3`) inside one isolated Redis container. Wall time is decomposed around `TimingRedisCommandExecutor` (`wall outside executor = wall_total_ms - redis_wait_ms`, which covers PHP application work plus host scheduling; `wall inside executor` covers Redis round-trip/Lua execution plus RESP serialization/parsing), while `cpu_total_ms` is measured independently via `getrusage()`:

| Scenario | Arm | CPU Total (ms) [min, med, max] | Wall Outside Executor (ms) [min, med, max] | Wall Inside Executor (ms) [min, med, max] | p50 (µs) median | Redis Cmds / iter |
|---|---|---|---:|---:|---:|---:|
| `non_opted_login` (control) | baseline | 1020.04 / **1061.76** / 1099.43 | 565.26 / **618.30** / 618.48 | 4509.69 / **4526.70** / 4689.10 | 34233.5 | 62 |
| `non_opted_login` (control) | g3 | 1030.11 / **1048.33** / 1056.51 | 569.92 / **584.79** / 586.94 | 4518.05 / **4525.37** / 4536.07 | 33708.0 | 62 |
| `opted_lane_u` | baseline | 681.44 / **709.81** / 724.18 | 402.29 / **424.41** / 435.03 | 2915.26 / **2938.86** / 3131.60 | 22332.3 | 42 |
| `opted_lane_u` | g3 | 724.27 / **776.75** / 785.61 | 441.50 / **477.85** / 481.26 | 2858.26 / **2872.73** / 2982.20 | 22278.7 | 41 |
| `opted_lane_p` | baseline | 472.61 / **482.72** / 560.53 | 285.99 / **293.06** / 343.77 | 2124.50 / **2133.57** / 2154.17 | 15648.8 | 32 |
| `opted_lane_p` | g3 | 527.40 / **537.64** / 538.59 | 341.52 / **349.02** / 350.83 | 2070.86 / **2075.76** / 2079.72 | 15737.6 | 31 |
| `opted_lane_p_previous_configured` | baseline | 763.90 / **815.05** / 879.49 | 444.20 / **478.77** / 517.33 | 3402.14 / **3415.38** / 3531.76 | 25611.0 | 50 |
| `opted_lane_p_previous_configured` | g3 | 828.62 / **844.33** / 905.44 | 513.76 / **525.97** / 573.98 | 3336.52 / **3343.48** / 3374.35 | 25494.8 | 49 |
| `account_revocation_and_epoch` | baseline | 19.10 / **21.30** / 22.00 | 12.18 / **13.53** / 14.20 | 102.69 / **103.10** / 116.16 | 744.0 | 2 |
| `account_revocation_and_epoch` | g3 | 20.15 / **29.34** / 31.72 | 13.16 / **19.78** / 21.37 | 102.75 / **104.60** / 105.69 | 771.0 | 2 |

## 6. Observed Differences & Measurement Boundaries

1. **Non-opted control (`non_opted_login`)**: median CPU (`1061.76` vs `1048.33 ms`, `-1.3%`), median wall inside executor (`4526.70` vs `4525.37 ms`), and Redis command count (`62/iter`) are essentially flat across the 3 interleaved runs.
2. **Opted Admission paths (`opted_lane_u`, `opted_lane_p`, `opted_lane_p_previous_configured`)**:
   - Observed median `CPU Total` is higher in G3 (`+66.94 ms`, `+54.92 ms`, `+29.28 ms` across 150 iterations), and observed median `Wall Outside Executor` is higher (`+53.44 ms`, `+55.96 ms`, `+47.20 ms`, i.e., `~0.31–0.37 ms/iter`), consistent with G3's additional length-prefixed `KeyPreimageFraming` and context/credential verification work.
   - Observed median `Wall Inside Executor` is lower in G3 (`-66.13 ms`, `-57.81 ms`, `-71.90 ms`), consistent with 1 fewer Redis command per iteration (`41` vs `42`, `31` vs `32`, `49` vs `50`), leaving median end-to-end `p50` latency within `~0.09 ms` of baseline (`22.33 -> 22.28 ms`, `15.65 -> 15.74 ms`, `25.61 -> 25.49 ms`).
3. **`account_revocation_and_epoch`**: median CPU is `21.30 -> 29.34 ms` over 150 iterations (`+0.05 ms/iter`), median `p50` is `744.0 -> 771.0 µs` (`+27 µs`), with `2` Redis commands/iter in both arms.
4. **Measurement limits**: `wall outside executor` includes PHP work plus OS scheduling, and `wall inside executor` includes RESP serialization/I/O/parsing in addition to Redis latency; all 3 repetitions ran in fixed `baseline` -> `g3` order.
5. **TTL Hygiene (`scopedKeysWithoutTtl = 0` across all runs)**: all scoped keys carry positive TTLs (`594–86400 s`); only durable control keys (`adm:a1:cfg`, `adm:a1:fence`, `adm:a1:epoch`) persist (`TTL = -1`).
