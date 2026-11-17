### Set `rtt7-w8x10` (8 workers x 10 requests, warm-up 12, php 8.4.27 INT_SIZE 8, redis 7.0.15)

| Pair | First | n | EVAL p50 | EVALSHA p50 | EVAL p95 | EVALSHA p95 | EVAL p99 | EVALSHA p99 | p99 delta | EVAL blocks | EVALSHA blocks | errors (e/s) | NOSCRIPT (s) |
| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- | ---: |
| 1 | eval | 80 | 51.4 | 55.2 | 764.3 | 803.0 | 765.0 | 803.7 | +5.1 % | 5 | 5 | 0/0 | 0 |
| 2 | evalsha | 80 | 53.3 | 54.6 | 735.1 | 798.8 | 751.1 | 804.3 | +7.1 % | 5 | 5 | 0/0 | 0 |
| 3 | eval | 80 | 50.9 | 50.5 | 744.5 | 838.7 | 752.9 | 839.1 | +11.5 % | 5 | 5 | 0/0 | 0 |
| 4 | evalsha | 80 | 51.1 | 52.5 | 743.4 | 825.1 | 765.4 | 828.9 | +8.3 % | 5 | 5 | 0/0 | 0 |
| 5 | eval | 80 | 50.2 | 56.2 | 729.4 | 800.4 | 745.6 | 801.0 | +7.4 % | 5 | 5 | 0/0 | 0 |
| 6 | evalsha | 80 | 52.0 | 57.2 | 761.8 | 795.5 | 769.6 | 799.4 | +3.9 % | 6 | 5 | 0/0 | 0 |
| 7 | eval | 80 | 51.6 | 53.9 | 754.6 | 878.5 | 773.4 | 886.7 | +14.7 % | 5 | 6 | 0/0 | 0 |
| 8 | evalsha | 80 | 51.5 | 54.8 | 756.0 | 791.7 | 772.5 | 800.0 | +3.6 % | 5 | 5 | 0/0 | 0 |
| 9 | eval | 80 | 51.9 | 54.3 | 743.4 | 825.3 | 766.8 | 826.1 | +7.7 % | 5 | 5 | 0/0 | 0 |
| 10 | evalsha | 80 | 50.8 | 52.6 | 749.0 | 780.5 | 763.3 | 792.7 | +3.9 % | 5 | 5 | 0/0 | 0 |

p99 in ms, EVALSHA above EVAL in **10 of 10** pairs; median EVAL 765.2 ms (range 745.6 to 773.4), median EVALSHA 804.0 ms (range 792.7 to 886.7); median of the per-pair deltas **7.3 %** (min 3.6 %, max 14.7 %).

Where the tail comes from: median latency of a concurrency-blocked request EVAL 763.7 ms against EVALSHA 802.3 ms; p99 of the requests that were not blocked EVAL 739.9 ms against EVALSHA 798.4 ms (medians over the pairs).

### Set `loop-w8x10` (8 workers x 10 requests, warm-up 12, php 8.4.27 INT_SIZE 8, redis 7.0.15)

| Pair | First | n | EVAL p50 | EVALSHA p50 | EVAL p95 | EVALSHA p95 | EVAL p99 | EVALSHA p99 | p99 delta | EVAL blocks | EVALSHA blocks | errors (e/s) | NOSCRIPT (s) |
| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- | ---: |
| 1 | eval | 80 | 2.5 | 1.6 | 79.6 | 48.1 | 80.3 | 49.4 | -38.5 % | 0 | 0 | 0/0 | 0 |
| 2 | evalsha | 80 | 2.7 | 1.6 | 68.8 | 45.7 | 81.2 | 48.0 | -40.9 % | 0 | 0 | 0/0 | 0 |
| 3 | eval | 80 | 2.5 | 1.4 | 68.0 | 43.7 | 78.5 | 45.9 | -41.5 % | 0 | 0 | 0/0 | 0 |
| 4 | evalsha | 80 | 2.3 | 1.4 | 109.3 | 41.9 | 111.6 | 43.3 | -61.2 % | 0 | 0 | 0/0 | 0 |
| 5 | eval | 80 | 2.3 | 1.5 | 78.9 | 42.5 | 80.1 | 44.1 | -44.9 % | 0 | 0 | 0/0 | 0 |
| 6 | evalsha | 80 | 2.6 | 1.7 | 70.9 | 48.2 | 81.5 | 51.0 | -37.5 % | 0 | 0 | 0/0 | 0 |
| 7 | eval | 80 | 2.4 | 2.2 | 78.5 | 48.5 | 79.9 | 50.9 | -36.3 % | 0 | 0 | 0/0 | 0 |
| 8 | evalsha | 80 | 4.3 | 1.5 | 77.3 | 42.8 | 91.7 | 46.5 | -49.3 % | 0 | 0 | 0/0 | 0 |
| 9 | eval | 80 | 2.4 | 1.4 | 68.4 | 43.9 | 79.9 | 44.9 | -43.9 % | 0 | 0 | 0/0 | 0 |
| 10 | evalsha | 80 | 2.6 | 1.5 | 77.0 | 44.0 | 78.4 | 46.0 | -41.3 % | 0 | 0 | 0/0 | 0 |

p99 in ms, EVALSHA above EVAL in **0 of 10** pairs; median EVAL 80.2 ms (range 78.4 to 111.6), median EVALSHA 46.2 ms (range 43.3 to 51.0); median of the per-pair deltas **-41.4 %** (min -61.2 %, max -36.3 %).

Where the tail comes from: median latency of a concurrency-blocked request EVAL - ms against EVALSHA - ms; p99 of the requests that were not blocked EVAL 80.2 ms against EVALSHA 46.2 ms (medians over the pairs).

### Set `rtt7-w8x40` (8 workers x 40 requests, warm-up 12, php 8.4.27 INT_SIZE 8, redis 7.0.15)

| Pair | First | n | EVAL p50 | EVALSHA p50 | EVAL p95 | EVALSHA p95 | EVAL p99 | EVALSHA p99 | p99 delta | EVAL blocks | EVALSHA blocks | errors (e/s) | NOSCRIPT (s) |
| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- | ---: |
| 1 | eval | 320 | 51.6 | 53.8 | 58.9 | 57.6 | 767.3 | 833.2 | +8.6 % | 5 | 5 | 0/0 | 0 |
| 2 | evalsha | 320 | 51.9 | 53.7 | 61.2 | 57.5 | 781.6 | 795.0 | +1.7 % | 5 | 5 | 0/0 | 0 |
| 3 | eval | 320 | 51.1 | 54.6 | 56.1 | 59.7 | 758.3 | 811.4 | +7.0 % | 5 | 6 | 0/0 | 0 |
| 4 | evalsha | 320 | 51.2 | 53.9 | 62.0 | 59.3 | 766.5 | 798.2 | +4.1 % | 5 | 5 | 0/0 | 0 |
| 5 | eval | 320 | 51.7 | 54.3 | 63.2 | 58.9 | 762.6 | 792.6 | +3.9 % | 6 | 5 | 0/0 | 0 |
| 6 | evalsha | 320 | 51.5 | 55.4 | 62.8 | 59.2 | 763.2 | 834.2 | +9.3 % | 5 | 5 | 0/0 | 0 |

p99 in ms, EVALSHA above EVAL in **6 of 6** pairs; median EVAL 764.9 ms (range 758.3 to 781.6), median EVALSHA 804.8 ms (range 792.6 to 834.2); median of the per-pair deltas **5.6 %** (min 1.7 %, max 9.3 %).

Where the tail comes from: median latency of a concurrency-blocked request EVAL 766.1 ms against EVALSHA 804.8 ms; p99 of the requests that were not blocked EVAL 300.7 ms against EVALSHA 319.3 ms (medians over the pairs).

### Set `rtt9-w8x10` (8 workers x 10 requests, warm-up 12, php 8.4.27 INT_SIZE 8, redis 7.0.15)

| Pair | First | n | EVAL p50 | EVALSHA p50 | EVAL p95 | EVALSHA p95 | EVAL p99 | EVALSHA p99 | p99 delta | EVAL blocks | EVALSHA blocks | errors (e/s) | NOSCRIPT (s) |
| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- | ---: |
| 1 | eval | 80 | 69.3 | 76.0 | 1000.9 | 1185.7 | 1023.4 | 1188.6 | +16.1 % | 5 | 5 | 0/0 | 0 |
| 2 | evalsha | 80 | 74.6 | 75.2 | 1050.3 | 1155.4 | 1054.6 | 1156.3 | +9.7 % | 6 | 5 | 0/0 | 0 |
| 3 | eval | 80 | 71.0 | 73.4 | 1013.2 | 1095.0 | 1025.6 | 1096.4 | +6.9 % | 5 | 5 | 0/0 | 0 |
| 4 | evalsha | 80 | 70.4 | 72.5 | 1009.5 | 1078.4 | 1022.5 | 1081.8 | +5.8 % | 5 | 5 | 0/0 | 0 |
| 5 | eval | 80 | 67.6 | 71.9 | 1028.9 | 1085.6 | 1029.5 | 1086.2 | +5.5 % | 5 | 5 | 0/0 | 0 |
| 6 | evalsha | 80 | 68.7 | 69.9 | 1006.9 | 1087.8 | 1023.9 | 1092.4 | +6.7 % | 5 | 5 | 0/0 | 0 |

p99 in ms, EVALSHA above EVAL in **6 of 6** pairs; median EVAL 1024.8 ms (range 1022.5 to 1054.6), median EVALSHA 1094.4 ms (range 1081.8 to 1188.6); median of the per-pair deltas **6.8 %** (min 5.5 %, max 16.1 %).

Where the tail comes from: median latency of a concurrency-blocked request EVAL 1021.2 ms against EVALSHA 1093.4 ms; p99 of the requests that were not blocked EVAL 1007.4 ms against EVALSHA 1089.7 ms (medians over the pairs).

### Set `loop-w16x25` (16 workers x 25 requests, warm-up 12, php 8.4.27 INT_SIZE 8, redis 7.0.15)

| Pair | First | n | EVAL p50 | EVALSHA p50 | EVAL p95 | EVALSHA p95 | EVAL p99 | EVALSHA p99 | p99 delta | EVAL blocks | EVALSHA blocks | errors (e/s) | NOSCRIPT (s) |
| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- | ---: |
| 1 | eval | 400 | 5.0 | 2.3 | 8.8 | 7.1 | 161.5 | 78.8 | -51.2 % | 0 | 0 | 0/0 | 0 |
| 2 | evalsha | 400 | 5.7 | 2.3 | 11.4 | 9.0 | 113.0 | 84.0 | -25.6 % | 0 | 0 | 0/0 | 0 |
| 3 | eval | 400 | 4.8 | 2.4 | 8.7 | 8.7 | 150.8 | 140.7 | -6.7 % | 0 | 0 | 0/0 | 0 |
| 4 | evalsha | 400 | 5.1 | 2.4 | 11.8 | 10.3 | 166.5 | 79.2 | -52.5 % | 0 | 0 | 0/0 | 0 |
| 5 | eval | 400 | 5.7 | 2.3 | 10.5 | 6.8 | 161.8 | 83.7 | -48.3 % | 0 | 0 | 0/0 | 0 |
| 6 | evalsha | 400 | 4.9 | 2.4 | 16.4 | 8.7 | 177.4 | 80.1 | -54.9 % | 0 | 0 | 0/0 | 0 |

p99 in ms, EVALSHA above EVAL in **0 of 6** pairs; median EVAL 161.6 ms (range 113.0 to 177.4), median EVALSHA 81.9 ms (range 78.8 to 140.7); median of the per-pair deltas **-49.7 %** (min -54.9 %, max -6.7 %).

Where the tail comes from: median latency of a concurrency-blocked request EVAL - ms against EVALSHA - ms; p99 of the requests that were not blocked EVAL 161.6 ms against EVALSHA 81.9 ms (medians over the pairs).

