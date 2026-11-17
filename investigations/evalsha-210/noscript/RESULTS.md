# #210 — EVALSHA study, second round: NOSCRIPT classification (A5) and the p99 under contention

Base `main@8b3dd27c34c490d7c5fbb03790e39fbe261c6a68` (tree `4188c389995959326ac0c6a2ee860b170f1643a8`, checked against GitHub `main` before the work started). First round: [`../RESULTS.md`](../RESULTS.md), accepted as a bounded investigation and merged; it is not rewritten here. Scope of this round: `investigations/evalsha-210/noscript/` and test support (`tests/Support/Redis/PhpRedisScriptExecutor.php`, `tests/Integration/Redis/NativeNoscriptStudyRedisTest.php`). **No `src/` change, no public API, no default switch: `EVAL` stays the default. No merge, tag or release.**

## Answers

**1. Can a Host adapter tell `NOSCRIPT` reliably inside the current executor contract (DEC-018)? Yes, for the client that was tested; A5 is resolved for that client only.**
With ext-redis 6.3.0 `rawCommand()` (PHP 8.4.27, linux/arm64, Redis 7.0.15, one node) an adapter owned by the Host recognises a cache miss without any change to `RedisCommandExecutorInterface` (same `execute(array): mixed`; the package is untouched). It has to do four things, each shown by a test that fails when it is left out (below):

1. clear the error text (`clearLastError()`) before every command, because `getLastError()` is sticky;
2. treat `false` as an error reply only when `getLastError()` is then non-null (a nil reply is also `false`, with no text);
3. compare the whole error code, the first token of the text, with `NOSCRIPT` (`NOSCRIPTISH` is a script's own error and is never a miss);
4. fall back to `EVAL` once, and let every other failure propagate unchanged: a server error text as `\RedisException($text)`, an unknown error code and a lost connection as the `\RedisException` that ext-redis raises.

Limits of this answer: one client and version (ext-redis 6.3.0; the shapes recorded for 6.2.0 in the first round are consistent but were not re-run), PHP 8.4.27 on linux/arm64, single Redis node, no Predis, no Cluster, replication or failover, no pipelining or `MULTI`. A simulated cache loss (`SCRIPT FLUSH`) is not a failover. One residual risk is stated, not hidden: an error reply whose code is `NOSCRIPT` that a *script* returns (`redis.error_reply('NOSCRIPT …')`) is byte-identical to a miss on the wire and would be replayed (`testALookalikeNoscriptFromAScriptThatRanIsReplayedByTheCodeOnlyClassification`: two effects). No script of the package answers with that code (`testNoScriptOfThePackageAnswersWithTheCodeNoscript` scans `src/`), so the risk is a future-script risk that this test guards. A `SCRIPT EXISTS` confirmation after the miss removes it (one effect) but was evaluated and rejected: when another client loads the script between the miss and the confirmation, it raises an error for a call that never ran (`testTheConfirmingVariantFailsACallThatNeverRanWhenAnotherClientLoadsTheScriptFirst`).

**2. Does the p99 increase at about 7 ms round trip repeat on current `main`? Yes. It repeats in every RTT pair of every set (22 of 22), with a median of +5.6 % to +7.3 % at a measured round trip of 6.3 ms and +6.8 % at 8.9 ms; individual pairs range from +1.7 % to +16.1 %.** Loopback is the opposite: `EVALSHA` p99 is lower in every pair (-41 % and -50 % median). The cause of the increase is not established; section "What was excluded" lists what the diagnostics rule out.

**Does it support continuing with `EVALSHA`?** The evidence is mixed. For: 95.5 % to 97.4 % fewer bytes sent (first round), a lower p99 on a local network, A5 resolved for ext-redis. Against: a repeatable p99 increase of about 6 % to 7 % (median) with 3 of 22 RTT pairs above the 10 % bound of the study's own criterion A4, whose cause is unknown. The decision that follows is the Owner's, as an independent step: see the end of this report.

## Environment and provenance

All PHP and Redis ran in Docker on one Apple M1 host (8 CPUs, 8 GB for the Docker VM, Docker 29.8.2, kernel 7.0.14-linuxkit).

| Item | Value |
| --- | --- |
| PHP image | `maatify-rl-php64:local`, `sha256:d4f5a573f22c…`, linux/arm64, PHP 8.4.27, `PHP_INT_SIZE` 8, ext-redis 6.3.0, pcntl |
| Redis | `redis:7.0.15-alpine`, `sha256:755105238729…`, linux/arm64, `--save "" --appendonly no`, defaults (`busy-reply-threshold 5000`, `maxmemory 0`), one container per campaign |
| Client of the measurements | `tests/Support/Redis/RespRedisCommandExecutor` (stream socket, no pipelining, one connection per worker), the client of the first round |
| Client of the NOSCRIPT part | ext-redis 6.3.0 `rawCommand()` through `PhpRedisScriptExecutor` |
| Network | the PHP container shares the Redis container's network namespace: "loopback" is a real `127.0.0.1` connection; the RTT sets use a user-space delay proxy in that namespace |

| Run | Head (full SHA) | Tree | Tracked tree at the run |
| --- | --- | --- | --- |
| Paired p99 campaign `raw-paired/` | `3253a3b3ebf2cb9047d46e77b236687d50f75fe1` | `df2a5f16cf6b73c063a285e665e66cd3e7c920d9` | clean (recorded in `raw-paired/environment.txt`) |
| Diagnostics `raw-diagnostics/` | `e55b634f178f427b4e85592468f74a056fba1957` | `5b7835d8312f252f38a3dd9bd9774a1ff9568db5` | clean |
| Native-client part `raw-native/` | `88e69a4c04dc7f12e28a9aa896676753f90edbf6` | `ac283e82b226a3ba3c00ed6101f4e72a3084d80f` | clean |

Files on the measurement path (SHA-256, first 12 hex digits, at `3253a3b3`): `paired-contention.php` `cd182de6b2ba`, `paired-inner.sh` `92bb24443b24`, `run-paired.sh` `0e8169f71fc1`, `delay-proxy-nodelay.php` `b098050c3e1d`, `rtt-probe.php` `2839219a703f`, `summarize-paired.php` `98f9b366b5df`, `tests/Support/Redis/RespRedisCommandExecutor.php` `d5272012ec92`, `investigations/evalsha-210/lib/Harness.php` `268f2628cdf9` (both unchanged from the first round). The diagnostics and native scripts were committed before their runs (`e55b634f`, `88e69a4c`); the header comments of `diag-inner.sh` and `run-diagnostics.sh` still name the paired scripts they were copied from, which does not change what they run.

A preliminary run on an uncommitted tree used the first round's proxy; it is not evidence and is not in the repository. It is what exposed the proxy artifact below.

### The first round's proxy stalls large requests on Linux

`investigations/evalsha-210/delay-proxy.php` (first round) lets Nagle and delayed ACK stall every request larger than about 8 KB by about 40 ms when it runs on Linux: an `EVAL` with a script text, never an `EVALSHA`. `raw-native/payload-latency-probe.txt` (60 sequential calls per size): direct 0.07 ms at 13 KB; through the first-round proxy at 3.5 ms each way 8.68 ms at 0 KB and **50.12 ms at 13 KB**; through `noscript/delay-proxy-nodelay.php` (the same proxy with `TCP_NODELAY` on its sockets, nothing else changed) 8.74 ms and 8.52 ms. The first round ran on macOS, where this was not observed and its figures stand as recorded; any measurement of `EVAL` through that proxy on Linux is inflated. This round uses the `TCP_NODELAY` copy.

## Part 1: NOSCRIPT classification (`NativeNoscriptStudyRedisTest`, 22 tests, 2160 assertions on 64-bit)

Group `evalsha-study` + `native-noscript`; skipped where ext-redis is absent (the 32-bit image). Raw output: `raw-native/native-noscript-testdox.txt`, shapes in `raw-native/shape-probe.txt`. The unchanged first-round class still passes (`raw-native/evalsha-study-test.txt`: 14 tests, 3542 assertions).

**A. Reply shapes of ext-redis 6.3.0 `rawCommand()`**

| Case | `rawCommand()` returns | `getLastError()` |
| --- | --- | --- |
| nil reply (`GET` of a missing key; a script `return false`) | `false` | `NULL` |
| `EVALSHA` of an unknown hash | `false` | `NOSCRIPT No matching script. Please use EVAL.` |
| ordinary error (`WRONGTYPE`, script runtime error) | `false` | the server's text |
| script `error_reply('NOSCRIPTISH x')`, `error_reply('NOSCRIPT script said')` | `false` | the text (so a script's own error can carry the code `NOSCRIPT`) |
| script `error_reply('MY custom error')` (a code ext-redis does not know) | throws `RedisException: MY custom error` | the text |
| any later command after a failure | its own reply | **still the earlier failure's text** until `clearLastError()` |
| a handle that was `close()`d (the test); a connection killed by the server behaved the same in an ad hoc probe that is not committed | reconnects before the write; the command runs once and replies | `NULL` |

**B. Classification through the adapter** (counts are the adapter's own: commands written, `EVALSHA` calls, misses, fallback `EVAL`s)

| Case | Result |
| --- | --- |
| cold cache (never loaded) | `EVALSHA`, `NOSCRIPT`, one `EVAL`: 2 commands, 1 miss, 1 fallback, effect once; warm: 1 command |
| a cold call, then `SCRIPT FLUSH` before each of 3 more calls | 4 misses and 4 fallbacks (one each per call), the counter ends at 4: no effect repeated |
| ordinary Redis error from a loaded script that had already written | `\RedisException('WRONGTYPE …')`, 1 command, no fallback, effect once |
| `NOSCRIPTISH` from a loaded script that had already written | exception with the script's text, 1 command, no fallback, effect once |
| legitimate nil reply, also right after an earlier failed command | returned as `false`, no miss counted, effect once |
| **negative control** (adapter that does not clear the text) | the nil reply is thrown as the stale `NOSCRIPT`, counted as a miss, the script runs twice |
| script that itself answers `NOSCRIPT …` | code-only classification: two effects (the stated residual risk); confirming variant: one effect |

**C. A lost request and a lost reply** (proxy that closes the connection, in front of Redis): the adapter writes one command, makes no fallback and raises `\RedisException`; lost request: 0 effects, `cmdstat_evalsha` calls 0; lost reply: 1 effect, calls 1. ext-redis did not resend in either case.

**D. DEC-037 through the adapter** (each against the same workload on the RESP client with `EVAL`: same decisions, same keys, same contents, expiries within 5 s because they come from Redis `TIME`): a request on a cold cache is saved by one commit (misses = fallbacks); the cache lost just before the commit: the commit is the first miss, one fallback, state equal to the control; a classified rejection leaves every key, value and expiry unchanged and never calls the commit; a stale plan (a competing request completes before the commit) is rejected and the recalculated plan saved once, with the cache lost before each commit (saves `[0, 1]`), no update lost or applied twice; the public workload (new-device and failure requests, admission reservations and outcomes, a hot account) ends in the same decisions and state as `EVAL` over the RESP client; an exact `PEXPIREAT`/`PEXPIRETIME` value and a one-millisecond difference survive the native path.

**Single mutations of the adapter** (`raw-native/mutants.txt`, run from the committed tree, restored after): prefix match instead of the whole code: 1 failure (`NOSCRIPTISH`); resend after a transport exception: 3 failures (cold-cache count and both lost-transport cases); a second fallback `EVAL`: 8 failures. Removing `clearLastError()` is the negative-control test above.

## Part 2: paired p99 (`run-paired.sh`; raw `raw-paired/`; tables `raw-paired/summary.md`)

Design: Redis and the PHP container are started by the script; every cell is its own PHP process, begins with `SCRIPT FLUSH`, runs an unmeasured warm-up of 12 requests of the hot account's own sequence in the cell's mode (all scripts of the measured phase are cached; measured `NOSCRIPT` misses: 0 in every cell), then `FLUSHALL` and `CONFIG RESETSTAT`, then the barrier. Workload: forked workers, one hot account, `recordFailure`, default retry budget (500 ms), the first round's `contention.php` logic (`paired-contention.php` adds the warm-up and the per-request commit and verify counts). A pair is the `EVAL` cell and the `EVALSHA` cell of one set run back to back; the order alternates between pairs (`raw-paired/manifest.tsv`); everything runs sequentially, 2 s apart. Round trip measured by `rtt-probe.php` (300 `PING`s on one connection, before and after every set, `raw-paired/rtt-probes.jsonl`): loopback p50 0.04 to 0.08 ms; 7 ms proxy (2.5 ms each way configured) p50 6.32 to 6.40 ms, p99 7.3 to 8.1 ms; 9 ms proxy (3.5 ms each way, the first round's configuration) p50 8.86 to 8.89 ms. The target of "about 7 ms" is therefore met at 6.3 ms (the proxy itself adds about 1.3 ms); the 8.9 ms set is the first round's configuration.

| Set | Pairs | n per cell | RTT p50 | EVAL p99, ms (median, range) | EVALSHA p99, ms (median, range) | Median delta (range) | EVALSHA p99 higher | Concurrency blocks per cell |
| --- | ---: | ---: | --- | --- | --- | --- | ---: | --- |
| RTT 8 workers × 10 (the first round's case) | 10 | 80 | 6.3 ms | 765.2 (745.6 to 773.4) | 804.0 (792.7 to 886.7) | **+7.3 %** (+3.6 % to +14.7 %) | **10 of 10** | 5 to 6 (all pairs) |
| RTT 8 workers × 40 | 6 | 320 | 6.3 ms | 764.9 (758.3 to 781.6) | 804.8 (792.6 to 834.2) | **+5.6 %** (+1.7 % to +9.3 %) | **6 of 6** | 5 to 6 |
| RTT 8 workers × 10, 3.5 ms each way | 6 | 80 | 8.9 ms | 1024.8 (1022.5 to 1054.6) | 1094.4 (1081.8 to 1188.6) | **+6.8 %** (+5.5 % to +16.1 %) | **6 of 6** | 5 to 6 |
| loopback 8 workers × 10 | 10 | 80 | 0.05 ms | 80.2 (78.4 to 111.6) | 46.2 (43.3 to 51.0) | **-41.4 %** (-61.2 % to -36.3 %) | 0 of 10 | 0 |
| loopback 16 workers × 25 (first round's loopback case) | 6 | 400 | 0.05 ms | 161.6 (113.0 to 177.4) | 81.9 (78.8 to 140.7) | **-49.7 %** (-54.9 % to -6.7 %) | 0 of 6 | 0 |

Zero errors and zero `NOSCRIPT` misses in every cell; the same number of requests and the same concurrency-block counts (within 1) in both modes. In the 8 × 10 RTT set the p99 of 80 requests is the slowest request: it is a request blocked by concurrency, which stops when the retry budget is spent. Per-pair numbers (p50, p95, p99, blocks) are in `raw-paired/summary.md`; every request is a record in the JSON cells. Against the first round: +7.2 % (original) and +4.8 % (rerun) at a nominal 7 ms on macOS; this round +5.6 % to +7.3 % on Linux.

Pairs above the 10 % bound of the study's criterion A4: 3 of 22 RTT pairs (pair 3 11.5 % and pair 7 14.7 % of the first set, pair 1 16.1 % of the third); the medians are below it.

### What was excluded (`run-diagnostics.sh`, `raw-diagnostics/`; `command-latency-probe.php`, `raw-native/command-latency-probe.txt`)

- **Not the number of round trips or retries.** The slowest requests have the same number of commands in both modes (119 in the examined pairs) and the same commits; the time per command is what differs: about 6.43 ms (`EVAL`) against 6.76 ms (`EVALSHA`) in those requests.
- **Not a per-command cost at this round trip.** `command-latency-probe.php`, same client, the same 13 KB script, 150 sequential calls per connection, 1 and 8 connections, 3 alternating repetitions: p50 6.17 to 6.58 ms in both modes, with no consistent difference (`raw-native/command-latency-probe.txt`; the 8-connection p99 values scatter in both directions).
- **Not present without contention.** One worker, 40 sequential requests through the same 7 ms proxy, 6 pairs: p50 53.8 to 57.2 ms in both modes, p99 median delta +0.9 % (range -4.8 % to +4.4 %), `EVALSHA` higher in 4 of 6 (`raw-diagnostics/summary.md`).
- **Not present without delay.** The 8 × 10 contention through the same proxy with 0 ms added: `EVALSHA` p99 lower in 6 of 6 pairs, median -28.5 %.

What remains is an interaction of contention and added delay in the request flow, not shown to belong to the command, the client, the proxy's size handling or extra retries. The cause is not established by this study; candidate explanations (scheduling in the Docker VM, the user-space proxy under eight connections, how the retry budget interacts with a shorter evaluation) were not tested.

## Rerun commands

```bash
bash investigations/evalsha-210/noscript/run-native.sh      # shapes, NOSCRIPT test class, proxy probe, command latency probe (about 2 minutes)
bash investigations/evalsha-210/noscript/run-paired.sh      # the paired p99 campaign (about 25 minutes); output raw-paired/
bash investigations/evalsha-210/noscript/run-diagnostics.sh # the two diagnostic sets (about 5 minutes); output raw-diagnostics/
docker run --rm -v "$PWD:/app" -w /app maatify-rl-php64:local php investigations/evalsha-210/noscript/summarize-paired.php investigations/evalsha-210/noscript/raw-paired
```

The scripts need Docker, the `maatify-rl-php64:local` image (the repository's `docker/php32/Dockerfile` built natively; override with `PHP_IMAGE`) and `redis:7.0.15-alpine`. Pair counts: `PAIRS_RTT7_W8X10`, `PAIRS_LOOP_W8X10`, `PAIRS_RTT7_W8X40`, `PAIRS_RTT9_W8X10`, `PAIRS_LOOP_W16X25` (and `PAIRS_D1`, `PAIRS_D2` for the diagnostics).

## Not done, and limits

Single node, one Docker VM on one laptop (timing noise is visible in the spread of every set); one client and version; no Predis, Cluster, replication, failover or pipelining; one workload for the p99 (hot-key `recordFailure`), not the other workloads of the first round; the new native test class was not run on `PHP_INT_SIZE` 4 (the 32-bit image has no ext-redis; it is skipped there); the first round's own raw data was not re-run beyond the cells reported here. The 7 ms target was measured as 6.3 ms.

## Decision needed from the Owner (a separate step; nothing here asks for it to be taken now)

The study answers the two questions it was given and does not choose between these:

1. Whether an `EVALSHA` translation owned by the Host (an adapter such as `PhpRedisScriptExecutor`, kept out of the package's public contract) is an acceptable direction at all, and if so whether the package should ship or document a reference adapter. The conditions of answer 1 would then be part of that adapter's contract; DEC-018 as written would not need to change, but a decision record may still be wanted.
2. Whether a median p99 increase of about 6 % to 7 % at a 6 to 9 ms round trip (with individual pairs above 10 %) is acceptable for about 96 % fewer bytes sent and a 40 % to 50 % lower p99 on a local network, or whether the cause must be found first. A follow-up that finds the cause would be a measurement unit of its own.
