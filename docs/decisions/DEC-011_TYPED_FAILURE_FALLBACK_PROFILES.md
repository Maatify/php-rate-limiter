# DEC-011 — Typed Failure Fallback Configuration and Ready-to-Use Presets

`DEC-011`

**Status:** ACTIVE
**Date:** 2026-09-26 (amended 2026-09-26)
**Decision authority:** Owner-approved under WU-S4-03C on 2026-09-26.
**Scope / Concern:** Typed, bounded backend-failure fallback configuration for reusable policies and their policy-identity state isolation.
**Supersedes:** None
**Superseded By:** None
**Canonical owner:** `docs/FAILURE_SEMANTICS.md`, the typed fallback configuration contracts, `LocalFallbackLimiter`, and failure resolution runtime

## Context

DEC-008 makes reusable policy behavior opt-in through typed capabilities, but
normal-runtime capabilities do not uniquely determine the bounded local
fallback configuration. Fallback therefore needs its own typed public
contract so a custom policy cannot inherit Login, OTP, or API guardrails from
numeric budget values, route names, or an official preset identity.

The original version of this decision closed that gap by introducing exactly
three named, package-owned profiles (`AUTHENTICATION_PRIMARY`,
`AUTHENTICATION_STEP_UP`, `API_OVERUSE`) as the *only* representable fallback
values, stating that "Hosts cannot provide arbitrary numeric fallback
settings." That design left no way for a direct custom reusable policy to
declare its own bounded fallback numbers: the only representable values were
the three official presets, so a custom policy could only proceed by
impersonating one of them. WU-S4-03C-01 identified this as a design gap
against the package's zero-config/typed-config dual requirement and amends
the decision below without introducing a new DEC and without changing DEC-008.

## Decision (amended)

Backend-failure fallback remains a separate typed concern from normal-runtime
`PolicyCapabilityEnum` classification. The runtime contract is now a **generic
typed configuration**, not a closed set of named profiles:

```text
FailureFallbackConfigurationProviderInterface
    -> FailureFallbackConfigurationDTO { rules: list<FailureFallbackRuleDTO> }

FailureFallbackRuleDTO
    -> dimension: FailureFallbackDimensionEnum (ACCOUNT | IP_PREFIX | IP_PREFIX_NORMALIZED_USER_AGENT)
    -> limit: positive int
    -> windowSeconds: positive int
```

This is the single runtime contract for both official presets and direct
custom policies. `LocalFallbackLimiter` applies exactly the rules an
effective configuration carries; it has no knowledge of policy names, preset
identities, or capability names, and it holds no separate hard-coded copies
of the official numeric values.

**Package-owned zero-configuration presets.** `FailureFallbackProfileEnum` is
retained as an internal factory enum with a `configuration(): FailureFallbackConfigurationDTO`
method. It is the single canonical source of the locked official values:

| Preset | Account cap | IP-prefix cap | IP-prefix + normalized UA cap | Window | Max degraded level |
| --- | --- | --- | --- | --- | --- |
| `AUTHENTICATION_PRIMARY` | 3 | 20 | N/A | 600 seconds | L2 |
| `AUTHENTICATION_STEP_UP` | 2 | 10 | N/A | 900 seconds | L2 |
| `API_OVERUSE` | N/A | 120 | 60 | 60 seconds | N/A |

`LoginProtectionPolicy`, `OtpProtectionPolicy`, and `ApiHeavyProtectionPolicy`
resolve `AUTHENTICATION_PRIMARY`, `AUTHENTICATION_STEP_UP`, and `API_OVERUSE`
respectively from this single factory and return the resulting generic
configuration from their own `getFailureFallbackConfiguration()`. A Host
using `new LoginProtectionPolicy()`, `new OtpProtectionPolicy()`, or
`new ApiHeavyProtectionPolicy()` performs no additional wiring and observes
unchanged fallback behavior. `FailureFallbackProfileEnum` is not part of the
public provider contract: a direct custom policy never references it and
cannot use it to "borrow" an official identity.

**Host/policy-owned typed configuration.** A direct custom reusable policy
composes its own `FailureFallbackConfigurationDTO` with whatever bounded
rules its threat model requires. Hosts may provide explicit, validated,
positive, bounded numeric fallback settings. Hosts may not provide:

- free-form profile identities (there are none to provide — dimensions are a
  finite typed enum, not a string);
- invalid or unbounded values (zero/negative limits or windows are rejected);
- hidden inference inputs (fallback configuration is never derived from
  `BudgetConfigDTO`, route names, or policy-name string matching).

Registration rejects incompatible capability/configuration combinations and
rejects authentication and API_OVERUSE policies that do not provide their
required bounded dimensions (see Validation below). A policy without a valid
profile is never served as an unbounded degraded fallback; fail-closed
remains the safe effective behavior.

Local fallback state is namespaced by policy identity (`BlockPolicyInterface::getName()`)
so that different reusable policies never consume each other's process-local
counters, even when they declare numerically identical configurations or one
of them reuses an official preset's exact numbers.

## Validation

The package remains the sole validation owner for the effective
configuration, regardless of whether it originated from an official preset
or a direct custom policy:

- every rule's `limit` and `windowSeconds` must be positive;
- a configuration must not declare the same dimension twice;
- a policy declaring `PolicyCapabilityEnum::CREDENTIAL_SPRAY`, `DISTRIBUTED_ACCOUNT`,
  or `TRUSTED_AUTHENTICATION` (or opting into DEC-007 lifecycle) must provide
  a configuration with both `ACCOUNT` and `IP_PREFIX` dimensions;
- a policy declaring `PolicyCapabilityEnum::API_OVERUSE` must provide a
  configuration with both `IP_PREFIX` and `IP_PREFIX_NORMALIZED_USER_AGENT`
  dimensions, and must not declare an `ACCOUNT` dimension (account-level
  enforcement in degraded API mode remains forbidden per
  `docs/FAILURE_SEMANTICS.md` §4.3);
- `FAIL_OPEN` continues to require the `API_OVERUSE` capability and a valid
  bounded configuration.

## Rationale

Separating fallback intent from ordinary policy capabilities keeps the
public extension model explicit and prevents a host-controlled threshold or
policy name from silently changing outage guardrails. A generic typed
configuration, rather than a closed named-profile enum, additionally
preserves this guarantee for direct custom policies without forcing them to
impersonate an official preset identity to obtain any bounded fallback at
all. Package-owned finite presets remain the single source of truth for the
official Login, OTP, and API limits, keeping them reviewable and stable
while the same validated runtime also serves host-owned configurations.

**Ready-to-use path is preserved.** `new LoginProtectionPolicy()`,
`new OtpProtectionPolicy()`, and `new ApiHeavyProtectionPolicy()` require no
fallback-specific constructor argument, factory call, builder configuration,
subclassing, or Host wiring to receive their locked official fallback
behavior.

**Advanced customization is first-class.** A direct custom policy composes
its own bounded `FailureFallbackConfigurationDTO` through the same public
interface official presets use, with numeric values entirely independent of
any official preset.

**Both use one runtime.** `LocalFallbackLimiter` has exactly one evaluation
path for the effective configuration; there is no `OfficialFallbackLimiter`/
`CustomFallbackLimiter` fork and no preset-only runtime restriction.

**Preset values remain package-owned defaults.** The three official caps in
the table above are locked constants inside `FailureFallbackProfileEnum` and are
not parameters a Host can override on the official policies.

**Custom values are host/policy-owned but package-validated.** A direct
custom policy's numeric choices are its own, but the Engine still rejects an
unsafe or malformed configuration before registration succeeds.

## Consequences

- Hosts must declare a compatible typed fallback configuration when a policy
  can use bounded local fallback; official presets declare theirs internally
  with no Host action required.
- API-overuse policies must expose positive, monotonic K1/K2/K3 thresholds,
  a positive access delta, and a bounded `IP_PREFIX` / `IP_PREFIX_NORMALIZED_USER_AGENT`
  fallback configuration (never `ACCOUNT`) before registration succeeds.
- A policy without a valid configuration is fail-closed during degraded
  evaluation; it never receives an unbounded local allowance.
- Policy identity is the process-local fallback namespace; numeric
  configuration content never determines namespace membership.
- `LocalFallbackLimiter` holds no duplicate hard-coded copies of the official
  Login/OTP/API numeric values; those values exist exactly once, inside
  `FailureFallbackProfileEnum`.

## Decision Authority

Owner approval occurred on 2026-09-26 under WU-S4-03C. This amendment,
prepared as technical direction under the Owner-directed WU-S4-03C
remediation track, is Owner-approved. Status is `ACTIVE`.
