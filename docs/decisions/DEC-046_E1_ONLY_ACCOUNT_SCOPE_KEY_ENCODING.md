# DEC-046 — E1 as the Sole Account-Scope Key Derivation

## Decision ID

`DEC-046`

## Status

`ACTIVE` — Owner direction approved on 2026-11-25 in [#284](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7155790356); bounded G1 design accepted and implementation authorized by the Lead in [#284](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7156769415). Implementation remains subject to independent Lead review; this record is not implementation or release acceptance.

## Date

2026-11-25

## Decision Authority / Deciders

Project Owner (direction and pre-Stable compatibility boundary); Technical Lead (the bounded G1 design and execution assignment).

## Scope / Concern

The sole derivation and closed composition of account-bearing Set A identities before rc.4. G2/G3, admission encoding, epoch/cohort policy, non-account framing and live migration are not authorized by this record.

## Context

DEC-044 introduced opt-in E1 after the account-field delimiter ambiguity was established. The Owner elected to remove the defective V2 account derivation before the first Stable release instead of maintaining both implementations or building an unrequested live migration. The accepted design and correction are recorded in #284 comments 7156192985 and 7156743430.

## Decision

1. E1 alone derives K4/K4P, K5/K5p, micro-cap, budget-cooldown and registered auxiliary account identities. Its framing, purpose registry, domain separation, byte-length bound, logical key form and vectors are unchanged. The V2 implementation, selector, enum and deriver injection are removed.
2. The writer and operational reader use the same final internal implementation, each constructed from its own validated environment scope. Object identity of the immutable deriver is not a contract.
3. The pipeline constructs BudgetTracker and AntiEquilibriumGate on the actual account views and EphemeralBucket on the correlation root. Advanced callers provide each existing clock dependency explicitly: null Gate/Bucket clocks remain backend-time paths. Store-bound helper injection is removed, without changing the standalone helper contracts.
4. Root/view binding is explicit and structurally validated before package backend commands. The exact validated handles are retained through resource binding and execution. Custom adapter semantics require conformance evidence; a type check is not proof of shared transaction state. Host-ownedResources methods are not trusted.
5. The layout-bound view remains a capability of the same store. Plain-string root methods, out-of-scope physical families, ownership, request atomic save, expiry and ACL contracts remain unchanged. Account-key registration and grammar-checked snapshot adoption remain mandatory.
6. Current/Previous secret and material generations remain within E1. Writes target Current; Previous is read-only; atomic budget seeding and lifecycle/cycle continuity remain. There is no dual-read, seed or migration between account encodings.

## Rationale

Removing the ambiguous account derivation reduces the supported runtime surface before Stable. Closed helper construction prevents account budget/watch state from silently using another layout. Explicit clock dependencies preserve authority without exposing store introspection or a generic composition API.

## Consequences

This is a breaking pre-Stable change to selection, advanced construction and required store support. A new isolated traffic space can start on E1 after store/ACL setup. Moving existing V2 traffic starts fresh Set A and derivative Set B identities, losing visibility of its blocks, scores, budgets and history; this is not a supported live upgrade or rollback, and mixed workers are not supported. No Redis data or historical evidence is deleted. Existing E1 bytes remain valid, but this is not general deployment qualification.

Adjacent A1–A4 remain release blockers. This record does not authorize F1/A1, an epoch/cohort change, loss of other families' state, merge, tag or publication. #180 remains open through published-artifact verification.

## Canonical Contract / Current Owner

`docs/KEY_STRATEGY.md`, `docs/KEY_SCOPE_ENCODING_E1.md`, the root Package Reference and Usage Guide. The pipeline, operational reader, builder and official Redis adapter implement the applicable boundaries.

## Supersedes

DEC-044.

## Superseded By

None.
