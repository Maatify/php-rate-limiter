# DEC-031 — Fenced Auxiliary Store Writes (Circuit-Breaker State and the Missing-Fingerprint Marker)

## Decision ID

`DEC-031`

## Status

`ACTIVE` — activated 2026-11-05 in [PR #197](https://github.com/Maatify/php-rate-limiter/pull/197) (F-185-16/17/18, [Maatify/php-rate-limiter#185](https://github.com/Maatify/php-rate-limiter/issues/185)) on the Owner's decision relayed on [Maatify/php-rate-limiter#185](https://github.com/Maatify/php-rate-limiter/issues/185) ([comment 6645757770](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6645757770)), after the measurements of `investigations/fenced-auxiliary-writes-185/` and the Lead's review.
Earlier status: `PROPOSED` in the same PR, on the Owner's decisions of 2026-11-05 relayed on #185 (LT-185-17,
LT-185-18), pending the measurements this record names (Consequences) and the Lead's review.

## Date

Proposed 2026-11-05; activated 2026-11-05.

## Decision Authority

**Owner**, on LT-185-18 and LT-185-17 ([#185 comment 6642682540](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6642682540),
[#185 comment 6642708109](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6642708109)), with the Owner's decisions
of 2026-11-05 relayed by the Lead on #185.

## Scope / Concern

Two read-compute-write paths outside the score write of DEC-030 that a concurrent request could overwrite: the circuit-breaker
failure report (`CircuitBreaker::reportFailure()`) and the `last_missing_fp` marker of `EvaluationPipeline::processUpdates()`;
the store capabilities they require of every store a Host builds with, and what happens when a fenced write keeps missing.

## Context

- **F-185-18 (P3, train 6, the Lead's adversarial review).** `reportFailure()` loaded the state, appended one failure and saved
  it with a plain `SET`. Three concurrent backend failures that read the same state each saved a one-failure state: the circuit
  stayed `CLOSED` with `failures = [t]`, against `OPEN` with `[t, t, t]` one after the other (`TRIP_THRESHOLD = 3`).
- **F-185-17 (P3).** The marker was read with `get()` and written later with `set()`, the delta decided between them. Two
  concurrent failures without a fingerprint both read "no marker": K4 = 6 and `SOFT_BLOCK` L1, against 12 and `HARD_BLOCK` L3.
- **Why not DEC-030's fenced write for the marker.** `incrementIfSnapshot()` applies the TTL only on creation; `set()` sets a
  fresh 3600 s on every write. A marker written at t = 0 and again at t = 3500 would have expired at t = 3600 and missed a failure
  at t = 3700 that is 200 s after the last one, inside the 1800 s window.
- **Why compare-and-set for the circuit breaker, not a Lua transition.** The transition (window, threshold, re-entry guard) is
  computed in PHP and emits `CB_OPENED` / `CB_RE_ENTRY_VIOLATION` through the Host's emitter; a Lua transition would duplicate
  that logic in every store and emit from outside the atomic step. `reportSuccess()` does not write in `CLOSED`, and the recovery
  probe runs under the exclusive probe lease, so the proven race is `reportFailure()` alone.

## Decision

1. **The circuit-breaker save is fenced by the version it was computed from.** `CircuitBreakerStateCasStoreInterface` adds
   `loadWithVersion(policy): array{?CircuitBreakerStateDTO, string}` (an opaque version of what is stored, `''` for nothing) and
   `saveIfVersion(policy, expectedVersion, state): bool` (applied only while the stored version is still the expected one).
   `reportFailure()` reads, appends the failure and saves with it, at most **three** attempts; a miss reads again.
2. **Signals follow the save.** The open transition is computed without side effects, and its signals are emitted only after a
   save that applied.
3. **Exhaustion drops this one failure without an exception.** `reportFailure()` runs where the engine has already caught a
   `BackendFailureException`; an exception there would hide the failure mode. Every miss means another process recorded a failure
   in the same window, so a dropped failure delays the trip by at most that one failure.
4. **The marker is written and its previous value returned in one atomic step.** `ScoreReplaceStoreInterface::replaceScore(key,
   value, ttl): ?RateLimitStateDTO` and its caller-time mirror `CallerTimeScoreReplaceStoreInterface::replaceScoreAt()` (DEC-016)
   write the score with a fresh TTL, as `set()` does, and return the state it replaced; the delta is decided from it. The previous
   generation of the marker stays a read-only fallback.
5. **Malformed state is an error.** Both writes validate what is stored first (DEC-017): malformed state is never overwritten.
6. **The capabilities are additive and required at build time.** Neither is a member of `FullCapabilityStoreInterface` or
   `CircuitBreakerStoreInterface`. `RedisFullCapabilityStore` implements both (`CIRCUIT_SAVE_IF_VERSION`, `SCORE_REPLACE`).
   `RateLimiterBuilder::build()` requires `CircuitBreakerStateCasStoreInterface` of the circuit-breaker store (it is always used),
   and `ScoreReplaceStoreInterface` of the rate-limit store whenever a registered policy has `k4_repeated_missing_fp > 0`, with a
   message that names the capability. A pipeline or circuit breaker built through the low-level constructors without them keeps
   the previous unfenced behaviour.

## Rationale

- **Compare-and-set over a transition in the store:** the state machine and its signals stay in one place, and a Host store
  implements two small primitives instead of the circuit-breaker logic.
- **Three attempts:** the pattern of K4 (DEC-029) and DEC-030; the measurement below sets the expectation under contention.
- **A separate primitive for the marker:** it keeps the marker's TTL semantics exactly, which DEC-030's write does not.
- **Required at build time, not optional with a fallback:** the same reasoning as DEC-030 — an optional capability would leave
  F-185-17 / F-185-18 open for a Host store without a word.

## Consequences

- **For a Host using `RedisFullCapabilityStore`:** no change to configure.
- **For a Host store before 1.0:** `build()` fails with `The circuit-breaker store requires CircuitBreakerStateCasStoreInterface
  (#185, DEC-031).` until the circuit-breaker store implements `loadWithVersion()` / `saveIfVersion()`, and with `Policy <name>
  records repeated missing fingerprints and requires ScoreReplaceStoreInterface (#185, DEC-031).` until the rate-limit store
  implements `replaceScore()` (and `replaceScoreAt()` for the caller's time) while such a policy is registered (`login_protection`
  and `otp_protection` are). This is the upgrade note of the CHANGELOG entry.
- **Measured before activation:** the regressions of the Lead's adv6/r2 and adv6/r3 (the race against the sequential control), the
  marker TTL case, and the exhaustion of the fenced circuit-breaker save under N = 2 / 4 / 8 concurrent failures, in the PR and
  `investigations/fenced-auxiliary-writes-185/`.
- **State.** No new key: the circuit-breaker state and the marker keep their keys and shapes.

## Relationship to Other Decisions

- **DEC-030** — the same pattern for two further writes; DEC-030's score write is unchanged.
- **DEC-029** — no new exception path; the fenced failure report never throws on exhaustion.
- **DEC-015** — `reportFailure()` is still entered only for `BackendFailureException`; a backend failure inside the fenced report
  falls back to the local emergency state exactly as before.
- **DEC-016** — the caller-time mirror follows the additive `CallerTime*` pattern.
- **DEC-017** — what is stored is validated before it is compared or replaced.
- **DEC-012** — the operational reader does not require the capabilities.
- This record modifies no ACTIVE decision and supersedes none.

## Canonical Contract / Current Owner

`CircuitBreakerStateCasStoreInterface`, `ScoreReplaceStoreInterface`, `CallerTimeScoreReplaceStoreInterface`, `RateLimiterBuilder`
(the build-time requirement), `CircuitBreaker::reportFailure()`, `EvaluationPipeline` (the marker), and `RedisFullCapabilityStore`
(`CIRCUIT_SAVE_IF_VERSION`, `SCORE_REPLACE`).

## Supersedes

None

## Superseded By

None
