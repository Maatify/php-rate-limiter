# DEC-011 — Typed Failure Fallback Profiles for Reusable Policies

`DEC-011`

**Status:** ACTIVE
**Date:** 2026-09-26
**Decision authority:** Lead-approved technical decision under Owner-directed WU-S4-03C remediation
**Scope / Concern:** Typed, bounded backend-failure fallback profiles for reusable policies and their policy-identity state isolation.
**Supersedes:** None
**Superseded By:** None
**Canonical owner:** `docs/FAILURE_SEMANTICS.md`, typed fallback profile contracts, `LocalFallbackLimiter`, and failure resolution runtime

## Context

DEC-008 makes reusable policy behavior opt-in through typed capabilities, but
normal-runtime capabilities do not uniquely determine the bounded local
fallback profile. Fallback therefore needs its own finite public contract so a
custom policy cannot inherit Login, OTP, or API guardrails from numeric budget
values, route names, or an official preset identity.

## Decision

Backend-failure fallback is a separate typed concern from normal-runtime
`PolicyCapability` classification. Policies that can enter bounded local
fallback explicitly implement `FailureFallbackProfileProviderInterface` and
return one finite package-owned `FailureFallbackProfile` enum case.

The only profiles are:

| Profile | Account cap | IP-prefix cap | IP-prefix + normalized UA cap | Window | Max degraded level |
| --- | --- | --- | --- | --- | --- |
| `AUTHENTICATION_PRIMARY` | 3 | 20 | N/A | 600 seconds | L2 |
| `AUTHENTICATION_STEP_UP` | 2 | 10 | N/A | 900 seconds | L2 |
| `API_OVERUSE` | N/A | 120 | 60 | 60 seconds | N/A |

The values are package-owned locked constants. Hosts cannot provide arbitrary
numeric fallback settings or free-form profile names.

Official mappings are `LoginProtectionPolicy` to
`AUTHENTICATION_PRIMARY`, `OtpProtectionPolicy` to
`AUTHENTICATION_STEP_UP`, and `ApiHeavyProtectionPolicy` to `API_OVERUSE`.

Registration rejects incompatible capability/profile combinations and rejects
authentication policies that do not provide the required bounded fail-closed
contract. A policy without a valid profile is never served as an unbounded
degraded fallback; fail-closed remains the safe effective behavior.

Local fallback state is namespaced by both policy identity and profile so that
different reusable policies cannot consume each other's process-local counters.

## Rationale

Separating fallback intent from ordinary policy capabilities keeps the public
extension model explicit and prevents a host-controlled threshold or policy
name from silently changing outage guardrails. Package-owned finite profiles
also make the official Login, OTP, and API limits reviewable and stable.

## Consequences

- Hosts must declare a compatible typed fallback profile when a policy can use
  bounded local fallback.
- API-overuse policies must expose positive, monotonic K1/K2/K3 thresholds and
  a positive access delta before registration succeeds.
- A policy without a valid profile is fail-closed during degraded evaluation;
  it never receives an unbounded local allowance.
- Policy identity is part of the process-local fallback namespace.
