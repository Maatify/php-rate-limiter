# #210 — EVALSHA measurement study: results

Baseline `main@743de0435fea70ab4a3292ad9ca4e9743c198354`. Plan and acceptance criteria: [`PLAN.md`](PLAN.md), committed before the first measurement. Lead brief: [#210 comment 6887007913](https://github.com/Maatify/php-rate-limiter/issues/210#issuecomment-6887007913).
Raw results: [`raw/`](raw/) (one JSON file per cell, every request is a record; `raw/environment.txt`; `raw/summary.md` is the output of `php summarize.php`). Commands: `run.sh` and `repeat-contention.sh`. The main run used head `6e846dee601315944529c1a610b53b09cf5fcc0c`; the repeated contention runs and the suites were run on later commits of this branch (the harness and the tests changed, `src/` did not); the exact head of the pull request is on #210.

## Recommendation: revise, do not adopt as it stands; keep `EVAL` as the default

`EVALSHA` removes about 96 % of the bytes the client sends and, on a local network, cuts the median request latency by 19 % to 23 % and the tail under contention by about 45 %; with about 7 ms of round trip the latency is unchanged, and it never adds a command in the warm state. Correctness is identical in every mode that was exercised. What is not established is how the clients the package would claim report a cache miss: with ext-redis `rawCommand()`, `NOSCRIPT` comes back as `false`, the same return value as any `ERR` reply, and the executor contract (DEC-018) does not expose the error text. An implementation needs a bounded, client-specific capability for that, which is a change of the public executor contract and is proposed separately for the Owner's approval (section "What a production implementation would need"). No production change is made or approved by this study.

| Criterion (PLAN.md) | Result |
| --- | --- |
| A1 decisions, final state and the suites identical in every mode | Met. 64-bit: all of `tests/Integration` (937 tests) passes in all four modes. 32-bit: the same three failures, present with `EVAL`, in all four modes. Public workload: same decisions and state in every mode (`EvalshaStudyRedisTest`). |
| A2 no replay after an unknown transport result; fallback only on an identified `NOSCRIPT` | Met for the client measured (`EvalshaStudyRedisTest`: lost request, lost reply, other error replies). |
| A3 at least 80 % fewer bytes sent on every workload, no extra warm round trip | Met: 95.5 % to 97.4 %, commands unchanged. |
| A4 cold and flushed cost reported and bounded; p99 under contention not worse by more than 10 % | Met, with a caveat: loopback p99 is lower; with about 7 ms RTT the p99 of 8 workers is higher in 6 of 6 repeats, by 7.2 % (median 1172 ms against 1093 ms); the cause was not investigated. |
| A5 how each claimed client reports `NOSCRIPT`, without a blanket catch | **Not met.** Shown for the stream-socket test client and ext-redis 6.2.0 only; ext-redis returns `false`. Predis and any other client were not measured. |

## Method and environment

One machine, one Redis 7.0.15 (`redis:7.0.15-alpine`, no persistence) in Docker, a stream-socket RESP client without pipelining (`tests/Support/Redis/RespRedisCommandExecutor`), PHP 8.4.27 with `PHP_INT_SIZE` 8 (Apple M1, 8 cores; the full environment is in `raw/environment.txt`). Every request goes through the public builder; the only variable between the compared cells is the script mode of the client and, in the RTT cells, a TCP proxy (3.5 ms each way) in front of Redis. Sent and received bytes are counted on the socket by the client. Samples: 200 requests per local cell after 20 warm-up requests (recovery 40 after 3), 40 per RTT cell (recovery 15). Percentiles are nearest-rank on the samples.

**Limits of what was measured.** Single node, loopback and one simulated added round trip; the clients are one test client and ext-redis for error shapes only. No Redis Cluster, replication or failover, no pipelining, no Predis, no managed service, no proven-lane permit claim with a credential. A cache loss was simulated with `SCRIPT FLUSH` and one server restart; that is not a failover. Each cell is one run except the repeated contention runs; the loopback figures carry the scheduling noise of a laptop (compare the spread in the repeated runs).

## Results

#### Per-request cost, local loopback (median of the measured requests)

| Workload | Mode | n | sent KB | received KB | commands | p50 ms | p95 ms | p99 ms | NOSCRIPT | errors |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| read | eval | 200 | 484.7 | 7.6 | 24 | 12.78 | 18.60 | 37.47 | 0 | 0 |
| read | evalsha | 200 | 12.7 | 7.6 | 24 | 9.90 | 12.64 | 26.19 | 0 | 0 |
| read | evalsha-flush-each | 200 | 498.0 | 8.8 | 72 | 53.61 | 71.05 | 76.55 | 4800 | 0 |
| read | evalsha-flush-commit | 200 | 12.7 | 7.6 | 24 | 10.06 | 13.79 | 21.50 | 0 | 0 |
| newdevice | eval | 200 | 497.5 | 7.9 | 25 | 13.12 | 18.81 | 24.81 | 0 | 0 |
| newdevice | evalsha | 200 | 20.6 | 7.9 | 25 | 10.55 | 15.01 | 19.12 | 0 | 0 |
| newdevice | evalsha-flush-each | 200 | 518.8 | 9.2 | 75 | 55.36 | 71.32 | 104.32 | 5000 | 0 |
| newdevice | evalsha-flush-commit | 200 | 228.3 | 8.4 | 36 | 25.24 | 32.18 | 40.08 | 2000 | 0 |
| failure | eval | 200 | 762.7 | 8.3 | 37 | 19.70 | 29.05 | 46.65 | 0 | 0 |
| failure | evalsha | 200 | 26.1 | 8.3 | 37 | 15.44 | 19.81 | 26.06 | 0 | 0 |
| failure | evalsha-flush-each | 200 | 789.8 | 10.2 | 111 | 83.15 | 99.62 | 120.83 | 7400 | 0 |
| failure | evalsha-flush-commit | 200 | 355.3 | 9.0 | 53 | 38.47 | 45.62 | 48.09 | 3000 | 0 |
| admission-reserve | eval | 200 | 406.6 | 6.6 | 22 | 11.41 | 15.25 | 16.26 | 0 | 0 |
| admission-reserve | evalsha | 200 | 18.5 | 6.6 | 22 | 9.23 | 11.90 | 15.78 | 0 | 0 |
| admission-reserve | evalsha-flush-each | 200 | 425.7 | 7.7 | 66 | 48.23 | 58.60 | 64.83 | 4400 | 0 |
| admission-reserve | evalsha-flush-commit | 200 | 239.3 | 7.1 | 34 | 25.49 | 30.98 | 35.85 | 2200 | 0 |
| admission-outcome | eval | 200 | 699.9 | 6.4 | 36 | 19.09 | 26.72 | 34.16 | 0 | 0 |
| admission-outcome | evalsha | 200 | 24.3 | 6.4 | 36 | 14.69 | 21.66 | 25.44 | 0 | 0 |
| admission-outcome | evalsha-flush-each | 200 | 725.2 | 8.2 | 108 | 80.04 | 95.07 | 103.82 | 7200 | 0 |
| admission-outcome | evalsha-flush-commit | 200 | 317.5 | 7.1 | 52 | 36.32 | 44.35 | 49.80 | 3000 | 0 |
| recovery | eval | 40 | 579.1 | 9.9 | 30 | 15.51 | 21.19 | 27.89 | 0 | 0 |
| recovery | evalsha | 40 | 23.6 | 9.9 | 30 | 12.54 | 19.69 | 58.17 | 0 | 0 |
| recovery | evalsha-flush-each | 40 | 603.5 | 11.4 | 88 | 65.12 | 79.06 | 97.71 | 1160 | 0 |
| recovery | evalsha-flush-commit | 40 | 293.9 | 10.6 | 45 | 31.87 | 39.02 | 46.08 | 560 | 0 |

#### Per-request cost with about 7 ms added round trip (3.5 ms each way)

| Workload | Mode | n | sent KB | received KB | commands | p50 ms | p95 ms | p99 ms | NOSCRIPT | errors |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| read | eval | 40 | 484.7 | 7.6 | 24 | 258.38 | 269.19 | 272.49 | 0 | 0 |
| read | evalsha | 40 | 12.7 | 7.6 | 24 | 261.01 | 276.33 | 329.43 | 0 | 0 |
| newdevice | eval | 40 | 497.5 | 7.9 | 25 | 272.95 | 281.70 | 289.20 | 0 | 0 |
| newdevice | evalsha | 40 | 20.6 | 7.9 | 25 | 273.32 | 284.50 | 293.94 | 0 | 0 |
| newdevice | evalsha-flush-each | 25 | 518.8 | 9.2 | 75 | 853.76 | 870.83 | 878.79 | 625 | 0 |
| failure | eval | 40 | 762.7 | 8.3 | 37 | 399.95 | 412.30 | 433.47 | 0 | 0 |
| failure | evalsha | 40 | 26.1 | 8.3 | 37 | 403.27 | 421.83 | 435.10 | 0 | 0 |
| admission-reserve | eval | 40 | 406.6 | 6.6 | 22 | 238.56 | 247.70 | 255.74 | 0 | 0 |
| admission-reserve | evalsha | 40 | 18.5 | 6.6 | 22 | 238.22 | 247.91 | 251.98 | 0 | 0 |
| admission-outcome | eval | 40 | 699.9 | 6.4 | 36 | 392.32 | 402.49 | 405.83 | 0 | 0 |
| admission-outcome | evalsha | 40 | 24.3 | 6.4 | 36 | 393.88 | 408.52 | 413.23 | 0 | 0 |
| recovery | eval | 15 | 579.1 | 9.9 | 30 | 328.58 | 336.18 | 336.18 | 0 | 0 |
| recovery | evalsha | 15 | 23.6 | 9.9 | 30 | 330.48 | 337.26 | 337.26 | 0 | 0 |

#### Reduction in bytes sent, evalsha against eval (median per request)

| Network | Workload | eval KB | evalsha KB | reduction |
| --- | --- | ---: | ---: | ---: |
| local | read | 484.7 | 12.7 | 97.4 % |
| local | newdevice | 497.5 | 20.6 | 95.9 % |
| local | failure | 762.7 | 26.1 | 96.6 % |
| local | admission-reserve | 406.6 | 18.5 | 95.5 % |
| local | admission-outcome | 699.9 | 24.3 | 96.5 % |
| local | recovery | 579.1 | 23.6 | 95.9 % |
| rtt | read | 484.7 | 12.7 | 97.4 % |
| rtt | newdevice | 497.5 | 20.6 | 95.9 % |
| rtt | failure | 762.7 | 26.1 | 96.6 % |
| rtt | admission-reserve | 406.6 | 18.5 | 95.5 % |
| rtt | admission-outcome | 699.9 | 24.3 | 96.5 % |
| rtt | recovery | 579.1 | 23.6 | 95.9 % |

#### Cold cache: SCRIPT FLUSH before the first request (sample 0) against the later requests

| Workload | sample 0 commands | sample 0 NOSCRIPT | sample 0 sent KB | sample 0 ms | later median commands | later median sent KB | later median ms |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| read | 33 | 9 | 206.7 | 27.30 | 24 | 12.7 | 9.87 |
| newdevice | 35 | 10 | 228.3 | 27.18 | 25 | 20.6 | 10.60 |
| failure | 52 | 15 | 355.3 | 42.88 | 37 | 26.1 | 15.19 |
| admission-reserve | 33 | 11 | 239.3 | 24.04 | 22 | 18.5 | 9.15 |
| admission-outcome | 51 | 15 | 317.5 | 35.03 | 36 | 24.3 | 14.84 |
| recovery | 43 | 13 | 293.4 | 35.49 | 30 | 23.6 | 12.50 |

#### Hot-key contention (one account, recordFailure, default retry budget)

| Network | Workers x requests | Mode | requests | concurrency blocks | errors | NOSCRIPT | p50 ms | p95 ms | p99 ms | max ms | total sent MB |
| --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| loopback | 16 x 25 | eval | 400 | 0 | 0 | 0 | 15.3 | 36.6 | 389.2 | 394.8 | 80.81 |
| loopback | 16 x 25 | evalsha | 400 | 0 | 0 | 0 | 9.0 | 42.4 | 202.0 | 207.7 | 2.25 |
| loopback | 8 x 25 | eval | 200 | 0 | 0 | 0 | 9.2 | 13.6 | 240.6 | 242.4 | 40.26 |
| loopback | 8 x 25 | evalsha | 200 | 0 | 0 | 0 | 5.7 | 8.9 | 140.5 | 144.3 | 1.12 |
| loopback | 16 x 25 | eval | 400 | 0 | 0 | 0 | 17.0 | 32.1 | 405.3 | 414.1 | 80.81 |
| loopback | 16 x 25 | evalsha | 400 | 0 | 0 | 0 | 10.2 | 26.1 | 196.0 | 197.8 | 2.25 |
| loopback | 16 x 25 | eval | 400 | 0 | 0 | 0 | 20.2 | 57.2 | 488.8 | 504.5 | 80.81 |
| loopback | 16 x 25 | evalsha | 400 | 0 | 0 | 0 | 9.7 | 19.9 | 207.5 | 209.6 | 2.25 |
| loopback | 16 x 25 | eval | 400 | 0 | 0 | 0 | 17.7 | 53.4 | 357.9 | 364.9 | 80.81 |
| loopback | 16 x 25 | evalsha | 400 | 0 | 0 | 0 | 8.5 | 30.7 | 186.5 | 191.2 | 2.25 |
| loopback | 16 x 25 | eval | 400 | 0 | 0 | 0 | 15.0 | 38.1 | 372.5 | 378.0 | 80.81 |
| loopback | 16 x 25 | evalsha | 400 | 0 | 0 | 0 | 9.6 | 22.3 | 183.9 | 188.0 | 2.25 |
| loopback | 16 x 25 | eval | 400 | 0 | 0 | 0 | 14.8 | 42.3 | 353.9 | 357.1 | 80.81 |
| loopback | 16 x 25 | evalsha | 400 | 0 | 0 | 0 | 8.4 | 32.4 | 217.3 | 267.0 | 2.25 |
| loopback | 16 x 25 | eval | 400 | 0 | 0 | 0 | 14.2 | 31.9 | 347.2 | 357.7 | 80.81 |
| loopback | 16 x 25 | evalsha | 400 | 0 | 0 | 0 | 8.6 | 25.3 | 208.6 | 211.3 | 2.25 |
| loopback | 8 x 10 | eval | 80 | 5 | 0 | 0 | 71.0 | 1097.9 | 1103.3 | 1103.3 | 27.78 |
| loopback | 8 x 10 | evalsha | 80 | 5 | 0 | 0 | 77.2 | 1136.2 | 1142.9 | 1142.9 | 0.89 |
| loopback | 8 x 10 | eval | 80 | 5 | 0 | 0 | 75.6 | 1084.1 | 1093.2 | 1093.2 | 27.78 |
| loopback | 8 x 10 | evalsha | 80 | 5 | 0 | 0 | 74.0 | 1171.2 | 1174.9 | 1174.9 | 0.89 |
| loopback | 8 x 10 | eval | 80 | 5 | 0 | 0 | 71.2 | 1086.9 | 1103.7 | 1103.7 | 27.78 |
| loopback | 8 x 10 | evalsha | 80 | 5 | 0 | 0 | 72.3 | 1134.6 | 1139.7 | 1139.7 | 0.89 |
| loopback | 8 x 10 | eval | 80 | 5 | 0 | 0 | 74.2 | 1080.5 | 1089.3 | 1089.3 | 27.78 |
| loopback | 8 x 10 | evalsha | 80 | 5 | 0 | 0 | 75.7 | 1182.9 | 1188.7 | 1188.7 | 0.89 |
| loopback | 8 x 10 | eval | 80 | 5 | 0 | 0 | 71.7 | 1075.4 | 1083.3 | 1083.3 | 27.78 |
| loopback | 8 x 10 | evalsha | 80 | 5 | 0 | 0 | 72.9 | 1166.2 | 1172.0 | 1172.0 | 0.89 |
| loopback | 8 x 10 | eval | 80 | 5 | 0 | 0 | 71.7 | 1090.7 | 1095.3 | 1095.3 | 27.78 |
| loopback | 8 x 10 | evalsha | 80 | 5 | 0 | 0 | 73.0 | 1168.5 | 1174.2 | 1174.2 | 0.89 |
| about 7 ms RTT | 8 x 10 | eval | 80 | 5 | 0 | 0 | 83.0 | 1199.2 | 1207.1 | 1207.1 | 27.78 |
| about 7 ms RTT | 8 x 10 | evalsha | 80 | 5 | 0 | 0 | 84.6 | 997.3 | 1332.4 | 1332.4 | 0.89 |

#### Server restart (script cache lost): new connection, `newdevice`

| Request | commands | NOSCRIPT | sent KB | ms |
| ---: | ---: | ---: | ---: | ---: |
| before #0 | 25 | 0 | 20.6 | 14.24 |
| before #1 | 25 | 0 | 20.6 | 11.14 |
| before #2 | 25 | 0 | 20.6 | 10.78 |
| before #3 | 25 | 0 | 20.6 | 10.00 |
| before #4 | 25 | 0 | 20.6 | 10.75 |
| after #0 | 35 | 10 | 228.3 | 28.79 |
| after #1 | 25 | 0 | 20.6 | 11.56 |
| after #2 | 25 | 0 | 20.6 | 11.16 |
| after #3 | 25 | 0 | 20.6 | 8.88 |
| after #4 | 25 | 0 | 20.6 | 10.51 |
| after #5 | 25 | 0 | 20.6 | 10.55 |

#### Repeated contention runs (alternating eval / evalsha; per-run p50, p95, p99 and concurrency blocks)

| Cell | Mode | Run | p50 ms | p95 ms | p99 ms | max ms | concurrency blocks | errors |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| local-w16 | eval | 1 | 17.0 | 32.1 | 405.3 | 414.1 | 0 | 0 |
| local-w16 | evalsha | 1 | 10.2 | 26.1 | 196.0 | 197.8 | 0 | 0 |
| local-w16 | eval | 2 | 20.2 | 57.2 | 488.8 | 504.5 | 0 | 0 |
| local-w16 | evalsha | 2 | 9.7 | 19.9 | 207.5 | 209.6 | 0 | 0 |
| local-w16 | eval | 3 | 17.7 | 53.4 | 357.9 | 364.9 | 0 | 0 |
| local-w16 | evalsha | 3 | 8.5 | 30.7 | 186.5 | 191.2 | 0 | 0 |
| local-w16 | eval | 4 | 15.0 | 38.1 | 372.5 | 378.0 | 0 | 0 |
| local-w16 | evalsha | 4 | 9.6 | 22.3 | 183.9 | 188.0 | 0 | 0 |
| local-w16 | eval | 5 | 14.8 | 42.3 | 353.9 | 357.1 | 0 | 0 |
| local-w16 | evalsha | 5 | 8.4 | 32.4 | 217.3 | 267.0 | 0 | 0 |
| local-w16 | eval | 6 | 14.2 | 31.9 | 347.2 | 357.7 | 0 | 0 |
| local-w16 | evalsha | 6 | 8.6 | 25.3 | 208.6 | 211.3 | 0 | 0 |
| rtt-w8 | eval | 1 | 71.0 | 1097.9 | 1103.3 | 1103.3 | 5 | 0 |
| rtt-w8 | evalsha | 1 | 77.2 | 1136.2 | 1142.9 | 1142.9 | 5 | 0 |
| rtt-w8 | eval | 2 | 75.6 | 1084.1 | 1093.2 | 1093.2 | 5 | 0 |
| rtt-w8 | evalsha | 2 | 74.0 | 1171.2 | 1174.9 | 1174.9 | 5 | 0 |
| rtt-w8 | eval | 3 | 71.2 | 1086.9 | 1103.7 | 1103.7 | 5 | 0 |
| rtt-w8 | evalsha | 3 | 72.3 | 1134.6 | 1139.7 | 1139.7 | 5 | 0 |
| rtt-w8 | eval | 4 | 74.2 | 1080.5 | 1089.3 | 1089.3 | 5 | 0 |
| rtt-w8 | evalsha | 4 | 75.7 | 1182.9 | 1188.7 | 1188.7 | 5 | 0 |
| rtt-w8 | eval | 5 | 71.7 | 1075.4 | 1083.3 | 1083.3 | 5 | 0 |
| rtt-w8 | evalsha | 5 | 72.9 | 1166.2 | 1172.0 | 1172.0 | 5 | 0 |
| rtt-w8 | eval | 6 | 71.7 | 1090.7 | 1095.3 | 1095.3 | 5 | 0 |
| rtt-w8 | evalsha | 6 | 73.0 | 1168.5 | 1174.2 | 1174.2 | 5 | 0 |

| Cell | Mode | runs | median of p50 | median of p99 | min p99 | max p99 | total concurrency blocks |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| local-w16 | eval | 6 | 15.0 | 357.9 | 347.2 | 488.8 | 0 |
| local-w16 | evalsha | 6 | 8.6 | 196.0 | 183.9 | 217.3 | 0 |
| rtt-w8 | eval | 6 | 71.7 | 1093.2 | 1083.3 | 1103.7 | 30 |
| rtt-w8 | evalsha | 6 | 73.0 | 1172.0 | 1139.7 | 1188.7 | 30 |


### Reading the tables

- **Bytes.** The request overlay sends the wrapped script text with every `EVAL`; with `EVALSHA` the same request sends 12.7 to 26.1 KB instead of 485 to 763 KB. The new-device `checkOnly` is 497.5 KB against 20.6 KB over 25 commands, in line with the 498 KB over 25 `EVAL`s that DEC-037 recorded in an earlier environment. Bytes received and the number of commands (round trips) are unchanged.
- **Latency.** Locally the median falls by 19 % to 23 % (for example `failure` 19.7 ms to 15.4 ms) because far fewer bytes are written and parsed. With about 7 ms of round trip the latency is the same within the noise (for example `newdevice` 273.0 ms against 273.3 ms), because the cost is the number of round trips, which does not change.
- **Cache loss costs round trips, and it is not one.** After a `SCRIPT FLUSH` the first request needs one `NOSCRIPT` round trip and one fallback `EVAL` for each distinct script it uses (9 to 15), i.e. 33 to 52 commands instead of 24 to 37 and 24 to 43 ms, then the cache is warm again. A server restart behaved the same: the first request of a new connection used 35 commands and 10 misses, the next five 25. `evalsha-flush-each`, a flush before every script call, is deliberately pathological: three commands per script call, 72 to 111 per request, and about four times the `EVAL` latency. A flush before every commit only (`evalsha-flush-commit`) costs the commit and the scripts that follow it until they are reloaded.
- **Contention.** In the 16-worker loopback run the median fell from 15.0 to 8.6 ms and the p99 from 358 to 196 ms (6 of 6 repeats lower). With about 7 ms of round trip, 8 workers: 5 concurrency blocks in every run in both modes (30 of 30 in each), p50 71.7 against 73.0 ms, p99 1093 against 1172 ms. The `EVALSHA` p99 is higher in all six repeats (1140 to 1189 ms against 1083 to 1104 ms, ranges without overlap). That tail is the request that exhausts the 500 ms retry budget plus the evaluation that was already running; why it is about 80 ms longer was not investigated, and it is not claimed to be noise.

## Correctness evidence

`tests/Integration/Redis/EvalshaStudyRedisTest.php` (group `evalsha-study`, also `php32`; 13 tests, 3538 assertions on 64-bit, isolated Redis):

- the wire command is `EVALSHA` of the exact text; a cold cache costs two commands for the first call (`EVALSHA`, answered `NOSCRIPT`, then `EVAL`) and one for the next; held request data travels in `ARGV`, so one script body has one hash;
- an error reply other than `NOSCRIPT` is rethrown, no fallback is sent and the script ran once;
- a lost request (the proxy closes the connection before the server sees it) and a lost reply (the server executed it, the reply is discarded) both end in a `BackendFailureException` with nothing sent again; the server's `commandstats` and the stored effect show at most one execution;
- the public workload (new-device and failure requests, admission reservations with their outcomes through the permit, a hot account) ends in the same decisions and the same final state as `eval`, in `evalsha`, `evalsha-flush-each` and `evalsha-flush-commit`;
- an exact `PEXPIRETIME` and a one-millisecond difference (`4102444800123` and `…124`) survive the script path in every mode, and read from outside Lua are the same value; this uses the exact-reply harness of #219;
- ext-redis 6.2.0 `rawCommand()` (64-bit only): `EVALSHA` of an unknown script returns `false`, `getLastError()` starts with `NOSCRIPT`; a script reply `ERR deliberate` also returns `false` with that text in `getLastError()`; only a reply with a custom prefix (`MY err`) throws `RedisException`.

The existing suites were run unchanged with `REDIS_INTEGRATION_SCRIPT_MODE` set; they include the atomicity, recovery-outage, boundaries, default-budget, overlay-differential, retry-budget and admission suites:

| Run | `eval` | `evalsha` | `evalsha-flush-each` | `evalsha-flush-commit` |
| --- | --- | --- | --- | --- |
| 64-bit, all of `tests/Integration` (`php vendor/bin/phpunit tests/Integration`, Redis 7.0.15, PHP 8.4.27) | OK, 937 tests | OK, 937 tests | OK, 937 tests | OK, 937 tests |
| 32-bit, `PHP_INT_SIZE=4`, `linux/386` (`run-phpunit-32bit.sh --filter 'RequestAtomicity\|RequestOverlay\|RequestRetryBudget\|EvalshaStudy\|AdmissionIntegerWidthRedis\|AdmissionPublicRuntimeRedis\|AdmissionStoreNm6\|AdmissionStoreNm7\|RespIntegerReply'`) | 104 tests, 13601 assertions, 3 failing tests | the same | the same | the same |

The assertion totals differ between the 64-bit modes (57300, 57603, 60491, 58505). The cause was checked: the junit assertion counts of 177 tests in the seven files that loop against the clock were compared between `eval` and `evalsha`, and only four tests differ (`BlockNoDowngradeRedisTest::testALateWeakerBlockWriteDoesNotDowngradeTheLiveBlock` 13212 against 16336, two data sets of `CallerTimeAuthorityRedisTest::testRedisRejectedExpiryDeletesEverythingTheCallCreated`, `RedisFullCapabilityStoreIntegrationTest::testRelativeExpiryPreflightIsMillisecondAccurateAtTheRealRedisBoundary` 723 against 179). Each loops until a real-time boundary passes, so a faster client iterates more. All of them pass in every mode.

### Findings that are not about `EVALSHA` (for Lead triage; the same in the `eval` baseline)

1. **32-bit, direct store and request overlay diverge at the native-integer boundary.** `RequestOverlayDifferentialRedisTest::testScoresBlocksBudgetsAndCountersBehaveAsTheScripts` on `PHP_INT_SIZE=4`: after `incrementAt('score', 3600, PHP_INT_MAX, …)` on a score of 7 the direct store holds `value` `2147483654` (above the 32-bit `PHP_INT_MAX`) and the overlay run holds `7`. Which behaviour is the contract is not decided here.
2. **32-bit test harness**: `RequestOverlayDifferentialRedisTest::testHardBlocksLifecycleAndGenerationScoresBehaveAsTheScripts` passes a millisecond constant that is a float on 32-bit to `readGenerationBoundScoreStateAt(int)` (TypeError); `AdmissionPublicRuntimeRedisTest::testUnknownLaneSourceCapacityUnderParallelPublicChecks` fails on the emulated 32-bit runner because a worker misses its start barrier. These suites are not in the `php32` gate. The `assertIsInt` on `PEXPIRETIME` in `RequestAtomicityBoundariesRedisTest` and `RequestOverlayDifferentialRedisTest` is fixed here in the same way as in #219 (an expiry is an `int` or its exact decimal string).

## Hazards from the brief

1. **Admission translated before the overlay.** Not possible in this design: the translation is at the wire, below `RedisFullCapabilityStore`, after `executeInRequestWhenHeld()` has routed the admission scripts into the overlay and wrapped them. The admission reservation and outcome workloads and the admission suites pass in every mode with their writes held back until the single commit. A production design must keep the translation below that interception.
2. **Which text is hashed, and one hash per script.** The hash is of the exact text handed to the wire (the wrapped overlay text, the commit script, the verify call, the direct scripts). Held request data is in `ARGV`; the cold-cache and restart tables show a bounded set of scripts (9 to 15 distinct per request kind), not one per request.
3. **Representation of `NOSCRIPT`.** The test client throws `RateLimiterException` with a message that starts `NOSCRIPT` (an error reply is a reply). ext-redis 6.2.0 does not throw: `false` plus `getLastError()`; the executor contract does not carry that text, and DEC-018 already records that a bare `false` is ambiguous between NIL and an error. Only these two clients were examined; no blanket catch was added.
4. **Unknown outcome versus identified miss.** A `NOSCRIPT` reply proves the script did not run, so the fallback `EVAL` is safe; a lost request or reply is a transport failure and is thrown as for `EVAL`, never replayed (tests above), which preserves DEC-037's all-or-none save and the unknown caller outcome after a lost reply.

## What a production implementation would need (a proposal, not approved)

A bounded, optional, per-client capability by which the executor can report an identified script-cache miss (for ext-redis from `getLastError()`), kept in the executor adapter and not in the store; the store would send `EVALSHA` of the exact wrapped text, fall back to `EVAL` once per identified miss, never after a transport failure, and keep the existing failure classification. That changes the public executor contract (DEC-018), so it needs the Owner's architectural approval before any implementation; the supported-client claim would stay limited to the clients tested, and the topology (cluster, failover, pipelining) would stay explicitly unclaimed until measured. The expected benefit is the one measured here: far fewer bytes, a lower local latency, and no change when the network round trip dominates.
