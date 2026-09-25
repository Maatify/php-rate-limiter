# DEC-011 — Typed Failure Fallback Profiles for Reusable Policies

`DEC-011`

**Status:** ACTIVE
**Decision authority:** Lead-approved technical decision under Owner-directed WU-S4-03C remediation
**Supersedes:** None
**Superseded By:** None
**Canonical owner:** `docs/FAILURE_SEMANTICS.md`, typed fallback profile contracts, `LocalFallbackLimiter`, and failure resolution runtime

## Decision

Backend-failure fallback is a separate typed concern from normal-runtime
`PolicyCapability` classification. Policies that can enter bounded local
fallback explicitly implement `FailureFallbackProfileProviderInterface` and
return one finite package-owned `FailureFallbackProfile` enum case.

The only profiles are:

| Profile | Account/IP-prefix | IP-prefix + normalized UA | Window | Max degraded level |
| --- | --- | --- | --- | --- |
| `AUTHENTICATION_PRIMARY` | 3 / 600 seconds | 20 / 600 seconds | 600 seconds | L2 |
| `AUTHENTICATION_STEP_UP` | 2 / 900 seconds | 10 / 900 seconds | 900 seconds | L2 |
| `API_OVERUSE` | 120 / 60 seconds | 60 / 60 seconds | 60 seconds | N/A |

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
