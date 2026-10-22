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

## Supersedes

DEC-006

## Superseded By

None.
