# DEC-036 — Atomic Outcome Claim and State Mutation for the Admission Layer

## Decision ID

`DEC-036`

## Status

`PROPOSED` — proposed 2026-11-10 in PR-R (#185) under Lead ruling LT-185-36b ([#185 comment 6781728109](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6781728109)).

## Date

2026-11-10

## Decision Authority

**Lead**, on LT-185-36b ([#185 comment 6781728109](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6781728109)), selecting Option (A) for atomic outcome claim and state mutation.

## Scope / Concern

Atomic outcome claim and admission state mutation; dedicated interface `AtomicAdmissionPermitStoreInterface` extending `AdmissionPermitStoreInterface`; single Lua script execution (`CLAIM_WITH_OUTCOME`); no two-step fallback.

## Context

In the initial admission layer design, recording an outcome involved a two-step sequence: first claiming the permit via `claimAdmissionPermit`, and then mutating admission scopes via `applyAdmissionOutcome`. If a backend failure or network interruption occurred after the permit was marked `CLAIMED` but before the scopes were updated, the permit was left in a claimed state without the corresponding state mutations (e.g. failure set additions or counter refunds). Any subsequent retry by the Host received `permit_duplicate`, preventing the intended state mutations from ever being applied (F-185-36).

Under Lead ruling LT-185-36b, Option (A) was selected to eliminate this vulnerability by unifying permit claim and outcome application into a single atomic operation.

## Decision

1. **Dedicated Atomic Interface:**
   - Introduce `AtomicAdmissionPermitStoreInterface` extending `AdmissionPermitStoreInterface`.
   - Method signature:
     ```php
     public function claimAdmissionPermitWithOutcome(
         string $lane,
         string $subject,
         string $operation,
         AdmissionOutcomeDTO $outcome,
         int $now,
     ): AdmissionPermitClaimEnum;
     ```
   - No two-step fallback: `AdmissionRuntime` strictly requires `AtomicAdmissionPermitStoreInterface`. The builder enforces this requirement for any policy opting into admission.

2. **Atomic Execution Semantics (`CLAIM_WITH_OUTCOME`):**
   - Executed as a single Lua script on Redis (`CLAIM_WITH_OUTCOME`), guaranteeing that permit state transition and scope mutations occur atomically.
   - **Phase 1 (Permit Validation):** The permit key is checked. If the permit is absent, expired beyond grace, mismatched in lane/subject/operation, already claimed, or expired past deadline, the corresponding non-accepted status (`unknown`, `rejected`, `duplicate`, `conflict`, `late`) is returned immediately and **no writes occur**.
   - **Phase 2 (Scope Validation):** All target scopes are inspected and validated for type and canonical score representation before any write. If any scope is malformed, a Lua error reply is returned; Redis rolls back script execution and the permit remains `ISSUED`.
   - **Phase 3 (Atomic Writes):** Only when all validations pass and the claim is `ACCEPTED`, the permit is updated to `CLAIMED` with the recorded outcome, and all scope mutations (counter refunds, failure set additions/pruning, bucket refills) are applied in the same script invocation.

3. **In-Memory and Reference Store Parity:**
   - `AdmissionInMemoryReferenceStore` and `PreSettlementReferenceStoreSliding` implement `AtomicAdmissionPermitStoreInterface` with identical validation and atomic commit semantics.

4. **Frozen Detector Fixture Preservation:**
   - Historic frozen fixtures (`PreSettlementReferenceStore` and `MergedAdmissionOperations`) remain 100% byte-identical to `d6aea4d`. Sliding and atomic parity fixtures (`PreSettlementReferenceStoreSliding` and `MergedAdmissionOperationsSliding`) implement the new atomic capability.
