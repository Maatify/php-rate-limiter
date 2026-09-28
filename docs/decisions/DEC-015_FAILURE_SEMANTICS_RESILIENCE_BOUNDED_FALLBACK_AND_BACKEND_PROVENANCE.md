# DEC-015 — Failure Semantics Resilience, Bounded Local Fallback, and Backend Failure Provenance

## Decision ID

`DEC-015`

## Status

`ACTIVE`

## Date

2026-09-28

## Decision Authority

Owner-authorized Gate 11 material decision under PR #74, recorded in Owner
Authority comment `5869118594`.

## Scope / Concern

Resilience of the Production Default circuit path during full persistence
outage, deterministic bounds for process-local fallback state, and explicit
classification of operational backend failures.

## Context

The prior shared persistent circuit path had no survivable state owner when
the full persistence topology was unavailable. A backend failure could be
classified for policy fallback, but the circuit itself could not retain its
trip, probe lease, epoch, or guard state. The first remediation therefore
introduced process-local emergency state, but its first handoff implementation
incorrectly discarded that state as soon as persistent storage responded.

The emergency owner is required because the Production Default composition
must remain usable without a second Host backend. The local fallback path also
needs a fixed cardinality bound because a persistence outage must not turn
request-controlled subjects into unbounded process memory. Explicit typed
backend provenance is required because treating malformed state, invalid
input, programming errors, or arbitrary Host throwables as infrastructure
outages could grant security-sensitive fallback allowance.

Storage restoration is therefore not circuit recovery. The `CircuitBreaker`
owns a backend-neutral conservative handoff: it reconciles known emergency and
persistent state, preserves the more protective active semantics and
authoritative timestamps, persists that state, and only then returns ownership
to the persistent state machine. Handoff is not a transition and emits no
synthetic signal; locked OPEN → HALF_OPEN → CLOSED recovery remains the only
recovery path.

## Decision

Persistent/Redis-backed circuit state remains the normal circuit path and
`RateLimiterBuilder::fromFullCapabilityStore()` remains usable without a
second Host-supplied persistence backend. If circuit persistence is itself
unavailable, the package may use a bounded process-local emergency circuit
temporarily. Emergency state exists only to preserve infrastructure-failure
survivability; it is not a new public failure mode. Existing `FAIL_CLOSED`,
`FAIL_OPEN`, and `DEGRADED_MODE` semantics remain authoritative. When
persistent circuit infrastructure becomes available, the CircuitBreaker
conservatively reconciles emergency and persistent state, persists the
authoritative active protection state, and only then returns execution to the
normal persistent path. If that handoff cannot be safely persisted, emergency
state remains authoritative. Emergency state contains no request-controlled
unbounded cardinality, and it cannot grant unlimited authentication or API
allowance.

Process-local fallback state is bounded at **4096 tracked subjects per policy
and fallback dimension**. The capacity is package-owned and not
Host-configurable in this remediation. Active entries are never evicted. Once
capacity is reached, previously unseen subjects use one conservative overflow
bucket for that policy/dimension/window. The overflow bucket uses the
applicable rule/window and never creates additional allowance or resets an
existing tracked subject. Expired windows are removed during normal fallback
activity so historical windows do not accumulate indefinitely.

Failure fallback and circuit accounting are entered only for the explicit
typed operational backend-failure contract represented by
`BackendFailureException`. Official infrastructure adapters classify eligible
availability, transport, and command-execution failures into that type. Input,
configuration, capability, contract, malformed persisted state, invariant,
programming, `TypeError`, unknown `Throwable`, and arbitrary untyped Host
store exceptions remain their actual exception contracts: they do not increment
the circuit, enter `FAIL_OPEN` or `DEGRADED_MODE`, or create authentication
allowance through fallback. Custom Host persistence implementations must use
the typed contract when they want an infrastructure outage to participate in
package backend-failure semantics.

The initial `CLOSED → OPEN` transition is not a re-entry. Only genuine
`HALF_OPEN → OPEN` transitions consume the locked re-entry allowance, and the
existing two-entry/30-minute guard, 600-second duration, signals, probe
suppression, and retry semantics remain unchanged.

`FailureStateDTO::failureCount` is summarized at read time from timestamps
still inside the existing inclusive 10-second trip window. Read-side
summarization does not persist a mutation.

## Rationale

The emergency circuit closes the specific survivability gap in the shared
Production Default storage topology without adding Host wiring or a second
public failure model. A fixed admission bound and conservative overflow keep
local fallback finite without allowing new subjects to reset active protection.
Typed provenance prevents invalid inputs and package/Host contract failures
from becoming security-sensitive degraded allowances.

## Consequences

- `BackendFailureException` is the explicit package boundary for operational
  backend failures.
- The Host-owned Redis executor boundary classifies known transport/backend
  outages explicitly; the Redis store preserves that provenance and malformed
  state remains an explicit package exception.
- The circuit can continue bounded state-machine behavior while its persistent
  state boundary is unavailable and resumes the persistent path after recovery.
- Existing public policies, failure modes, numeric circuit constants, and
  fallback rule values remain unchanged.

## Supersedes

None.

## Superseded By

None.

## Canonical Contract / Current Owner

`docs/FAILURE_SEMANTICS.md`, `src/Exception/BackendFailureException.php`,
`CircuitBreaker`, `LocalFallbackLimiter`, `RateLimiterEngine`, and the official
Redis adapter.
