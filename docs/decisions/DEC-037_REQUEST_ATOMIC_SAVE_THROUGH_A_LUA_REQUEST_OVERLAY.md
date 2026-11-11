# DEC-037 — Request-Atomic Save Through a Lua Request Overlay

## Decision ID

`DEC-037`

## Status

`PROPOSED` — recorded 2026-11-12 in [PR #205](https://github.com/Maatify/php-rate-limiter/pull/205) (T-5, #185) for the Owner's decision. It changes the mechanism of the T-5 design that was approved earlier (the calculation in PHP and the conditional save in Lua); the Lead and the Owner have not accepted it yet.

## Date

Proposed 2026-11-12.

## Decision Authority

**Owner**, on the Lead's review of PR #205. The earlier T-5 design (PHP computes the changes in evaluation order, Lua compares the state it was built on and saves in one batch) was set by the Lead; this record asks for the Owner's decision on a different mechanism that keeps its contract.

## Scope / Concern

How one `EvaluationPipeline::process()` request (the whole `checkOnly` evaluation and the failure/success commands that run through the same pipeline) reaches Redis: which writes it may make before its decision is final, and what it does when another request changed state it read.

## Context

- **Measured on `942d7cf` (the T-5 group commits of observations).** A `checkOnly` request of a new account, rejected by a classified validation failure after its device-cap observation had been prepared, left 4 Redis keys changed. The write inventory of one such request has six independent commit points with six different Lua scripts: the device-cap pair, the bounded snapshot, the spray observation, the churn observation with its watch increment, the policy writes (scores, blocks, budgets, lifecycle), and the anti-equilibrium and flood watches. A group commit covers only the observations, so the classified failure of a later step still follows committed writes.
- **Why not move every script to PHP.** Planning each write script in PHP (score increments with decay credit, K4 lifecycle and generation state, hard-block cycle history, budget seeding across rotation, expired-record cleanup) re-implements about fifteen scripts of production semantics in a second language; the two would have to be kept equal for ever.
- **What Redis cannot do.** A Lua script that fails after its first write is not rolled back, so atomicity cannot come from "run the writes in one script and hope they pass".

## Decision

1. **The store offers `RequestAtomicStoreInterface::transactRequest(callable $evaluate)`.** `RedisFullCapabilityStore` implements it. `EvaluationPipeline::process()` runs its evaluation through it when `store` is that interface and `correlationStore` is the same object; any other store keeps the sequential writes.
2. **A request overlay holds every write back.** Inside `transactRequest()` each store script runs wrapped in the Lua request overlay (`RedisRequestOverlay`). `redis.call` / `redis.pcall` are replaced by an implementation that reads the live state of a key once, keeps every later read and write in the overlay, and never issues a write command. Every step therefore sees the results of the earlier steps, including key rotation, expired-record cleanup and the writes of the policy decision. The script text is the production script, unchanged.
3. **Nothing unsupported can write.** The overlay implements the commands the store scripts use (strings, hashes, sets, sorted sets, expiry and `DUMP`/`PEXPIRETIME` for the state fingerprints). A command it does not implement, or a key not declared in `KEYS`, is a classified error raised inside the overlay, before any write. A new store script that needs another command must extend the overlay; `RequestAtomicityRedisTest` rejects an unsupported command.
4. **One commit script saves the request.** After the evaluation returns, one script compares every key the request read with the fingerprint (type, content, expiry) it was read in. Only if all of them match, it validates the whole plan (types, values, expiry bounds) and only then applies every change. The comparison and the validation both finish before the first write, because a script error after a write has started cannot be undone.
5. **A conflict recalculates.** If any fingerprint differs the commit writes nothing and returns, and the request is evaluated again from a fresh read. An error raised by a step over state that is no longer current is treated the same way (a step that read keys at different moments can see a combination no single moment held); an error over current state is the request's answer and reaches the caller with nothing saved. A backend failure is never retried here.
6. **Bounded retries.** 32 evaluations at most, with a random pause growing to 16 ms between them; then `RateLimiterException`. Measured on an isolated Redis with forked workers on one hot account/IP: up to 256 simultaneous writers, 0 failed requests; a single burst of 256 writers had a worst latency of about 3.5 s. The bound and the pause are the package's constants, not configuration.
7. **Scope of "one request".** The save covers everything `process()` reads and writes through the store. It does **not** cover:
   - the circuit breaker (`CircuitBreaker`): it writes only when the circuit is not closed (the recovery probe lease, the state on a probe's success) or after a backend failure, in separate operations, and it runs before and after `process()`;
   - the admission layer (`AdmissionRuntime`, DEC-024/DEC-036): for a policy with an open admission fence, the outcome hooks run before `process()` and the reservation after an allowed `checkOnly`, each as its own atomic operation;
   - `claimPostPunishmentReentry()`, which is one script of its own.
   The "no write before a classified failure" guarantee therefore holds for `process()` itself, and for a whole `checkOnly` request of a policy that is outside the admission fence with a closed circuit.
8. **The T-5 group commits stay as store operations.** `commitBoundedObservationGroup()`, `commitDeviceCapPlans()` and their plan readers keep their contracts for a direct caller; inside an overlay they run against it like any other script.

## Rationale

- **One mechanism for every write** instead of six, and no second implementation of the write scripts.
- **A rejection is free:** a held-back evaluation that fails writes nothing, so no classified check has to be ordered "before the first write" by hand.
- **Fenced writes still protect direct callers:** DEC-030 and DEC-031 stay as they are for any use of the store outside `transactRequest()`.

## Consequences

- **Behaviour under concurrency.** Two requests that touch the same keys are serialised by the commit: the later one is calculated again after the earlier one and may return a different decision than it would have computed on the stale state (for example the second failure of a block race now returns the hard block). The set of decisions and the final state equal one sequential order of the requests. The block-race, score-race and marker-race suites assert exactly that.
- **No partial prefix.** A request interrupted by a lost reply leaves no part of its writes (the admission interruption suite).
- **Cost.** Each store call of a request carries the overlay script (about 12 KB, sent with every `EVAL`) and the held key states as an argument; the commit reads each key it read once more. A request that changes nothing sends no commit.
- **Redis.** Redis 7.0 or newer (`PEXPIRETIME`, `TIME` before a write in a script); the CI service image is `redis:7.0.15`.
- **A Host store** that wants the same guarantee implements `RequestAtomicStoreInterface`; one that does not keeps the sequential writes and the fenced writes of DEC-030/DEC-031.
- **Tests that read the wire.** A test that recognises a store script by its text or position now finds it wrapped; `OverlayWire` in the test support does that matching.

## Relationship to Other Decisions

- **DEC-029** (no concurrency exception after a committed write): unchanged; with one save per request a conflict before the save raises nothing and recalculates.
- **DEC-030 / DEC-031:** unchanged for direct store use.
- **DEC-024 / DEC-036:** the reservation and the outcome claim stay separate atomic operations (item 7).
- **DEC-017:** the caller's semantic time still decides logical expiry; key expiry still uses the Redis clock, applied as an absolute time at the save.

## Canonical Contract / Current Owner

`src/Repository/RequestAtomicStoreInterface.php` defines the capability. `src/Repository/Redis/RedisRequestOverlay.php` owns the overlay and commit scripts, `src/Repository/Redis/RedisFullCapabilityStore.php` owns `transactRequest()`, and `src/Service/EvaluationPipeline.php` owns the choice to run a request through it.

## Supersedes

None. It changes the mechanism of the T-5 design approved earlier on #185 (PHP calculation, Lua conditional save), not a recorded decision.

## Superseded By

None.
