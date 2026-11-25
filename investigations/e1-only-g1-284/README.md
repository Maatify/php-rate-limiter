# G1: sole E1 account composition — evidence and reproduction

Authority: [accepted bounded design and G1 assignment](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7156769415), correcting [7156743430](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7156743430), and DEC-046. This work does not qualify a release or resolve A1–A4/G2/G3.

## Sources and implementation boundary

Base: `6db137868f4abaebb94a287f75a3af1742d8d3bc`, tree `b03cf3e2e267ab8c863edc8264e80923a7f1812e`. Frozen implementation: `f176bf871b10e600bbb3e46351b1ccdc10a63aba`, tree `607da52fe561cd62d6f3ce0d85a3b529446d852b`. The evidence/test follow-up changes no production source. The Draft PR records its final HEAD/tree and actual CI run URLs; `raw/source-manifest.json` binds every production file to the base and implementation, and verifies the Builder/Pipeline hashes emitted by every benchmark process.

Pipeline constructs BudgetTracker/Gate on the captured account handles and Bucket on the correlation root, retaining the explicit clock dependencies. Writer/reader construct the same final E1 implementation internally. Reader's optional **internal closed binding token** transfers the already verified root/view pair across Builder binding/adoption without another provider lookup; its private constructor and root identity check prevent supplying an arbitrary view, environment or deriver. All actual handles and RequestScopedClock resources enter binding/ownership. OwnershipRegistry's Host trust boundary is unchanged.

Invalid compositions fail before package backend access, including before reading an existing block; they cannot rely on reaching a later capability check. Values used by new policy preflight are captured through protected Host crossings and retained across retries. Standalone helpers and plain-string root storage APIs retain their contracts. A custom adapter can pass structural checks while violating shared storage/rollback semantics: the negative conformance test demonstrates this limit, rather than claiming the constructor can inspect Host state.

## Preserved detectors and historical evidence

- Original S0 fixtures, archived observations, historical decision strings and golden constants remain. Separate E1 serialization hashes account for changed identities/root-view object structure; mutation controls compare against the E1 state target, not an unrelated historical hash. Historical short digest **display labels** are normalized only in replay reporting; actual state observations still target E1 handles.
- `tests/Fixtures/PreG1/` retains the exact executable bodies of the approved base's later V2 worker. `PreG1ReplayProvenanceTest` checks the body tokens against that immutable base. The old S0/V2 mixed-version detectors keep their original assertions against this historical worker. They do not certify a G1 transition.
- The current Redis test separately demonstrates V2/E1 account-block invisibility in both directions. Its result is **unsupported transition**, not a migration or mixed-worker operating procedure.
- Positive fault adapters acquire the new composition capabilities to reach their original failure boundary. The malformed-result detector additionally asserts that its injected invalid result was actually reached, so a preflight refusal cannot make that detector pass accidentally.

## Coverage map

Final-head results and raw CI logs belong to the Draft PR. These are the applicable evidence entry points, not a substitute for the Lead's independent judgment.

| Criteria | Evidence entry points |
|---|---|
| T1 | `AccountScopeKeyDeriverTest`: unchanged vectors, hand-checked framing, adversarial corpus, purpose/material shape and u32/native-width bounds |
| C1 / T2 | `ClosedAccountCompositionTest`: removed named/positional helpers/derivers, excess arguments, production API removal; real `KeyScopeE1RedisTest` runtime-first/reader-first behavior |
| C2–C3 / T3 | distinct/stable/idempotent handle controls, invalid static-return TypeError, actual processing capabilities, reader without unused correlation capabilities, DEC-032 advanced-read fallback |
| C4 / T4 | public Redis budget/Gate reads, root Bucket/distinct families, per-family physical inspection and the three separate routing mutants |
| C5 | captured Gate/Bucket null/caller dispatch (including rotated decimal milliseconds), deliberately different budget/evaluation clocks, original caller-time/clock-count/pair Redis suites |
| T5 | E1-only default in budget/device-generation/cycle/lifecycle/publication/allowance suites; current/previous Redis seeding and claims |
| T6 | distributed-account snapshot/adoption tests, canonical/invalid/legacy member rejection and previous/current state observations |
| T7 | all ten plain-string root families and eight account-view families, real store differential; no Redis implementation change |
| C6 / T8–T12 | `KeyScopeE1RedisTest` plus ownership, clone, request atomicity, conflict, lost-reply, both-layout ACL and DEC-045 expiry suites; broken custom cancellation control declares the adapter unsupported |
| C7 / T9 | provider mismatch/recovery, nested successful reader retained after outer failure, cancelled Fiber, actual RequestScopedClock binding and existing Host-crossing suites |
| C8 / T13 | current public E1 versus historical V2 worker on isolated Redis: neither sees the other's account block; persisted E1 bytes remain unchanged |
| T14 | full applicable CI, actual PHP32 group/Redis, PHP64 suite, examples, PHPStan max, PER/style, Composer validate/platform/audit and two clean external Composer consumer roots |
| T15 | three-arm raw measurements and reproducible aggregation below |

## Comparative measurement

Run **alone**, without concurrent tests, on an isolated Docker Redis. `run-bench.sh` creates and removes its own containers/network and exact-source base archive; it never swaps files in the checkout. Both widths use PHP 8.4.27 and Redis 7.0.15, the repository RESP client in EVAL mode, fixed caller timestamp `1793793600`, identical synthetic inputs/policies, concurrency 1, 15 warmups and 100 measured operations per scenario per repeat. Three repeats rotate arm order. Fourteen scenarios × three arms × three repeats × 100 × two widths = **25,200 measured operations**, excluding setup/seeding/warmup.

```bash
bash investigations/e1-only-g1-284/run-bench.sh 64 3
bash investigations/e1-only-g1-284/run-bench.sh 32 3
python3 investigations/e1-only-g1-284/summarize.py
bash investigations/e1-only-g1-284/run-negatives.sh 64
bash investigations/e1-only-g1-284/run-negatives.sh 32
```

The arms are pinned main with E1 selected (primary byte-identical comparison), pinned main with V2 (historical cost context only), and frozen G1 production source. Each process verifies the loaded Builder path. Base src is extracted from Git, mounted read-only, and prepended to Composer resolution in a separate process. The same installed dependencies and unchanged clock/client/emitter fixtures serve all arms. Image identities/platforms are in `raw/bench-*-meta.txt`.

`compose-*` measures warm composition over an initialized store, separately from requests. Allow/failure/blocked/rotation/reader/U/P/claim/simple/K1-scoring-only controls traverse public runtime/reader APIs. Claim results are asserted true and the K1 control is asserted allowed; distinct synthetic fingerprints prevent contaminating that control with collective churn. The mixed-save/conflict cases are additional **direct official-store controls**, not public-runtime timings; one live score is changed outside the overlay before the first save to force exactly one recalculation.

[Full table](BENCHMARK.md), [PHP64 samples](raw/bench-64.jsonl), [PHP32 samples](raw/bench-32.jsonl). Every row retains all latency samples, per-repeat p50/p95/p99, throughput, client CPU, PHP peak allocation, wire bytes and command/commit/conflict counters. The summarizer validates completeness and equality of E1/G1 counters and outcomes in every repeat/scenario before emitting pooled nearest-rank percentiles.

The stricter composition has measurable startup overhead; request effects and distributions must be read from the table. No numeric acceptance limit or statistical significance is invented. PHP64 is native linux/arm64; PHP32 is an actual four-byte-int linux/386 interpreter under emulation on the same Docker host. Compare arms **within** a width; absolute cross-width timings are not comparable. CPU is client user+system CPU, memory is PHP allocated peak (not RSS); Redis CPU, high concurrency, production network/TLS, replicas, Cluster and arbitrary custom adapters are not measured.

## Gate reproduction and remaining limits

```bash
composer validate --strict
composer check-platform-reqs
composer audit --no-interaction --abandoned=fail
composer analyse
composer format:check
TMPDIR=/private/tmp GIT_CONFIG_COUNT=0 XDEBUG_MODE=off bash scripts/ci/run-phpunit-with-redis.sh --no-progress
bash scripts/ci/run-phpunit-32bit.sh --group php32 --no-progress
TMPDIR=/private/tmp GIT_CONFIG_COUNT=0 bash scripts/ci/run-consumer-verification.sh
```

The local macOS runner needs canonical `/private/tmp` for the existing realpath assertions and `GIT_CONFIG_COUNT=0` because its inherited empty Git-config value disappears from PHP's explicit child environment. The release-tooling tests/verifiers were not changed to hide this environmental issue. PHPStan/style may use their sequential/debug mode locally when sandbox TCP listeners are unavailable; normal CI commands remain.

Raw negative-control logs show the budget-only mutant losing the budget decision, Gate-only mutant losing account history, and Bucket-only mutant violating the account-view family boundary. Each is required to fail; anchor drift or a false-green mutant aborts its runner. Historical logs/fixtures are not deleted or rewritten. The final PR links actual full-gate results at its own HEAD; no old #295 PASS is reused as acceptance of G1.

E1 bytes are preserved, but existing V2 traffic loses visibility of Set A and derivative Set B state on transfer. No live upgrade, cross-encoding seed, mixed fleet or rollback is supported, and runtime composition cannot discover all old workers. A1–A4/G2/G3 remain release blockers. No merge, tag, release, issue closure or independent Lead PASS is claimed.
