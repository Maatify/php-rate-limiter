# RateLimiter — Key Strategy (Official)

**Module:** RateLimiter
**Namespace:** `Maatify\RateLimiter`
**Status:** LOCKED — Design & Security Contract
**Spec Version:** `1.1.0`

This document defines the **key construction strategy** used by the RateLimiter.
Keys determine how limits, scores, correlation, and blocks are applied.

Incorrect key design weakens security, increases false positives, or enables evasion.
This strategy is mandatory for all implementations.

---

## 1. Purpose of the Key Strategy

The key strategy exists to:

* Avoid IP-only decisions
* Support multi-device and distributed attack detection
* Reduce false positives in shared IP environments
* Enable progressive penalties scoped correctly
* Preserve privacy by avoiding raw identifiers
* Prevent key-explosion and storage exhaustion attacks
* Prevent threshold-gaming and IPv6 boundary exploits

Keys are **security primitives**, not implementation details.

---

## 2. Core Principles (Non-Negotiable)

* No single-signal key may determine a final decision
* IP-only keys are advisory, never final
* Device-aware keys are preferred whenever available
* Account-scoped keys are mandatory for authentication flows
* Keys must be deterministic and stable
* Keys must be privacy-safe (no raw PII)
* Keys MUST be bounded: no unbounded per-request key creation

---

## 3. Canonical Evaluation Keys

The module defines a **fixed, minimal key set**.

### 3.1 K1 — IP / IP Prefix (Hierarchical IPv6 Aggregation)

```
K1 = IP_PREFIX
```

**Definition:**

* IPv4: exact IP address
* IPv6: default normalization `/64`, with **hierarchical adaptive aggregation**:

#### IPv6 Hierarchical Aggregation (Required)

Correlation storage MUST support grouping IPv6 prefixes into larger scopes for detection:

* `/64` → `/48` → `/40` → `/32`

**Escalation Triggers (Deterministic):**

If, within a 10-minute correlation window:

1. Multiple `/64` prefixes under the same `/48` participate in correlation signals (spray/churn/dilution), then
  * detection operates at `/48` scope, and
  * enforcement remains scoped to **offending `/64` prefixes** unless otherwise stated by policy.

2. If the system observes correlation activity across **≥ 4 distinct `/48`** under the same `/40`, then
  * detection also operates at `/40` scope (anti “/48 boundary spray”),
  * enforcement remains scoped to offending `/48` and `/64` prefixes.

3. If correlation activity spans **≥ 8 distinct `/40`** under the same `/32`, then
  * detection operates at `/32` scope,
  * enforcement remains scoped to offending `/40`/`/48`/`/64` prefixes.

**Purpose:** Detect large-scale IPv6 spray without blanket-banning all users in a macro-scope.

**Usage:**

* Advisory limits
* Correlation rules
* Never used alone for final account blocks

---

### 3.2 K2 — IP Prefix + User-Agent

```
K2 = IP_PREFIX + UA
```

**Purpose:**

* Differentiate devices behind shared IPs
* Reduce NAT/VPN false positives
* Provide a safer fallback when DeviceFP confidence is LOW

---

### 3.3 K3 — IP Prefix + Device Fingerprint

```
K3 = IP_PREFIX + DeviceFP
```

**Purpose:**

* Device-scoped abuse detection
* Fair enforcement under shared IPs

**Confidence Rule:**
If `DeviceConfidence = LOW` (passive-only), K3 is permitted for throttling but MUST NOT be the sole basis of a global DeviceFP block (see `DECISION_MATRIX.md` 5.3).

---

### 3.4 K4 — Account Identifier

```
K4 = AccountID
```

**Purpose:**

* Protect accounts independently of network
* Detect distributed attacks
* Provide account memory across device rotation

**Rules:**

* AccountID MUST be a blind index or internal ID
* Raw usernames/emails MUST NOT be used

---

### 3.5 K5 — Account Identifier + Device Fingerprint

```
K5 = AccountID + DeviceFP
```

**Purpose:**

* Detect repeated failures from the same device
* Differentiate legitimate retries from attacks

**Constraint:**

* K5 MUST NOT be the only persistence mechanism
* Account-wide protection via K4 is mandatory
* K5 failures are subject to per-device micro-caps for budget eligibility (see `DECISION_MATRIX.md` 2.4.1 and §4.5.2)
* K5 presence alone does NOT prove a device was previously verified for the account
  (see `DEVICE_FINGERPRINT.md` §4.3)

---

## 4. Key Construction Rules

### 4.1 Normalization

All key components MUST be normalized before hashing:

* IP normalized to canonical form
* IPv6 normalized per hierarchical aggregation rules
* UA normalized (major version only)
* Device fingerprints normalized per `DEVICE_FINGERPRINT.md`
* Account identifiers pre-hashed or blind-indexed

Normalization MUST be deterministic and versioned.

---

### 4.2 Hashing

* Keys MUST be hashed using a keyed hash (HMAC)
* Plain hashes are forbidden
* HMAC secrets MUST be server-side only
* Key rotation MUST be supported
* Hash output MUST NOT be reversible

---

### 4.3 Key Rotation (Survival Contract)

Key rotation MUST NOT erase active enforcement.

Required behavior:

* Dual-key window is mandatory:

  * writes use `key_v2`
  * reads check `key_v2` then `key_v1`
* Window duration MUST be ≥ max block duration or key TTL
* After window expiry, old keys MAY be dropped

#### 4.3.1 Budget & Micro-Cap Rotation Survival (Owner-Safety)

§4.3 survival applies to **all budget state**:

* K4 budget epoch/count
* K5 micro-cap budget
* Budget cooldown marker

Rule: `writes → V2`, `reads → V2 then V1`.

**Forbidden merge:** K4 budget counters and K5 micro-cap counters MUST NOT be read as
`max(v1.count, v2.count)`. Taking the maximum across versions silently discards cumulative
history: during rotation each counter is a **single logical budget state**, not two
independent counters. The `max()` merge acceptable for decaying **scores** is NOT acceptable
for **cumulative budget counts** — a budget counter must never appear to jump backward or
“reseed” under rotation.

#### 4.3.2 Atomic Budget Seeding Across Rotation (Capability Interface)

The current `RateLimitStoreInterface` cannot carry a budget epoch from V1 into V2 while
preserving `count`, `epochStart`, the fixed epoch end, and atomic concurrency. The
architecture therefore adopts an **additive capability interface**, implemented by stores
that must hand a V1 budget epoch forward into V2. `RateLimitStoreInterface` itself stays
unchanged, so existing store implementations remain source-compatible:

```php
interface BudgetSeedStoreInterface extends RateLimitStoreInterface
{
    public function incrementBudgetWithSeed(
        string $key,
        int $epochDurationSeconds,
        BudgetStateDTO $seed,
        int $amount = 1
    ): BudgetStateDTO;
}
```

Locked semantics:

* The operation is atomic on the current/V2 key.
* If a valid V2 budget already exists: the seed is **ignored** and the existing V2 state is
  incremented.
* If no V2 budget exists and the seed is still inside its epoch:
  initialize V2 with `seed.count + amount`, same `seed.epochStart`; expiry stays derived
  from the original epoch start (fixed epoch end preserved).
* If the seed is expired: start a normal new epoch.
* Concurrent initialization MUST NOT duplicate the seed or lose increments.

The contract is used for **both** `K4` account budget and `K5` same-device micro-cap. Key
rotation therefore never acts as a reset and never extends the 24h epoch.

**Runtime integration contract (implemented):**

* `EvaluationPipeline` continues to accept `RateLimitStoreInterface`.
* Paths that do not require a V1→V2 budget migration proceed without any additional
  capability.
* When a previous-secret budget state is valid and must be moved to V2:
  * the store MUST also be a `BudgetSeedStoreInterface`;
  * if the capability is not available, silent reset or loss of enforcement is forbidden;
  * the path MUST fail explicitly and pass through the existing failure semantics
    (`docs/FAILURE_SEMANTICS.md`).
* Runtime integration status:
  * **K4 account budget — implemented.** `EvaluationPipeline` implements the §4.3.1 read
    rule directly: budget reads resolve V2 first and fall back to V1 only when no valid V2
    state exists (never a `max`), and budget writes use `incrementBudgetWithSeed()` when a
    valid V1 epoch must be carried into V2.
  * **K5 micro-cap — pending.** The K5 micro-cap key embeds `fingerprintHash`, and
    `DeviceIdentityResolver` derives `fingerprintHash` from the current secret
    (`deviceFp_v2 = HMAC(rawFingerprint, new_secret)`), so the historical micro-cap key is
    keyed by `deviceFp_v1`. Surviving a fingerprint-secret rotation therefore requires the
    previous fingerprint hash (dual-fingerprint rotation support, §4.3.3 and
    `docs/DEVICE_FINGERPRINT.md` §5.1), which is not wired into the runtime yet. The
    §4.3.1/§4.3.2 survival requirement above remains locked and required; it is simply not
    yet implemented.

This preserves source compatibility for existing store implementations while keeping the
rotation-survival contract intact.

#### 4.3.3 Device-Derived Key Pairing Under Fingerprint-Secret Rotation

§4.3.1/§4.3.2 handle outer-storage-key rotation for budget state. Fingerprint-secret
rotation is a separate axis: when the Fingerprint HMAC secret changes, the device
fingerprint itself changes. Device-derived keys must therefore pair the **matching**
fingerprint version with the matching outer secret.

**Current gap (documented as-is):** `EvaluationPipeline` builds the previous-secret
device-derived keys today using the previous outer key secret plus the **current**
`fingerprintHash`. That does not represent the historical K3/K5 key when the fingerprint
secret itself changed. This affects at least:

* K3 active block lookup
* K5 active block lookup
* K3/K5 score fallback
* K5 micro-cap

K4 is unaffected because it does not embed `DeviceFP`.

**Pipeline boundary:** the pipeline MUST NOT rebuild the raw fingerprint or re-hash device
material; it consumes the already-resolved `fingerprintHash` / `previousFingerprintHash`
from the identity layer (`docs/DEVICE_FINGERPRINT.md` §5.1).

**Exact pairing (locked):**

| Version     | Construction                                             |
| ----------- | ------------------------------------------------------- |
| V2 / current  | `current outer key secret + current fingerprintHash`      |
| V1 / previous | `previous outer key secret + previousFingerprintHash` (when non-null) |

If the fingerprint secret did not change, the previous identity may be logically equal to
the current one.

**Forbidden cross-pairing:**

* `previous outer secret + current fingerprint` — when `previousFingerprintHash` is
  available
* `current outer secret + previous fingerprint`

**Outer-key-only rotation compatibility:** a configured `previousKeySecret` does not imply
that the fingerprint secret changed. When `previousFingerprintHash === null`, the
device-derived V1 lookup MAY use the current `fingerprintHash` as a compatibility behavior
for the scenario where only the outer storage key rotated while the fingerprint identity
secret stayed stable. This is documented explicitly:

> If the Fingerprint HMAC secret itself was rotated, the host/default resolver MUST provide
> the previous fingerprint hasher during the rotation window.

The library does not guess whether the host rotated the fingerprint secret or not.

**Write-current / read-previous rule:** every new runtime write uses only the current pair
(`current outer key secret + current fingerprintHash`). The previous fingerprint is
**read/migration-compatibility state only**; writing to historical V1 device keys is
forbidden.

**K3 / K5 active block lookup:** after implementation, check the V2/current pair first, then
the V1/previous pair, so an active historical block does not disappear during rotation.

**K3 / K5 score state:** score fallback uses the historical V1 key built from the previous
fingerprint. Existing score merge/decay semantics are unchanged by this decision; this is an
architecture gate, not a score-semantics redesign.

---

### 4.4 Namespacing & Scoping

All keys MUST include:

* Action / policy identifier
* Algorithm & version
* Environment scope
* Module scope (`rate_limiter`)

This prevents cross-action and cross-module leakage.

### 4.5 Auxiliary Budget Keys (Locked)

Budget owner-safety uses **dedicated auxiliary keys** — never the K4 score key and never the
K4 budget epoch/count key.

#### 4.5.1 Budget Cooldown Marker

Logical namespace (before HMAC):

```
{policy}:rate_limiter:budget_cooldown:v1:{env}:{accountId}
```

* The stored key is HMAC-ed with the same keyed-hash strategy as all other keys (§4.2).
* The marker uses the **existing atomic fixed-TTL primitive** `RateLimitStoreInterface::increment(key, cooldownTTL)`:
  first creation sets the TTL, subsequent increments do **not** extend it — a no-extension
  cooldown by construction (no new Store primitive required).

Atomic acquisition (locked):

```
if active V2 marker exists      → cooldown active
else if active V1 marker exists → cooldown active
else atomic increment(V2, cooldownTTL)
     issuance allowed only when returned value == 1
```

* `get()` + `set()` acquisition is **forbidden** (not atomic under concurrency).
* Only the first increment inside a cooldown returns `1`; the issuer acquires the cooldown,
  later concurrent issuers return `> 1` and MUST NOT re-issue.
* The marker is part of Budget enforcement, never part of the 24h epoch, and never a
  level-1 `BlockState`.
* Rotation survival (§4.3.1) applies: writes → V2; reads → V2 then V1.

#### 4.5.2 K5 Micro-Cap Counter (Known-Device Budget Eligibility)

Logical namespace (before HMAC):

```
{policy}:rate_limiter:microcap:k5:{ver}:{env}:{accountId}:{deviceFp}
```

* A fixed-epoch (24h) counter per known device used to decide when same-device failures
  become budget-eligible (`DECISION_MATRIX.md` §2.4.1).
* Subject to rotation survival (§4.3.1): never `max(v1, v2)`, atomic seeding per §4.3.2.
* Under fingerprint-secret rotation the device fingerprint component is versioned
  (`FingerprintHasher` remains single-secret; the identity layer resolves both versions of
  the same normalized identity):

  ```
  V2 micro-cap = current fingerprintHash
  V1 micro-cap = previousFingerprintHash (when available)
  ```
* Locked micro-cap rotation rule:

  ```
  V2 exists           → V2 authoritative (normal V2 increment)
  V2 absent + valid V1 → atomic seed V1 → V2 via
                         BudgetSeedStoreInterface::incrementBudgetWithSeed()
  no V1                → normal V2 start
  ```
* Because the micro-cap is a **cumulative budget state**, the two versions MUST NOT be read
  as `max(v1, v2)` (see §4.3.1). The fixed epoch/count do not change because of rotation.
* Implementation status: **pending.** It requires `previousFingerprintHash` from the
  identity layer (`docs/DEVICE_FINGERPRINT.md` §5.1), which is architecture-locked but not
  yet wired into the runtime.

---

## 5. Key Usage by Context

### 5.1 Login / Password Authentication

Primary:

* K4 (Account)
* K5 (Account + Device)

Secondary:

* K2, K3, K1

Rules:

* IP-only keys MUST NOT cause final account blocks
* Missing fingerprint accelerates K4 escalation only
* Budget cooldown markers and K5 micro-cap counters are auxiliary keys (§4.5); they are
  budget-enforcement state, never score keys and never `BlockState`

---

### 5.2 OTP / Step-Up Verification

Primary:

* K4
* K5

Secondary:

* K2

Rules:

* OTP enforcement MUST be account-centric
* IP-only signals are advisory

---

### 5.3 API Heavy / Brute Endpoints

Primary:

* K2
* K3

Secondary:

* K1

Account keys optional based on endpoint nature.

---

## 6. Correlation-Oriented Key Usage

Correlation relies on **relationships between keys**, not single counters.

### 6.1 Canonical Correlations

* Many K4 under one K1 → credential spray
* Many K3 under one K4 → distributed account attack
* Rapid churn of K3 under one K2 → device evasion
* Same DeviceFP across many K1 prefixes → fingerprint dilution

---

### 6.2 Queryability Requirement

Correlation storage MUST support one of:

* Atomic counters with TTL, OR
* Bounded exact sets, OR
* Approximate distinct counting only if:

  * error ≤ 2%
  * safety margin applied
  * near-threshold detections set WATCH flags (see `DECISION_MATRIX.md` 5.0)
  * confirmation window required for high-impact decisions

If guarantees cannot be met, exact bounded sets are mandatory.

---

## 7. Key Explosion & Flood Guards (Mandatory)

The system MUST protect against adversarial key creation.

Required constraints:

* Cap new DeviceFP-related keys per AccountID and per IP prefix
* After caps are exceeded:

  * DO NOT create additional unique keys
  * Route to a **rate-limited ephemeral bucket**
  * Ephemeral bucket MUST:

    * have TTL ≤ 30 minutes
    * NOT inherit historical **penalties**
    * MUST still honor **active blocks** (see `DEVICE_FINGERPRINT.md` 7.3 and `DECISION_MATRIX.md` 2.1 invariant)
    * escalate correlation signals only
    * continue to accumulate K4 scoring/budget signals

This prevents storage exhaustion without providing block evasion.

---

## 8. Anti-Patterns (Forbidden)

* IP-only blocking for authentication
* Raw email/username in keys
* Unbounded custom keys
* Per-request random keys
* Cross-policy shared counters
* Cross-module key reuse

Violations invalidate the security model.

---

## 9. Privacy Guarantees

The key strategy ensures:

* No raw identifiers are stored
* No cross-context tracking
* Keys expire naturally via TTL and decay
* Fingerprints are **risk signals, not identities**

Rules:

* Fingerprint presence ≠ trust
* Inconsistency > absence in severity
* Stability combined with abuse escalates risk

---

## 10. Stability & Versioning

* Key definitions are part of the public contract
* Any change requires:

  * version bump
  * documentation update
  * migration strategy with rotation survival

---

**This document is authoritative.
Key strategy MUST NOT be altered without explicit versioning and security review.**
