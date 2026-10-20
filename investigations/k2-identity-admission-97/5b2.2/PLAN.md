# Stage 5-b2.2 plan — settlement is optional per scope and off by default (#97, before 5-b3b resumes)

> **Status:** PRE-REGISTRATION v1, before any red test and any line of the implementation. Base: `main@7057abc`. Input: the Owner's decision on #147 (2026-10-20): F-5b3b-NM10 / NM5 showed that settlement feeds `F` and D8 only, does not compensate for lost outcome scores, and harms benign traffic under outcome drops and probes; the default returns to the tombstone semantics (SF §2.3, I9). Binding: `5b2.1/PLAN.md`, `RUNTIME_IMPLEMENTATION_CONTRACT.md` §2.4 and §3 row (b), DEC-008 BC1–BC4, DEC-016, DEC-018. No gate may be weakened after a run; a detector that did not fire is "not evidenced".

## 1. Shape

One optional trailing field on `AdmissionScopeDTO`: `bool $settlesOnExpiry = false`, valid on `SET_SIZE` only, `RateLimiterException` otherwise (the pattern of `successKeepsSettled`). **DEC-008 analysis.** BC1 (no existing signature changes): the constructor gains an optional trailing parameter after `successKeepsSettled`, every existing call stays valid. BC2 (no existing implementer breaks): no interface method is added or changed; `AdmissionReservationStoreInterface` / `AdmissionPermitStoreInterface` are untouched. BC3 (defaults preserve the earlier behaviour): `false` is the behaviour of the store as merged at `d6aea4d`, so a caller that never heard of the field gets the tombstone semantics of 5-b2. BC4 (additive documentation): CHANGELOG, Package Reference, `llms.txt`, contract §2.4 / §3, `KEY_STRATEGY.md` §4.8.

## 2. Behaviour

| | `settlesOnExpiry = false` (default) | `settlesOnExpiry = true` |
| --- | --- | --- |
| Reserve, set scope | exactly `d6aea4d`: type check of the set, member test by `SISMEMBER`, size by `SCARD`, no write to the set. **The `p` key is neither read, validated nor written** | as 5-b2.1: pending member (`ZADD p LT deadline`, only with a permit), size = settled + due pending, the LT-S3 guard (`deadline − now ≤ ttl`) |
| Outcome, set scope | exactly `d6aea4d`: `failure` → `SADD` (+ `EXPIRE` when created); `success` → `SREM`. `p` untouched. `successKeepsSettled` has no effect | as 5-b2.1 (including `successKeepsSettled` and "a success never removes a due member") |
| Request validation | the LT-S3 guard is **not** applied (a deadline beyond the set lifetime is accepted, as at `d6aea4d`) | applied |

`successKeepsSettled` is meaningful only together with `settlesOnExpiry`: without it the store behaves as at `d6aea4d`, which has no such concept. A caller that wants D8 to survive a success under the default simply does not send the D8 scope on a success (the `d6aea4d` contract). The DTO still accepts `successKeepsSettled` alone (its own 5-b2.1 unit test is unchanged).

The reference store (`AdmissionInMemoryReferenceStore`) follows the same table in the same commit.

## 3. Question raised before the build

**LT-24 — the 5-b2.1 fixtures build their set scopes without the new flag.** `SettlementScenarios::member()` (the builder of every SETTLE scenario) and `settle-race-worker.php` construct `AdmissionScopeDTO` for the set scope without `settlesOnExpiry`; under the new default those fixtures exercise the tombstone behaviour and the existing 5-b2.1 settlement tests fail. The assignment says to raise an LT before touching a 5-b2.1 fixture. **Recommendation:** one added argument in `SettlementScenarios::member()` and one in `settle-race-worker.php` (`settlesOnExpiry: true`); no test class, no assertion, no scenario step changes; disclosed in `RESULTS.md` as the only edits of existing test-side files. The ON-PARITY gate is then the existing 5-b2.1 suite itself plus a new class built on new fixtures. **Until it is ruled those two lines are not edited**, and the existing 5-b2.1 settlement tests (`AdmissionSettlementContract*`, `AdmissionSettlementRedisTest`, `AdmissionSettlementNm7RedisTest`) are expected to fail; `RESULTS.md` lists them by name.

## 4. Gates (real Redis for the production store; the same contract on the reference store)

| Gate | PASS | Detector (must fire) |
| --- | --- | --- |
| **OFF-PARITY** | the scenarios SETTLE-1…4, the NM-6 fixtures and the NM-7 sequences of 5-b2 and 5-b2.1 are built **without** the flag and run step by step against `MergedAdmissionOperations` (the operations at `d6aea4d`, verbatim) and against the production operations: every step gives the same result (admitted / refused scope / retry-after / claim) and the same full key dump (type, content, TTL class) | a mutation that ignores the flag (the pending write always on) breaks it |
| **ON-PARITY** | with the flag, the results of 5-b2.1: its existing suite passes (after the LT-24 edit) and a new class on new fixtures (flag set) repeats SETTLE-1…4 on both stores | with the flag off the settlement scenarios fail (the new default is the tombstone) |
| **Reference parity** | the contract fixtures give identical results on the Redis store and the reference store in both modes | — |
| **5-b2 / 5-b2.1 parity** | every 5-b2 test passes unmodified; `git diff --diff-filter=M --name-only 7057abc -- tests` lists only what LT-24 allows; PHPStan max normal and lowest; full suite on Redis with `COMPOSER_PROCESS_TIMEOUT=0`; `composer format:check`; no constant for `D` / `G` / budgets in `src/` | — |

## 5. Documents

`RUNTIME_IMPLEMENTATION_CONTRACT.md` §2.4 and §3 row (b) (settlement is optional per scope, the default is the tombstone; NM-10 is NO-GO as a store mechanism, by reference to #147), `KEY_STRATEGY.md` §4.8 (the `p` family is written only when a scope settles), a second dated note in `SECURITY_FEASIBILITY.md` §2.3, `CHANGELOG.md`, `RATE_LIMITER_PACKAGE_REFERENCE.md`, `llms.txt`.

## 6. Order of commits

1. this plan; 2. red tests and fixtures; 3. implementation; 4. documents; 5. `5b2.2/RESULTS.md`. Any change to a test after commit 2 is listed line by line. After the merge, `#147` is re-founded on it and its plan is amended (the Owner's decision, items 1–6 of the assignment).
