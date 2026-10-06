# DEC-018 — Redis Raw-Command Status Reply Compatibility

## Decision ID

`DEC-018`

## Status

`ACTIVE`

## Date

2026-10-06

## Decision Authority

Owner-approved RC2 remediation direction for Issue #91, executed in Draft PR #92.

## Scope / Concern

Health `PING` result representation at the Host-owned
`RedisCommandExecutorInterface` raw-command boundary consumed by
`RedisFullCapabilityStore::isHealthy()`.

## Context

`RedisFullCapabilityStore::isHealthy()` compared the raw `PING` result strictly
with the string `'PONG'`. The executor boundary is Host-owned and
client-agnostic: it returns whatever the Host's client returns. `ext-redis`
(phpredis) `rawCommand('PING')` materializes the Redis simple-string status
reply as `bool(true)`, so a healthy Redis was reported unhealthy (Issue #91).
That blocks circuit-breaker recovery
(`CircuitBreaker::attemptRecoveryProbe()` →
`EvaluationPipeline::isBackendHealthy()` → `isHealthy()`) and makes
`RateLimitOperationalSnapshotDTO::$backendHealthy` falsely `false`.

## Decision

1. `RedisCommandExecutorInterface` remains the Host-owned, client-agnostic
   raw-command boundary. The package does not require every Redis client to
   return RESP simple-string status replies as literal PHP strings.
2. At the health `PING` boundary only, the result is healthy if and only if it
   is strictly identical to one of the two explicitly supported
   representations: the string `'PONG'` or the boolean `true`.
3. Generic truthiness and loose comparison are forbidden. `1`, `'1'`, `'OK'`,
   `'true'`, `[]`, objects, `false`, `null`, and any other value are not
   healthy.
4. `BackendFailureException` remains the known operational backend failure and
   yields an unhealthy result. `TypeError`, unknown/untyped throwables, and
   programming/protocol failures propagate unchanged and are not converted into
   backend-outage semantics (DEC-015 provenance is preserved).
5. No general Redis response-normalization layer is introduced for other
   commands.
6. `ext-redis` does not become a runtime dependency of the package.
7. No logger, Failure Signal, or public observability contract is added for an
   unexpected `PING` representation. An unsupported `PING` result remains an
   explicit unhealthy result under the existing health boundary.

## Relationship to Other Decisions

Complements DEC-015 (failure provenance and the typed
`BackendFailureException` boundary) and DEC-016 (official Redis store
contract). It does not supersede either.

## Rationale

The defect is a representation mismatch, not a backend fault. Accepting the two
exact, documented representations fixes phpredis without weakening the check
into truthiness, without pushing a normalization obligation onto the Host, and
without expanding the failure model.

## Consequences

Hosts using phpredis `rawCommand()` need not map `PING` to `'PONG'`. Hosts
using string-returning clients are unchanged. The health boundary is covered by
a reply matrix, a real Redis + real ext-redis regression, operational-read and
circuit-recovery regressions, and the Consumer Verification Harness.

## Supersedes

None

## Superseded By

None
