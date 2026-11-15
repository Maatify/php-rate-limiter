### Repeated contention runs (alternating eval / evalsha; per-run p50, p95, p99 and concurrency blocks)

| Cell | Mode | Run | p50 ms | p95 ms | p99 ms | max ms | concurrency blocks | errors |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| loopback, w16 | eval | 1 | 16.7 | 30.3 | 473.8 | 485.9 | 0 | 0 |
| loopback, w16 | evalsha | 1 | 12.6 | 197.7 | 298.6 | 347.1 | 0 | 0 |
| loopback, w16 | eval | 2 | 15.7 | 54.7 | 349.7 | 352.6 | 0 | 0 |
| loopback, w16 | evalsha | 2 | 9.3 | 34.5 | 203.3 | 210.6 | 0 | 0 |
| loopback, w16 | eval | 3 | 13.7 | 29.1 | 365.7 | 370.2 | 0 | 0 |
| loopback, w16 | evalsha | 3 | 8.3 | 21.2 | 180.4 | 186.2 | 0 | 0 |
| loopback, w16 | eval | 4 | 13.9 | 26.3 | 346.6 | 350.3 | 0 | 0 |
| loopback, w16 | evalsha | 4 | 8.5 | 37.1 | 185.4 | 190.1 | 0 | 0 |
| loopback, w16 | eval | 5 | 13.9 | 50.1 | 321.1 | 326.5 | 0 | 0 |
| loopback, w16 | evalsha | 5 | 8.5 | 20.4 | 208.1 | 210.2 | 0 | 0 |
| loopback, w16 | eval | 6 | 13.6 | 37.8 | 334.7 | 339.5 | 0 | 0 |
| loopback, w16 | evalsha | 6 | 8.5 | 28.7 | 177.2 | 179.6 | 0 | 0 |
| about 7 ms RTT, w8 | eval | 1 | 72.0 | 1097.8 | 1108.9 | 1108.9 | 5 | 0 |
| about 7 ms RTT, w8 | evalsha | 1 | 72.5 | 1181.4 | 1185.9 | 1185.9 | 5 | 0 |
| about 7 ms RTT, w8 | eval | 2 | 72.5 | 1122.7 | 1131.7 | 1131.7 | 5 | 0 |
| about 7 ms RTT, w8 | evalsha | 2 | 75.8 | 1188.1 | 1193.1 | 1193.1 | 5 | 0 |
| about 7 ms RTT, w8 | eval | 3 | 73.4 | 1134.4 | 1137.0 | 1137.0 | 5 | 0 |
| about 7 ms RTT, w8 | evalsha | 3 | 72.7 | 1154.7 | 1160.0 | 1160.0 | 5 | 0 |
| about 7 ms RTT, w8 | eval | 4 | 74.5 | 1126.6 | 1137.0 | 1137.0 | 5 | 0 |
| about 7 ms RTT, w8 | evalsha | 4 | 72.5 | 1164.3 | 1169.4 | 1169.4 | 5 | 0 |
| about 7 ms RTT, w8 | eval | 5 | 73.7 | 1123.7 | 1125.8 | 1125.8 | 5 | 0 |
| about 7 ms RTT, w8 | evalsha | 5 | 71.8 | 1196.1 | 1200.9 | 1200.9 | 5 | 0 |
| about 7 ms RTT, w8 | eval | 6 | 70.8 | 1158.9 | 1162.3 | 1162.3 | 5 | 0 |
| about 7 ms RTT, w8 | evalsha | 6 | 75.1 | 1205.3 | 1210.5 | 1210.5 | 5 | 0 |

| Cell | Mode | runs | median of p50 | median of p99 | min p99 | max p99 | total concurrency blocks |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| loopback, w16 | eval | 6 | 13.9 | 346.6 | 321.1 | 473.8 | 0 |
| loopback, w16 | evalsha | 6 | 8.5 | 185.4 | 177.2 | 298.6 | 0 |
| about 7 ms RTT, w8 | eval | 6 | 72.5 | 1131.7 | 1108.9 | 1162.3 | 30 |
| about 7 ms RTT, w8 | evalsha | 6 | 72.5 | 1185.9 | 1160.0 | 1210.5 | 30 |
