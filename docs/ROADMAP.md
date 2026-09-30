# Future Upgrade Roadmap

**Status:** Planning only
**Scope:** Post-first-RC capability and quality upgrades
**Last reviewed:** 2026-09-27

This roadmap preserves upgrade candidates that were deliberately deferred during the pre-release comparative review so they are not lost when branches and pull requests are closed.

It is **not** a runtime contract, Public API contract, release commitment, or delivery schedule. Current supported behavior remains owned by the Package Reference, active Decision Records, current semantic specifications, and runtime source.

A roadmap item moves into implementation only after a fresh Owner-approved review.

## Status Model

- **COMPLETED-PRE-RC** — adopted before the first release candidate.
- **POST-RC-CANDIDATE** — potentially valuable, intentionally deferred, and requires fresh design/evidence before implementation.
- **REJECTED-FOR-DIRECTION** — reviewed and intentionally not planned because it conflicts with package ownership or invariants.
- **ACTIVE-WORK** — may be used only after an Owner-approved Work Unit explicitly promotes a candidate.

No roadmap entry has an implied target version or date.

## Completed Before the First RC

### Weighted / Variable Simple Fixed-Window Consumption

**Status:** COMPLETED-PRE-RC

DEC-013 evolved simple fixed-window consumption from unit-only cost to a positive caller-supplied cost with a default of `1`.

The implemented contract preserves:

- one `consume()` operation;
- unchanged fixed-window identity and reset boundaries;
- exact persisted weighted counts;
- rotation continuity;
- typed `FAIL_CLOSED` behavior;
- read-only Operational Read;
- no token-bucket, reservation, sliding-window, or compound-quota semantics.

This item is recorded here so future reviews do not re-propose it as deferred work.

## Post-RC Capability Candidates

### Calendar-Aligned Fixed Windows

**Status:** POST-RC-CANDIDATE

**Potential value:** Daily, hourly, billing-cycle, fiscal, and other wall-clock-aligned quotas.

**Why deferred:** The current fixed-window contract is first-hit aligned. Calendar alignment needs explicit boundary, timezone, clock, reset, rotation, and backend-atomicity semantics.

**Before implementation:** Define a dedicated semantic contract and prove behavior around boundary transitions, clock sources, key rotation, and Redis persistence.

### Sliding Window

**Status:** POST-RC-CANDIDATE

**Potential value:** Smoother rate enforcement around fixed-window boundaries and better burst distribution.

**Why deferred:** It introduces a distinct algorithm and persisted-state model rather than extending the existing fixed-window primitive.

**Before implementation:** Refresh comparative evidence, select one stable semantic model, define storage capability requirements, and prove atomicity and bounded state.

### Token Bucket / GCRA / Burst-Refill Limiting

**Status:** POST-RC-CANDIDATE

**Potential value:** Controlled bursts, refill-based capacity, and broader general-purpose throttling.

**Why deferred:** Refill arithmetic, time semantics, persisted token state, precision, race handling, and backend atomicity require a dedicated design.

**Before implementation:** Decide the algorithm family first; do not expose multiple equivalent algorithms without a concrete reusable need.

### Reservation / Future Capacity

**Status:** POST-RC-CANDIDATE

**Potential value:** Workers, outbound providers, scheduled work, and consumers that need to know when capacity will become available.

**Why deferred:** Reservation introduces future-capacity, wait, debt, cancellation, expiry, and concurrency semantics that do not belong inside the current atomic `consume()` contract.

**Before implementation:** Define whether reservation is package-owned state or a read-only forecast and prove that it cannot weaken enforcement.

### Compound / Multi-Window / Global Quotas

**Status:** POST-RC-CANDIDATE

**Potential value:** Per-second plus per-minute plus per-day limits, shared tenant quotas, and coordinated global limits.

**Why deferred:** Atomic multi-limit mutation, decision precedence, partial failure, retry/reset selection, and distributed ownership require their own contract.

**Before implementation:** Define one authoritative atomicity model and failure behavior before introducing public composition APIs.

### Shared-Quota Drain Prevention

**Status:** POST-RC-CANDIDATE

**Potential value:** Prevent one actor, route, tenant, or identity class from exhausting a quota shared by a wider population.

**Why deferred:** The problem only becomes concrete once package-owned shared or compound quotas exist.

**Before implementation:** Design together with compound/global quotas, including fairness, NAT/shared-IP effects, privacy, and anti-starvation behavior.

### Cross-Policy / Global Protection

**Status:** POST-RC-CANDIDATE

**Potential value:** Coordinated protection across several policies or traffic classes rather than isolated policy budgets.

**Why deferred:** Cross-policy state ownership, identity correlation, precedence, shared infrastructure, and Host/package boundaries are material design questions.

**Before implementation:** Prove a reusable package-owned need that cannot be expressed through current Host orchestration and typed policies.

### Simple-Throttling Availability Fallback

**Status:** POST-RC-CANDIDATE

**Potential value:** Availability-oriented behavior when the simple-throttling backend is unavailable.

**Why deferred:** Simple throttling intentionally remains `FAIL_CLOSED`. Any `FAIL_OPEN` or local fallback is security-sensitive and must be bounded and typed.

**Before implementation:** Define a dedicated fallback decision contract, failure observability, bounded local state, and policy opt-in rules. Do not copy score-policy fallback semantics blindly.

## Post-RC Backend and Verification Candidates

### Reusable Backend Conformance / Certification Kit

**Status:** POST-RC-CANDIDATE

**Potential value:** Give third-party `FullCapabilityStoreInterface` implementations a reusable way to prove atomicity, rotation, lifecycle, malformed-state, and failure semantics.

**Why deferred:** Repository test helpers under `autoload-dev` are not a supported consumer API. A conformance kit needs an intentional packaging and compatibility contract.

**Before implementation:** Decide whether it is shipped in the main package, a test namespace, or a separate package; define required capability profiles and version compatibility.

### Official MySQL / MariaDB Adapter

**Status:** POST-RC-CANDIDATE

**Potential value:** First-party relational backend for consumers that do not operate Redis.

**Why deferred:** A complete official adapter must prove locking/transaction behavior and every required capability, not only basic counters.

**Before implementation:** Map all current atomic contracts to database transactions/locking and run the same conformance evidence as Redis.

### Official MongoDB Adapter

**Status:** POST-RC-CANDIDATE

**Potential value:** First-party document-store backend.

**Why deferred:** Atomic document updates, TTL behavior, indexes, rotation, lifecycle evidence, and failure semantics need full capability proof.

**Before implementation:** Define the data model and prove every package-required atomic operation rather than implementing only the simple path.

### Generic Stress / Soak / Performance Qualification

**Status:** POST-RC-CANDIDATE

**Potential value:** Repeatable evidence for throughput, race behavior, resource use, and long-running stability.

**Why deferred:** No package SLA has been defined yet, and pre-RC correctness/concurrency-sensitive paths already have focused integration evidence.

**Before implementation:** Define reproducible scenarios, measurable thresholds, supported environments, and which results are release gates versus informational benchmarks.

## Rejected for Package Direction

### Generic Reset / Unblock Mutation API

**Status:** REJECTED-FOR-DIRECTION

The package intentionally keeps Operational Read read-only and owns lifecycle/security invariants. A generic external reset/unblock mutation could bypass punishment, generation, decay, or re-entry semantics.

Reconsider only if a future reusable contract can preserve those invariants without exposing backend/private lifecycle state.

### Package-Owned Shadow Mode

**Status:** REJECTED-FOR-DIRECTION

The package returns typed decisions; the Host owns transport and whether/how to reject a request. A package-level shadow-mode switch would move Host admission/presentation ownership into the package without a demonstrated reusable need.

Observability or simulation features may be studied independently, but should not be implemented by weakening the enforcement result contract.

## Comparative Study Provenance

The pre-RC uplift review used the following source identities as comparative evidence:

| Source | Historical study reference |
| --- | --- |
| `Maatify/rate-limiter` | `fa7ec71c0dd7ad7acfad281ef9db6d7c84e0a576` |
| `symfony/rate-limiter` | `39f140598fa9e825950dad25830b88607fe087c8` |
| `bucket4j/bucket4j` | `8f6e185fa4c577be8d0722cc1ef804bbcc2a6a95` |
| `laravel/framework` | `bddd0646678a127f75623339236e75b4939070e5` |
| `resilience4j/resilience4j` | `7e3ab5252ed380b596e25240f19376a4435570b8` |
| `go-redis/redis_rate` | `8eadf45ee4d9d7a53189c2a968ead40521a76322` |
| `envoyproxy/ratelimit` | `0482748eb309e09b9f45e7aad406543cd7bec264` |

These references are historical evidence only. Before promoting any candidate to active work, refresh the comparison against current upstream versions and the package's then-current architecture.

## Promotion Gate for Future Work

Before a POST-RC-CANDIDATE becomes implementation work:

1. Re-verify the current package contract and the original reason for deferral.
2. Refresh relevant external comparative evidence; do not adopt a feature solely because another library has it.
3. Prove a reusable package-level need independent of one Host implementation.
4. Classify the change as additive, material, breaking, or rejected.
5. Create or supersede a durable Decision Record when public semantics or ownership materially change.
6. Define backend capability, atomicity, rotation, failure, privacy, and Operational Read implications.
7. Define the required contract/documentation updates and identify any package,
   runtime, or protocol version impact only when such an impact is real and
   applicable.
8. Require focused unit/system/integration evidence and real backend evidence where applicable.
9. Extend Consumer Verification when the public installed-package contract changes.
10. Run Real Host validation when the change materially affects Host integration.
11. Obtain Owner approval before merge, release, or publication.

## Maintenance Rule

This roadmap is the durable planning source for deferred package upgrades.

When a candidate changes state:

- update this file in the same Work Unit that makes the decision;
- link the relevant Decision Record when one exists;
- move completed work to the completed section or mark it completed in place;
- preserve rejected-direction rationale unless a later Owner-approved decision explicitly reopens it;
- do not use closed branches, PR descriptions, or chat history as the only record of future work.
