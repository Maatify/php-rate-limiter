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

**Application note (2026-11-04, #185 F-185-01, PR #188).** The scores of the
admission store's sorted sets are persisted semantic timestamp fields and
belong to the list above: the expiry score of every member of a device-member
set, current and previous generation, and the permit-deadline score of every
pending member of a failure set. The built-in `RedisFullCapabilityStore`
validates them as canonical non-negative integers (at most `9007199254740991`)
before the first write: the reservation script checks every score of the
device-member sets and of the pending set it reads, and the outcome script the
pending score of its own member. A score such as `-1`, `0.5`, `inf`, `-inf` or
`1e21` is malformed state. Malformed stored state is a Lua error reply
`malformed admission state: …`, returned before any write. The reference test
executor (`RespRedisCommandExecutor`) raises it as `RateLimiterException`; a
Host executor whose client throws on an error reply (phpredis:
`RedisException`) propagates that exception unchanged, as
`RedisCommandExecutorInterface` requires. It is never a
`BackendFailureException` and never a fallback. The member, its key and its
TTL are not pruned, repaired or refreshed, and no other scope, counter or
permit of the request is written. Before this note such a score was read with
`tonumber()` alone: a negative score was pruned silently by the next admitted
write, and a fractional or infinite one counted as live. The Decision above is
not changed by this note. Evidence: the external review of `5c8b785`
(EX-RC4-F01), confirmed by the Lead on #185 (F-185-01); Owner permission on
#185.

## Supersedes

None

## Superseded By

None
