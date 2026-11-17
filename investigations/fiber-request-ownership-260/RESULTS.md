# #260 — request ownership when independent calls overlap (Fibers)

Baseline `main@316b1de99e784fec95b40f849d83a04dec772e2b`, tree `fe75547412fcb21b695644b21b86b387fc26bd31` (matched with GitHub; the investigation changes no `src/`, no DTO, no behaviour and no contract).
Status of this report: **investigation and proposal. Nothing here is a fix, and nothing is decided.** A choice between the two directions in section 6 belongs to the Owner.

## 1. Governance

`AGENTS.md` and Adoption §10.1 were followed with the pinned Manifest (`ebcf55b0591db61d18a1407bc3effef0197b388f`, not refreshed). The deterministic helper
`.agents/helpers/maatify-governance-context.php` (sha-256 `96ed5625…`, as pinned) ran in Docker on a real clone of the baseline: exit 0, `governance_mode` `selective-adoption`,
`errors: []`, 30 indexed ACTIVE decisions (`raw/governance-helper.log`). Decision Preflight read DEC-010, DEC-015, DEC-016, DEC-036 and DEC-037 and the interfaces below.

## 2. What is written, what is measured, what is assumed

| Topic | Written contract (source) | Measured here | Not documented / assumed |
| --- | --- | --- | --- |
| A `transactRequest()` made while one of the store's own requests is being evaluated | "runs `$evaluate` inside that request and does not start another save" (`RequestAtomicStoreInterface`, last guarantee) | holds for a call nested **by the same request**; the store cannot tell a nested call from an independent one: any call on the same object while a request is open joins it | nothing says whether an independent call from another execution context may do so |
| Repeatable evaluation, no partial save, no save after an error, bounded recalculation | `RequestAtomicStoreInterface` (all items) | holds for sequential calls (controls) | the guarantees are stated per request; "request" is not defined for overlapping callers |
| Who owns the connection and its lifecycle | "The Host owns the Redis client and connection lifecycle" (`RedisCommandExecutorInterface`) | the harness gives each store its own connection and suspends only between whole commands, so connection multiplexing is excluded | whether an executor may suspend inside `execute()` while another call uses the same runtime: **not stated** |
| One Host clock reading per runtime call; a nested call keeps it | `RequestScopedClock` doc, LT-P2-05, DEC-016 | nested call keeps the outer reading (control) | the reading is one field on the clock object: a call that starts while another holds it shares it |
| Signals of a recovery step are held until the request is saved | DEC-037 item 7 | held per `CircuitBreaker` object, one buffer | one buffer per breaker object; ownership by call not defined |
| Instance sharing, lifetime in a Host, Fibers, coroutines, async runtimes | no mention in `docs/`, `README.md`, `RATE_LIMITER_PACKAGE_REFERENCE.md`, the usage guide, FAILURE_SEMANTICS or any ACTIVE decision (searched for Fiber, coroutine, Swoole, RoadRunner, async, re-entrant, thread-safe, one instance, shared) | — | **the contract is silent**; support and prohibition are both unproven |

Nothing here infers support or prohibition from the presence of Fibers or from silence.

## 3. Harness

`tests/Integration/Redis/FiberRequestOwnershipRedisTest.php` (13 tests, groups `integration`, `fiber-ownership-260`, `php32`), through `RateLimiterBuilder` → composite runtime → `RedisFullCapabilityStore` → isolated Redis 7.0.15.
A and B are independent public calls with separate inputs (`acct-a`, `acct-b`). A `Fiber` is suspended only **between** Redis commands, after the first held evaluation script of its request, and resumed on an explicit schedule; nothing sleeps.
Every store owns its connection; the oracle reads over another one with exact `DUMP` and `PEXPIRETIME` (no native wide-integer reply is used as an oracle). Fixed Host clocks with values that fit both widths pin each call's sample.
The tests are **characterizations of the baseline**: they assert what the code does today so that the Owner's decision has an exact before-state and the assertions flip deliberately.

## 4. Scenarios

Shared objects: runtime graph (engine, pipeline, `RequestScopedClock`, `CircuitBreaker`) and the store object with its `requestState`/`requestBackendFailure`. Resume order: A suspended first, then B runs, then A resumes (unless stated).

| # | Scenario | Contract expectation (source) | Observed | State when B returns / after A ends | Reach and impact |
| --- | --- | --- | --- | --- | --- |
| 1 | Sequential calls, same runtime | each call saved before it returns; a later rejection does not touch earlier results (`RequestAtomicStoreInterface`) | as expected | B stored on return; a later rejected call leaves the snapshot byte-identical | control |
| 2 | Nested request inside one request | one save; outer clock reading kept (store interface, LT-P2-05) | as expected: 1 commit, both writes saved together; the nested call reads the outer sample | — | control (the supported nesting) |
| 3a | Independent B while A holds its request, A then **saved** | an independent call is saved before it returns (implied by "each request is saved"; not stated for overlap) | B answered `ALLOW` with **nothing stored** (snapshot identical to before); A's one commit later carried B's effects | at B's return: no key; after A: B's keys present | reachable only if a Host suspends an executor mid-request on a shared runtime; impact: an answer given before its save |
| 3b | same, A then **rejected** (classified error) | same | B answered `ALLOW`; **B's recorded failure was never stored** (snapshot identical to the empty before-state; the keys the sequential control stores are absent) | at B's return: no key; after A: no key | same prerequisite; impact: a reported/recorded failure silently lost (rate-limit evasion by overlap) |
| 3c | Store level: independent `acquireProbeLease()` while A holds a request, then A rejected | a lease reported as acquired is stored | B told `true`, nothing in Redis, still nothing after A's rejection | — | narrowest form of 3b |
| 4 | Both calls suspended inside one request | each call saved by its own request | both answer `ALLOW`. **Opener resumes first:** A's save carries what is held at that moment, B resumes afterwards and part of what its answer promised is never stored. **Joiner resumes first:** B returns with nothing saved; A's one save later stores everything | opener first: stored set is a strict subset of the sequential control; joiner first: stored set equals the control | same prerequisite; impact depends on resume order (outside the Host's control) |
| 5 | Host clock advanced between A's start and B's start | one reading per runtime call (LT-P2-05) | B stamped with **A's reading** (`updatedAt` = A's second) although the Host clock had moved on; with its own runtime B is stamped with its own reading | stored stamps | impact: wrong-time decay, windows and expiries for B (bounded by how long A stays open) |
| 6 | B meets a backend failure while A holds the request | a failure belongs to the call that met it (DEC-015) | B answered `HARD_BLOCK / FAIL_CLOSED` (its own failure); on resuming, **A, which had no failure, is also answered `HARD_BLOCK / FAIL_CLOSED`** and nothing of A is stored | `requestBackendFailure` is one field on the store | impact: an innocent call refused by another call's failure |
| 7 | Recovering circuit, B returns while A holds its recovery step (A then saved, and A then rejected) | recovery signals are sent only after the right save; a rejected request announces nothing (DEC-037 item 7) | **`CB_RECOVERED` was emitted when B returned**, while Redis still showed `HALF_OPEN`; in both endings it was emitted once and never again; when A was rejected the circuit was never closed | emitted before any save | impact: a signal for a transition that is not stored |
| 8 | Two runtimes share one store **object** | — | same as 3b: B on the other runtime is held in A's request and lost when A is rejected, but B keeps its own clock (`updatedAt` = its own reading) | — | the store object, not the runtime, carries the request |
| 9 | Runtimes with their own store and clock (separate connections) on the same Redis | independent | B stored when it returns whatever A does later; A's rejection leaves B's keys byte-identical (`DUMP`/`PEXPIRETIME`); own clock | stored on return | control: no shared object, no interaction |

## 5. Classification (scoped to what was demonstrated)

- **Reached:** yes, deterministically, on PHP_INT_SIZE 8 and 4, when **one runtime or one store object serves two calls whose execution can overlap** and an executor suspends (Fiber, or any cooperative scheduler) between Redis commands.
- **Not reached by ordinary deployments:** one call at a time per runtime/store (classic PHP-FPM, CLI workers, one runtime per request or per worker with no suspension inside `execute()`) never overlaps; scenario 9 is the control.
- **Impact when reached:** an independent call answered before its state is saved and lost if another call is rejected (3a–4); the other call's clock reading (5); a backend failure and a recovery signal crossing between calls (6–7). These are correctness and rate-limit-integrity effects, not a data corruption of Redis (every save remains all-or-none).
- **Not examined here** (stated, not claimed): the process-wide `LocalFallbackLimiter` counters, `RateLimiterEngine::$sequentialRecovery` under overlap, and any multi-process behaviour (unchanged by this unit). No performance measurement was run.
- No separate defect outside #260 was found; the effects above are one ownership gap and are tracked by #260.

## 6. Decision for the Owner (no choice made here)

| | A. Support overlapping independent calls with per-request state | B. Enforce and document instance exclusivity |
| --- | --- | --- |
| Idea | the request state, clock reading, signal buffer and failure flag belong to the **call**, not the object; a nested call is recognised by the engine passing its own request token | a runtime/store serves one call at a time; a second independent call while one is open is refused (or, documented, undefined) |
| Contract | `RequestAtomicStoreInterface` gains a definition of "request" for overlapping callers; the nested rule stays | the contract states "one runtime/store per concurrent execution context"; the guard needs no new knowledge of Fibers: the public entry points mark themselves busy and engine-internal calls do not pass through them |
| API / exceptions | a request token or context identity must reach the store (new parameter or a context provider; Fiber identity alone does not cover other schedulers); no new exception | a new classified exception (or an existing `RateLimiterException`) for an overlap; no signature change if the guard sits at the public entries |
| Sharing a store between runtimes | becomes supported (state per call) | stays unsupported; two runtimes must not share a store object concurrently |
| Host lifetime / cost | more state per call (clock sample, signal buffer, request map), a lookup per store command; connections still per context | one runtime graph and one connection per concurrent context; no per-call bookkeeping |
| Compatibility | sequential Hosts unaffected; behaviour of overlapping Hosts changes from lost/misdirected to correct | sequential Hosts unaffected; overlapping Hosts get an explicit refusal instead of silent loss |
| Risk | larger change in the store, clock, circuit breaker and engine (several files, DEC-037 wording) | small; does not make overlap work |

Either way a remediation must prove that an unrelated call can neither consume another call's held state nor produce a saved result before the correct save boundary, and must keep the nested behaviour of scenario 2. No existing ACTIVE decision picks between A and B; DEC-037 item 7 describes nesting "inside the request" and is silent on overlap. Both choices need Owner disposition; no pin, workflow or `src/` change was made.

## 7. Evidence

`run.sh` (this folder) runs the governance helper and the suite on actual PHP_INT_SIZE 8 and 4 (`maatify-rl-php64:local` sha256:d4f5a573…, `maatify-rl-php32:local` sha256:e7016865…, ext-redis 6.3.0, PHP 8.4.27, isolated `redis:7.0.15-alpine` sha256:75510523…), keeps every output under `raw/`, and exits 1 if a required step failed.
`raw/result.txt` lists each step's exit status; `raw-failure-demo/` is the same runner pointed at a missing test file (exit 1, outputs kept). Results are in `raw/`. PHPStan max, cs-fixer and whitespace are clean for the new files.

Run on the committed source named in `raw/source.txt` (the verification commit; the commit that follows adds only these logs): governance helper exit 0, suite on PHP_INT_SIZE 8: 13 tests, 700 assertions, OK; on PHP_INT_SIZE 4: 13 tests, 700 assertions, OK; no skips.
