# #210 — EVALSHA measurement study: plan (registered before any measurement)

Baseline: `main@743de0435fea70ab4a3292ad9ca4e9743c198354`. Lead brief: [#210 comment 6887007913](https://github.com/Maatify/php-rate-limiter/issues/210#issuecomment-6887007913).
Scope: an isolated experimental path in the test harness and an investigation folder. No change to `src/`, no public API, no default runtime switch. The result is a recommendation, not an implementation.

## Question

DEC-037 records, from an earlier environment, about 88 KB without and 498 KB with the request overlay per `checkOnly` of a new device, because the wrapped Lua text travels with every `EVAL`, and defers `EVALSHA`. What does `EVALSHA` change on the current baseline, what does it cost when the script cache is cold or lost, and is it safe next to DEC-037's all-or-none save?

## Experimental path

`tests/Support/Redis/RespRedisCommandExecutor` (the RESP test client) gains script modes. Default `eval`: unchanged. Modes `evalsha`, `evalsha-flush-each` (`SCRIPT FLUSH` before every script call), `evalsha-flush-commit` (before every commit script only). In an `evalsha*` mode the client sends `EVALSHA <sha1 of the exact text it was given>` and falls back to `EVAL` only after a server error reply whose text starts with `NOSCRIPT`.

Why at the wire, below the store: `RedisFullCapabilityStore::executeInRequestWhenHeld()` routes the admission `EVAL`s into the request overlay by their command name and source. A translation placed above that interception could bypass the held writes. At the wire every script has already been intercepted and wrapped, so the hash is of the text Redis would execute, and held request data stays in `ARGV` (one script body, one hash).

The client is instrumented: bytes written and read, Redis commands written, script calls, `EVALSHA` calls, `NOSCRIPT` misses, fallback `EVAL`s. Counters are per request.

## Workloads (public builder, `RedisFullCapabilityStore`, isolated Redis 7.0.15)

`read` (an identical `checkOnly` whose state is already stored: no commit), `newdevice` (`checkOnly`, all-new state), `failure` (`recordFailure`, score writes), `admission-reserve` (`checkOnly` of the admission policy, fence open, unproven lane), `admission-outcome` (the outcome command with its permit), `recovery` (the request that closes a half-open circuit), and hot-key contention (forked workers, one hot account, `recordFailure`).
Declared omissions: the proven lane (a credential and a permit claim with a credential), a Redis Cluster or replicated topology, Predis, any managed Redis service.

## Cells

1. Local loopback, every workload × {eval, evalsha, evalsha-flush-each, evalsha-flush-commit}: 200 requests after 20 warm-up (recovery 40 after 3).
2. Cold cache: `SCRIPT FLUSH` before the first measured request, no warm-up, 20 requests: sample 0 against the rest.
3. Added round trip: a TCP proxy delaying each direction by 3.5 ms (about 7 ms RTT), every workload × {eval, evalsha} (40 requests after 4; recovery 15 after 2), plus `evalsha-flush-each` for `newdevice`.
4. Contention: 8 and 16 workers × 25 requests locally, 8 workers × 10 requests with the proxy, {eval, evalsha}.
5. Cache loss by a server restart: warm, restart the container, the first six requests of a new connection.

Reported per cell: samples, median request bytes sent and received, Redis commands (= round trips, no pipelining), `NOSCRIPT` misses, p50/p95/p99 latency, errors. Raw results are in `raw/`; the exact commands are in `run.sh`; the environment (PHP, Redis, client, CPU, docker, configuration) is in `raw/environment.txt`. Wire bytes are counted by the client on the socket, not inferred from script length.

## Correctness evidence (`tests/Integration/Redis/EvalshaStudyRedisTest.php`, group `evalsha-study`, also `php32`)

- the wire command is `EVALSHA` of the exact text; a cold cache costs one `NOSCRIPT` round trip, then `EVAL`; a warm cache costs none;
- an error reply other than `NOSCRIPT` is rethrown, never replayed, and the script ran once;
- a lost request and a lost reply (a proxy that closes the connection, in front of the server and after it) are never replayed: at most one execution, a `BackendFailureException` for the caller;
- the same public workload (new-device and failure requests, admission reservations and outcomes, a hot account) ends in the same state with the same decisions as `eval` in every mode, including a cache loss before every script call and before every commit;
- an exact `PEXPIRETIME` and a one-millisecond difference survive the path (the harness of #219);
- native-client error shapes with ext-redis `rawCommand()` (64-bit only; the 32-bit image has no ext-redis).

The existing atomicity, recovery, retry and admission suites are run unchanged under `REDIS_INTEGRATION_SCRIPT_MODE=evalsha` and the flush modes, on 64-bit and on actual `PHP_INT_SIZE=4`.

## Acceptance criteria for a recommendation to adopt (fixed now)

Adopt only if all hold: (A1) decisions, final state and the existing suites are identical in every mode; (A2) no script is replayed after an unknown transport result and no error other than an identified `NOSCRIPT` triggers a fallback; (A3) median bytes sent fall by at least 80 % on every request workload, with no extra round trip in the warm state; (A4) the cold and flushed-cache cost (one extra round trip per missed script) is reported and bounded, and p99 under contention does not worsen by more than 10 % against `eval`; (A5) the way a client reports `NOSCRIPT` is established for every client the package would claim, without a blanket catch.
Adopt with revision if A1 to A4 hold and A5 needs a bounded, client-specific capability. Retain `EVAL` if A1 or A2 fails, or the gain is small. Any change to the public executor contract is a separate proposal for the Owner.
