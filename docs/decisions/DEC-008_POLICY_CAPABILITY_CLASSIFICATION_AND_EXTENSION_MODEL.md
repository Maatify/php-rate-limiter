# DEC-008 — Policy Capability Classification and Extension Model

**Decision ID:** `DEC-008`
**Status:** `PROPOSED`
**Date:** `2026-09-25`
**Decision authority:** Pending WU-S4-03C Lead/Owner decision
**Scope / Concern:** Reusable policy capability classification, official preset semantics, and public custom-policy extension behavior
**Canonical contract / current owner:** Pending; current behavior is distributed across `BlockPolicyInterface`, `RateLimiterBuilder`, `RateLimiterEngine`, `EvaluationPipeline`, and `docs/POLICIES.md`

> This record is a proposal only. It is not implementation authority while its status is `PROPOSED`.

## Context

WU-S4-03C Fresh Lead baseline established that the package exposes a real public custom-policy registration path through `RateLimiterBuilder::withPolicy()`, but several reusable runtime semantics are still selected by exact policy names.

Current runtime classification includes behavior equivalent to:

- credential-spray participation for `login_protection` and `otp_protection`;
- distributed-account participation for those same names;
- trusted-authentication K1 semantics for those same names;
- API-heavy-specific enforcement behavior for `api_heavy_protection`.

Therefore a custom policy with a new, semantically meaningful name can be registered and evaluated, but cannot currently opt into all of the same reusable behavior simply by satisfying a typed public contract.

The same baseline also found that `docs/POLICIES.md` defines requirements for auth-related custom policies more broadly than `RateLimiterEngine::registerPolicy()` currently validates.

Fresh real-consumer evidence shows hosts reusing `login_protection` for forgot-password, registration, and authenticated password-change flows. That reuse is useful evidence, but route naming is not package authority and must not become the package taxonomy.

## Proposed direction

The package should separate:

```text
Policy identity
from
Reusable runtime capabilities
```

Reusable behavior that is intended to be available to more than one official preset should not require a policy to adopt a reserved official policy name merely to activate that behavior.

The official presets must preserve their current semantics and stable identifiers.

A custom policy should be able to declare the reusable capability set it intentionally participates in through typed package-owned contracts or another equally explicit typed public model.

The final design must determine the appropriate capability boundaries before implementation. Candidate concerns include, at minimum:

- credential-spray participation;
- distributed-account participation;
- trusted-authentication behavior;
- API-overuse-specific enforcement behavior.

The exact interface/marker shape is intentionally **not decided by this proposal**.

## Required invariants

Any accepted design must preserve all of the following:

1. `login_protection`, `otp_protection`, and `api_heavy_protection` retain their existing public identifiers and accepted behavior unless separately versioned and approved.
2. DEC-007 remains explicit opt-in through its current lifecycle capability contract; this proposal must not weaken generation-bound K4 semantics.
3. DEC-003 hard-block-cycle and decay-pause semantics remain unchanged.
4. A custom policy must not gain hidden security behavior merely because its name happens to match an undocumented string convention.
5. A host must not need a pipeline fork, host-only marker, parallel storage lifecycle, or duplicated rate-limit engine to obtain reusable package behavior.
6. Runtime validation and canonical policy documentation must describe the same custom-policy requirements.
7. The design must not create a preset for every endpoint or route.

## Coverage implication

This decision is expected to determine whether reusable account-recovery flows can be represented by a semantically named custom policy using package-owned authentication/correlation capabilities, or whether an additional package-owned preset remains justified after the capability model is available.

Registration and account-creation abuse should likewise be judged by threat model and required capabilities rather than by reusing `login_protection` solely because it already activates hidden runtime branches.

## Alternatives under review

### Alternative A — Keep exact-name classification

Preserve current runtime name checks and document that reserved names carry hidden semantic behavior.

This is simple but makes the public custom-policy model incomplete for reusable behaviors and encourages consumers to borrow semantically incorrect preset identities.

### Alternative B — Typed reusable capability model

Preserve stable official policy names while exposing package-owned typed capability declarations for reusable runtime behavior.

This is the leading proposal because it aligns public extensibility with actual runtime behavior without requiring a preset per endpoint.

### Alternative C — Expand the official preset catalog instead

Add presets for every recurring host use-case while leaving name-driven runtime behavior in place.

This risks route/use-case catalog inflation and does not solve the general custom-policy extensibility problem.

## Decision required before implementation

WU-S4-03C must resolve:

- whether Alternative B is accepted;
- the final capability boundaries;
- how those capabilities are represented in the public contract;
- validation rules for custom authentication/security policies;
- backward-compatibility behavior for the three existing official presets.

No runtime implementation may rely on this proposal until the decision is approved and moved to `ACTIVE`.
