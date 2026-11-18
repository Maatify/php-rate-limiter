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

## Implementation evidence (DEC-041 approved M1–M7)

Verification head: `e4f6ae70989938cd6f4ded37e202be5fc9265219` (tree `7baefc4f2f998954768a9aeec5b2dcd179809ebb`); baseline `main@316b1de99e784fec95b40f849d83a04dec772e2b`. Raw outputs: `raw-final/`, `raw-mutants-final/`, `raw-bench-final/`. Docker, isolated Redis 7.0.15, PHP 8.4.27, actual PHP_INT_SIZE 8 and 4 (32-bit under linux/386 emulation). Coverage of requirements: `COVERAGE.md`.

| Run | Tests | Result |
|---|---|---|
| Baseline source + new tests, FiberRequestOwnership (24), 64 and 32 | 24 | 21 failures on both widths (red) |
| Baseline source + provider and registry tests (29), 64 and 32 | 29 | 27 errors, 1 failure on both widths (red) |
| Candidate full suite, 64 | 2858 | 100 errors, 4 failures, 14 skipped: ReleaseVerification classes only |
| Baseline full suite, 64 | 2805 | 100 errors, 4 failures, 14 skipped: the same classes |
| Candidate full suite, 32 | 2853 | 101 errors, 4 failures, 65 skipped: ReleaseVerification classes only |
| Baseline full suite, 32 | 2800 | 101 errors, 4 failures, 65 skipped: the same classes |
| Candidate `--group php32`, 32 | 862 | OK, 64 skipped |
| Mutants M01–M12, both widths | | 12 of 12 detected |

Limits of this evidence: the Docker image has no `git`, so the ReleaseVerification tests (git repository and 2999-year date cases) cannot run locally; they fail identically on the baseline and are not counted as passing. Lowest-dependency install and lowest PHPStan are verified by CI only. Policy callbacks are not crossing-marked (deviation from M7, reported to the Lead).

### Cost (committed source, alternating baseline/candidate, 5 rounds of 3000 iterations after 300 warm-up, one Redis, controls noop and ping; no acceptance threshold applied)

| width | workload | tree | rounds | n/round | p50 µs (median of rounds, min–max) | p99 µs (median, min–max) |
|---|---|---|---|---|---|---|
| 32 | direct | baseline | 5 | 3000 | 152.88 (149.71–157.46) | 494.92 (394–563.13) |
| 32 | direct | candidate | 5 | 3000 | 235.38 (230.79–237.63) | 672.88 (610.38–859.46) |
| 32 | limit | baseline | 5 | 3000 | 2160.38 (2154.75–3023.79) | 10732.63 (10606.54–15009.21) |
| 32 | limit | candidate | 5 | 3000 | 2606.79 (2596.13–2615.42) | 12523 (12064.17–13053.88) |
| 32 | nested | baseline | 5 | 3000 | 627.38 (625.08–632.54) | 1516.21 (1416.21–1781.13) |
| 32 | nested | candidate | 5 | 3000 | 770.25 (767.54–785.54) | 1639.58 (1603.13–2402.58) |
| 32 | noop | baseline | 5 | 3000 | 1.79 (1.79–1.88) | 2 (1.96–6.96) |
| 32 | noop | candidate | 5 | 3000 | 1.83 (1.79–1.83) | 2 (2–2.08) |
| 32 | ping | baseline | 5 | 3000 | 76.92 (76.13–77.04) | 249.96 (107.58–356.29) |
| 32 | ping | candidate | 5 | 3000 | 76.58 (76.38–90.58) | 259.38 (137.63–441.67) |
| 64 | direct | baseline | 5 | 3000 | 76.96 (76.13–78.04) | 219.25 (149.58–351.96) |
| 64 | direct | candidate | 5 | 3000 | 83.08 (80.5–83.25) | 141.63 (123.88–232.79) |
| 64 | limit | baseline | 5 | 3000 | 901.71 (898.25–903.42) | 4486.67 (4406.67–4752.08) |
| 64 | limit | candidate | 5 | 3000 | 934.13 (930.63–935.33) | 4649.54 (4582.63–4818.5) |
| 64 | nested | baseline | 5 | 3000 | 293 (287.29–295.92) | 701.13 (537.83–850.29) |
| 64 | nested | candidate | 5 | 3000 | 297.96 (290.13–305.58) | 758.5 (644.17–939.38) |
| 64 | noop | baseline | 5 | 3000 | 0.04 (0.04–0.04) | 0.08 (0.04–0.08) |
| 64 | noop | candidate | 5 | 3000 | 0.04 (0.04–0.04) | 0.08 (0.08–0.54) |
| 64 | ping | baseline | 5 | 3000 | 52.75 (26.21–52.79) | 77.08 (75.13–163.63) |
| 64 | ping | candidate | 5 | 3000 | 52.79 (52.29–52.96) | 147.04 (76.25–235.21) |

Reading: the no-op and Redis round-trip controls are equal between trees, so the differences are in the library path. The 32-bit run is emulated, with noisier absolute values. The numbers are measurements, not a verdict.

## Review corrections of `c9eb4dd5` (F266-10..14): evidence on the corrected head

Corrected head: `9568553ff8d0b2a6cae4e8692f99416cf9b336f3` (tree `f888df6585c36d4523904797a62b4a9f6e5c3067`); reviewed head `c9eb4dd5701c233e1ba278e4eee274e872b3d92d`; baseline `main@316b1de99e784fec95b40f849d83a04dec772e2b`. Raw outputs: `raw-final2/`, `raw-mutants-final2/`, `raw-bench-final2/`, `raw-bench-vs-reviewed-final2/`. The section above ("Implementation evidence") belongs to `e4f6ae70`/`c9eb4dd5` and is kept as history; its figures are not the evidence of this head. Docker, isolated Redis 7.0.15, PHP 8.4.27, actual PHP_INT_SIZE 8 and 4 (32-bit under linux/386 emulation).

| Run | Result |
|---|---|
| Regressions (the 9 crossing tests, the 4 Host-store tests, the 25 registry tests = 38) on the **reviewed source `c9eb4dd5`**, 64 and 32 | 16 failures on both widths (red): policy getters, store clock before the guard, readers' clock and resolver, failed reader build, method-name inventory, Host store that is also a clock |
| The same tests on the corrected head | pass on both widths |
| Candidate full suite, 64 / 32 | 2874 tests (100 errors, 4 failures, 14 skipped) / 2869 tests (101 errors, 4 failures, 65 skipped): ReleaseVerification classes only (no `git` in the image); the one extra 32-bit error is the year-2999 date fixture, independent and tracked as #267 |
| `--group php32`, 32 / 64 | 878 tests OK (64 skipped) / 879 tests OK (13 skipped) |
| Mutants M01–M22, both widths | 22 of 22 detected (M13–M22 are the controls of these corrections; M14 was first undetected, which led to the pipeline-getter test) |
| PHPStan max, formatter | clean on the current toolchain; lowest-supported PHPStan and lowest dependencies by CI only |

One regression of the correction itself was found by the full suite and fixed before delivery: existing mutation tests (`FamilyRpG5Test`, `AdmissionEngineSourceCapacityTest`, `AdmissionNm10BindingTest`) anchor on exact source text of the policy getter calls; their anchors follow the marked calls. The anchored suites (815 tests) pass on 64-bit.

### Cost of the corrected source (committed source, alternating, 5 rounds of 3000 iterations after 300 warm-up, one Redis; no threshold)

Against `main@316b1de9`:

| width | workload | tree | p50 µs median (min–max) | p99 µs median (min–max) |
|---|---|---|---|---|
| 32 | direct | baseline | 160.58 (157.75–194.67) | 686.08 (499.08–1465.54) |
| 32 | direct | candidate | 245.42 (240.63–264) | 1021.33 (630.54–1550.83) |
| 32 | limit | baseline | 2389 (2288.79–2601.5) | 20057 (11805.54–27507.63) |
| 32 | limit | candidate | 2959.75 (2858.08–3592.5) | 19527.54 (14400–27860.08) |
| 32 | nested | baseline | 669.67 (652.38–699.42) | 2066.63 (1670.67–4215.79) |
| 32 | nested | candidate | 832.5 (821.04–838.29) | 2436.42 (2046.75–3209.54) |
| 32 | noop | baseline | 1.88 (1.88–1.88) | 4.17 (2–5.38) |
| 32 | noop | candidate | 1.88 (1.88–1.88) | 2.08 (2.04–4.21) |
| 32 | ping | baseline | 78.25 (77.42–104.83) | 425.08 (216.58–6363.08) |
| 32 | ping | candidate | 77.25 (76.79–97) | 437.33 (170.63–572.33) |
| 64 | direct | baseline | 78.13 (77.46–78.54) | 359.29 (179.83–441.25) |
| 64 | direct | candidate | 84.04 (83.38–84.58) | 194.29 (136.5–295.63) |
| 64 | limit | baseline | 946.08 (939.33–950.5) | 4866.58 (4582.08–5525.71) |
| 64 | limit | candidate | 988.96 (979.79–1024.75) | 5234.29 (5055.21–5718.17) |
| 64 | nested | baseline | 293.67 (278.96–297.08) | 567.33 (544.54–781.46) |
| 64 | nested | candidate | 300.08 (294.67–310.83) | 716.13 (564.08–806.21) |
| 64 | noop | baseline | 0.04 (0.04–0.08) | 0.08 (0.08–0.13) |
| 64 | noop | candidate | 0.04 (0.04–0.08) | 0.08 (0.08–0.13) |
| 64 | ping | baseline | 52.92 (52.54–53.46) | 231.83 (164.42–311.13) |
| 64 | ping | candidate | 53 (52.29–53.63) | 297.08 (114.5–484.33) |

Against `c9eb4dd5` (the cost of this correction alone):

| width | workload | tree | p50 µs median (min–max) | p99 µs median (min–max) |
|---|---|---|---|---|
| 32 | direct | baseline | 270.5 (250.67–317.5) | 1235.63 (913.38–1398.5) |
| 32 | direct | candidate | 267.46 (246.17–281.17) | 1151.08 (1045.58–1467.96) |
| 32 | limit | baseline | 3180 (3167.25–3262.75) | 16343.96 (15948.96–18951.67) |
| 32 | limit | candidate | 3282.13 (2907.25–3434.54) | 18672.21 (15790.71–24232.71) |
| 32 | nested | baseline | 896.08 (860.04–995.33) | 2947.79 (2225.5–3034.92) |
| 32 | nested | candidate | 894.17 (824.63–1024.46) | 2793.71 (2623.33–2915.21) |
| 32 | noop | baseline | 1.88 (1.88–1.88) | 10.29 (3.21–16.08) |
| 32 | noop | candidate | 1.88 (1.88–1.88) | 2.08 (2–13.54) |
| 32 | ping | baseline | 95.71 (93.21–103.54) | 532.79 (297.46–638.46) |
| 32 | ping | candidate | 77.38 (76.83–98.58) | 415.58 (162.38–553.17) |
| 64 | direct | baseline | 85.04 (83.38–88.63) | 223.79 (181.17–423.79) |
| 64 | direct | candidate | 83.96 (83.67–85.04) | 295.54 (240.04–357.88) |
| 64 | limit | baseline | 1013.83 (969.21–1147.63) | 5356 (4886.17–6352.21) |
| 64 | limit | candidate | 1003.92 (986.29–1142.17) | 5952.96 (4885.13–7244.54) |
| 64 | nested | baseline | 296.13 (270.5–299.79) | 544.54 (541.63–886.08) |
| 64 | nested | candidate | 291.46 (243.04–305.08) | 564.29 (488.38–679.63) |
| 64 | noop | baseline | 0.04 (0.04–0.08) | 0.08 (0.08–0.67) |
| 64 | noop | candidate | 0.04 (0.04–0.08) | 0.08 (0.08–0.13) |
| 64 | ping | baseline | 52.63 (51.58–53.08) | 160.75 (125–180.67) |
| 64 | ping | candidate | 52.46 (14.58–53.17) | 164.54 (146.63–256.54) |

Reading: on 64-bit the p50 of the affected paths moves by about 2 to 8 percent against `main` (direct 78.13→84.04 µs, nested 293.67→300.08, limit 946.08→988.96) and by no more than noise against `c9eb4dd5`. The `ping` control is a raw Redis round trip that this change does not touch, and its p99 on 64-bit differs between the trees in both directions across runs (231.83→297.08 µs here against 491.04→248.13 in the earlier run of `c9eb4dd5`): tail differences of that size are measurement noise of this machine, not a cost of the guard, and the p99 differences of the library paths are not attributed to the guard. The 32-bit run is emulated; its p50 for `direct` (160.58→245.42 µs) is a measured increase and is reported as such, not explained away. The numbers are measurements, not a verdict.

## Second review of the correction `02d51f24` (F266-15, F266-16): evidence on the corrected head

Corrected source head `7d368531e526313efd8233bb70f1d334a8e0c9c9` (tree `1061a6273aceecd474eea9b8a30ac9ab0a4c0646`); reviewed head `02d51f246703128c504444a041db4ccef65c77f6`; baseline `main@316b1de99e784fec95b40f849d83a04dec772e2b`. Raw outputs: `raw-final3/`, `raw-mutants-final3/`, `raw-bench-final3/`, `raw-bench-vs-reviewed-final3/`. Earlier sections stay as history of their own heads; the cost tables of `9568553f` stay tied to that source and are not the cost of this one. Docker, isolated Redis 7.0.15, PHP 8.4.27, actual PHP_INT_SIZE 8 and 4 (32-bit emulated).

| Run | Result |
|---|---|
| New regressions (the 43 tests of `OwnershipCrossingsRedisTest` and `OwnershipRegistryTest`) on the **reviewed source `02d51f24`**, 64 and 32 | 5 red on both widths: a failed `build()` and a failed `buildOperationalReader()` that unbind a runtime built meanwhile (F266-15), the hand-assembled engine whose raw clock reaches the executor (F266-16), the counted-rollback unit and the `HostClock` unit |
| The same tests on the corrected head | pass on both widths |
| Candidate full suite 64 / 32 | 2879 tests (100 errors, 4 failures, 14 skipped) / 2874 tests (101 errors, 4 failures, 65 skipped): ReleaseVerification classes only; the extra 32-bit error is the year-2999 fixture (#267) |
| `--group php32`, 32 / 64 | 883 tests OK (64 skipped) / 884 tests OK (13 skipped) |
| Mutants M01–M25, both widths | 25 of 25 detected (M23–M25 are the controls of these corrections) |
| Runner (provenance, governance helper, clean clone, php64/php32 suites) | all steps exit 0 |

Mechanism of F266-15: a binding is counted. Every `bindAll` that records or relies on a binding takes a hold; a rollback releases its holds and undoes a binding only when no hold remains, and a binding whose binder rolled back first is undone by the last one standing if that one rolls back too. `adopt()` of an existing binding takes no hold. No public method, interface or constructor parameter was added. Mechanism of F266-16: `HostClock` (internal) wraps a raw clock one-to-one in the package classes that read a clock inside a call; the request clock and an already wrapped clock are never wrapped.

### Cost of the corrected source (committed, alternating, 5 rounds of 3000 iterations after 300 warm-up; no threshold)

Against `main@316b1de9`:

| width | workload | tree | p50 µs median (min–max) | p99 µs median (min–max) |
| 32 | direct | baseline | 149.96 (149–153.17) | 298.46 (272.42–387.33) |
| 32 | direct | candidate | 230.71 (229.5–231.63) | 407.08 (353.21–556.79) |
| 32 | limit | baseline | 2206.25 (2195.04–2209.88) | 11884.38 (11741.79–12534.38) |
| 32 | limit | candidate | 2726.21 (2694.38–2733.46) | 13134.21 (12968.17–14212.92) |
| 32 | nested | baseline | 642.08 (640.54–643.13) | 1261.46 (1157.58–1695.83) |
| 32 | nested | candidate | 785.08 (781.04–794.83) | 1489.5 (1443.75–1883.38) |
| 32 | ping | baseline | 75.04 (74.96–75.71) | 162.96 (109.08–198.96) |
| 32 | ping | candidate | 75.38 (74.96–75.58) | 140.75 (106.63–181.04) |
| 64 | direct | baseline | 75 (74.71–76.75) | 206.5 (131.5–373.13) |
| 64 | direct | candidate | 80.71 (72.17–82.42) | 172.33 (121.5–255.29) |
| 64 | limit | baseline | 912.33 (899.88–917.54) | 4533.5 (4460.5–5589.29) |
| 64 | limit | candidate | 955.63 (928.17–960) | 4540.29 (4441.42–5296.96) |
| 64 | nested | baseline | 286.25 (277.17–288.38) | 530.38 (504.33–565.04) |
| 64 | nested | candidate | 298.5 (285.38–299.88) | 566.21 (500.33–602.83) |
| 64 | ping | baseline | 52.17 (51.42–52.75) | 182.5 (150.04–253.25) |
| 64 | ping | candidate | 51.96 (25.71–53.75) | 201.42 (104.54–312.67) |

Against `02d51f24` (the cost of this correction alone):

| width | workload | tree | p50 µs median (min–max) | p99 µs median (min–max) |
| 32 | direct | baseline | 228.63 (225.92–230.33) | 527.46 (399.67–556.54) |
| 32 | direct | candidate | 228.63 (225.54–231.71) | 489.88 (358.96–616.17) |
| 32 | limit | baseline | 2907 (2690.5–3295.92) | 16610.04 (14023.88–20046.5) |
| 32 | limit | candidate | 2880.21 (2697.5–3118.08) | 15285.04 (13913.58–18663.38) |
| 32 | nested | baseline | 781.25 (778.63–786) | 1674.13 (1569.63–2283.88) |
| 32 | nested | candidate | 779.46 (778.29–1115.29) | 1722.88 (1574.83–2572.33) |
| 32 | ping | baseline | 75.29 (74.79–75.92) | 115.92 (107.04–288.79) |
| 32 | ping | candidate | 75.29 (55.04–76.79) | 128.21 (112.08–317.38) |
| 64 | direct | baseline | 82 (81–84.38) | 318.71 (133.88–394.75) |
| 64 | direct | candidate | 82.17 (81.42–82.92) | 185.21 (145.96–376.46) |
| 64 | limit | baseline | 948.79 (935.17–951.29) | 4756.13 (4664.29–5139.79) |
| 64 | limit | candidate | 948.21 (940.13–1045.38) | 4914.42 (4554.79–9966.92) |
| 64 | nested | baseline | 299.13 (297.21–302.88) | 606.38 (517.54–1069.29) |
| 64 | nested | candidate | 296.63 (282.08–303.75) | 618.42 (540.58–643.67) |
| 64 | ping | baseline | 52.96 (41.29–53.25) | 277.33 (209.88–562.33) |
| 64 | ping | candidate | 53.33 (31.96–53.54) | 219.21 (96.33–303.21) |

Reading: this correction adds a pass-through wrapper only to raw clocks in hand-assembled graphs and a counter to the binding; the Builder paths are unchanged. Against `02d51f24` the p50 of every measured path differs by no more than noise on both widths (64-bit direct 82→82.17 µs, nested 299.13→296.63, limit 948.79→948.21). Against `main` the 64-bit p50 is 5 to 8 percent higher on the affected paths (direct 75→80.71, nested 286.25→298.5, limit 912.33→955.63) and the emulated 32-bit p50 is about 20 to 55 percent higher (direct 149.96→230.71, limit 2206.25→2726.21, nested 642.08→785.08): those are measured costs of the guard as a whole, reported as such, and the p99 differences are not attributed to it (the `ping` control is untouched code and its p99 moves in both directions between runs). The numbers are measurements, not a verdict.

## Third review of the correction `f0f9db3a` (F266-17): evidence on the corrected source

Corrected source `fc9bd785bc1b7b7c1d811612115d68d042a1c7f9` (tree `c980689181c7b87e72aa8db306532564dc3e4e29`); reviewed head `f0f9db3ad8ab5e7d5e6ead87e606eca3ddf813b5`; baseline `main@316b1de99e784fec95b40f849d83a04dec772e2b`. Later commits change only investigation files and the definitions of three mutants (below). Raw outputs: `raw-final4/`, `raw-mutants-final4/`, `raw-mutants-final4-rerun/`, `raw-bench-final4/`, `raw-bench-vs-reviewed-final4/`. Earlier sections stay as the history of their own heads. Docker, isolated Redis 7.0.15, PHP 8.4.27, actual PHP_INT_SIZE 8 and 4 (32-bit emulated).

Design of the fix (M2): a binding is a generation of its own (holders, and the state it replaced). A `bindAll` takes a hold on the generation it records or relies on; a rollback releases only its own holds and undoes a generation back to the state it replaced only when nobody stands on it, the call that recorded it has rolled back, and no call is active on the resource; an active call keeps its domain until its last release, when the overdue rollback happens. An entry class that Host code builds from inside a build (a Builder graph, or a class built by hand) stands on the binding it adopts (`hostRegion` marks the policy getters and the admission-runtime construction that run during a build); an entry adopting inside its own build does not. A replacement of a vacated binding is decided by its own holders, so a holder of the earlier generation never keeps the replacement. The binding lifetime is not stricter than M2: a live binding and its provider references are unchanged, a vacated binding is replaceable under the approved conditions. No public method, interface or constructor parameter was added.

| Run | Result |
|---|---|
| New tests (52 in `OwnershipCrossingsRedisTest` and `OwnershipRegistryTest`) on the **reviewed source `f0f9db3a`**, 64 and 32 | 9 red on both widths: the entry built by hand (case 1), the failed replacement of a vacated binding (case 2) and the active direct call during rollback (case 3), each for `build()` and `buildOperationalReader()`, and three registry units (adoption inside a Host region, holders of a replaced binding, delayed rollback) |
| The same tests on the corrected head | pass on both widths; the kept controls (Builder-built B, ordinary failed build, CE-29, CE-31, CE-32) pass |
| Candidate full suite 64 | 2888 tests (100 errors, 4 failures, 14 skipped): ReleaseVerification classes only |
| Candidate full suite 32 | 2883 tests (105 errors, 5 failures, 65 skipped): ReleaseVerification classes and the year-2999 fixture (#267) as before, **plus two classes that timed out in this run**: `AdmissionStoreNm7RedisTest` (64-process race workers "not ready within 90 s" under the emulated 32-bit image) and `BlockNoDowngradeRedisTest`. Both passed when re-run alone on 32-bit (`rerun-32-race-classes.log`: 18 tests OK, 30641 assertions). They did not fail in the earlier full runs of this series and do not touch the changed code; I record the failure of this run as it happened and the isolated pass as a separate fact, and do not call the full 32-bit run clean |
| `--group php32`, 32 / 64 | 892 tests OK (64 skipped) / 893 tests OK (13 skipped) |
| Mutants | M01–M28 detected on both widths in the full run; M06, M23 and M29 were re-defined after it because the code they target changed (M06 and M23 anchors followed the new `clear()`/rollback text; the former M29 turned out to be an equivalent mutant: a stale generation pointer after an undo is unobservable, because a replacement only ever happens over an unbound or vacated state) and were re-run alone: M06, M23, M29 detected on both widths. M26–M29 are the controls of this correction |
| Runner (provenance, governance helper, clean clone, php64/php32 suites) | all steps exit 0 |

### Cost (committed source, alternating, 5 rounds of 3000 iterations after 300 warm-up; no threshold). The path measured changed: `clear()` now settles an abandoned binding at every release

Against `f0f9db3a` (the cost of this correction alone):

| width | workload | tree | p50 µs median (min–max) | p99 µs median (min–max) |
| 32 | direct | baseline | 227.92 (225.17–230.38) | 427.63 (317.54–565.04) |
| 32 | direct | candidate | 233 (228.79–234.83) | 459.42 (424.29–727.75) |
| 32 | limit | baseline | 2660.96 (2649–2665.54) | 12944.08 (12651.54–13609.17) |
| 32 | limit | candidate | 2662.42 (2653.46–2677.38) | 12554.75 (12532.83–13443.04) |
| 32 | nested | baseline | 761.63 (760.08–766.08) | 1529.33 (1346.54–1700.63) |
| 32 | nested | candidate | 768.67 (761.63–773.79) | 1547.13 (1340.08–1568.13) |
| 32 | ping | baseline | 76.54 (75.38–78.29) | 177.38 (131.13–283.71) |
| 32 | ping | candidate | 76.79 (75.17–79.08) | 210.67 (119.25–244.25) |
| 64 | direct | baseline | 81.58 (81–86.63) | 343.29 (129.71–387.63) |
| 64 | direct | candidate | 81.5 (81.04–86.79) | 249.46 (122.92–442.13) |
| 64 | limit | baseline | 939.08 (937.17–945.92) | 4539.04 (4527.54–4938.33) |
| 64 | limit | candidate | 937.21 (936.5–979.92) | 4545.29 (4498.25–5111) |
| 64 | nested | baseline | 295.88 (294.67–298.67) | 541.63 (521.54–611.5) |
| 64 | nested | candidate | 295.04 (289.13–299.96) | 539.04 (492.38–897.21) |
| 64 | ping | baseline | 53.13 (52.21–53.54) | 273.67 (179.96–718.29) |
| 64 | ping | candidate | 52.5 (51.88–52.88) | 173.63 (82.21–253.38) |

Against `main@316b1de9`:

| width | workload | tree | p50 µs median (min–max) | p99 µs median (min–max) |
| 32 | direct | baseline | 148.96 (145.83–160.58) | 306 (211.58–496.25) |
| 32 | direct | candidate | 232.63 (226.88–237.92) | 397.67 (310.88–597.13) |
| 32 | limit | baseline | 2206.38 (2159.54–2246.13) | 11921.83 (11641.79–13743.75) |
| 32 | limit | candidate | 2691.5 (2688.04–2718.21) | 14213.13 (12764.92–14339.25) |
| 32 | nested | baseline | 625.04 (619.33–640.5) | 1324.42 (1083.08–1427.25) |
| 32 | nested | candidate | 777.54 (766.42–818.42) | 1827.38 (1608.21–1938.92) |
| 32 | ping | baseline | 75.08 (74.54–79.25) | 128.38 (104.96–343.96) |
| 32 | ping | candidate | 75.54 (74.63–75.75) | 117.5 (104.25–213.96) |
| 64 | direct | baseline | 76.75 (71.79–77.92) | 300.54 (125.54–453.54) |
| 64 | direct | candidate | 83.83 (82.04–84.38) | 209.63 (159.42–411.17) |
| 64 | limit | baseline | 911.54 (905.17–918.46) | 4493.96 (4391.17–4640.08) |
| 64 | limit | candidate | 949.21 (941.54–979.63) | 4918 (4509.5–5186.17) |
| 64 | nested | baseline | 291.38 (282–312.04) | 560.92 (434.08–850.29) |
| 64 | nested | candidate | 298.29 (289.79–346.92) | 754.33 (532.79–1086.29) |
| 64 | ping | baseline | 53.33 (52.71–54.33) | 243.67 (86.38–428.25) |
| 64 | ping | candidate | 53.33 (17.33–53.63) | 245.13 (108.17–465.08) |

Reading: against `f0f9db3a` the p50 of every measured path differs by noise on both widths (64-bit direct 81.58→81.5 µs, nested 295.88→295.04, limit 939.08→937.21; emulated 32-bit direct 227.92→233). Against `main` the 64-bit p50 is about 4 to 9 percent higher on the affected paths and the emulated 32-bit p50 about 22 to 56 percent higher: the measured cost of the guard as a whole. The `ping` control is untouched code and its p99 moves in both directions between trees and runs (64-bit 273.67→173.63 µs here), so p99 differences are not attributed to the guard. The numbers are measurements, not a verdict.

## Fourth review of the correction `b8f264f2` (F266-18, F266-19): evidence on the corrected source

Corrected source `4823e381f74d48174a938ef5219dd0884f6164a5` (tree `45324060a9bf84d1a7400b411394a1ffa3dcb25b`); reviewed head `b8f264f2034f8d133efba0128ac47e90b15ae45d`; baseline `main@316b1de99e784fec95b40f849d83a04dec772e2b`. Later commits change only investigation files and one test (the per-context frame unit test, see M30 below); `src` is identical. Raw outputs: `raw-final5/`, `raw-mutants-final5/`, `raw-mutants-final5-rerun/`, `raw-bench-final5/`, `raw-bench-vs-reviewed-final5/`. Earlier sections stay as history of their own heads. Docker, isolated Redis 7.0.15, PHP 8.4.27, actual PHP_INT_SIZE 8 and 4 (32-bit emulated).

Design of the fix. F266-19: a binding generation no longer points at the one it replaced; an undo restores the three fields it saved (the state replaced is only ever unbound or vacated) and drops the generation, so one record per resource is reachable however many graphs replaced one another. F266-18: the process-wide depth counter is replaced by build frames kept per execution context (main stack, each Fiber). A Builder opens an `own` scope for the components it constructs (an entry adopting the binding inside it is part of that build and does not pin it); Host code that runs during a build (policy getters, admission runtime construction) opens a `host` frame; an entry adopting under a `host` frame, or under no frame, stands on the binding. A build suspended in a Fiber never marks another context, and a Builder that Host code runs from inside a getter has a scope of its own for its components. No public method, interface or constructor parameter was added. Declared limit: the tracking distinguishes Fibers; under a scheduler that is not Fibers a build must not suspend inside a policy getter while another build runs on the same context.

| Run | Result |
|---|---|
| New tests (62 in `OwnershipCrossingsRedisTest` and `OwnershipRegistryTest`) on the **reviewed source `b8f264f2`**, 64 and 32 | 9 red on both widths: a build suspended in a Fiber (2), a nested failed build on the same stack (2), the 128-graph bounded-records test (integration and unit), and the frame/adoption units; the registry file re-run alone against the reviewed source after its last edit: 3 errors and 1 failure on both widths |
| The same tests on the corrected head | pass on both widths; the kept controls (F266-15/17 cases, the nested graph that succeeds, failed replacement, rollback after the last owner's release, an older record never releasing a newer one) pass |
| Candidate full suite 64 | 2898 tests (100 errors, 4 failures, 14 skipped): ReleaseVerification classes only |
| Candidate full suite 32 | 2893 tests (101 errors, 4 failures, 65 skipped): ReleaseVerification classes and the year-2999 fixture (#267) only. This run did **not** repeat the two race/timing failures recorded in the previous section (#268); that run is reported as a failed run in its own section and this one is not used to clear it |
| `--group php32`, 32 / 64 | 902 tests OK (64 skipped) / 903 tests OK (13 skipped) |
| Mutants M01–M31 | 30 of 31 detected in the full run; **M30 (one process-wide frame stack instead of one per context) was undetected**: the earlier test could not tell the two apart, because a Builder scope pushed on the shared stack hid the difference. The per-context frame unit test was rewritten so that a scope open in a suspended Fiber must not be taken for a scope of the main stack, and M30 was re-run alone: detected on both widths. F266-19 has no one-textual-change mutant (the fault is a property that chains records); its control is the red run against `b8f264f2` |
| Runner (provenance, governance helper, clean clone, php64/php32 suites) | all steps exit 0 |

### Cost (committed source, alternating, 5 rounds of 3000 iterations after 300 warm-up; no threshold). The call path is unchanged by this correction; the Builder scope and frames run at build time

Against `b8f264f2` (the cost of this correction alone):

| width | workload | tree | p50 µs median (min–max) | p99 µs median (min–max) |
| 32 | direct | baseline | 233.08 (231.79–234.13) | 430.33 (406.13–648.13) |
| 32 | direct | candidate | 232.92 (231.63–234.46) | 426.46 (387.46–471.88) |
| 32 | limit | baseline | 2701.04 (2692.71–2740.54) | 12969.58 (12428.33–16831.92) |
| 32 | limit | candidate | 2711.17 (2700.17–2717.13) | 13452.58 (12664–13605.63) |
| 32 | nested | baseline | 781.54 (775.58–785.96) | 1603.58 (1450–1892.96) |
| 32 | nested | candidate | 779.33 (774.17–784.33) | 1618.58 (1407.71–1882) |
| 32 | ping | baseline | 76.92 (76.21–90.92) | 175.54 (141.04–231.67) |
| 32 | ping | candidate | 76.38 (76.13–76.75) | 169.21 (116.38–194.79) |
| 64 | direct | baseline | 82.92 (79.17–84.17) | 330.38 (259.92–460.58) |
| 64 | direct | candidate | 85 (83.96–87.96) | 592.79 (202.63–1286.13) |
| 64 | limit | baseline | 940.21 (936.67–943.67) | 4570.75 (4485–4613) |
| 64 | limit | candidate | 940.46 (937.58–944.29) | 4587.63 (4510.21–4597.54) |
| 64 | nested | baseline | 296.75 (290.79–297.83) | 633.17 (597.75–1213.04) |
| 64 | nested | candidate | 293.21 (291.46–298.29) | 776 (688–867.75) |
| 64 | ping | baseline | 53.04 (36.13–53.38) | 218.75 (208.75–603.46) |
| 64 | ping | candidate | 52.42 (21.08–53.04) | 473.13 (188.42–825.17) |

Against `main@316b1de9`:

| width | workload | tree | p50 µs median (min–max) | p99 µs median (min–max) |
| 32 | direct | baseline | 149.75 (148.58–155) | 300.54 (214.13–353.96) |
| 32 | direct | candidate | 233.63 (232.29–239.08) | 431.58 (398.17–777.88) |
| 32 | limit | baseline | 2185.33 (2179.71–2198.88) | 11554.17 (11441.25–12136.58) |
| 32 | limit | candidate | 2708.88 (2703.42–2724.88) | 13083.92 (12918.04–13605.75) |
| 32 | nested | baseline | 636.71 (630.5–638.17) | 1235.63 (1031.58–1389) |
| 32 | nested | candidate | 785.96 (785–787.21) | 1497.96 (1350.42–1648.29) |
| 64 | direct | baseline | 58.88 (48.58–76.46) | 528.67 (189.29–1256.08) |
| 64 | direct | candidate | 61.79 (56.5–91.42) | 364.38 (281.17–620.63) |
| 64 | limit | baseline | 910.04 (693.79–915.33) | 4534.63 (4264.88–4611.17) |
| 64 | limit | candidate | 943.83 (728.38–947.29) | 4558.25 (4149.08–4850.17) |
| 64 | nested | baseline | 241.88 (238.71–250.25) | 678.58 (596.25–912.96) |
| 64 | nested | candidate | 250 (235.63–270.71) | 757.42 (636.04–1180.63) |

Reading: against `b8f264f2` the p50 of the library paths differs by noise on both widths (64-bit direct 82.92→85 µs, nested 296.75→293.21, limit 940.21→940.46; emulated 32-bit direct 233.08→232.92). The 64-bit p99 of the `ping` control moved 218.75→473.13 µs in this run (and in the other direction in earlier runs): `ping` is a raw Redis round trip through code that this change does not touch, so that tail difference is measurement noise of this machine and is shown here, not attributed to the guard; the p99 differences of the library paths are not attributed to it either. The run against `main` was less stable than earlier ones (64-bit baseline direct min 48.58 and max 76.46 µs, wide ranges): its 64-bit numbers are indicative only and the earlier, tighter comparison against `main` stays the reference for the cost of the guard as a whole (about 4 to 9 percent of p50 on 64-bit and about 22 to 56 percent on the emulated 32-bit). The numbers are measurements, not a verdict.

### Addendum: the typing fix of `476c1dcc` (lowest supported PHPStan)

The ordinary CI run 44339676061 on `c15b1764` failed `Lowest Supported Dependencies` (and `Final Gate` with it) with one PHPStan 2.0.0 error: the static `WeakMap` of the per-Fiber build frames did not accept the assignment as written. The cause was the code, not the infrastructure (the other 15 jobs passed); it was fixed by extracting a typed helper (`fiberFrameMap()`, the pattern already used by `slots()`) and pushing a new head, not by rerunning. `src` differs from `4823e381` only by that helper (15 lines in `OwnershipRegistry.php`). Evidence on `476c1dcc`: Unit, System and Correlation on 64-bit (1787 tests, the ReleaseVerification failures only, `raw-final5-typing-fix/anchors-typing-fix-64.log`), the ownership suites on both widths (93 tests OK), mutants M30 and M31 detected on both widths (`raw-mutants-rerun2/`), PHPStan max and the formatter clean on the current toolchain. The full 32/64-bit runs, the red proof and the cost measurements above are those of `4823e381`; they were not repeated for a typing-only change. Ordinary CI run 44341742654 on `476c1dcc`: attempt 1, 17/17.

## Fifth review of the correction `6afce55d` (F266-20, build isolation across execution contexts): evidence on the corrected source

Corrected source `361e0f4c3361486647f1650fc59a206f28479bb9` (tree `06d6a5c455b91d96eac9c77c0647383bb1aa6ac4`); reviewed head `6afce55d352e18161992549535c83348b985ab34`; baseline `main@316b1de99e784fec95b40f849d83a04dec772e2b`. Raw outputs: `raw-final6/`, `raw-mutants-final6/`, `raw-mutants-final6-rerun/`, `raw-bench-final6/`, `raw-bench-vs-reviewed-final6/`, `raw-lifetime-followup/`; an earlier full run on `3796b4d7` (the intermediate head, not delivered) is kept as superseded in `raw-final6-superseded-3796b4d7/`. Earlier sections stay as history of their own heads. Docker, isolated Redis 7.0.15, PHP 8.4.27, actual PHP_INT_SIZE 8 and 4 (32-bit emulated). No tests ran in parallel with a measurement.

Decision: the approved M2/M7 contract is kept, not narrowed (the Lead's recommendation; no Owner decision was needed). The shared build state is removed (see `COVERAGE.md`, fifth review): each entry class records the hold it took on an existing binding against its own object, the Builder keeps the list of the entry classes it constructed itself, and a failed build releases those holds first and then rolls its own binding back; entries built by Host code from a getter are in no list and keep their holds. The registry keeps only its two weak maps (slots and adoptions). The review follow-up added: a hold (`AdoptionHold`) names its resource only weakly and releases itself when its entry is destroyed or when its build releases it. No public method, interface or constructor parameter was added.

The two controls the reviewer asked for were run on `3796b4d7` before any change and are recorded as they came out: the collection test (a graph whose store's callback points back to it, collected with its store and provider, adoption records gone) **passed**, so there was no leak through the weak map on PHP 8.4.27; the dead-entry test **failed**, so a dead entry's hold was a real defect, fixed as above.

| Run | Result |
|---|---|
| New tests (69 in `OwnershipCrossingsRedisTest` and `OwnershipRegistryTest`) on the **reviewed source `3796b4d7`**, 64 and 32 | 3 red on both widths: the dead-entry test (`build()` and `buildOperationalReader()`) and the hold-lifetime unit; the collection test passes there (kept as a control) |
| Red proof against `6afce55d` (the head the finding is about) | the structural test fails for the intended reason (the registry keeps per-context build state there); the four model tests fail there because the API (`releaseAdoptions`) does not exist, which is not the same reason, and is stated as such (`raw-final6-superseded-3796b4d7/reviewed-red-*.log`) |
| The same tests on the corrected head | pass on both widths; the F266-15/17/18/19 controls, the nested/direct/failed/128-replacements cases pass |
| Candidate full suite 64 | 2905 tests (100 errors, 4 failures, 14 skipped): ReleaseVerification classes only |
| Candidate full suite 32 | 2900 tests (101 errors, 4 failures, 65 skipped): ReleaseVerification classes and the year-2999 fixture (#267) only. The race/timing failures of #268 did not appear in this run; that does not clear the earlier run, which stays reported as a failed full 32-bit run in its own section |
| `--group php32`, 32 / 64 | 909 tests OK (64 skipped) / 910 tests OK (13 skipped) |
| Mutants M01–M34 | 31 of 34 detected in the full run; `M26`, `M30` and `M31` could not be applied (their anchors named code that the hold refactor replaced: ANCHOR-NOT-FOUND, not UNDETECTED) and were re-defined for the new code and re-run alone: M26, M30, M31 detected on both widths (`raw-mutants-final6-rerun/`). M34 (a hold that does not release itself on destruction) detected |
| Runner (provenance, governance helper, clean clone, php64/php32 suites) | all steps exit 0 |

**Limits of what ran.** The Fiber cases and the explicit-order interleaving models ran on both widths. **No scheduler other than PHP Fibers was run** (no Swoole or similar extension in the image). The claim for schedulers that are not Fibers rests on two things that are different in kind and are kept apart here: (a) a structural fact, shown by `testTheRegistryKeepsNoBuildStateShapedByTheExecutionContext`: the registry keeps no state that depends on the execution context, so the mechanism of the finding (an exit that pops a frame another context pushed) no longer exists; (b) the interleaving models, which schedule the steps of two builds in every order with explicit ledgers and entries and no stack. Neither is a run of such a scheduler.

### Cost (committed source, alternating, 5 rounds of 3000 iterations after 300 warm-up; no threshold). The call path of the entry classes is unchanged; the hold objects exist at construction and destruction

Against `3796b4d7`:

| width | workload | tree | p50 µs median (min–max) | p99 µs median (min–max) |
| 32 | direct | baseline | 239.88 (237.63–242.21) | 736.17 (668.92–778.17) |
| 32 | direct | candidate | 240.08 (233.92–243.71) | 704.83 (519.21–946.58) |
| 32 | limit | baseline | 2738.21 (2702.13–2746.96) | 13153.17 (12941.83–15018.58) |
| 32 | limit | candidate | 2760.96 (2701.38–3035.58) | 13867.58 (12404.5–24945.54) |
| 32 | nested | baseline | 788.46 (786.25–797.46) | 1973.21 (1820.17–2244.79) |
| 32 | nested | candidate | 790.92 (784.17–801.38) | 2139.29 (1591.04–2636.08) |
| 32 | ping | baseline | 78.79 (78.04–79.5) | 329.54 (113.79–720.75) |
| 32 | ping | candidate | 78.58 (78.5–78.96) | 204.5 (180.79–367.08) |
| 64 | direct | baseline | 84.88 (82.63–86.67) | 376.21 (288.5–419.96) |
| 64 | direct | candidate | 85.67 (83.71–86.58) | 580.67 (339.29–781.71) |
| 64 | limit | baseline | 944.29 (940.79–956) | 4888.29 (4606.25–5025.88) |
| 64 | limit | candidate | 950.29 (941.83–958.75) | 4914.29 (4823.92–5349.33) |
| 64 | nested | baseline | 305.83 (294.04–312.17) | 1009.79 (967–1107.63) |
| 64 | nested | candidate | 300.96 (300.13–308.13) | 999.79 (729.42–1180.46) |
| 64 | ping | baseline | 53.33 (52.42–54.54) | 337.54 (193.21–951.29) |
| 64 | ping | candidate | 52.67 (52.33–53.42) | 297.96 (188–322.58) |

Against `main@316b1de9`:

| width | workload | tree | p50 µs median (min–max) | p99 µs median (min–max) |
| 32 | direct | baseline | 152.96 (151.38–155.88) | 501.21 (370.46–602.38) |
| 32 | direct | candidate | 238.67 (234.08–241.79) | 697.63 (500.63–840.38) |
| 32 | limit | baseline | 2191.08 (2182.25–3059.46) | 11524.04 (11130–15871.42) |
| 32 | limit | candidate | 2718.88 (2707.63–2721.13) | 13130.04 (13012.17–13299.63) |
| 32 | nested | baseline | 643.83 (641.92–651.46) | 1476.54 (1401.54–1777.83) |
| 32 | nested | candidate | 792.88 (784.96–798.17) | 1946.63 (1803.67–2213.92) |
| 64 | direct | baseline | 78.04 (76.83–79.38) | 319.5 (200.33–431.83) |
| 64 | direct | candidate | 85.17 (84.38–88) | 520.46 (395.42–795.29) |
| 64 | limit | baseline | 905.25 (902.46–912.88) | 4711.38 (4548.13–5229.04) |
| 64 | limit | candidate | 946.13 (936.92–952.63) | 4945.13 (4881.88–4962.21) |
| 64 | nested | baseline | 293.29 (229.71–305.63) | 880.67 (648.92–1250.83) |
| 64 | nested | candidate | 305.42 (303.58–308.88) | 924.92 (699.83–1040.25) |

Reading: against `3796b4d7` the p50 of the library paths differs by noise on both widths (64-bit direct 84.88→85.67 µs, nested 305.83→300.96, limit 944.29→950.29; emulated 32-bit direct 239.88→240.08). Against `main` the 64-bit p50 is about 4 to 9 percent higher on the affected paths and the emulated 32-bit p50 about 22 to 56 percent higher: the measured cost of the guard as a whole. The `ping` control is a raw Redis round trip through untouched code and its p99 differs between trees in both directions across runs (64-bit 337.54→297.96 µs here), and the p99 differences of the library paths (64-bit direct 376.21→580.67 µs against `3796b4d7`) are shown, not attributed to the guard. The numbers are measurements, not a verdict.
