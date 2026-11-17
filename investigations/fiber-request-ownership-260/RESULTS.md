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

`tests/Integration/Redis/FiberRequestOwnershipRedisTest.php` (14 tests, groups `integration`, `fiber-ownership-260`, `php32`), through `RateLimiterBuilder` → composite runtime → `RedisFullCapabilityStore` → isolated Redis 7.0.15.
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
| 8 | Two runtimes share one store **object** | — | same as 3b: B on the other runtime is held in A's request and lost when A is rejected. With A **saved**, B's effects carry the reading of **B's own** Host clock (T1) although A's runtime holds T0; a detector (B's clock wiring left at T0) fails that test on both widths | at B's return: nothing stored; after A: B's keys stamped T1 | the store object, not the runtime, carries the request; clocks stay per runtime |
| 9 | Runtimes with their own store and clock (separate connections) on the same Redis | independent | B stored when it returns whatever A does later; A's rejection leaves B's keys byte-identical (`DUMP`/`PEXPIRETIME`); own clock | stored on return | control: no shared object, no interaction |

## 5. Classification (scoped to what was demonstrated)

- **Reached:** yes, deterministically, on PHP_INT_SIZE 8 and 4, when **one runtime or one store object serves two calls whose execution can overlap** and an executor suspends (Fiber, or any cooperative scheduler) between Redis commands.
- **Not reached by ordinary deployments:** one call at a time per runtime/store (classic PHP-FPM, CLI workers, one runtime per request or per worker with no suspension inside `execute()`) never overlaps; scenario 9 is the control.
- **Impact when reached:** an independent call answered before its state is saved and lost if another call is rejected (3a–4); the other call's clock reading (5); a backend failure and a recovery signal crossing between calls (6–7). These are correctness and rate-limit-integrity effects, not a data corruption of Redis (every save remains all-or-none).
- **Not examined here** (stated, not claimed): the process-wide `LocalFallbackLimiter` counters, `RateLimiterEngine::$sequentialRecovery` under overlap, and any multi-process behaviour (unchanged by this unit). No performance measurement was run.
- No separate defect outside #260 was found; the effects above are one ownership gap and are tracked by #260.

> **Update (2026-11-18).** The Owner has since **approved direction B** (principle only), recorded as [DEC-041](../../docs/decisions/DEC-041_EXCLUSIVE_REQUEST_OWNERSHIP_AND_EXPLICIT_OVERLAP_REFUSAL.md); the design proposal for B is `EXCLUSIVITY_DESIGN.md` (unapproved details, nothing implemented). The section below is the **historical comparison** that preceded that decision; it is kept unchanged, and the evidence, logs and their attribution above are untouched. The current approved direction is B, and the baseline behaviour still does not enforce it.

## 6. Decision for the Owner (no choice made here)

Two directions are compared. Neither is approved or selected, no exception type or ownership mechanism is chosen, and everything about cost and size below is an **estimate**: no design exists and nothing was measured.

**A. Support overlapping independent calls with isolated ownership.** The state a request needs (held writes and their reads, the failure flag, the Host clock reading, the recovery-signal buffer) belongs to the call, not to the object that serves it.
**B. Enforce and document exclusivity, with an explicit refusal.** A second independent call that starts while another call's request is open on the same store (and, through it, the same runtime graph) is refused with a classified error. B does **not** include "undefined behaviour"; leaving overlap undefined while documenting it would be a separate contract relaxation, not an equivalent option, and is not proposed.

### Which shared objects participate (from the scenarios above)

| Shared object | What overlap breaks (scenario) | Participates in A | Participates in B |
| --- | --- | --- | --- |
| the store object's `requestState` / `requestBackendFailure` | 3a–4, 6, 8 | yes: per-call request state | yes: the place that knows a request is open |
| `RequestScopedClock::$held` (one per runtime graph) | 5 | yes | refusal happens before a second reading is taken |
| `CircuitBreaker` signal buffer (one per runtime graph) | 7 | yes | refusal happens before the buffer is touched |
| the public runtime entries (`limit()` and the other calls) | where an independent call begins | the engine passes a call identity down | marks a call as started; sees only its own runtime graph |
| store capability entries called directly (e.g. `transactRequest()`, `acquireProbeLease()`) | 3c | each needs an owner | each would have to be refused or owned |

### The two unresolved boundaries the proposal must account for

1. **Two runtimes on one store.** Scenario 8 shows A and B on different runtime instances share the request through the store object. A busy marker kept by each runtime graph at its public entries cannot stop B on another runtime from entering the store that belongs to A's request. Under B the guard therefore has to live where the shared state lives (the store), or sharing a store between concurrently used runtimes has to be forbidden and documented as such; which of the two is an open choice.
2. **Legitimate nested calls.** Scenario 2 shows the supported nesting (a store request inside a request of the same call, the engine's recovery step around the pipeline's own request). A store-level blanket "request already open, refuse" would reject it. Something must distinguish a nested call from an independent one. Candidate means, listed and **not** chosen: a call identity or request token the engine passes to the store (needs an interface or capability change); the execution-context identity of the caller (`Fiber::getCurrent()` covers Fibers only, not other cooperative schedulers or coroutine libraries, and would need a Host-supplied provider); or a handle returned by the store's outer `transactRequest()` that nested code must use. Each changes a public or internal contract differently.

### What each direction would touch (estimates)

| | A | B |
| --- | --- | --- |
| Contract | `RequestAtomicStoreInterface` has to define "request" for overlapping callers and keep the nested rule | the contract states one request at a time per store object and what happens otherwise |
| API / exceptions | a call identity or context provider has to reach the store: a signature or capability change (shape open); no new exception is implied | a classified refusal is needed: a new exception type or an existing one is an open choice; the shape of the refusal for direct store calls is open |
| Store shared by runtimes | supportable once state is per call | refused or forbidden (boundary 1) |
| Host lifetime | one runtime graph can serve overlapping calls | one runtime graph and one connection per concurrent context |
| Cost | per-call bookkeeping (clock sample, signal buffer, request map) and a lookup per store command, size unmeasured | a guard check per entry, size unmeasured; the nested-call distinction (boundary 2) carries most of the design work |
| Size of change | larger: store, clock, breaker, engine, DEC-037 wording (estimate) | smaller in the engine, but the store-level distinction is the same problem as in A (estimate) |

Either way a remediation must prove that an unrelated call can neither consume another call's held state nor produce a saved result before the correct save boundary, and must keep scenario 2. No ACTIVE decision picks between A and B; DEC-037 item 7 describes nesting "inside the request" and is silent on overlap. Both need Owner disposition; no pin, workflow or `src/` change was made.

## 7. Evidence

`run.sh` (this folder) runs the governance helper (on an isolated temporary clone) and the suite on actual PHP_INT_SIZE 8 and 4 (`maatify-rl-php64:local` sha256:d4f5a573…, `maatify-rl-php32:local` sha256:e7016865…, ext-redis 6.3.0, PHP 8.4.27, isolated `redis:7.0.15-alpine` sha256:75510523…). Source identification is made of required, tracked steps (HEAD and tree, a clean working tree with the status command's own failure kept, an isolated `mktemp` clone, checkout, the clone's identity equal to the tested source); each keeps its output and exit status, any failure of one of them ends the runner with exit 1 even when both suites pass, and `source.txt` is written **only** when the source was identified and proven to be the committed source (an empty file otherwise).

Controls, each run on the verification commit with its runner exit code and logs retained:

| Control | Directory | Suites | Runner | `source.txt` |
| --- | --- | --- | --- | --- |
| normal, clean committed source | `raw/` | pass (14 tests, 751 assertions) | **0** | the verification commit and tree |
| missing test path | `raw-failure-demo/` | exit 2 | **1** | recorded for the source, suites not run |
| source identification fails (`rev-parse HEAD 'HEAD^{tree}'` exits 71) | `raw-identity-failure-demo/` | pass | **1** | empty |
| `git status` fails (exit 71, a message on stderr, no output) | `raw-status-failure-demo/` | pass | **1**; `working-tree-clean` is 71 and its log keeps the message | empty |
| an uncommitted change to a tracked file | `raw-dirty-source-demo/` | pass | **1**; `working-tree-clean` is 1 | empty: a dirty tree is not reported as verified committed source |

`provenance-failure-control.sh <identity|status|dirty> <output-dir>` produces the last three. The dirty control protects work that predates it: it **refuses** (exit 3, before any change and before anything is installed) when its target file already differs from the commit in the worktree or the index, and otherwise saves the file's own bytes first and puts exactly those bytes back; it never uses a checkout. `preservation-check.sh <output-dir>` proves this in a disposable clone with synthetic edits (`raw-preservation-check/`): a pre-existing unstaged edit and a pre-existing staged edit are refused with bytes, worktree diff and index unchanged, and an initially clean target is restored byte-for-byte after the control ran to its end (runner exit 1).
Provenance of the committed evidence: the commit that carries the logs changes only the investigation evidence (the logs and the verification paragraph of this report); tests, runner and production source are unchanged from the verification commit named below, and production source is untouched since the baseline.

Verification commit of this run: `a9abb398b968192f5c7a2364be7d1948238372b7` (`raw/source.txt`); 14 tests, 751 assertions, OK on PHP_INT_SIZE 8 and 4, no skips. Clock-wiring detector (B's runtime clock left at T0): 1 failure of 14 on both widths (`raw/mutant-clock-wiring.log`, from the earlier verification commit; the test is unchanged).

Verification commit of this run: `705651cf347b934fb7a13d6bd7372312cd1f7d30` (`raw/source.txt`, tree `a026750fa0ce10a97c1785b5a092efd8aa629026`); 14 tests, 751 assertions, OK on PHP_INT_SIZE 8 and 4, no skips; the five controls and `preservation-check.sh` (`raw-preservation-check/`: unstaged pre-existing edit refused, staged pre-existing edit refused, initially clean target restored byte-for-byte; all three 0) ran on it. The clock-wiring detector log (`raw/mutant-clock-wiring.log`) is from an earlier verification commit; the test is unchanged. One earlier attempt at this run was discarded because the local `maatify-rl-php64:local` image had disappeared from Docker (`docker: Error response from daemon: pull access denied`, exit 125 in the helper and the PHP64 suite); the image was rebuilt from `docker/php32/Dockerfile` for `linux/arm64` and came back with the same id (sha256:d4f5a573…), and every control was run again.
