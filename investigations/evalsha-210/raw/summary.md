### Per-request cost, local loopback (median of the measured requests)

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

### Per-request cost with about 7 ms added round trip (3.5 ms each way)

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

### Reduction in bytes sent, evalsha against eval (median per request)

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

### Cold cache: SCRIPT FLUSH before the first request (sample 0) against the later requests

| Workload | sample 0 commands | sample 0 NOSCRIPT | sample 0 sent KB | sample 0 ms | later median commands | later median sent KB | later median ms |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| read | 33 | 9 | 206.7 | 27.30 | 24 | 12.7 | 9.87 |
| newdevice | 35 | 10 | 228.3 | 27.18 | 25 | 20.6 | 10.60 |
| failure | 52 | 15 | 355.3 | 42.88 | 37 | 26.1 | 15.19 |
| admission-reserve | 33 | 11 | 239.3 | 24.04 | 22 | 18.5 | 9.15 |
| admission-outcome | 51 | 15 | 317.5 | 35.03 | 36 | 24.3 | 14.84 |
| recovery | 43 | 13 | 293.4 | 35.49 | 30 | 23.6 | 12.50 |

### Hot-key contention (one account, recordFailure, default retry budget)

| Network | Workers x requests | Mode | requests | concurrency blocks | errors | NOSCRIPT | p50 ms | p95 ms | p99 ms | max ms | total sent MB |
| --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| loopback | 16 x 25 | eval | 400 | 0 | 0 | 0 | 15.3 | 36.6 | 389.2 | 394.8 | 80.81 |
| loopback | 16 x 25 | evalsha | 400 | 0 | 0 | 0 | 9.0 | 42.4 | 202.0 | 207.7 | 2.25 |
| loopback | 8 x 25 | eval | 200 | 0 | 0 | 0 | 9.2 | 13.6 | 240.6 | 242.4 | 40.26 |
| loopback | 8 x 25 | evalsha | 200 | 0 | 0 | 0 | 5.7 | 8.9 | 140.5 | 144.3 | 1.12 |
| about 7 ms RTT | 8 x 10 | eval | 80 | 5 | 0 | 0 | 83.0 | 1199.2 | 1207.1 | 1207.1 | 27.78 |
| about 7 ms RTT | 8 x 10 | evalsha | 80 | 5 | 0 | 0 | 84.6 | 997.3 | 1332.4 | 1332.4 | 0.89 |

### Server restart (script cache lost): new connection, `newdevice`

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

### Repeated contention runs (alternating eval / evalsha; per-run p50, p95, p99 and concurrency blocks)

| Cell | Mode | Run | p50 ms | p95 ms | p99 ms | max ms | concurrency blocks | errors |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| loopback, w16 | eval | 1 | 17.0 | 32.1 | 405.3 | 414.1 | 0 | 0 |
| loopback, w16 | evalsha | 1 | 10.2 | 26.1 | 196.0 | 197.8 | 0 | 0 |
| loopback, w16 | eval | 2 | 20.2 | 57.2 | 488.8 | 504.5 | 0 | 0 |
| loopback, w16 | evalsha | 2 | 9.7 | 19.9 | 207.5 | 209.6 | 0 | 0 |
| loopback, w16 | eval | 3 | 17.7 | 53.4 | 357.9 | 364.9 | 0 | 0 |
| loopback, w16 | evalsha | 3 | 8.5 | 30.7 | 186.5 | 191.2 | 0 | 0 |
| loopback, w16 | eval | 4 | 15.0 | 38.1 | 372.5 | 378.0 | 0 | 0 |
| loopback, w16 | evalsha | 4 | 9.6 | 22.3 | 183.9 | 188.0 | 0 | 0 |
| loopback, w16 | eval | 5 | 14.8 | 42.3 | 353.9 | 357.1 | 0 | 0 |
| loopback, w16 | evalsha | 5 | 8.4 | 32.4 | 217.3 | 267.0 | 0 | 0 |
| loopback, w16 | eval | 6 | 14.2 | 31.9 | 347.2 | 357.7 | 0 | 0 |
| loopback, w16 | evalsha | 6 | 8.6 | 25.3 | 208.6 | 211.3 | 0 | 0 |
| about 7 ms RTT, w8 | eval | 1 | 71.0 | 1097.9 | 1103.3 | 1103.3 | 5 | 0 |
| about 7 ms RTT, w8 | evalsha | 1 | 77.2 | 1136.2 | 1142.9 | 1142.9 | 5 | 0 |
| about 7 ms RTT, w8 | eval | 2 | 75.6 | 1084.1 | 1093.2 | 1093.2 | 5 | 0 |
| about 7 ms RTT, w8 | evalsha | 2 | 74.0 | 1171.2 | 1174.9 | 1174.9 | 5 | 0 |
| about 7 ms RTT, w8 | eval | 3 | 71.2 | 1086.9 | 1103.7 | 1103.7 | 5 | 0 |
| about 7 ms RTT, w8 | evalsha | 3 | 72.3 | 1134.6 | 1139.7 | 1139.7 | 5 | 0 |
| about 7 ms RTT, w8 | eval | 4 | 74.2 | 1080.5 | 1089.3 | 1089.3 | 5 | 0 |
| about 7 ms RTT, w8 | evalsha | 4 | 75.7 | 1182.9 | 1188.7 | 1188.7 | 5 | 0 |
| about 7 ms RTT, w8 | eval | 5 | 71.7 | 1075.4 | 1083.3 | 1083.3 | 5 | 0 |
| about 7 ms RTT, w8 | evalsha | 5 | 72.9 | 1166.2 | 1172.0 | 1172.0 | 5 | 0 |
| about 7 ms RTT, w8 | eval | 6 | 71.7 | 1090.7 | 1095.3 | 1095.3 | 5 | 0 |
| about 7 ms RTT, w8 | evalsha | 6 | 73.0 | 1168.5 | 1174.2 | 1174.2 | 5 | 0 |

| Cell | Mode | runs | median of p50 | median of p99 | min p99 | max p99 | total concurrency blocks |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| loopback, w16 | eval | 6 | 15.0 | 357.9 | 347.2 | 488.8 | 0 |
| loopback, w16 | evalsha | 6 | 8.6 | 196.0 | 183.9 | 217.3 | 0 |
| about 7 ms RTT, w8 | eval | 6 | 71.7 | 1093.2 | 1083.3 | 1103.7 | 30 |
| about 7 ms RTT, w8 | evalsha | 6 | 73.0 | 1172.0 | 1139.7 | 1188.7 | 30 |
