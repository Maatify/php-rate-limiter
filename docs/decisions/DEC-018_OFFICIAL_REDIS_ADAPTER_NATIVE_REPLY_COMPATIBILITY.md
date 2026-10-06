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

Lead finding F92-02 then proved a second representation difference of the same
nature: phpredis `rawCommand('GET', <missing key>)` returns `false` where the
adapter only understood `null`. `RedisFullCapabilityStore::load()` threw
`Malformed circuit-breaker response`, so the first request on an empty Redis
failed through a direct phpredis executor. A Host-side workaround was rejected.

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
5. A `GET` reply for a missing circuit-state key is absent when strictly `null`
   or `false`; an existing value is a `string`. Any other shape is an explicit
   `RateLimiterException` (malformed response).
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

Direct phpredis `rawCommand()` works on an empty Redis from the first request.
The proof is a strict reply matrix, real Redis + real ext-redis regressions
(including empty-Redis first request), Operational Read and circuit recovery
regressions, and the Consumer Verification Harness with a direct raw bridge.

## Supersedes

None

## Superseded By

None
