# Maatify Rate Limiter Package Reference

**Package:** RateLimiter
**Namespace:** `Maatify\RateLimiter`
**Status:** LOCKED — Architecture Contract
**Spec Version:** `1.13.0`
**Location:** `src/`

This document explains **why** the RateLimiter package is designed the way it is.
It is an architectural contract intended to prevent accidental weakening, incorrect refactors, or scope creep.

Behavioral rules are specified in:
- `docs/DECISION_MATRIX.md`
- `docs/POLICIES.md`
- `docs/DEVICE_FINGERPRINT.md`
- `docs/KEY_STRATEGY.md`
- `docs/FAILURE_SEMANTICS.md`

The [Usage Guide](docs/guides/USAGE_GUIDE.md) is the consumer-facing walkthrough, [`examples/basic-rate-limit.php`](examples/basic-rate-limit.php) is the runnable consumer assembly, and [`examples/infrastructure-failure.php`](examples/infrastructure-failure.php) demonstrates the failure boundary. This root document remains the canonical package contract and complete public runtime inventory.

## Package Fit and Boundaries

`maatify/php-rate-limiter` is a standalone, framework-agnostic Composer package for deterministic, multi-signal rate-limit decisions. It protects host-selected operations such as login, OTP/step-up, and API-heavy access. The package owns enforcement evaluation and its bounded state semantics; the Host owns account/session truth, transport behavior, storage implementations, authorization, logging destinations, and cross-domain reporting.

For authentication correlation, the package uses the opaque `correlationId` supplied by the Host when present and otherwise falls back to `accountId`. The package does not derive subjects from raw usernames or email addresses, and it stores only domain-separated keyed-HMAC correlation members.

The package is currently proprietary and in pre-release development. It is not a published stable Packagist distribution.

## Source Topology

**Source Topology: Single Capability**

The package exposes one rate-limiting capability. Its canonical runtime roots are:

    src/
    ├── Builder/
    ├── Command/
    ├── Config/
    ├── Contract/
    ├── DTO/
    ├── Exception/
    ├── Repository/
    └── Service/

All public runtime classes and interfaces use the `Maatify\RateLimiter` namespace
under the responsibility that owns them. There are no `Domain`, capability-wrapper,
`Engine`, `Device`, `Penalty`, `Policy`, `DTO/Internal`, or `DTO/Store` runtime roots.

## Operational Read / Reporting Classification

**Classification: In Scope.**

The package owns persisted operational semantics for the rate limiter even though the Host supplies the concrete storage drivers. A point-in-time operational read is therefore a package capability, not a generic dashboard or cross-domain reporting layer.

Package-owned operational concepts are:

- score state;
- temporary block state;
- account budget;
- known-device micro-cap;
- budget cooldown; and
- circuit-breaker state.

The Host owns:

- the concrete persistence backend;
- account and session source of truth;
- HTTP and transport behavior;
- permissions;
- dashboards and UI;
- cross-package aggregation; and
- exports.

The public operational contract is `Maatify\RateLimiter\Service\RateLimitOperationalReaderInterface` and its production implementation `Maatify\RateLimiter\Service\RateLimitOperationalReader`, together with the operational DTOs listed below. The query is:

    RateLimitContextDTO + BlockPolicyInterface
        → RateLimitOperationalReaderInterface::read()
        → RateLimitOperationalSnapshotDTO

This is a read-only, point-in-time operational observation and is not an alternate enforcement API. `RateLimiterInterface::limit()` remains the consumer enforcement API.

Correlation distinct-set members, watch-flag internals, churn sets, and dilution sets are intentionally unsupported reporting dimensions. They are internal bounded enforcement structures without a stable operational reporting semantic. The operational read surface has no mutation/reset/unblock API, global listing, arbitrary key lookup, raw-key exposure, historical audit store, Host joins, cross-package reporting, or correlation-set inspection.

Distributed-account correlation is an enforcement capability, not an operational reporting
surface. The runtime returns bounded K5 member snapshots internally to apply involved-device
blocks; it does not expose correlation members through the operational reader.

## Public Runtime API Inventory

The following inventory describes the current public runtime types. Test and support classes are excluded.

### Public Interfaces

| Type | Responsibility |
| --- | --- |
| `Maatify\RateLimiter\Service\RateLimiterInterface` | Consumer entrypoint contract: `limit(Maatify\RateLimiter\DTO\RateLimitContextDTO, Maatify\RateLimiter\Command\RateLimitCommand): Maatify\RateLimiter\DTO\RateLimitResultDTO`. |
| `Maatify\RateLimiter\Repository\RateLimitStoreInterface` | Atomic counters, blocks, budgets, and backend health boundary. |
| `Maatify\RateLimiter\Repository\BudgetSeedStoreInterface` | Additive capability for atomic budget-epoch seeding across key rotation. |
| `Maatify\RateLimiter\Repository\CorrelationStoreInterface` | Bounded distinct-count and watch-flag boundary. |
| `Maatify\RateLimiter\Repository\CorrelationRotationStoreInterface` | Additive capability for atomic credential-spray continuity across key rotation; previous state is read-only. |
| `Maatify\RateLimiter\Repository\BoundedCorrelationStoreInterface` | Additive bounded distinct operation for device-cap and correlation state; preserves the base contract. |
| `Maatify\RateLimiter\Repository\BoundedCorrelationRotationStoreInterface` | Additive bounded current/previous-generation distinct operation with a current-only bridge. |
| `Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotStoreInterface` | Additive bounded distinct operation returning the complete logical member snapshot and fixed expiry. |
| `Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotRotationStoreInterface` | Snapshot-capable current/previous-generation operation with read-only previous state and a current-only bridge. |
| `Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface` | Circuit-breaker state persistence boundary. |
| `Maatify\RateLimiter\Repository\CircuitBreakerProbeStoreInterface` | Additive atomic per-policy recovery-probe lease capability. |
| `Maatify\RateLimiter\Repository\HardBlockCycleStoreInterface` | Additive atomic L2+ block, hard-cycle, and decay-pause capability; Previous is read-only. |
| `Maatify\RateLimiter\Contract\FailureSignalEmitterInterface` | Failure and circuit-breaker signal delivery boundary. |
| `Maatify\RateLimiter\Service\DeviceIdentityResolverInterface` | Device identity resolution boundary. |
| `Maatify\RateLimiter\Service\RateLimitOperationalReaderInterface` | Read-only point-in-time operational state query boundary. |
| `Maatify\RateLimiter\Config\BlockPolicyInterface` | Policy name, thresholds, score deltas, failure mode, and budget configuration. |
| `Maatify\RateLimiter\Exception\RateLimiterExceptionInterface` | Package exception marker contract. |

### Public Configuration and Composition

| Type | Responsibility |
| --- | --- |
| `Maatify\RateLimiter\Config\RateLimiterConfig` | Immutable active/previous outer and fingerprint secrets plus the non-empty environment scope used by the default composition. |
| `Maatify\RateLimiter\Builder\RateLimiterBuilder` | Package-wide default composition surface for the engine graph and the three default policy presets. |

### Public Command

| Type | Contract |
| --- | --- |
| `Maatify\RateLimiter\Command\RateLimitCommand` | Immutable execution intent with `checkOnly()`, `recordFailure()`, and `recordSuccess()` factories; `checkOnly()` does not record scoring success/failure but may update bounded pre-check correlation state. |

### Public DTOs

| Group | Types |
| --- | --- |
| Context and result | `Maatify\RateLimiter\DTO\RateLimitContextDTO`, `Maatify\RateLimiter\DTO\RateLimitResultDTO`, `Maatify\RateLimiter\DTO\RateLimitMetadataDTO`, `Maatify\RateLimiter\DTO\RateLimitContextMetadataDTO` |
| Identity and policy | `Maatify\RateLimiter\DTO\DeviceIdentityDTO`, `Maatify\RateLimiter\DTO\PolicyThresholdsDTO`, `Maatify\RateLimiter\DTO\ScoreThresholdsDTO`, `Maatify\RateLimiter\DTO\ScoreDeltasDTO`, `Maatify\RateLimiter\DTO\BudgetConfigDTO` |
| Runtime state | `Maatify\RateLimiter\DTO\BudgetStatusDTO`, `Maatify\RateLimiter\DTO\EphemeralStateDTO`, `Maatify\RateLimiter\DTO\FailureSignalDTO`, `Maatify\RateLimiter\DTO\FailureStateDTO` |
| Bounded correlation | `Maatify\RateLimiter\DTO\BoundedDistinctResultDTO`, `Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO`, `Maatify\RateLimiter\DTO\BoundedCorrelationObservationDTO` |
| Store boundary state | `Maatify\RateLimiter\DTO\RateLimitStateDTO`, `Maatify\RateLimiter\DTO\BlockStateDTO`, `Maatify\RateLimiter\DTO\BudgetStateDTO`, `Maatify\RateLimiter\DTO\CircuitBreakerStateDTO` |
| Hard-block cycle state | `Maatify\RateLimiter\DTO\HardBlockCycleResultDTO`, `Maatify\RateLimiter\DTO\DecayPauseStateDTO` |
| Operational read | `Maatify\RateLimiter\DTO\RateLimitOperationalKeyStateDTO`, `Maatify\RateLimiter\DTO\RateLimitOperationalScopesDTO`, `Maatify\RateLimiter\DTO\RateLimitOperationalBudgetDTO`, `Maatify\RateLimiter\DTO\RateLimitOperationalSnapshotDTO` |

`Maatify\RateLimiter\DTO\PipelineScoreDTO` is an internal composition DTO and is not part of the consumer Public Runtime API.

### Public Concrete Entrypoints, Services, and Policies

| Group | Types | Consumer role |
| --- | --- | --- |
| Default composition | `Maatify\RateLimiter\Builder\RateLimiterBuilder` | Builds a coherent `RateLimiterInterface` graph around `RateLimiterEngine` while requiring the host storage and failure-signal boundaries explicitly. |
| Primary entrypoint | `Maatify\RateLimiter\Service\RateLimiterEngine` | Production implementation of `Maatify\RateLimiter\Service\RateLimiterInterface`; composes identity resolution, evaluation, circuit-breaker, failure, and policy behavior. |
| Composition services | `Maatify\RateLimiter\Service\EvaluationPipeline`, `Maatify\RateLimiter\Service\CircuitBreaker`, `Maatify\RateLimiter\Service\FailureModeResolver`, `Maatify\RateLimiter\Service\LocalFallbackLimiter` | Public runtime services used to assemble or extend the engine without coupling it to a storage implementation. `EvaluationPipeline::isBackendHealthy()` is the read-only recovery-probe boundary. |
| Identity services | `Maatify\RateLimiter\Service\DeviceIdentityResolver`, `Maatify\RateLimiter\Service\FingerprintHasher`, `Maatify\RateLimiter\Service\EphemeralBucket` | Default identity hashing, normalization, bounded device-cap admission, and ephemeral routing without synthetic persistent keys. |
| Operational read | `Maatify\RateLimiter\Service\RateLimitOperationalReader` | Resolves a read-only point-in-time snapshot from a typed context and policy without invoking enforcement or mutation primitives. |
| Decision services | `Maatify\RateLimiter\Service\AntiEquilibriumGate`, `Maatify\RateLimiter\Service\BoundedCorrelationResultValidator`, `Maatify\RateLimiter\Service\BudgetTracker`, `Maatify\RateLimiter\Service\DecayCalculator`, `Maatify\RateLimiter\Service\PenaltyLadder` | Publicly typed services for bounded result validation, penalty, budget, decay, and escalation orchestration. |
| Configuration presets | `Maatify\RateLimiter\Config\LoginProtectionPolicy`, `Maatify\RateLimiter\Config\OtpProtectionPolicy`, `Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy` | Production policy definitions selected by the command policy name. |
| Exception | `Maatify\RateLimiter\Exception\RateLimiterException` | Package-defined invalid-input and configuration exception implementing the package marker interface. |

The recommended consumer construction is `Maatify\RateLimiter\Builder\RateLimiterBuilder`, which returns the `RateLimiterInterface` after composing the package-owned graph. The low-level service constructors remain available as the Advanced Path for consumers that intentionally need manual control. Their current signatures are stable only as reflected in the source and the contracts above.

## Default Composition Surface

The production default is constructed with required Host boundaries and a typed `RateLimiterConfig`. Secrets are caller-provided; the package does not read environment variables, generate secrets, serialize secrets, or merge outer and fingerprint rotation into one input.

```php
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\RateLimiterConfig;

$limiter = new RateLimiterBuilder(
    new RateLimiterConfig(
        keySecret: $activeKeySecret,
        fingerprintSecret: $activeFingerprintSecret,
        environmentScope: 'production',
        previousKeySecret: $previousKeySecret,
        previousFingerprintSecret: $previousFingerprintSecret,
    ),
    $rateLimitStore,
    $correlationStore,
    $circuitBreakerStore,
    $failureSignalEmitter,
)
    ->build();
```

`build()` supplies the UTC `SystemClock`, the default identity resolver, the package-owned evaluation graph, and the `login_protection`, `otp_protection`, and `api_heavy_protection` policies. `withClock()`, `withDeviceIdentityResolver()`, and `withPolicy()` are the only targeted overrides. A policy with an existing name replaces that policy; a new name is appended without removing defaults. Consumers needing direct control of `EvaluationPipeline` or its internal services retain the existing low-level constructors as the Advanced Path.

## Runtime Workflow

The realistic consumer path is:

    Host Input → Public API → Domain Service → Integration Boundary → Observable Result

Concretely:

    RateLimitContextDTO + RateLimitCommand
        → RateLimiterInterface::limit()
        → RateLimiterEngine::limit()
        → DeviceIdentityResolver → EvaluationPipeline
        → RateLimitStoreInterface + CorrelationStoreInterface
          + BoundedCorrelationStoreInterface for bounded observations
          + BoundedCorrelationRotationStoreInterface when a previous generation is present
          + BoundedCorrelationSnapshotStoreInterface for distributed-account snapshots
          + BoundedCorrelationSnapshotRotationStoreInterface for rotated distributed snapshots
          + CorrelationRotationStoreInterface for rotated WATCH state
          + CircuitBreakerStoreInterface + FailureSignalEmitterInterface
        → RateLimitResultDTO and, when applicable, FailureSignalDTO

`RateLimiterEngine` selects the policy by the command's policy name. `EvaluationPipeline` resolves active blocks, identity-derived keys, scoring, correlation, budgets, decay, and final aggregation. Credential-spray and distributed-account correlation are observed during Login/OTP authentication pre-checks only; the later failure/success command does not observe the same lifecycle a second time. The distributed-account path uses a 600-second, four-member snapshot of canonical K5 keys, a 30-minute N-1 watch, and a 24-hour three-occurrence account gate. API Heavy and requests without the required account/device/K4/K5 inputs do not observe it. The integration boundaries provide the stateful primitives; the result is returned to the Host, which decides how to enforce it at its own transport or application boundary.

`HardBlockCycleStoreInterface::blockWithCycleTracking()` is required for the
first persisted L2+ block. It atomically persists the Current block, classifies
the real hard-block transition, records independent K1/K2/K3/K4/K5 cycle
history, and activates the fixed decay pause. A store that exposes only
`RateLimitStoreInterface` remains valid for normal reads and L1 writes, but an
L2+ persistence attempt fails explicitly before any block write. The additive
`readDecayPauseState()` operation is read-only; Current and Previous form one
logical history, with Current writable and Previous read-only.

The independent operational path is:

    RateLimitContextDTO + BlockPolicyInterface
        → RateLimitOperationalReaderInterface::read()
        → package-owned score/block/budget/cooldown/circuit-breaker reads
        → RateLimitOperationalSnapshotDTO
        → Host monitoring or operations boundary

The operational reader derives only the real enforcement keys for the supplied context, observes current generation state with the documented previous-generation fallback, and never changes score counters, budgets, cooldowns, blocks, circuit-breaker state, or correlation state.

---

## 1. Goals

### 1.1 Security Goals
- Prevent brute-force attacks on authentication flows (password + OTP/Step-Up)
- Detect credential spray (single IP scope attempting many accounts)
- Detect distributed attacks (single account attacked from many devices/IP scopes)
- Detect fingerprint dilution (same fingerprint reused across many IP scopes)
- Reduce false positives caused by NAT, VPNs, mobile networks, and shared IPs
- Provide deterministic, testable decisions (auditable security behavior)
- Prevent storage exhaustion via adversarial key creation (key explosion)
- Prevent deterministic **equilibrium** and **threshold-gaming** (N-1 attacks)
- Prevent renewable **denial-of-owner** via budget/cooldown arithmetic

### 1.2 Product / UX Goals
- Prefer throttling (`SOFT_BLOCK`) before full blocking where possible
- Progressive penalties with decay **without allowing stable equilibrium**
- Budget friction that cannot be turned into scheduled recurring lockout
- Fast fail for clearly malicious patterns
- Minimal integration burden for host applications

### 1.3 Engineering Goals
- Storage-agnostic core
- Standalone package structure
- Contract-based public API: no arrays in public contracts, distinguishing Commands (execution intent) and DTOs (data shapes)
- Clear boundaries between Engine, Policy, Penalty, and Store (testability and replaceability)
- Explicit failure behavior that cannot be weaponized as a kill-switch
- Bounded operations (no scans, no unbounded key creation)

---

## 2. Non-Goals (Explicitly Out of Scope)
- A permanent ban system (account bans, IP bans beyond temporary rate-limiting)
- A WAF replacement (CDN/WAF remains a separate security layer)
- User tracking across sites or contexts
- Advanced browser fingerprinting (canvas/audio/WebGL)
- Risk scoring based on external intelligence feeds

---

## 3. Architectural Principles (Non-Negotiable)

### 3.1 Multi-Signal Decisions (No Single-Signal Security)
Decisions MUST never rely on a single signal (e.g., IP-only).
The package combines:
- IP scope
- User-Agent
- Device Fingerprint (confidence-aware)
- Account Identifier
- Correlation patterns

This reduces false positives and improves detection of distributed attacks.

### 3.2 Device Awareness is Mandatory
Device signals are foundational to fairness and attack detection:
- `IP_PREFIX + DeviceFP` is preferred over `IP_PREFIX` alone
- `AccountID + DeviceFP` is required for account-sensitive operations
- Passive-only fingerprints are treated as **LOW confidence** and have explicit enforcement constraints

The fingerprint system is defined in `docs/DEVICE_FINGERPRINT.md`.

### 3.3 Progressive Blocking + Persistence (Anti-Equilibrium)
Blocking MUST be progressive and MUST decay over time.

Additionally, the design MUST include:
- Account-wide persistence (prevents device-rotation ladder resets)
- Hard caps for low-and-slow abuse (fixed budget epochs)
- Deterministic **anti-equilibrium gates** (e.g., “3 soft blocks within 6 hours → next failure hard block”)
- Deterministic **near-threshold watch** to prevent N-1 correlation gaming

Ladder, decay modifiers, budgets, and gates are locked in `docs/DECISION_MATRIX.md`.

Score-threshold `retryAfter` is the package-owned time until the current score
decays below the threshold that ends the current decision class. `SOFT_BLOCK`
uses L1, while every score-derived `HARD_BLOCK` level uses L2; an L3 score does
not end its hard-block class merely by decaying below L3. The calculation uses
the stored score, its update timestamp, the effective decay interval, and the
elapsed part of the current interval through `DecayCalculator`.

An active persisted block remains authoritative and returns its remaining block
TTL. Persistence still uses `PenaltyLadder` durations, so a score-derived
response `retryAfter` and the persisted block duration are separate values.
Budget cooldowns, correlation, flood, credential-spray, and other non-score
candidates retain their existing retry semantics.

### 3.4 Determinism (Without Deterministic Bypass)
Given the same inputs and same stored state, the package MUST produce the same decision.

Determinism MUST NOT create an “attacker roadmap”. Therefore:
- Budgets use fixed epochs (no rolling extension)
- Correlation uses near-threshold watch (anti N-1)
- Same-device failures are budget-eligible after micro-caps

### 3.5 Bounded State (Key Explosion Resistance)
The package MUST NOT allow adversarial traffic to create unbounded keys.
Device-related key creation MUST be capped and aggregated as defined in:
- `docs/KEY_STRATEGY.md`
- `docs/DEVICE_FINGERPRINT.md`

Ephemeral routing must protect storage **without** bypassing active blocks.

### 3.6 Explicit Failure Behavior (No Silent Bypass, No Kill-Switch)
Failure behavior MUST be explicit, observable, and bounded.

Auth-critical flows MUST remain protected without turning RateLimiter into a global denial lever.

Therefore:
- Login/OTP use FAIL_CLOSED with mandatory bounded DEGRADED_MODE
- API Heavy may FAIL_OPEN, but still must be bounded by local guardrails
- Circuit breaker parameters are locked (3 failures/10 seconds, 300-second minimum OPEN duration, 120-second probe lease, and 120-second healthy interval)

Failure semantics are locked in `docs/FAILURE_SEMANTICS.md`.

### 3.7 Budget Is a Decision Candidate, Not a Fail-Fast Gate (Non-Negotiable)

The Account Budget supports owner-safety without becoming a bypass or a kill-switch.
It is an **aggregation candidate only**:

- An active budget MUST NOT short-circuit the pipeline before the stronger decision is evaluated.
- The locked aggregation rule stays `HARD_BLOCK > SOFT_BLOCK > ALLOW`.
- An active budget MUST NOT hide a `K4`/`K5`/Correlation `HARD_BLOCK`.
- Decision class wins before level or duration; level, duration, `retryAfter`, and
  persistence are resolved only among candidates in the winning class.
- A budget `SOFT_BLOCK` MUST NOT upgrade or contribute properties to a winning
  `HARD_BLOCK`, directly or indirectly.
- On `recordFailure`, Anti-Equilibrium reads only soft events from prior requests before
  budget cooldown acquisition; an eligible history adds a `HARD_BLOCK` candidate and
  makes the budget candidate and cooldown ineligible.
- `checkOnly()` MUST evaluate normal score/correlation state before choosing the final result.
- Login `checkOnly` may attempt budget enforcement only when the normal result would be
  `ALLOW`; normal `SOFT_BLOCK` and `HARD_BLOCK` make the budget candidate ineligible.
- `recordFailure()` MUST continue normal failure scoring, correlation, and budget counting
  even while `BudgetActive`.
- The budget MUST NOT stop `processUpdates()`.

**Budget-issued `SOFT_BLOCK` is not `BlockState`:**
budget enforcement is decoupled from `RateLimitStoreInterface::block()`. It does not create
a K4 hard block, does not use level-1 `BlockState` as a cooldown marker, and does not alter
active hard-block semantics; its repetition is controlled by an **independent budget
cooldown** state. The cooldown marker is acquired only for an actually issuable budget
`SOFT_BLOCK`; it is not consumed merely because `BudgetActive` exists. Normal
score/anti-equilibrium `HARD_BLOCK` keeps using `BlockState`.

Normative Behavior: `docs/DECISION_MATRIX.md` §2.4 / §2.5 / §3.3. Preset values:
`docs/POLICIES.md`. Keys & rotation: `docs/KEY_STRATEGY.md`.

---

## 4. Package Layers and Responsibilities

### 4.1 Contracts and configuration boundaries

The public boundaries are placed under their owning responsibility:

- `Config/` owns `RateLimiterConfig`, `BlockPolicyInterface`, and the three policy presets.
- `Builder/` owns the package-wide default composition surface; it does not replace the low-level Advanced Path or provide a service container.
- `Repository/` owns `RateLimitStoreInterface`, `BudgetSeedStoreInterface`,
  `CorrelationStoreInterface`, `CorrelationRotationStoreInterface`,
  `BoundedCorrelationStoreInterface`, `BoundedCorrelationRotationStoreInterface`,
  `CircuitBreakerStoreInterface`, and `HardBlockCycleStoreInterface`.
- `Service/` owns `RateLimiterInterface` and `DeviceIdentityResolverInterface`.
- `Contract/` retains the general host/outbound `FailureSignalEmitterInterface`.

These interfaces are pure and storage-agnostic; concrete persistence and signal
implementations remain host-owned.

### 4.2 Commands and DTOs (Public Data Shapes)
**Location:** `Command/` and `DTO/`

All data crossing boundaries MUST be strictly typed:
- Command (`RateLimitCommand`): execution/action intent (policy + action + cost)
- Context DTOs (signals): context/data/state/result snapshots
- Result DTOs (decision + retry-after + block level + failure mode)
- Internal state DTOs (score/level/windows)

No public arrays are allowed.

DTO naming MUST end with `DTO`. Commands must clearly represent execution intent.

### 4.3 Services (Decision Orchestration)
**Location:** `Service/`

The services coordinate the package decision:
- Evaluates active hard blocks in strict order (fail-fast)
- Applies scoring rules
- Applies correlation rules (bounded windows + watch flags)
- Applies caps and persistence rules (fixed epochs; anti-equilibrium gates)
- Treats the **budget as a decision candidate** in final aggregation: it never
  short-circuits scoring/correlation/update processing
- Evaluates prior Anti-Equilibrium soft-event history before budget enforcement and
  records a new soft event only after final aggregation for future requests
- Aggregates decisions deterministically by class first (`HARD_BLOCK > SOFT_BLOCK > ALLOW`),
  then resolves level and duration within the winning class only
- Enforces failure semantics explicitly

The Engine MUST NOT depend on specific storage implementations.
Budget owner-safety orchestration is described in `docs/DECISION_MATRIX.md` §1.

### 4.4 Configuration (Policy Presets)
**Location:** `Config/`

Policies are pre-configured rule sets built on the Decision Matrix:
- `login_protection`
- `otp_protection`
- `api_heavy_protection`

Policies define:
- which keys are used
- scoring deltas
- thresholds
- failure semantics mode (including degraded behavior)

Policies MUST remain compliant with `docs/POLICIES.md`.

The `api_heavy_protection` preset enforces API score scopes independently: K2
produces at most `SOFT_BLOCK` L1, K3 produces at most `HARD_BLOCK` L2, and K1
produces `HARD_BLOCK` L3 at its configured threshold. API Heavy does not enforce
account K4 or account/device K5 blocks. A LOW-confidence moderate K3 signal is
still a hard L2 decision, but its persisted enforcement target is K2 rather than
K3.

### 4.5 Decision services (Escalation + Decay + Caps)
**Location:** `Service/`

Penalty logic is separated to:
- prevent mixing “decision” with “punishment”
- keep escalation rules testable and deterministic
- centralize caps, gates, and epoch rules
- avoid hidden behavioral changes inside Store drivers
- own budget epochs/counts, the **budget cooldown** (enforcement-owned, never level-1
  `BlockState`), and the OTP **Recovery Collision Guard** transition

Account-only auxiliary state is derived by `EvaluationPipeline` from the policy name,
purpose, environment, version, and raw AccountID, then stored only as a keyed HMAC. The
`AntiEquilibriumGate` receives opaque package-derived state keys; it does not know secrets,
environment scope, or raw AccountID. Current-generation auxiliary state is writable, while
one previous outer-key generation is read-only during rotation. The three policy-scoped
purposes are repeated-missing-fingerprint, Anti-Equilibrium, and New Device Flood stage;
fingerprint-secret rotation alone does not create a previous account-only key.

### 4.6 Identity services (Fingerprinting)
**Location:** `Service/`

Device identity is resolved into a single `DeviceIdentityDTO` with:
- hashed fingerprints only
- confidence level
- churn/evasion awareness
- bounded creation rules integration (no key explosion)

The package MUST NOT store raw fingerprint components.

The default `DeviceIdentityResolver` is minimal, deterministic, and stateless:
it canonicalizes the explicit user agent, serializes the explicit Host-provided
low-entropy `clientFingerprint`, and applies the explicit session/trust inputs.
It does not inspect `RateLimitContextDTO::$headers` or harvest passive request
headers. Churn history remains a runtime correlation responsibility or an
explicit custom resolver responsibility. The normalized raw identity uses the
`v2|normalizedUa|normalizedClientFp|sessionDeviceId` schema; current and previous
fingerprint hashers always receive that exact same identity.

Client-fingerprint canonicalization sorts associative keys recursively, preserves
list order and scalar/null types, and raises a package-owned exception when JSON
serialization fails. It never silently downgrades confidence.

**Dual-fingerprint rotation contract:** the identity layer owns the current and previous
fingerprint versions; the pipeline never rebuilds or re-hashes raw fingerprint material.
The outer key secret and the fingerprint secret are independently rotatable components, but
runtime continuity is represented as **one coordinated current generation and at most one
previous generation** (`docs/DEVICE_FINGERPRINT.md` §5.1.6), never as Cartesian combinations
of versions. Public contract (implemented):

- `fingerprintHash` — current-generation fingerprint component (unchanged semantics)
- `previousFingerprintHash` — previous-generation fingerprint component, nullable, additive
  optional field at the end of `DeviceIdentityDTO`
  (`public ?string $previousFingerprintHash = null`)

`DeviceIdentityResolverInterface::resolve()` stays unchanged; the default resolver applies a
current and (optionally) a previous single-secret `FingerprintHasher` to the **same**
normalized raw identity (`docs/DEVICE_FINGERPRINT.md` §5.1). The identity semantics
(`confidence`, `isTrustedSession`, `isDevicePreviouslyVerifiedForAccount`, `isKnownForAccount`)
are independent of `previousFingerprintHash`.

**Fingerprint-secret rotation implementation status:**

| Concern                                          | Status                                     |
| ------------------------------------------------ | ------------------------------------------ |
| K4 budget rotation runtime                       | implemented                                |
| Dual-fingerprint public contract (identity layer)| implemented                               |
| K3/K5 persistent two-generation rotation         | implemented                                |
| K5 micro-cap two-generation migration            | implemented                                |
| Credential-spray outer-secret rotation           | implemented                                |
| Correlation/ephemeral fingerprint-secret rotation| implemented                                |

Generation resolution and the K5 micro-cap current/previous rule are owned by
`docs/KEY_STRATEGY.md` §4.3.3 / §4.5.2.

### 4.7 Infrastructure (Drivers)

The package owns storage contracts for the required persistence layer. Consumers must provide implementations of these contracts. The package itself does not ship with concrete driver implementations.

**Infrastructure rules for consumers:**
- Drivers MUST provide deterministic, bounded behavior
- Drivers MUST NOT swallow exceptions
- If a backend cannot satisfy required atomicity for an operation, the driver MUST fail explicitly and defer to Engine failure semantics
- Drivers must be interchangeable without changing Engine logic
- Drivers provide the atomic, no-extension primitives required by budget owner-safety (§4.8)

For credential-spray key rotation, `CorrelationRotationStoreInterface` is an additive
capability over the unchanged `CorrelationStoreInterface`. The no-rotation path needs
only the base contract. The rotation primitives are atomic inside the concrete store:
they write the current generation, read the previous generation without modifying its
members or TTL, and use `previous cardinality + bridge cardinality` for the active
spray window. A current-only fallback is forbidden; a missing capability or malformed
previous state fails through the engine's existing failure semantics. The bridge is
current-secret-only, fixed-TTL, and capped by the previous remaining TTL. The core
package provides no Redis, Lua, PDO, or other concrete adapter.

Bounded device-cap, churn, dilution, and spray distinct state uses the additive
`BoundedCorrelationStoreInterface`; a current/previous observation requires
`BoundedCorrelationRotationStoreInterface`. Distributed-account device windows additionally
require `BoundedCorrelationSnapshotStoreInterface`, or
`BoundedCorrelationSnapshotRotationStoreInterface` when a previous generation is active.
The snapshot returns `count`, `accepted`, `added`, complete logical `members`, and fixed
`expiresAt`; duplicates are accepted without being added, while new members at the cap are
rejected without set growth. The pipeline validates malformed snapshots fail-closed before
using the members as direct K5 enforcement keys. The distributed window is 600 seconds with
a cap of four; its occurrence state is account-only, 86400 seconds, and capped at three.
Rotation writes current state only, reads previous state without mutation, and uses a bridge
whose TTL cannot exceed the remaining previous TTL. Corrupt or over-cap state and missing
capabilities fail explicitly. All keys and members are opaque purpose/version/environment-
separated HMAC references; raw account, IP, correlation, and fingerprint values do not cross
the store boundary.

### 4.8 Budget Owner-Safety — Storage Boundaries

Budget owner-safety relies on **existing and declared** storage primitives:

- **No new enforcement primitive for the budget `SOFT_BLOCK`:** budget enforcement is never
  expressed through `RateLimitStoreInterface::block()`, never creates a K4 hard block, and
  never uses level-1 `BlockState` as a cooldown marker.
- **Budget cooldown marker:** uses the existing atomic fixed-TTL `increment(key, cooldownTTL)`
  on a dedicated HMAC-namespaced auxiliary key (first creation sets TTL; later increments do
  not extend it). `get()+set()` acquisition is forbidden. Key layout:
  `docs/KEY_STRATEGY.md` §4.5.
- **Rotation survival applies to all budget state** (K4 budget epoch/count, K5 micro-cap,
  budget cooldown marker): writes → V2, reads → V2 then V1, never a `max(v1,v2)` merge for
  cumulative counters. `docs/KEY_STRATEGY.md` §4.3.1.
- **Atomic budget seeding across rotation:** the current store contract cannot move a budget
  epoch from V1 to V2 while preserving `count`, `epochStart`, the fixed epoch end, and
  atomicity. The architecture adopts an **additive capability interface** —
  `BudgetSeedStoreInterface extends RateLimitStoreInterface` with
  `incrementBudgetWithSeed(string $key, int $epochDurationSeconds, BudgetStateDTO $seed,
  int $amount = 1): BudgetStateDTO` — now implemented; locked semantics in
  `docs/KEY_STRATEGY.md` §4.3.2. `RateLimitStoreInterface` itself is unchanged, so
  existing store implementations stay source-compatible. The capability is designed to
  serve both K4 account budget and K5 same-device micro-cap without rotation reset or epoch
  extension. Runtime integration status:
  - **K4 account-budget rotation — integrated** in `EvaluationPipeline`: budget reads
    resolve V2 first and fall back to V1 only when no valid V2 state exists (never a
    `max(v1,v2)` merge), and K4 budget writes migrate a valid V1 state into V2 atomically
    via the capability.
  - **K5 micro-cap rotation — integrated.** The pipeline resolves the current and
    previous-generation micro-cap keys, treats Current as authoritative, and atomically seeds
    a valid Previous state into Current through the capability. The Previous state remains
    unchanged; `max(v1, v2)` merging is not used.
When a valid previous-secret budget must move to V2 and the store is not a
`BudgetSeedStoreInterface`, the path MUST fail explicitly through the existing failure
semantics (`docs/FAILURE_SEMANTICS.md`) — never a silent reset or loss of enforcement.

### 4.8.1 Transaction, Locking, and Concurrency Contract

**Transaction owner.** The package does not open or manage a general database
transaction around `RateLimiterInterface::limit()`. Transaction and backend
atomicity ownership belongs to the concrete Host repository/store implementation
when its backend requires one.

**Atomicity boundary.** Every operation declared atomic by
`RateLimitStoreInterface`, `BudgetSeedStoreInterface`, `CorrelationStoreInterface`, or a
bounded correlation capability MUST be atomic inside that concrete implementation.
This includes counter increments, fixed-TTL behavior, budget initialization and
increment, budget seeding across rotation, cooldown acquisition, distinct
correlation updates, bounded-cap admission, and watch-flag increments. Circuit-breaker state persistence
must likewise preserve the state primitive promised by its store implementation.

There is no package-level distributed transaction spanning the rate-limit store,
correlation store, circuit-breaker store, and failure emitter.

**Locking boundary.** The package services do not own backend locks and expose no
lock lifecycle to consumers. A concrete store may use whatever lock or native
atomic primitive its backend requires, provided it preserves the public contract
and does not change the service-visible failure semantics.

**Outer transaction.** `RateLimiterInterface::limit()` does not require a
caller-owned outer transaction and does not promise an atomic commit shared with
the Host's business transaction. A Host adapter may run its own store operations
inside an existing backend transaction only as an implementation detail. It must
not weaken atomic primitives, change failure behavior, make rate-limit correctness
depend on an undeclared transaction, or assume shared atomicity across separate
integration boundaries.

**Concurrency.** Correctness under concurrency depends on the atomic store
primitives above, especially counter increments, fixed-TTL preservation, budget
initialization/increment, budget seeding across rotation, cooldown acquisition,
distinct correlation updates, and watch-flag increments. This contract does not
add a transaction manager or a locking API.

### 4.9 Host-Provided Known-Device Signal (Public Boundary)

The public context/device-identity contracts express:

```
device previously verified for this AccountID (known K5), without a trusted session
```

as a host-provided signal carried on `RateLimitContextDTO` and `DeviceIdentityDTO` and
propagated through `DeviceIdentityResolver`:

```
isDevicePreviouslyVerifiedForAccount   // default: false
```

The host is the sole authority for this value; the RateLimiter MUST NOT infer it from
`K5` presence, from `DeviceConfidence = HIGH`, or by querying external storage.

Resolved semantic:

```
isKnownForAccount = isTrustedSession OR isDevicePreviouslyVerifiedForAccount
```

`isTrustedSession` and `isDevicePreviouslyVerifiedForAccount` remain independent
contracts: a previously-verified signal alone does not raise confidence or create a
trusted session. Device semantics are owned by `docs/DEVICE_FINGERPRINT.md` §4.3.

---

## 5. Key Strategy (Why These Keys Exist)
The package relies on a small, explicit set of evaluation keys:
- `K1 = IP_PREFIX`
- `K2 = IP_PREFIX + UA`
- `K3 = IP_PREFIX + DeviceFP`
- `K4 = AccountID`
- `K5 = AccountID + DeviceFP`

This key set is designed to:
- minimize false positives
- enable correlation detection
- avoid IP-only enforcement
- prevent key explosion via bounded creation rules
- defeat IPv6 boundary spray via hierarchical aggregation

For IPv6, only canonical `/64` is an enforcement K1. The `/48` → `/40` → `/32`
hierarchy is bounded correlation detection state activated at exact `2/2`,
`4/4`, and `8/8` child thresholds within a fixed 600-second window; it has no
macro score or block state, and it never enters `RateLimitStoreInterface`.
Macro Spray and Churn enforce only the current `/64` K1 or `/64 + UA` K2.
Dilution continues to count canonical `/64` members. Hierarchy state uses
policy/environment/version/purpose-separated keyed-HMAC references, with one
read-only previous outer-key generation and a TTL-bounded current bridge.

The public operational snapshot serialized shape is intentionally contracted to
`k1`, `k2`, `k3`, `k4`, and `k5`. The former `k1_48`, `k1_40`, and `k1_32`
serialized fields are removed: adaptive macro hierarchy is internal correlation
detection state, not an operational enforcement scope or public hierarchy API.

Detailed key rules are defined in `docs/KEY_STRATEGY.md`.

---

## 6. Failure Semantics (Security vs Availability)
Rate limiting is security-critical for login and OTP.

When storage fails:
- Login/OTP MUST be FAIL_CLOSED with mandatory bounded DEGRADED_MODE
- API Heavy MAY be FAIL_OPEN, but MUST still apply local guardrails
- Circuit breaker parameters are LOCKED to prevent “undefined N/window” exploitation

Rules are defined in `docs/FAILURE_SEMANTICS.md`.

---

## 7. Privacy-by-Design Commitments
The package is designed to support security without tracking:
- No raw headers persisted
- No raw fingerprint components stored
- Only hashed, keyed identifiers persisted
- Fingerprints are probabilistic and versioned
- No cross-context or cross-module tracking

See `docs/DEVICE_FINGERPRINT.md` for full rules.

---

## 8. Testing Strategy (What Must Be Proven)
The package is only acceptable if tests prove:
- Deterministic outcomes for known states
- Correct ladder escalation, persistence, decay modifiers, and anti-equilibrium gates
- Correct budget epoch behavior (no extension) and owner-safety enforcement
- Correct **budget candidate aggregation** (no fail-fast masking of score/correlation
  `HARD_BLOCK`), atomic **cooldown** acquisition, and the OTP **Recovery Collision Guard** transition
- Correct retry-after calculations
- Correct correlation detection triggers with watch flags + confidence constraints
- Key explosion resistance (caps + ephemeral behavior + “no bypass” invariants)
- Failure semantics correctness (including circuit breaker constants and re-entry guard)

Circuit-breaker recovery uses the public `CLOSED`, `OPEN`, and `HALF_OPEN` states.
Normal evaluation is short-circuited outside `CLOSED`. Recovery is request-driven
through `EvaluationPipeline::isBackendHealthy()`, which delegates only to
`RateLimitStoreInterface::isHealthy()` and performs no score, block, budget,
correlation, or device work. A recovery-eligible request requires the additive
`CircuitBreakerProbeStoreInterface::acquireProbeLease()` capability with a
120-second atomic lease; the unchanged `CircuitBreakerStoreInterface` remains
source-compatible. The first healthy probe enters `HALF_OPEN` while remaining
degraded; a second healthy probe after 120 seconds closes the circuit and emits
`CB_RECOVERED` once. Failed probes restart or re-enter `OPEN` according to the
state-machine contract. The rolling re-entry guard has precedence over every
persisted state, suppresses probing/backend work, emits `CB_RE_ENTRY_VIOLATION`
once at activation, and returns the remaining guard duration as `Retry-After`.

---

## 9. Architectural Boundaries
This package maintains strict architectural boundaries:
- No coupling to HTTP frameworks
- No reliance on globals (`$_SERVER`, `$_COOKIE`)
- Contract, Command, and DTO boundaries
- Consumers implement infrastructure drivers

Composer autoload maps:
- `Maatify\RateLimiter\` → `src/`

---

## 10. Stability & Versioning Rules
- Any change to behavior requires:
  - version bump
  - changelog entry
  - updated tests aligned to the Decision Matrix
- Documents in `docs/` are part of the public contract
- Policies are stable identifiers and must not change without explicit versioning

---

**This document is authoritative.
Do not “simplify” this package by removing multi-signal logic, device awareness, bounded state rules, progressive blocking, caps, gates, and determinism.**
