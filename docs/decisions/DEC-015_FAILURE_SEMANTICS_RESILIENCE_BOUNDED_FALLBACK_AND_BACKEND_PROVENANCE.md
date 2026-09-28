# DEC-015 — Failure Semantics Resilience, Bounded Local Fallback, and Backend Failure Provenance

## Decision ID

`DEC-015`

## Status

`ACTIVE`

## Date

2026-09-28

## Decision Authority

Owner-authorized Gate 11 clarification under PR #74, recorded in Owner
Authority comments `5872190395` and mirrored at `5872191089`, superseding the
earlier persistent handoff wording.

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

Storage restoration is therefore not circuit recovery. The emergency circuit
is process-local only: while active, it is authoritative for that runtime and
is not reconciled with, replaced by, or written back to persistent storage.
The local state continues through the locked OPEN → HALF_OPEN → CLOSED
recovery path, with no synthetic transition or signal from storage restoration.
For a tripped/protective episode (`OPEN`, `HALF_OPEN`, or active local guard),
genuine local recovery to `CLOSED` is the release point and the next circuit
access resumes normal persistent ownership. A pre-trip `CLOSED` episode is a
second lifecycle form: it remains authoritative while a failure timestamp
satisfies the inclusive `failureAt >= now - 10` trip-window rule, or while
other local protection is active. Once that `CLOSED` episode is quiescent, it
is discarded without an `OPEN`/`HALF_OPEN` recovery sequence and the same load
path resumes normal persistent ownership. This provides no cross-worker or
cross-host emergency consistency guarantee.

## Decision

Persistent/Redis-backed circuit state remains the normal circuit path and
`RateLimiterBuilder::fromFullCapabilityStore()` remains usable without a
second Host-supplied persistence backend. If circuit persistence is itself
unavailable, the package may use a bounded process-local emergency circuit
temporarily. Emergency state exists only to preserve infrastructure-failure
survivability; it is not a new public failure mode. Existing `FAIL_CLOSED`,
`FAIL_OPEN`, and `DEGRADED_MODE` semantics remain authoritative. When
persistent circuit infrastructure becomes available, the active local emergency
state remains authoritative for that runtime and is never merged into,
reconciled into, or written back to persistent storage. It completes the
locked local recovery path when it is a tripped/protective episode and is
discarded only after genuine `CLOSED` recovery. A pre-trip `CLOSED` state is
retained only while live inclusive trip-window evidence (`failureAt >= now -
10`) or active local protection remains; once quiescent, it is discarded and
the same load path resumes persistent ownership without writeback. Emergency
state contains no request-controlled unbounded cardinality, and it cannot
grant unlimited authentication or API allowance.

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
- The circuit can continue bounded process-local state-machine behavior while
  its persistent state boundary is unavailable. No cross-worker or cross-host
  emergency consistency is promised, and no CAS, reconciliation capability,
  handoff API, or second Host backend is required.
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
