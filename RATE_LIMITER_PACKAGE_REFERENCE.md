# RateLimiter — Architecture (Official)

**Package:** RateLimiter
**Namespace:** `Maatify\RateLimiter`
**Status:** LOCKED — Architecture Contract
**Spec Version:** `1.1.0`
**Change Class:** Adversarial Hardening Alignment
**Location:** `src/`

This document explains **why** the RateLimiter package is designed the way it is.
It is an architectural contract intended to prevent accidental weakening, incorrect refactors, or scope creep.

Behavioral rules are specified in:
- `docs/DECISION_MATRIX.md`
- `docs/POLICIES.md`
- `docs/DEVICE_FINGERPRINT.md`
- `docs/KEY_STRATEGY.md`
- `docs/FAILURE_SEMANTICS.md`

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
- Circuit breaker parameters are locked (no undefined N/window)

Failure semantics are locked in `docs/FAILURE_SEMANTICS.md`.

### 3.7 Budget Is a Decision Candidate, Not a Fail-Fast Gate (Non-Negotiable)

The Account Budget supports owner-safety without becoming a bypass or a kill-switch.
It is an **aggregation candidate only**:

- An active budget MUST NOT short-circuit the pipeline before the stronger decision is evaluated.
- The locked aggregation rule stays `HARD_BLOCK > SOFT_BLOCK > ALLOW`.
- An active budget MUST NOT hide a `K4`/`K5`/Correlation `HARD_BLOCK`.
- `checkOnly()` MUST evaluate normal score/correlation state before choosing the final result.
- `recordFailure()` MUST continue normal failure scoring, correlation, and budget counting
  even while `BudgetActive`.
- The budget MUST NOT stop `processUpdates()`.

**Budget-issued `SOFT_BLOCK` is not `BlockState`:**
budget enforcement is decoupled from `RateLimitStoreInterface::block()`. It does not create
a K4 hard block, does not use level-1 `BlockState` as a cooldown marker, and does not alter
active hard-block semantics; its repetition is controlled by an **independent budget
cooldown** state. Normal score/anti-equilibrium `HARD_BLOCK` keeps using `BlockState`.

Normative Behavior: `docs/DECISION_MATRIX.md` §2.4 / §2.5 / §3.3. Preset values:
`docs/POLICIES.md`. Keys & rotation: `docs/KEY_STRATEGY.md`.

---

## 4. Package Layers and Responsibilities

### 4.1 Contracts (Public Boundary)
**Location:** `Contract/`

Contracts define stable APIs:
- `RateLimiterInterface` — single entrypoint for consumption/guarding
- `RateLimitStoreInterface` — storage abstraction
- `BudgetSeedStoreInterface` — additive capability over `RateLimitStoreInterface`
  (budget-epoch hand-off across key rotation; does not modify the base contract)
- `DeviceIdentityResolverInterface` — device identity resolution abstraction
- `BlockPolicyInterface` — penalty computation (ladder + decay + caps)
- `CorrelationStoreInterface` (if separated) — bounded distinct counting support

Contracts are pure and storage-agnostic.

### 4.2 Commands and DTOs (Public Data Shapes)
**Location:** `Command/` and `DTO/`

All data crossing boundaries MUST be strictly typed:
- Command (`RateLimitCommand`): execution/action intent (policy + action + cost)
- Context DTOs (signals): context/data/state/result snapshots
- Result DTOs (decision + retry-after + block level + failure mode)
- Internal state DTOs (score/level/windows)

No public arrays are allowed.

DTO naming MUST end with `DTO`. Commands must clearly represent execution intent.

### 4.3 Engine (Decision Orchestration)
**Location:** `Engine/`

The Engine is the “brain”:
- Evaluates active hard blocks in strict order (fail-fast)
- Applies scoring rules
- Applies correlation rules (bounded windows + watch flags)
- Applies caps and persistence rules (fixed epochs; anti-equilibrium gates)
- Treats the **budget as a decision candidate** in final aggregation: it never
  short-circuits scoring/correlation/update processing
- Aggregates decisions deterministically (`HARD_BLOCK > SOFT_BLOCK > ALLOW`)
- Enforces failure semantics explicitly

The Engine MUST NOT depend on specific storage implementations.
Budget owner-safety orchestration is described in `docs/DECISION_MATRIX.md` §1.

### 4.4 Policies (Presets)
**Location:** `Policy/`

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

### 4.5 Penalty (Escalation + Decay + Caps)
**Location:** `Penalty/`

Penalty logic is separated to:
- prevent mixing “decision” with “punishment”
- keep escalation rules testable and deterministic
- centralize caps, gates, and epoch rules
- avoid hidden behavioral changes inside Store drivers
- own budget epochs/counts, the **budget cooldown** (enforcement-owned, never level-1
  `BlockState`), and the OTP **Recovery Collision Guard** transition

### 4.6 Device Identity (Fingerprinting)
**Location:** `Device/`

Device identity is resolved into a single `DeviceIdentityDTO` with:
- hashed fingerprints only
- confidence level
- churn/evasion awareness
- bounded creation rules integration (no key explosion)

The package MUST NOT store raw fingerprint components.

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
| Correlation/ephemeral fingerprint-secret rotation| pending — separate design                 |

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
