# DEC-006 — Built-in Redis Full-Capability Store

## Decision ID

`DEC-006`

## Status

`ACTIVE`

## Date

2026-09-23

## Decision Authority

Owner-approved WU-S4-02B contract.

## Scope / Concern

Official Redis placement and dependency boundary for the full-capability store.

## Context

DEC-005 established the aggregate storage boundary while intentionally deferring
the official backend implementation. The package now needs a built-in Redis
adapter without making Redis a requirement for consumers that choose another
backend.

## Decision

- The official Redis implementation is shipped inside `maatify/php-rate-limiter`
  as `Maatify\\RateLimiter\\Repository\\Redis\\RedisFullCapabilityStore`.
- `FullCapabilityStoreInterface` and `RateLimiterBuilder::fromFullCapabilityStore()`
  remain unchanged and accept any valid implementation.
- Redis is an implementation detail, not the core abstraction. Other backends
  may implement the same aggregate or individual capability contracts.
- The package does not require `ext-redis`, Predis, or another Redis client at
  runtime. The Host owns the client and connection lifecycle and supplies a
  `RedisCommandExecutorInterface`; `CallableRedisCommandExecutor` is the
  package-owned bridge for raw command callables.
- The executor boundary carries Redis commands only and owns no connection,
  retry, pooling, authentication, discovery, failover, logging, or policy logic.
- The first supported topology is one logical non-clustered Redis server.
  Redis Cluster, Sentinel management, failover orchestration, and client retry
  strategies are outside this decision.
- Atomic guarantees use Redis native commands and package-owned Lua scripts,
  with Redis server time inside atomic operations.
- Physical keys are package-prefixed, namespace-isolated, deterministic, opaque
  to Host identity, and separated by state family. Their exact layout is an
  internal implementation detail.
- Real-Redis integration tests are required and are part of the canonical
  Integration test entrypoint.

## Rationale

Keeping the adapter in the package makes the official implementation discoverable
while preserving the backend-agnostic contracts and installation freedom of the
core. A command-executor boundary lets each Host select and own its Redis client
without leaking a client library into Composer runtime dependencies.

## Consequences

Consumers selecting Redis must provide a reachable non-clustered Redis service
and a command executor. Consumers selecting another backend do not need Redis or
its PHP extension. Adapter behavior must prove fixed-TTL, rotation, budget,
probe-lease, and hard-block-cycle atomicity against real Redis.

## Supersedes

DEC-005

## Superseded By

None.
