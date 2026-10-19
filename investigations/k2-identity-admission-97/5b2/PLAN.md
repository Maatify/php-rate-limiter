# Stage 5-b2 plan — admission Store operations (#97, step 5)

> **Status:** PRE-REGISTRATION, committed **before** any line of the implementation. Binding inputs: `RUNTIME_IMPLEMENTATION_CONTRACT.md` §2.4, §8.3 (ROT, FAIL, S0-PARITY), §8.4 (NM-6), §8.5 (NM-7), §10; DEC-024 conditions C-c2 … C-c5 and point 3; OD-1 (permit T1 required for opted policies). Base: `main@b417ae3` (5-b1).
> No gate below may be weakened after a run. A gate whose detector did not fire is "not evidenced", not a pass.

## 1. Scope

Implemented: `RedisFullCapabilityStore` implements `AdmissionCapableFullStoreInterface` and `AdmissionPermitStoreInterface`; the in-memory reference store (`tests/Support/Admission/AdmissionInMemoryReferenceStore`, the full-capability reference store of the test suite) implements the same contract in PHP. `FullCapabilityStoreInterface` is not edited. No engine, pipeline or builder code calls any new operation.

Not implemented (prohibited): any DEC-025 constant, any `D` / `G` value (they arrive as request parameters), any edit of an existing Lua script or existing test, any touch of DEC-019 / DEC-021, any index line or decision record.

## 2. Operation semantics fixed before the code

One Lua script per operation: `reserve`, `outcome`, `epochRead`, `epochBump`, `permitClaim`. Caller `now` is a parameter, TTLs are relative, no `TIME` call, replies per DEC-018. Keys are `PREFIX:{hash(namespace)}:adm:<family>:hash(...)`; the hash tag puts every admission key of a namespace (hence of every verifier class) in one slot. The operations live in an internal class (`Redis\RedisAdmissionOperations`) so that the existing store file receives only the `implements` clause and four delegating methods, and no existing Lua constant is touched.

| Scope kind | Reserve (gate, write) | Outcome |
| --- | --- | --- |
| `COUNTER` | gate `cur + prev >= capacity` refuses; `prev` is read, never written; write `INCR cur`, `EXPIRE ttl` when created. Window or day is carried by the caller's key and ttl | `SUCCESS`: refund = decrement `cur` if it exists and is above 0 (P counters). `FAILURE`: nothing |
| `DEVICE_MEMBER` | `cur` zset and `prev` zset (members scored by expiry). The member is existing when live in `cur` (`member`) or in `prev` (`previousMember`). Existing: no write. New: gate `liveCount(cur) + liveCount(prev) >= capacity` refuses; else prune `cur` only, `ZADD cur now+ttl`, `EXPIRE`. **No prune and no write on any refusal; `prev` is never written** (T1-N4b, T1-N5) | nothing |
| `SET_SIZE` | member of a set (U net-failure set `F`, D8 set). Gate: member not in the set and `SCARD >= capacity` refuses. Reserve never writes it | `FAILURE`: `SADD member`, `EXPIRE ttl` when created. `SUCCESS`: `SREM member`. The caller passes only the sets an outcome applies to (the D8 set is not passed on `SUCCESS`: it neither adds nor removes) |
| `BUCKET` | credit bucket in credit-seconds: capacity `C * T` (`capacity` = C, `ttlSeconds` = T), refill 1 per second, cost T per admission; absent = full. Gate: refilled credit `< T` refuses, `retryAfterSeconds` = `T - credit`. Write: credit `-T`, `last = now`, `EXPIRE` = time to full | `SUCCESS`: credit `min(C*T, credit + T)`; absent stays absent (already full). `FAILURE`: never refunded |

Rules common to all kinds:

* **Validation before the first write.** The type and every field of every key of the request (current and previous, all scopes, the permit key) are read and validated first; a malformed value raises `RateLimiterException` (Lua `error_reply`) with nothing written. A current or previous key that exists without a TTL is malformed.
* **Order of gates = order of the request's scopes**; the first refusing scope is named in the result (the caller controls the order). A refusal writes nothing.
* A scope with a `previousKey` is accepted for `COUNTER` and `DEVICE_MEMBER` only; `SET_SIZE` and `BUCKET` reject it with `RateLimiterException` (rotation of those scopes is not specified by the contract; rejecting beats silently ignoring).
* `retryAfterSeconds` is computed by the store only where the store owns the clock arithmetic (the bucket); it is `0` for the other scopes (the window end is the caller's value, derived from `now` and its `W`).
* **Permit** (when the request carries one): created inside the same script after all gates pass, state `ISSUED`, fields `lane`, `subject`, `operation`, `deadline`, `grace`, lifetime `deadline + grace - now` (minimum 1). A permit id that already exists (any type) refuses with scope `permit_exists` before any write (T1-N1 … N3).
* **Claim** (`claimAdmissionPermit`), one compare-and-set: absent or `now >= deadline + grace` ⇒ `UNKNOWN`; lane / subject / operation mismatch ⇒ `REJECTED`; state `CLAIMED`: same outcome ⇒ `DUPLICATE`, other ⇒ `CONFLICT`; `now >= deadline` ⇒ `LATE` (no write); else write `CLAIMED` + outcome ⇒ `ACCEPTED`. Under a race of claims exactly one is `ACCEPTED`.
* **Epoch.** One integer per account, key supplied by the caller. Read: current, else previous, else 0. Bump: `current = max(current, previous) + 1`, returned. **No TTL is written** (the interface carries none and a lapsed epoch would revive voided credentials): retention is an open item for the Lead, stated in the PR.
* **Failure classification (C-c5).** The executor classifies outages: `BackendFailureException` propagates unchanged and a non-typed throwable propagates unchanged (never wrapped, never reclassified). A reply that is not the documented shape, or a Lua `error_reply`, is `RateLimiterException`. No fallback anywhere. PHP validates every request field before the call (positive capacity / ttl, representable sums), so a field error writes nothing.
* No idempotency is claimed for `applyAdmissionOutcome` (a duplicated outcome applies twice).

## 3. Gates

All Redis gates run on `docker/redis-integration` (redis 7.0.15) through `composer test` / `composer test:integration`. Test classes are tagged with PHPUnit groups named below.

### NM-6 (group `nm6`, real Redis, `tests/Integration/Store/AdmissionStoreNm6RedisTest.php`)

| Fixture | PASS |
| --- | --- |
| N4a refusal by a counter, expired members present in both device sets | a full key dump (type-aware, with PTTL class) before = after |
| N4b refusal by the device cap, expired members in both sets | dump before = after: no member pruned in either set, previous untouched |
| N5 admitted, previous set present | previous set bytes unchanged; current set gains exactly the new member |
| Wrong-type / malformed key anywhere in the request (counter, previous counter, device set, set, bucket, permit) | `RateLimiterException`, dump before = after (all-or-nothing validation) |
| R-1 counter race: cap 5, 2 seeded, 16 processes released at one barrier, 3 rounds | admitted per round exactly `3/3/3` |
| R-2 device race: `capacity` 5, 4 members seeded, 16 processes, 3 rounds | admitted per round exactly `1/1/1` |
| R-3 value-level all-or-nothing: a request whose last scope refuses leaves every earlier scope's state unchanged | dump before = after |

**Detectors (must fire, reported with the run):** (1) the **original study** reserve script (`G1RedisStore::RESERVE`, embedded verbatim as a test fixture) fails N4b (sets pruned on refusal) and N5 (previous set written) on the same fixtures; (2) a deliberately non-atomic reservation (check in one round trip, write in another; the study control) admits more than the cap in at least one R-1 round.

### NM-7, store level (group `nm7`, real Redis, `AdmissionStoreNm7RedisTest.php`)

Registered values (none set elsewhere):

| Item | Value |
| --- | --- |
| Concurrency levels | 16 and 64 processes, one reservation each, released at one barrier |
| Rounds per level | 3 |
| Scopes under race | counter (cap 5, 2 seeded), device member (cap 5, 4 seeded), bucket (`C` 4, `T` 1800 s, fresh account: 4 takes), set size (`F` 4, 3 members seeded, new accounts) |
| Multi-scope race | one request over counter + device member + bucket (all must admit together): admitted = min of the caps, each scope's state = admitted count, never above its cap |
| Failover | `5b2/failover.php`: primary and replica (redis 7.0.15), 5 rounds; one client reserves sequentially on a distinct counter, the primary is killed (`SIGKILL`) after 500 acknowledged reservations and the replica promoted; **`L` = acknowledged − present on the promoted replica**, reported per round (min / median / max) and **not bounded** |
| Mixed fleet, rollback | not in 5-b2 (engine level, 5-b3b) |

PASS: per round, admitted equals the exact expectation of each scope (counter 3, device 1, bucket 4, set `F - seeded`), no scope state above its cap, the multi-scope race writes all or none. **Detector:** the non-atomic control exceeds the cap under the same concurrency (at least one round at 16 or 64). Failover is reported only (no PASS threshold); the run must complete and print `L` for every round.

### ROT (store part, group `rot`)

Counters sum current and previous and write current only; previous keys are never written on admit or refuse (dump of previous keys before = after, PTTL included). **Detector:** the study original script writes the previous device set (N5) and is flagged by the same assertion.

### FAIL (store part, group `fail`)

(a) an executor raising `BackendFailureException` on EVAL ⇒ the same exception type reaches the caller from each of the five operations, and a dump taken afterwards shows nothing written; (b) an executor raising a non-typed `\LogicException` ⇒ the same `\LogicException` reaches the caller (not `BackendFailureException`, not `RateLimiterException`); (c) malformed stored state ⇒ `RateLimiterException`, never `BackendFailureException`. **Detector:** a wrapper executor that blanket-wraps every throwable as `BackendFailureException` fails (b).

### PERMIT (group `permit`)

claim twice same outcome ⇒ `DUPLICATE`, other outcome ⇒ `CONFLICT`; now in `[deadline, deadline+grace)` ⇒ `LATE`; now `>= deadline+grace` or never issued ⇒ `UNKNOWN`; wrong lane / subject / operation ⇒ `REJECTED`; re-issue of an existing id ⇒ refusal `permit_exists` with the record byte-identical; race of 16 claims on one permit ⇒ exactly one `ACCEPTED`, 3 rounds. **Detector:** a non-atomic claim (read state, then write) yields two `ACCEPTED` in at least one round.

### Contract parity (reference store)

The same contract fixtures (counters, rotation, device members, sets, bucket, outcome, permit, epoch) run against both `RedisFullCapabilityStore` and `AdmissionInMemoryReferenceStore` and must give identical results. The reference store is the executable specification used by the later engine tests.

### S0-PARITY

* The existing suites (Unit, System, Integration) pass with **no edit to any existing test** (the diff against `b417ae3` shows only added test files).
* **No existing Lua script changes:** `git diff b417ae3 -- src/Repository/Redis/RedisFullCapabilityStore.php` contains no removed line other than the `implements` clause and no added line inside any heredoc; reported with the diff.
* PHPStan max on normal and lowest dependencies; `composer format:check`.

## 4. Documents in this PR (Decision Governance §3)

`docs/KEY_STRATEGY.md` §4.6 (new `binding_epoch` purpose and the `_p` purposes) and a new admission-keys section (contract §7, row 5-b2); `CHANGELOG.md`; `RATE_LIMITER_PACKAGE_REFERENCE.md`; `llms.txt`. No `POLICIES`, `USAGE_GUIDE`, `DECISION_MATRIX`, index or decision record.

## 5. Order of commits

1. this plan; 2. the tagged tests and fixtures (they fail: no implementation yet); 3. the implementation and the reference store; 4. documents; 5. evidence file `5b2/RESULTS.md` with the run at the exact HEAD.
