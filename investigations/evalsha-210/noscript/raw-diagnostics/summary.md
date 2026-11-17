### Set `diag-w1x40-rtt7` (1 workers x 40 requests, warm-up 12, php 8.4.27 INT_SIZE 8, redis 7.0.15)

| Pair | First | n | EVAL p50 | EVALSHA p50 | EVAL p95 | EVALSHA p95 | EVAL p99 | EVALSHA p99 | p99 delta | EVAL blocks | EVALSHA blocks | errors (e/s) | NOSCRIPT (s) |
| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- | ---: |
| 1 | eval | 40 | 54.9 | 54.2 | 245.9 | 249.9 | 305.9 | 307.3 | +0.5 % | 0 | 0 | 0/0 | 0 |
| 2 | evalsha | 40 | 54.5 | 54.3 | 250.8 | 243.4 | 310.5 | 324.1 | +4.4 % | 0 | 0 | 0/0 | 0 |
| 3 | eval | 40 | 54.6 | 53.8 | 247.1 | 244.3 | 317.7 | 321.7 | +1.3 % | 0 | 0 | 0/0 | 0 |
| 4 | evalsha | 40 | 54.7 | 54.0 | 249.1 | 253.3 | 322.5 | 307.1 | -4.8 % | 0 | 0 | 0/0 | 0 |
| 5 | eval | 40 | 54.5 | 56.2 | 243.7 | 245.4 | 323.0 | 327.4 | +1.4 % | 0 | 0 | 0/0 | 0 |
| 6 | evalsha | 40 | 56.1 | 57.2 | 249.7 | 283.3 | 318.3 | 305.6 | -4.0 % | 0 | 0 | 0/0 | 0 |

p99 in ms, EVALSHA above EVAL in **4 of 6** pairs; median EVAL 318.0 ms (range 305.9 to 323.0), median EVALSHA 314.5 ms (range 305.6 to 327.4); median of the per-pair deltas **0.9 %** (min -4.8 %, max 4.4 %).

Where the tail comes from: median latency of a concurrency-blocked request EVAL - ms against EVALSHA - ms; p99 of the requests that were not blocked EVAL 318.0 ms against EVALSHA 314.5 ms (medians over the pairs).

### Set `diag-w8x10-proxy0` (8 workers x 10 requests, warm-up 12, php 8.4.27 INT_SIZE 8, redis 7.0.15)

| Pair | First | n | EVAL p50 | EVALSHA p50 | EVAL p95 | EVALSHA p95 | EVAL p99 | EVALSHA p99 | p99 delta | EVAL blocks | EVALSHA blocks | errors (e/s) | NOSCRIPT (s) |
| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- | ---: |
| 1 | eval | 80 | 3.7 | 3.7 | 106.7 | 92.0 | 110.3 | 93.3 | -15.5 % | 0 | 0 | 0/0 | 0 |
| 2 | evalsha | 80 | 3.9 | 2.7 | 106.9 | 86.7 | 113.1 | 96.0 | -15.1 % | 0 | 0 | 0/0 | 0 |
| 3 | eval | 80 | 3.5 | 2.3 | 91.2 | 60.7 | 94.3 | 62.4 | -33.9 % | 0 | 0 | 0/0 | 0 |
| 4 | evalsha | 80 | 3.9 | 3.1 | 92.9 | 61.8 | 95.4 | 71.3 | -25.2 % | 0 | 0 | 0/0 | 0 |
| 5 | eval | 80 | 3.6 | 2.4 | 99.9 | 53.5 | 101.8 | 58.0 | -43.1 % | 0 | 0 | 0/0 | 0 |
| 6 | evalsha | 80 | 3.9 | 2.5 | 84.9 | 55.6 | 87.8 | 60.0 | -31.7 % | 0 | 0 | 0/0 | 0 |

p99 in ms, EVALSHA above EVAL in **0 of 6** pairs; median EVAL 98.6 ms (range 87.8 to 113.1), median EVALSHA 66.8 ms (range 58.0 to 96.0); median of the per-pair deltas **-28.5 %** (min -43.1 %, max -15.1 %).

Where the tail comes from: median latency of a concurrency-blocked request EVAL - ms against EVALSHA - ms; p99 of the requests that were not blocked EVAL 98.6 ms against EVALSHA 66.8 ms (medians over the pairs).
