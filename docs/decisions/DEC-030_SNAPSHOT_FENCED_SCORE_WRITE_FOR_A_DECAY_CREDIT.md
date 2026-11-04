# DEC-030 — Snapshot-Fenced Score Write for a Decay Credit (K1, K2, K3, K5)

## Decision ID

`DEC-030`

## Status

`PROPOSED` — in the PR of F-185-08 (#185, PR-I), on the Owner's decision relayed on #185 (LT-185-08: conditional CAS,
`increment(delta)` on exhaustion, capability option (a), this record). It becomes `ACTIVE` on the Owner's word after the
second measurement this record names (Consequences) and the Lead's review.

## Date

Proposed 2026-11-04.

## Decision Authority

**Owner**, on LT-185-08 ([#185 comment 6634942780](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6634942780)) and
the Owner's approval relayed by the Lead on #185 (2026-11-04).

## Scope / Concern

How a score write of K1, K2, K3 and K5 (`EvaluationPipeline::processUpdates`, outside the K4 generation path) stays correct when
concurrent requests read the same score, apply its decay, and write back; what happens when the fence is exhausted; and the
store capability this requires of every store a Host builds with.

## Context

- **F-185-08 (P1, the fourth external review, WA-01).** The runtime computes `netChange = (decayed + delta) − base` on a snapshot
  read outside any atomic operation and sends it to `increment()`. K5 = 5, 1500 s old (fully decayed), and two concurrent failures
  (+2 each): sequential 4; interleaved **−1** (both send −3). Only K4 was protected (the generation path, L1741–L1757).
- **Measurement before the change (LT-185-08, `4e90064`, real clock).** Under benign concurrency (N = 2/4/8 processes, the same
  account or IP), 10.0 % to 98.5 % of score writes found the snapshot changed between their read and their write, but **none of
  9,750 writes carried a decay credit**: their `netChange` was the delta itself, which `increment()` adds correctly under any
  interleaving. A CAS on every write would therefore have refused correct writes at rates up to 98.5 % and made the exhaustion
  path the common path.
- **DEC-029 P1.** No `RateLimitConcurrencyException` after the request's first K4 mutation or K5 increment is committed. K5 follows
  K4 in the loop, so an exception on K5 exhaustion would breach P1; `k4_concurrency_conflict` is K4's.

## Decision

1. **The fence applies only to a write that carries a decay credit.** A write whose net change is below its delta (`netChange <
   delta`: the decay of the snapshot is credited back) is sent as `incrementIfSnapshot(key, ttl, expectedValue,
   expectedUpdatedAt, newValue, now)`: the store sets the score to `newValue = decayed + delta` only while the stored
   `(value, updatedAt)` still equals the snapshot, atomically. Every other write stays `increment(netChange)`, unchanged.
2. **A miss is re-read and recomputed.** On a miss the score is read again and its decay computed again; a write without a credit
   then goes to `increment()`, a write with a credit is fenced again. At most **three** fenced attempts, as on the K4 path.
3. **Exhaustion adds the delta alone.** After three misses the request sends `increment(delta)`: no exception, no `HARD_BLOCK`,
   no `FAIL_CLOSED`. No update is lost and no score goes below the sequential result; the only cost is that this one write does
   not receive its decay credit (the stricter direction).
4. **The capability.** `SnapshotFencedScoreStoreInterface` (`incrementIfSnapshot()`) and its caller-time mirror
   `CallerTimeSnapshotFencedScoreStoreInterface` (`incrementIfSnapshotAt()`, DEC-016) are additive interfaces, not members of
   `FullCapabilityStoreInterface`. `RedisFullCapabilityStore` implements both (`SCORE_SET_IF_SNAPSHOT`, with the structural checks
   of `SCORE_INCREMENT`: malformed state is an error, never a miss). `RateLimiterBuilder::build()` requires
   `SnapshotFencedScoreStoreInterface` whenever a registered score policy has a positive score delta, and fails with a message that
   names it; the operational reader does not require it (DEC-012).
5. **K4 is unchanged.** The K4 generation path (DEC-007, DEC-029 L1741) is not touched.

## Rationale

- **Why the credit, not every write.** The lost update exists only when two writes apply the same decay credit; an additive write
  is already correct. Fencing it would turn the benign concurrency measured above into misses.
- **Why `increment(delta)` on exhaustion.** An exception breaches DEC-029 P1 on K5 and, on K1–K3, leaves K1 written and the rest
  not, for nothing; a block or `FAIL_CLOSED` refuses a benign user for a race that is not a backend failure. Adding the delta alone
  never loses an update and never produces a value below the sequential result.
- **Why a separate capability with a build-time requirement.** Adding the method to `FullCapabilityStoreInterface` would break
  every Host store at class load with an abstract-method error; an optional capability with a fallback to the old write would leave
  F-185-08 open for those stores without a word. The build-time requirement fails once, at composition, and names what is missing.

## Consequences

- **For a Host using `RedisFullCapabilityStore`:** no change to configure.
- **For a Host store (`FullCapabilityStoreInterface`) before 1.0:** `build()` fails with `Policy <name> writes scores and requires
  SnapshotFencedScoreStoreInterface (#185, DEC-030).` until the store implements `incrementIfSnapshot()` (and, to keep the caller's
  time, `incrementIfSnapshotAt()`). This is the upgrade note of the CHANGELOG entry.
- **Measured before activation (conditions of the Owner's approval):** (1) the reviewer's two-process barrier regression, 50 rounds,
  `4e90064` −1 and the change 4, with the sequential control 4; (2) a second measurement after the change on the credit scenario
  (K5 decayed, N = 2/4/8 concurrent): no negative value, the sum of the deltas preserved, the number of exhaustions. Measured in
  `investigations/fenced-score-write-185/RESULTS.md` (Redis 7.0.15, PHP 8.4.4, 50 rounds each): (1) `4e90064` K5 = −1 and K1 = 5
  in 50 / 50 barrier rounds, the change K5 = 4 and K1 = 10 in 50 / 50, the sequential control 4 / 10 on both; (2) the change: no
  negative value and K5 = 2 × (requests that wrote K5), K1 = 5 × (requests that wrote K1) in 150 / 150 rounds, 0 exhaustions,
  fenced writes 300 applied and 831 missed; `4e90064`: a negative value in 68 / 150 rounds and the sum lost in 107 / 150 (K5).
- **State.** No new key; the fenced write writes the same score hash as `increment()`.

## Relationship to Other Decisions

- **DEC-029** — P1 is respected: no exception on exhaustion; K4's `k4_concurrency_conflict` is not extended to K1–K3/K5.
- **DEC-007** — the K4 generation fence is unchanged.
- **DEC-016** — the caller-time mirror follows the additive `CallerTime*` pattern.
- **DEC-017** — `updatedAt` stays a canonical non-negative integer, validated before the comparison.
- **DEC-012** — the operational reader does not require the capability.
- This record modifies no ACTIVE decision and supersedes none.

## Canonical Contract / Current Owner

`SnapshotFencedScoreStoreInterface`, `CallerTimeSnapshotFencedScoreStoreInterface`, `RateLimiterBuilder` (the build-time
requirement), `EvaluationPipeline::writeScore()`, and `RedisFullCapabilityStore` (`SCORE_SET_IF_SNAPSHOT`).

## Supersedes

None

## Superseded By

None
