# DEC-016 — Redis Clock Authority Reconciliation

## Decision ID

`DEC-016`

## Status

`ACTIVE`

## Date

2026-09-29

## Decision Authority

Owner-approved Gate 12 clock-authority reconciliation recorded in PR #74
comment `5883872398`.

## Scope / Concern

Clock authority for the built-in Redis full-capability adapter when a capability
contract receives caller-supplied semantic time.

## Context

DEC-006's blanket Redis-server-time wording conflicted with the shared
`ClockInterface` contract and with capability methods that explicitly receive
`now`. Replacing that caller time with Redis wall-clock time would split one
runtime state machine across two time authorities and break fixed/custom clock
behavior.

## Decision

- A capability contract that receives caller-supplied `now` (or an equivalent
  semantic timestamp) treats that value as the operation's time authority.
- Its Redis Lua operation must use that supplied value atomically and must not
  substitute `redis.call('TIME')`.
- A primitive whose governing contract does not supply semantic current time may
  use Redis server `TIME` when the Redis adapter owns the atomic temporal
  boundary.
- Existing public interfaces remain unchanged; `RateLimiterBuilder::withClock()`
  and fixed/custom clock behavior remain coherent.
- DEC-006's unaffected boundaries remain in force: built-in Redis placement,
  Host-owned client and connection lifecycle, thin raw-command executor, no
  `ext-redis`/Predis runtime dependency, the first non-clustered topology,
  namespace isolation, and real-Redis integration coverage.

## Rationale

Explicit semantic time is part of the capability contract. Preserving it keeps
caller-controlled and deterministic clocks coherent while retaining Redis
server time only where no caller time contract exists.

## Consequences

Lease and ordinary hard-block cycle operations remain caller-time authoritative.
Redis-owned-time score, budget, bounded-state, watch, and persistence primitives
may continue to use Redis `TIME` inside their atomic operations.

**Application note (2026-10-21, #153 phase 2, PR #158).** The score, budget,
bounded-state, watch, and generation-bound lifecycle primitives now receive the
caller's `now` through additive capability interfaces (`CallerTime*` in
`Maatify\RateLimiter\Repository`), so rule 1 of this Decision applies to them:
the built-in `RedisFullCapabilityStore` reads no Redis `TIME` in any script.
The runtime reads the Host clock once per call and passes that value to every
store call. Physical key lifetimes stay relative on the Redis clock; logical
deadlines are the caller's time plus a TTL. The existing interfaces are
unchanged (this Decision's own rule); a Host store that does not implement the
capabilities keeps its own time source. The Decision above is not changed by
this note. Evidence: #154 (phase 1) and #156 (the non-throwing mixes); Owner
decision on #153; Lead rulings LT-P2-01 … 12 on #158.

**Application note (2026-10-29, #160 fix (a3), PR #171).** A legacy
generation-bound score (no stored `expiresAt`, hence no generation) carries no
logical expiry of its own: the lifecycle read maps its remaining life onto the
caller's timeline, `⌈(now + PTTL) / 1000⌉`. That derived value is a reading of
the caller's clock, not state, so it is not part of the staleness fence. The
lifecycle mutation fences a legacy source by its value, its `updatedAt` and the
absence of a generation alone, never derives the expiry a second time, and on the
first applied mutation writes the snapshot's `expiresAt` — the one the read
observed — as the logical expiry. Before this note the mutation derived it again
from its own caller time and rejected a valid snapshot whenever a second boundary
fell between the two readings. Phase 3 of #160 (PR #170) measured it: 22, 25 and
31 not applied per 100,000 natural legacy mutations; 41 per 200,000 with one held
caller reading for both calls (the production path); and 24.29 % with the key's
deadline forced 1 ms into a second. The counted run of this PR measured 0 of
300,000, 0 of 200,000 and 0 of 10,000 in the same arms. Two contract consequences,
accepted by the Lead (LT-a3-02, LT-a3-03):

1. A change of a legacy key's TTL alone between the read and the mutation, with
   the same value and `updatedAt`, is not staleness: the mutation applies and
   writes the read's expiry. Only a concurrent pre-DEC-007 writer can cause it,
   which DEC-007 rules out. The in-memory reference store and the example store
   keep an absolute expiry and still compare it, so they differ here.
2. The legacy expiry comes from the snapshot with no upper bound, because
   bounding it would need Redis `TIME`, which rule 1 rules out. The snapshot is
   the authority exactly as it is for a generated score; a hand-made snapshot is a
   caller contract violation in both cases. A snapshot expiry that is not a
   canonical positive integer, or is before the observed `updatedAt`, is an
   explicit failure before any write.

The Decision above is not changed by this note. Evidence:
`investigations/flaky-160/phase3-k4-hand/REPORT.md` and
`investigations/flaky-160/fix-a3/REPORT.md`; Lead rulings LT-a3-01 … 09 on #171.

## Supersedes

DEC-006

## Superseded By

None.
