# DEC-008 — Policy Capability Classification and Extension Model

## Decision ID

`DEC-008`

## Status

`ACTIVE`

## Date

2026-09-25

## Decision Authority

Owner-approved WU-S4-03C decision under PR #60.

## Scope / Concern

Reusable policy capability classification, official preset semantics, public custom-policy extension behavior, and validation alignment.

## Context

The package exposes a real public custom-policy registration path through `RateLimiterBuilder::withPolicy()`, but the current runtime still selects several reusable behaviors by exact policy name.

The current `EvaluationPipeline` recognizes `login_protection` and `otp_protection` for credential-spray, distributed-account, and trusted-authentication behavior, and recognizes `api_heavy_protection` for API-overuse-specific behavior. A differently named custom policy can be registered successfully while remaining unable to opt into those same reusable semantics through a typed public contract.

The same baseline found that `docs/POLICIES.md` describes broader requirements for auth-related custom policies than `RateLimiterEngine::registerPolicy()` currently enforces generically.

Fresh real-consumer evidence also shows `login_protection` being reused for forgot-password, registration, and authenticated password-change flows. That reuse proves a capability need, but route naming is not package taxonomy.

DEC-007 already demonstrates the preferred capability principle for generation-bound K4 post-punishment re-entry: policy identity does not activate the behavior; an explicit typed opt-in contract does.

## Decision

The package separates:

```text
Policy identity
from
Reusable runtime capabilities
```

`BlockPolicyInterface` remains source-compatible and does not gain new required methods.

Policies that need reusable runtime behavior beyond the base scoring contract opt in through an additive typed capability provider:

```text
PolicyCapabilityProviderInterface
    -> list<PolicyCapabilityEnum>
```

The package-owned capability set for this decision is:

- `CREDENTIAL_SPRAY`
- `DISTRIBUTED_ACCOUNT`
- `TRUSTED_AUTHENTICATION`
- `API_OVERUSE`

The exact PHP representation may use an enum/value object plus the provider interface, but the public contract must remain typed, finite, package-owned, and validation-friendly. Arbitrary free-form capability strings are not accepted.

The existing `PostPunishmentReentryPolicyInterface` remains a separate lifecycle capability contract governed by DEC-007. It is not folded into the classification provider because it carries storage, atomicity, and claim semantics beyond simple runtime classification.

The official presets retain their stable names and current behavior by declaring the capabilities that correspond to their existing semantics:

| Official policy | Required capability declaration |
| --- | --- |
| `login_protection` | `CREDENTIAL_SPRAY`, `DISTRIBUTED_ACCOUNT`, `TRUSTED_AUTHENTICATION`, plus DEC-007 opt-in |
| `otp_protection` | `CREDENTIAL_SPRAY`, `DISTRIBUTED_ACCOUNT`, `TRUSTED_AUTHENTICATION`, plus DEC-007 opt-in |
| `api_heavy_protection` | `API_OVERUSE` |

Runtime behavior must no longer depend on exact policy-name checks for those reusable capabilities.

Policy names remain stable runtime identifiers used for policy selection, state namespacing, observability, and replacement in the builder registry. They do not silently grant reusable behavior.

## Validation Contract

Capability opt-in is explicit and validated.

The runtime validation owner remains package-owned. It must reject a policy whose declared capabilities cannot be supported by its thresholds, deltas, budget configuration, failure semantics, or required storage/runtime capabilities.

The implementation must align runtime validation with the canonical custom-policy rules in `docs/POLICIES.md`. Documentation must not claim generic auth-policy validation that the runtime does not actually enforce.

Capability validation must be based on semantic requirements, not preset names.

DEC-007 validation remains binding:

- generation-bound K4 post-punishment re-entry requires K4;
- K4 thresholds must be positive and monotonic;
- the policy cannot use `FAIL_OPEN`;
- required lifecycle storage must be present;
- any additional authentication-policy invariants required by the canonical policy contract must be enforced for custom opt-in policies as well as official presets.

## Recovery / Registration / Sensitive-Action Consequence

No route-specific preset is added by this decision.

Account-recovery initiation, registration/account-creation abuse, and authenticated sensitive actions must be modeled by threat model and required capabilities.

A semantically named custom policy may opt into the package-owned capabilities it actually needs without borrowing `login_protection` solely to activate hidden runtime branches.

A dedicated new preset remains justified only if a later evidence-based review proves that a reusable threat model has stable package-owned defaults that are materially more than capability composition. Route names alone are not sufficient justification.

## Backward Compatibility

The implementation must preserve:

1. the public identifiers `login_protection`, `otp_protection`, and `api_heavy_protection`;
2. accepted behavior of those official presets;
3. `RateLimiterBuilder::withPolicy()` same-name replacement and new-name append semantics;
4. existing custom `BlockPolicyInterface` implementations that do not opt into extra capabilities;
5. DEC-003 hard-block-cycle and decay-pause semantics;
6. DEC-007 lifecycle semantics and explicit opt-in contract.

A custom policy that declares no extra capabilities continues to receive only the base policy behavior implied by its existing public configuration.

## Rationale

Typed capability declaration makes the public extension model match the actual runtime model.

Keeping exact-name behavior would force hosts to borrow semantically incorrect preset identities. Adding a preset for every recurring route would inflate the catalog without solving extensibility. A typed additive provider preserves compatibility while making behavior explicit, discoverable, and enforceable.

## Consequences

- Name-coupled reusable behavior must be removed from runtime classification.
- Official presets must declare their existing capabilities.
- Custom policies can obtain reusable package behavior without host pipeline forks or reserved-name impersonation.
- Runtime validation must become capability-aware.
- Recovery can move from baseline classification C to target classification B without a route-specific official preset.
- Registration and authenticated sensitive-action families remain custom-policy composition cases unless future evidence justifies a reusable preset.

## Supersedes

None.

## Superseded By

None.

## Canonical Contract / Current Owner

This decision governs the WU-S4-03C capability-model implementation. Until that implementation lands, the existing runtime remains the compatibility baseline; it is not authority for introducing additional name-coupled behavior.
