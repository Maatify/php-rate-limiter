# Stage 5-b2 results — admission Store operations

Tested tree: `8745864` (HEAD of the implementation; this file is the only later change). Redis 7.0.15 (`docker/redis-integration`) via `scripts/ci/run-with-redis-service.sh`, PHP 8.5.9.

## Suites

| Run | Result |
| --- | --- |
| Full suite, normal dependencies, redis 7.0.15 | `OK (1550 tests, 20050 assertions)` (baseline at `b417ae3`: 1471 tests; +79 are the new admission tests) |
| Full suite, `composer update --prefer-lowest --prefer-stable` (phpunit 11.5.50, phpstan 2.0.0), redis 7.0.15 | `OK (1550 tests, 20538 assertions)` |
| PHPStan max, normal and lowest | `[OK] No errors` both |
| `composer format:check` (php-cs-fixer + PER-CS 3.1 delta) | clean |

Disclosure: a first lowest-dependencies run (run through `composer test`) stopped at composer's 300 s process timeout and showed one `E` at 66 % while the machine was loaded; that run used the earlier race-runner whose barrier let 64 workers serialise. It was repeated after the runner fix (barrier `1.5 s + n/10`) without composer's timeout, and passed. The `E` was not reproduced and its test was not identified from the truncated log; it is reported, not explained.

## Gates (each detector fired)

| Gate | Result |
| --- | --- |
| NM-6 T1-N4a / N4b / N5 on the production store | pass: key dump before = after on every refusal (N4a, N4b), previous set byte-identical on admit (N5), no TTL refreshed |
| NM-6 detector: original study reserve script (`G1RedisStore::RESERVE`, verbatim) | fails N4b (sets pruned on refusal) and N5 (previous set written); passes N4a, as archived |
| NM-6 malformed state (10 cases: wrong type or bad field in counter, previous counter, device set, previous set, set, bucket, permit; counter without TTL; non-numeric counter) | `RateLimiterException`, dump before = after |
| NM-6 R-3 value level (counter, device, bucket, permit all precede a refusing scope) | dump before = after |
| NM-6 R-1 counter race (cap 5, 2 seeded, 16 processes, 3 rounds) | `3/3/3` |
| NM-6 R-2 device race (cap 5, 4 seeded, 16 processes, 3 rounds) | `1/1/1` |
| NM-6 detector: non-atomic reservation | counter `16/16/16` (cap 3 left), device `16/16/16` (1 left) |
| NM-7 counter, device, bucket, multi-scope (all-or-none), concurrent outcomes | exact at 16 and 64 processes, 3 rounds each: counter `3/3/3`, device `1/1/1`, bucket `4/4/4`, multi-scope `2/2/2` with every scope advanced by exactly the admitted count; 16 or 64 concurrent failure outcomes leave exactly 16 or 64 set members and refunds are not lost |
| NM-7 detector: non-atomic reservation | x16 `16/16/16`; x64 `14/37/64` (cap 3 left) |
| NM-7 failover (`5b2/failover.php`, 5 rounds, 500 acknowledged then primary `SIGKILL`, replica promoted) | natural lag: `L` = 0 / 0 / 0 / 0 / 0 (min 0, median 0, max 0). Injected lag of 100 (replica cut off): `L` = 100 in all 5 rounds, so the harness does observe loss. **Reported, not bounded** |
| ROT: counters sum current + previous, write current only; previous device set never written on admit or refuse | pass; detector: the study script writes the previous set |
| FAIL: typed outage from each of the five operations | `BackendFailureException` reaches the caller, nothing written |
| FAIL: unclassified `LogicException` | reaches the caller unchanged (not wrapped, not reclassified); detector: a blanket-wrapping executor turns it into `BackendFailureException` |
| FAIL: malformed epoch / permit record (6 cases) | `RateLimiterException`, never `BackendFailureException`, dump unchanged |
| PERMIT: duplicate, conflict, rejected, late, unknown, `permit_exists` | pass on both stores |
| PERMIT: race of 16 claims on one permit, 3 rounds | exactly 1 `accepted`, the other 15 `duplicate` |
| PERMIT detector: non-atomic claim | `16/16/16` accepted |
| PERMIT: race of 16 issues of one permit id | exactly 1 admitted, counter 1, others `permit_exists` |
| Contract parity | the 17 contract fixtures give identical results on `RedisFullCapabilityStore` and `AdmissionInMemoryReferenceStore` |

Not covered here (by plan): mixed fleet and rollback (5-b3b, engine level). Failover ran on redis 7.0.15 in Docker with asynchronous replication on one host; it says nothing about other topologies.

## S0-PARITY

`git diff b417ae3 -- src tests` shows one modified file, `src/Repository/Redis/RedisFullCapabilityStore.php`: two removed lines (the `FullCapabilityStoreInterface` import and the `implements` clause), added imports, one private property, and the delegating methods after `claimPostPunishmentReentry`. No hunk lies inside a Lua heredoc: the `git diff -U0` hunks are at lines 7, 21, 35, 38 (header) and 1723 (after the last `LUA;`). Every other changed path is an added file. No existing test was edited.

## Open items for the Lead

1. **Epoch retention.** `bumpBindingEpoch` writes no TTL (the interface carries none; a lapsed epoch would revive voided credentials). Storage grows by one key per account that ever changed its password. A retention rule needs a decision tied to the credential's absolute lifetime.
2. **Gate order is request order**, not a fixed kind order; the engine (5-b3) decides the order of its scopes.
3. **`retryAfterSeconds`** is computed by the store only for the bucket; for counters and sets it is 0 (the window end belongs to the caller).
4. Scope fields beyond the contract are rejected rather than ignored (`previousKey` on a set-size or bucket scope, a member on a counter or bucket, duplicate keys in one request, the scope name `permit_exists`).
5. The `binding_epoch` and `_p` purposes are documented in `KEY_STRATEGY.md` §4.6 as reserved/dormant; the derivation arrives with 5-b3.
