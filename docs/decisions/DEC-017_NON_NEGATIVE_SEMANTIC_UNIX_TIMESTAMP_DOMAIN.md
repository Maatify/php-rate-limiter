# DEC-017 — Non-Negative Semantic Unix Timestamp Domain

## Decision ID

`DEC-017`

## Status

`ACTIVE`

## Date

2026-09-29

## Decision Authority

Owner-approved Gate 12 semantic-timestamp-domain decision (Option A) recorded
in PR #83 comment `5887936343`.

## Scope / Concern

Value domain for semantic Unix timestamps: caller-supplied semantic time
received by capability contracts, and persisted semantic timestamp fields
where this package owns the representation and validation contract.

## Context

Gate 12 remediation surfaced boundary questions about caller-supplied `now`
and `fromTimestamp` values (for example `HardBlockCycleStoreInterface::blockWithCycleTracking()`
and `::readDecayPauseState()`) and about persisted
cycle/pause timestamps. DEC-016 settled *which* clock is authoritative for a
given capability contract, but left the *value domain* of a semantic
timestamp unresolved: whether a negative caller-supplied value is a valid
(if unusual) point in time or a contract violation, and how `0` is
distinguished from "no timestamp" in DTOs that also use `0` as a documented
sentinel.

## Decision

- All semantic timestamps governed by this package's time contracts —
  caller-supplied and persisted package-owned — are **non-negative Unix
  timestamps**: `timestamp >= 0`.
- A caller-supplied semantic timestamp that is negative is rejected with an
  explicit `RateLimiterException` before any backend mutation, and before
  any arithmetic that relies on the value in a read-only operation. This
  applies to every capability contract that receives caller-supplied
  semantic time, including `blockWithCycleTracking(..., int $now, ...)` and
  `readDecayPauseState(..., int $fromTimestamp, int $now)`, and to any
  future semantic timestamp parameter absent a stronger/narrower contract
  already governing it.
- A persisted semantic timestamp field must be a canonical non-negative
  decimal integer, exact according to that field's own PHP-int/backend/
  arithmetic requirements; a malformed persisted value fails explicitly
  before it is used.
- `0` remains a valid Unix epoch timestamp wherever a field represents an
  ordinary timestamp. Separately, `0` also remains the existing documented
  sentinel for "no active pause" in the DTO fields that already define it
  that way (for example `HardBlockCycleResultDTO`/`DecayPauseStateDTO`
  fields so documented). Internal implementation must not conflate the two:
  it must not treat `0` as "no timestamp present" in a context where `0` is
  a legitimate Unix-epoch value, and must use an explicit presence signal
  (for example `nil`/an explicit boolean/explicit state) instead of
  overloading `0` for that purpose.
- This decision does not authorize substituting Redis server time for
  caller-supplied semantic time, and does not change any public method
  signature or require a DTO redesign.

## Relationship to DEC-016

DEC-016 and DEC-017 are complementary, not overlapping:

- **DEC-016** owns clock *authority* — which capability contracts are
  caller-time authoritative versus Redis-owned-time authoritative.
- **DEC-017** owns the *value domain* of a semantic timestamp once that
  authority is established — non-negative Unix timestamps.

DEC-017 does not supersede DEC-016; DEC-016's authority assignments remain
unchanged and ACTIVE.

## Rationale

A semantic Unix timestamp has no meaningful negative value in this
package's domain (rate-limiting decisions, hard-block cycles, decay-pause
accounting). Rejecting negative caller-supplied values explicitly, before
mutation or unguarded arithmetic, prevents a malformed caller input from
silently producing an incorrect boundary computation or a partially
mutated state, while leaving `0` — a legitimate epoch instant — usable
wherever the field is an ordinary timestamp.

## Consequences

Capability contracts receiving caller-supplied semantic time reject a
negative value with `RateLimiterException` before any Redis access (or,
for read-only operations, before relying on the value in arithmetic).
Internal accumulator/boundary variables that track "the latest timestamp
seen so far" must not use a bare `0` to mean "none yet" once `0` is an
accepted timestamp value; they use an explicit presence signal instead.
Persisted semantic timestamp fields are validated as canonical
non-negative integers before use, independently of the exact-integer
magnitude validation that governs their upper bound. This covers, at
least: hard-block cycle members and scores, pause start/finish/scores,
persisted hard-block `expiresAt`, score `updatedAt`, budget `epochStart`
(persisted and, when actually used, seeded), and every temporal field of
the circuit-breaker state. A malformed persisted value — including a
negative one — is explicit malformed state: it is never repaired, deleted,
or silently overwritten, and an operation that would have to use it fails
before any mutation. A circuit-breaker state carrying a negative timestamp
is rejected with `RateLimiterException` both when loaded and before it is
saved; `0` remains valid wherever circuit semantics already use it.

## Supersedes

None

## Superseded By

None
