# F300 corrective measurements

Rejected HEAD 304a5835 vs corrected source, same unchanged parent bench.php and pinned dependencies. Three repeats of 100 measured operations after 15 warmups per repeat. Pooled nearest-rank percentiles; commands, commits, conflicts and outcomes must match in every pair. Concurrency=1; no production-load or significance claim. Original G1 versus historical E1 results remain in ../BENCHMARK.md.

## PHP_INT_SIZE=8

| Scenario | Rejected p50 µs | Corrected p50 µs | Δ p50 | Corrected p95/p99 µs | Corrected ops/s | Client CPU µs/op before→after | PHP peak MiB before/after | Commands/commits/conflicts per 100 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| allow | 4224.0 | 4135.0 | -2.1% | 4359.1/4673.2 | 241.5 | 1608.8→1522.5 | 6.0/6.0 | 2500/100/0 |
| blocked | 974.4 | 969.2 | -0.5% | 1171.0/1290.2 | 998.7 | 416.8→426.8 | 6.0/6.0 | 800/0/0 |
| claim | 364.6 | 384.8 | +5.6% | 479.8/515.6 | 2590.3 | 168.1→175.4 | 6.0/6.0 | 400/0/0 |
| compose-reader | 12.2 | 12.0 | -2.1% | 12.5/17.4 | 82431.1 | 12.6→12.3 | 6.0/6.0 | 0/0/0 |
| compose-runtime | 49.9 | 47.9 | -3.9% | 58.2/67.2 | 20198.5 | 52.5→49.8 | 6.0/6.0 | 0/0/0 |
| failure | 5971.1 | 5971.8 | +0.0% | 6440.5/8205.1 | 164.5 | 2243.8→2263.6 | 6.0/6.0 | 3700/100/0 |
| k1-only | 3650.0 | 3622.6 | -0.7% | 4007.2/4474.2 | 276.1 | 1376.9→1336.9 | 6.0/6.0 | 2300/100/0 |
| mixed-conflict | 1666.8 | 1703.9 | +2.2% | 2034.5/2135.8 | 588.0 | 439.8→451.3 | 6.0/6.0 | 800/200/100 |
| mixed-save | 612.8 | 641.0 | +4.6% | 821.4/930.2 | 1511.9 | 209.8→218.7 | 6.0/6.0 | 400/100/0 |
| proven | 2613.0 | 2681.1 | +2.6% | 3078.5/3330.4 | 369.3 | 1061.9→1064.6 | 6.0/6.0 | 1700/100/0 |
| reader | 1133.5 | 1139.4 | +0.5% | 1260.4/1365.4 | 865.1 | 502.9→508.8 | 6.0/6.0 | 1500/0/0 |
| rotation | 7870.4 | 7763.3 | -1.4% | 8378.0/8812.1 | 124.2 | 2954.2→2833.6 | 6.0/6.0 | 4200/100/0 |
| simple | 196.6 | 197.2 | +0.3% | 238.3/258.8 | 4919.2 | 80.2→80.3 | 6.0/6.0 | 200/0/0 |
| unproven | 3598.6 | 3549.8 | -1.4% | 3847.2/3966.1 | 279.6 | 1377.3→1355.5 | 6.0/6.0 | 2200/100/0 |

PHP64 is native linux/arm64; PHP32 is actual linux/386 under emulation. Compare arms within a width. CPU is client user+system CPU; PHP allocated peak is not RSS. Redis CPU, concurrency, network/TLS, production hosts and the custom allowance-only advanced Pipeline are not performance-qualified by this run.

## PHP_INT_SIZE=4

| Scenario | Rejected p50 µs | Corrected p50 µs | Δ p50 | Corrected p95/p99 µs | Corrected ops/s | Client CPU µs/op before→after | PHP peak MiB before/after | Commands/commits/conflicts per 100 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| allow | 12072.7 | 12191.2 | +1.0% | 14455.4/16338.7 | 80.4 | 9244.0→9457.8 | 6.0/6.0 | 2500/100/0 |
| blocked | 3102.9 | 3120.7 | +0.6% | 3494.1/3695.0 | 316.5 | 2605.2→2542.5 | 6.0/6.0 | 800/0/0 |
| claim | 1267.5 | 1300.4 | +2.6% | 1466.3/1557.1 | 757.6 | 1086.4→1109.3 | 6.0/6.0 | 400/0/0 |
| compose-reader | 284.4 | 282.0 | -0.8% | 301.3/336.9 | 3502.5 | 294.2→291.3 | 6.0/6.0 | 0/0/0 |
| compose-runtime | 1141.6 | 1150.6 | +0.8% | 1248.9/1305.2 | 859.5 | 1172.1→1181.1 | 6.0/6.0 | 0/0/0 |
| failure | 16881.9 | 17229.5 | +2.1% | 18982.6/20866.4 | 57.2 | 13058.0→13180.0 | 6.0/6.0 | 3700/100/0 |
| k1-only | 10026.5 | 10073.0 | +0.5% | 11166.5/12829.4 | 97.5 | 7820.3→7776.5 | 6.0/6.0 | 2300/100/0 |
| mixed-conflict | 3466.4 | 3454.8 | -0.3% | 4363.2/5376.5 | 282.1 | 2334.5→2334.2 | 6.0/6.0 | 800/200/100 |
| mixed-save | 1671.5 | 1643.3 | -1.7% | 1860.0/2006.0 | 598.0 | 1279.4→1242.7 | 6.0/6.0 | 400/100/0 |
| proven | 7989.2 | 8055.0 | +0.8% | 8886.6/9201.7 | 123.1 | 6356.5→6341.8 | 6.0/6.0 | 1700/100/0 |
| reader | 3271.6 | 3182.2 | -2.7% | 3522.1/3834.1 | 310.4 | 3008.0→2560.9 | 6.0/6.0 | 1500/0/0 |
| rotation | 20986.9 | 20920.2 | -0.3% | 22943.7/24683.4 | 47.5 | 15815.2→15651.1 | 6.0/6.0 | 4200/100/0 |
| simple | 611.6 | 612.2 | +0.1% | 689.0/720.8 | 1607.5 | 513.2→501.9 | 6.0/6.0 | 200/0/0 |
| unproven | 10658.7 | 10227.6 | -4.0% | 12433.5/13031.3 | 93.5 | 8553.3→8241.7 | 6.0/6.0 | 2200/100/0 |

PHP64 is native linux/arm64; PHP32 is actual linux/386 under emulation. Compare arms within a width. CPU is client user+system CPU; PHP allocated peak is not RSS. Redis CPU, concurrency, network/TLS, production hosts and the custom allowance-only advanced Pipeline are not performance-qualified by this run.

