# DEC-036 — Atomic Outcome Claim and State Mutation for the Admission Layer

## Decision ID

`DEC-036`

## Status

`ACTIVE` — activated 2026-11-11 in [PR #203](https://github.com/Maatify/php-rate-limiter/pull/203) on the Owner’s explicit approval. The activation commit records the authorization.
Earlier status: `PROPOSED` under Lead ruling LT-185-36b ([#185 comment 6781728109](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6781728109)).

## Date

Proposed 2026-11-10. Activated 2026-11-11.

## Decision Authority

**Owner**, approving the atomic outcome contract and authorizing activation on PR #203 on 2026-11-11. The Lead selected Option (A) in LT-185-36b ([#185 comment 6781728109](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6781728109)).

## Scope / Concern

Atomic outcome claim and admission state mutation; dedicated interface `AtomicAdmissionPermitStoreInterface` extending `AdmissionPermitStoreInterface`; single Lua script execution (`CLAIM_WITH_OUTCOME`); no two-step fallback.

## Context

In the initial admission layer design, recording an outcome involved a two-step sequence: first claiming the permit via `claimAdmissionPermit`, and then mutating admission scopes via `applyAdmissionOutcome`. If a backend failure or network interruption occurred after the permit was marked `CLAIMED` but before the scopes were updated, the permit was left in a claimed state without the corresponding state mutations (e.g. failure set additions or counter refunds). Any subsequent retry by the Host received `permit_duplicate`, preventing the intended state mutations from ever being applied (F-185-36).

Under LT-185-36b, the atomic operation commits permit claim and admission outcome together. The score pipeline remains a separate stage under LT-185-36a.

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
   - **Phase 2 (Scope Validation):** Validate the stored keys the outcome will touch, including their types and relevant canonical scores, before any write. A failure outcome leaves counter and bucket state untouched. Malformed state found during validation returns a Lua error while the permit is still `ISSUED`. Redis does not roll back writes already made by a failed script, so validation precedes the write phase.
   - **Phase 3 (Atomic Writes):** Only when all validations pass and the claim is `ACCEPTED`, the permit is updated to `CLAIMED` with the recorded outcome, and all scope mutations (counter refunds, failure set additions/pruning, bucket refills) are applied in the same script invocation.

3. **In-Memory and Reference Store Parity:**
   - `AdmissionInMemoryReferenceStore` and `PreSettlementReferenceStoreSliding` implement `AtomicAdmissionPermitStoreInterface`. Validation, outcome behavior and the commit boundary must match the official adapter, with parity still subject to Lead acceptance.
   - Under LT-185-36c, success removes the member of a non-settling set-size scope regardless of lane or scope name. The runtime preserves recorded D8 failures by omitting that scope from a proven-lane success.

4. **Frozen Detector Fixture Preservation:**
   - Historic frozen fixtures (`PreSettlementReferenceStore` and `MergedAdmissionOperations`) are byte-identical to their copies on `main@d0805c76770047338b59e355f857319b9f877f10`. The sliding reference store and the `MergedAdmissionOperationsSliding` test helper carry the candidate atomic operation.

## Rationale

The permit records whether the store accepted an outcome. Applying that outcome in the same store operation keeps the permit and admission scopes consistent when the caller loses the reply to a completed operation. The score pipeline performs separate writes. Replaying it on a duplicate permit could repeat writes whose original responses were lost, so LT-185-36a keeps the existing non-replay rule.

## Consequences

- An opt-in Host using a custom store needs the additive atomic capability at `build()`. The legacy methods remain available on their existing interfaces. The admission runtime invokes the combined method.
- A retry with the original permit can complete if the first invocation never reached the write phase and the retry still reaches admission within the permit deadline. If the first invocation committed and the retry reaches the claimed permit during its retained lifetime, the runtime returns `permit_duplicate`.
- A typed interruption after the admission commit can leave score recording absent or partial. A duplicate outcome does not re-enter that pipeline. See the three outage cases in `docs/FAILURE_SEMANTICS.md` §4.5. Runtime interruption and reference-store parity evidence remain acceptance requirements.

## Relationship to Other Decisions

- **DEC-024:** Keeps the atomic reservation boundary. This decision adds an atomic operation for recording an outcome.
- **DEC-028:** Keeps the permit deadline and grace constants.
- **DEC-029:** Preserves the no-double-recording rule for the separate score pipeline.
- **DEC-033:** Uses the existing sliding scope representation. This decision changes the outcome operation boundary.

## Canonical Contract / Current Owner

`src/Repository/AtomicAdmissionPermitStoreInterface.php` defines the additive capability. `src/Builder/RateLimiterBuilder.php` owns its composition check. `src/Service/AdmissionRuntime.php` owns outcome scope construction and claim dispatch. `src/Repository/Redis/RedisAdmissionOperations.php` implements `CLAIM_WITH_OUTCOME`. Host retry handling is described in `docs/FAILURE_SEMANTICS.md` §4.5 and `docs/guides/USAGE_GUIDE.md`.

## Supersedes

None.

## Superseded By

None.
