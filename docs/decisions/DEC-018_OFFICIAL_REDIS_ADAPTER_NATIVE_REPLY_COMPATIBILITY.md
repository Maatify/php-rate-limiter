# DEC-018 — Official Redis Adapter Native Reply Compatibility

## Decision ID

`DEC-018`

## Status

`ACTIVE`

## Date

2026-10-06

## Decision Authority

Owner-approved RC2 remediation direction for Issue #91, executed in Draft PR #92
and extended after Lead review finding F92-02.

## Scope / Concern

How the **official Redis adapter** (`src/Repository/Redis/*`, specifically
`RedisFullCapabilityStore`) interprets native/raw replies returned by the
Host-owned `RedisCommandExecutorInterface`. This decision is limited to the
official Redis adapter. It does not touch any generic storage contract.

## Context

Issue #91: `RedisFullCapabilityStore::isHealthy()` compared the raw `PING`
result with `'PONG'` only, but `ext-redis` (phpredis) `rawCommand('PING')`
returns `bool(true)`, so a healthy Redis was reported unhealthy and circuit
recovery and `backendHealthy` were wrong.

Lead finding F92-02 then proved a second representation difference:
phpredis `rawCommand('GET', <missing key>)` returns `false`. Finding F92-03
showed that this `false` is ambiguous: phpredis can also return `false` for a
Redis ERROR reply such as `WRONGTYPE`. Treating a bare client `false` as absent
would turn a corrupted or wrong-type persisted circuit state into a normal
absent state, which violates DEC-015 failure provenance. A Host-side
normalization or a client-specific error API was rejected.

A sweep of every client-side raw command the adapter issues (`GET`, `SET`,
`PING`, `TIME`, `EVAL`), by running the whole real-Redis suite through direct
phpredis `rawCommand()`, found no other representation difference that changes
package semantics. (Commands inside Lua are unaffected by client representation.)

## Decision

1. The decision applies to the official Redis adapter only. Generic storage
   contracts (`RateLimitStoreInterface`, `CircuitBreakerStoreInterface`,
   `FullCapabilityStoreInterface`), `RateLimiterEngine`, `EvaluationPipeline`,
   `CircuitBreaker`, and generic DTOs do not change and know nothing about
   Redis, RESP, phpredis, or reply shapes. Future backends are not constrained.
2. `RedisCommandExecutorInterface` remains the Host-owned, client-agnostic
   boundary. The Host returns the client's native/raw result and is **not**
   required to normalize it (for example `GET false → null` or
   `PING true → 'PONG'`).
3. The official Redis adapter owns the interpretation of the supported client
   reply representations, only for the operations it uses, through small
   command-specific internal helpers. No generic reply framework or
   normalization layer is introduced.
4. `PING` is healthy only when strictly identical to `'PONG'` or `true`.
5. A direct client `GET` `false` is ambiguous and is **not** used as the
   semantic absence contract. `RedisFullCapabilityStore::load()` reads circuit
   state with a package-owned, read-only, atomic `EVAL` primitive that runs
   `redis.pcall('GET', key)` and returns a tagged reply, so NIL and ERROR stay
   distinguishable before any client representation: `['absent']`,
   `['value', <payload>]`, or `['error', <Redis error text>]`. One command, one
   round trip, no storage migration. The PHP side accepts exactly those tuples
   (strict identity, list shape, exact size, string payload). `['absent']` is
   absent state; `['value', string]` continues the existing JSON/state
   validation; `['error', string]` is an explicit `RateLimiterException` that is
   not `BackendFailureException`, not absent, and not fallback; anything else is
   an explicit malformed-response `RateLimiterException`.
6. Generic truthiness and loose comparison are forbidden. `1`, `'1'`, `'OK'`,
   `'true'`, `[]`, objects, and other values are not healthy for `PING`.
7. `BackendFailureException` remains the known operational backend failure and
   yields an unhealthy `PING` result. `TypeError`, unknown throwables, and
   programming/protocol failures propagate unchanged. DEC-015 failure
   provenance is unchanged.
8. `ext-redis` is not a runtime dependency. No logger, Failure Signal, or public
   observability contract is added.
9. Support is claimed only for the proven direct phpredis `rawCommand()` and
   string-reply (RESP) executors, not for other clients or topologies.

## Relationship to Other Decisions

Complements DEC-015 (failure provenance) and DEC-016 (official Redis store
contract). Supersedes neither.

## Rationale

Representation differences belong to the adapter that talks to Redis. Keeping
the interpretation inside the official adapter keeps Core backend-agnostic and
spares every Host from reimplementing package semantics, while staying bounded
to the two representations proven by real-driver evidence.

## Consequences

Direct phpredis `rawCommand()` works on an empty Redis from the first request, and a wrong-type circuit key fails explicitly instead of being hidden.
The proof is a strict reply matrix, real Redis + real ext-redis regressions
(empty-Redis first request and a real `WRONGTYPE` circuit key), Operational Read and circuit recovery
regressions, and the Consumer Verification Harness with a direct raw bridge.

## Supersedes

None

## Superseded By

None
