# Device Fingerprint — Official Specification

**Module:** RateLimiter
**Namespace:** `Maatify\RateLimiter`
**Status:** LOCKED — Behavioral & Privacy Contract
**Spec Version:** `1.2.0`

This document defines the **Device Fingerprint system** used by the Rate Limiter.
It specifies how device identity is derived, normalized, hashed, bounded, and evaluated.

The Device Fingerprint is designed to be:

* Privacy-respecting
* Deterministic
* Storage-agnostic
* Resistant to trivial, synthetic, and replay-based evasion
* Suitable for security decisions (not tracking, not identity)

---

## 1. Purpose

The Device Fingerprint exists to support:

* Rate limiting decisions
* Attack correlation
* Distributed and multi-device attack detection
* Reduction of false positives caused by IP-only checks
* Fair enforcement in shared IP environments

It is **not** intended for:

* User tracking
* Cross-site identification
* Advertising or analytics
* Acting as an authentication factor
* Acting as proof of device ownership

---

## 2. Core Principles (Non-Negotiable)

* Raw headers MUST NOT be stored
* Raw fingerprint components MUST NOT be persisted
* Only hashed fingerprints may be stored or logged
* Fingerprints are probabilistic risk signals, not identities
* Fingerprints MAY change naturally
* Absence of fingerprint data is a security signal, not a fault
* Fingerprints MUST be bounded to prevent storage abuse
* Fingerprints MUST NOT be treated as trusted identifiers
* Account-level signals always override device-level signals
* Passive-only fingerprints MUST NOT be used to trigger global DeviceFP blocks
* “Previously verified for this account” is a **host-proven fact**, never a
  fingerprint-derived inference; fingerprint presence does not equal verified provenance

---

## 3. Fingerprint Levels

The system defines **three fingerprint levels**.
Levels increase confidence but NEVER replace account-level protection.

### 3.1 Level 1 — Passive Fingerprint (Mandatory)

**Source:** Backend-only
**Availability:** Always available

#### Inputs (Normalized)

* User-Agent (major version only)
* Accept-Language (normalized and ordered)
* Platform / OS hints
* HTTP/TLS-level hints (if available)

These are permitted low-entropy passive signal categories, not mandatory inputs
to the default resolver. The default `DeviceIdentityResolver` is intentionally
minimal and deterministic: it consumes the explicit user agent supplied in the
context and does not inspect `RateLimitContextDTO::$headers` or harvest
Accept-Language, platform, HTTP, or TLS material automatically. A custom
`DeviceIdentityResolverInterface` may use allowed low-entropy passive inputs
explicitly supplied by its host, while remaining within this privacy contract.

#### Output

* `passive_fingerprint` (hashed)

#### Properties

* Requires no client-side code
* Available on first request
* Lowest confidence
* Baseline signal only
* MUST NOT be used alone for blocking decisions
* MUST NOT be used for `HARD_BLOCK(DeviceFP)` decisions (see `DECISION_MATRIX.md` 5.3)

---

### 3.2 Level 2 — Client-Assisted Fingerprint (Optional)

**Source:** Minimal client-provided hints
**Availability:** Optional

#### Client Inputs

* Timezone offset (bucketed)
* Screen resolution (bucketed)
* Platform hint
* Browser major version
* Client-generated random ID (local storage)

#### Mandatory Constraints

* Inputs MUST be normalized
* High-entropy fingerprinting (canvas, audio, WebGL, fonts) is FORBIDDEN
* Client ID MUST be random, opaque, and non-derivable
* Client ID MUST NOT be treated as stable identity
* Absence MUST NOT block alone

`clientFingerprint` is a Host-provided, already-normalized/bucketed collection
of low-entropy hints. The package does not collect browser data or invent a
timezone, screen, platform, JavaScript, canvas, audio, WebGL, or font schema.
The default resolver owns only deterministic canonical serialization and HMAC
derivation.

#### Output

* `client_fingerprint` (hashed)

#### Properties

* Medium confidence
* Improves differentiation under NAT/VPN
* Fully optional
* Absence increases suspicion weight only

---

### 3.3 Level 3 — Session-Bound Device Identifier (Optional)

**Source:** Server-generated
**Availability:** Post-authentication only

#### Behavior

* Server generates a cryptographically random identifier
* Identifier is:

  * Bound to authenticated AccountID
  * Stored in an HttpOnly, Secure cookie
* Rotation MUST NOT reset account-level penalties
* Loss MUST NOT imply trust reset

#### Output

* `session_device_id` (hashed)

#### Properties

* Highest confidence
* Account-scoped
* Advisory only
* NOT proof of device ownership

---

## 4. Device Identity Resolution

All fingerprint levels are combined into a single resolved identity.

### 4.1 DeviceIdentity Components

* Passive fingerprint (mandatory)
* Client fingerprint (optional)
* Session device identifier (optional)
* Confidence level (derived)
* Stability flag (derived)

The default resolver is stateless. It does not infer cross-request churn from
headers or local history; churn state remains the responsibility of runtime
correlation paths or an explicitly stateful custom resolver.

### 4.2 Confidence Levels

| Signals Present            | Confidence |
| -------------------------- | ---------- |
| Passive only               | LOW        |
| Passive + Client           | MEDIUM     |
| Passive + trusted Session  | HIGH       |
| Passive + Client + trusted Session | HIGH       |

The trusted session identifier is sufficient for `HIGH`; client-assisted hints
are optional. `isSessionTrusted = true` without a non-empty
`sessionDeviceId` does not create a trusted session. The host remains
responsible for proving that a supplied session identifier is server-issued and
bound to the correct account.

**Rule:**
Confidence affects **scoring weight** and certain correlation enforcement constraints; never authorization.

---

### 4.3 Previously Verified for Account (Known Device)

“Known device” and “previously verified device” are **account-scoped** facts, not
fingerprint-presence facts.

* A **trusted session device** is known/verified because it already requires a proven
  association with the `AccountID` (§3.3; `DECISION_MATRIX.md` §0).
* A **previously verified device for this account** (known K5) is a device for which the
  host proves a prior verified association with that `AccountID`, independently of any
  active trusted session (e.g. verified via out-of-band token, a prior successful
  authenticated session, or account settings).

The **public context/device-identity contracts represent** the second case via a
**host-provided signal** carried on `RateLimitContextDTO` and `DeviceIdentityDTO` and
propagated through `DeviceIdentityResolver`:

```
isDevicePreviouslyVerifiedForAccount   // default: false
```

Resolved known-device semantic (owned here, consumed by `DECISION_MATRIX.md` §0):

```
isKnownForAccount = isTrustedSession OR isDevicePreviouslyVerifiedForAccount
```

Rules:

* The **host** is the only authority for proving a previous verified association; the
  RateLimiter performs no independent verification and no inference from `K5` presence,
  `DeviceConfidence = HIGH`, or external storage.
* A previously-verified signal does **not** create a trusted session and does **not**
  raise confidence on its own; `isTrustedSession` and
  `isDevicePreviouslyVerifiedForAccount` are independent contracts.
* The existence of a `K5` counter in RateLimiter storage **does not, by itself**, mean the
  device was previously verified. `K5` presence is a risk-history signal (`§9`), not proof
  of device ownership.
* `DeviceConfidence = HIGH` alone is **not** a substitute for “previously verified”: HIGH
  confidence describes signal strength, not a verified account association.

---

## 5. Fingerprint Hashing

All fingerprints MUST be hashed using a keyed hash (HMAC).

### Hashing Rules

* Plain hashes are forbidden
* Secrets MUST be server-side only
* Hashes MUST be module-scoped
* Hash output MUST be deterministic
* Hash rotation MUST preserve enforcement continuity
* Fingerprint hashes MUST NOT be reused outside RateLimiter

---

### 5.1 Fingerprint-Secret Rotation — Dual-Fingerprint Rotation Contract

The implemented identity layer resolves the fingerprint component of the **current** and of at
most one **previous** generation:

```
fingerprintHash         = HMAC(normalized raw device identity, current fingerprint secret)
previousFingerprintHash = HMAC(normalized raw device identity, previous fingerprint secret)  // when configured
```

Current identity-layer shape:

* `FingerprintHasher` owns a single secret per instance.
* `DeviceIdentityResolver` holds a current hasher plus an optional previous hasher.
* `DeviceIdentityDTO` carries `fingerprintHash` and the nullable `previousFingerprintHash`
  (additive last field; `null` when no previous hasher is configured).

When the Fingerprint HMAC secret is rotated, the same normalized raw identity produces two
distinct hashes:

```
deviceFp_current  = HMAC(rawIdentity, currentFingerprintSecret)
deviceFp_previous = HMAC(rawIdentity, previousFingerprintSecret)
```

and therefore `deviceFp_current != deviceFp_previous` in general. This section fixes the
**identity-layer contract** that keeps historical enforcement readable across that rotation.

`fingerprintHash` is the **current-generation** fingerprint component;
`previousFingerprintHash` is the **previous-generation** fingerprint component. The outer key
secret and the fingerprint secret are independently rotatable components, but runtime
continuity is represented as **one coordinated current generation and at most one previous
generation** — never as independent Cartesian combinations of outer-secret and fingerprint
versions. The rotation-generation invariant is locked in §5.1.6.

Implementation status:

```
Dual-fingerprint architecture                          = locked
DeviceIdentityDTO.previousFingerprintHash              = implemented
Default resolver optional previous hasher              = implemented
K3/K5 persistent two-generation pipeline integration  = implemented
K5 micro-cap two-generation migration                  = implemented
Correlation/ephemeral rotation                         = pending separate design
```

#### 5.1.1 Public Identity Contract

The pipeline MUST NOT rebuild the raw fingerprint or re-hash device material itself. The
normalized raw identity remains owned by `DeviceIdentityResolver` only.

Locked public identity contract:

```
fingerprintHash          // current-generation fingerprint component
previousFingerprintHash  // previous-generation fingerprint component, nullable
```

`previousFingerprintHash` is an **additive optional field at the end of `DeviceIdentityDTO`**
representing the **previous-generation fingerprint component**:

```
public ?string $previousFingerprintHash = null
```

It MUST NOT change the meaning of the existing `fingerprintHash`. It does not stand alone: it
is consumed as one member of the previous generation (`docs/KEY_STRATEGY.md` §4.3.3), paired
with the previous-generation outer secret (`previousKeySecret ?? currentKeySecret`) — never
recombined across generations.

#### 5.1.2 Resolver Responsibility

`DeviceIdentityResolverInterface::resolve(RateLimitContextDTO $context): DeviceIdentityDTO`
remains unchanged. The default resolver builds the normalized raw identity **exactly once**:

```
v2|normalizedUa|normalizedClientFp|sessionDeviceId
```

then hashes the **same identity twice**:

```
fingerprintHash         = currentHasher(rawIdentity)
previousFingerprintHash = previousHasher(rawIdentity)   // only when configured
```

The default resolver constructor is additive:

```
new DeviceIdentityResolver(
    FingerprintHasher $currentHasher,
    ?FingerprintHasher $previousHasher = null
)
```

The old single-hasher constructor remains valid.

Rules:

* There MUST be no normalization difference between the two hashes; normalization is
  identical for the current and the previous version.
* `normalizedClientFp` uses deterministic JSON serialization: associative-map keys are
  sorted lexicographically at every depth and encoded as JSON objects, list order is
  preserved, and scalar/null types are preserved. This preserves map/list structural
  identity. A null or empty client payload is omitted from the identity.
* A non-serializable client payload MUST raise a package-owned exception. It MUST NOT
  become an empty string or silently downgrade `MEDIUM` confidence to `LOW`.
* `FingerprintHasher` stays single-secret: each instance is responsible for exactly one
  secret. The resolver applies both hashers to the same normalized raw identity.
* Raw fingerprint material MUST NOT leave the resolver, MUST NOT be stored, MUST NOT be
  logged, and MUST NOT appear in `DeviceIdentityDTO`.

#### 5.1.3 Host Responsibility During Fingerprint-Secret Rotation

If the host rotates the Fingerprint HMAC secret itself, the host/default resolver MUST
provide the previous fingerprint hasher — and therefore `previousFingerprintHash` — during
the rotation window. The library does not guess whether the host rotated the fingerprint
secret or not.

If the host does not rotate the fingerprint secret, `previousFingerprintHash = null` is the
expected value, and the previous generation reuses the current fingerprint component as a
compatibility behavior (`docs/KEY_STRATEGY.md` §4.3.3). `previousFingerprintHash` exists only
to represent the fingerprint component of a genuinely previous generation.

#### 5.1.4 Trust & Confidence Independence

Adding `previousFingerprintHash` MUST NOT change any of:

```
confidence
isTrustedSession
isDevicePreviouslyVerifiedForAccount
isKnownForAccount
```

`previousFingerprintHash` MUST NOT be interpreted as a trusted device or a known device. It
is only a **rotation-compatibility alias** for the same resolved device material.

#### 5.1.5 Correlation / Ephemeral Boundary

`fingerprintHash` is also used directly in `EphemeralBucket`, churn distinct sets, dilution
keys, and new-device/flood correlation state. These are NOT the same problem class as the
K3/K5 persistent-key migration, and they do not currently have an atomic alias/migration
contract.

Locked boundary statement:

```
Dual fingerprint contract enables historical K3/K5 and K5 micro-cap continuity.

Full fingerprint-secret rotation continuity for correlation/ephemeral state
is NOT yet claimed by this decision.
```

This section does not resolve correlation/ephemeral state. That remains a known
architecture boundary within this documentation only.

#### 5.1.6 Rotation-Generation Invariant (No Overlapping Generations)

The architecture supports only a **current generation plus at most one previous generation**.
The following invariant is locked:

```
A second secret rotation affecting either component MUST NOT begin
while an earlier previous generation still needs enforcement continuity.
```

* A new rotation (outer key secret, fingerprint secret, or both) MUST NOT begin until the
  previous-generation enforcement window has ended or the required state has been migrated or
  expired according to its contract.
* This prevents more than one historical cumulative state — in particular for the K5
  micro-cap, whose cumulative budget state cannot be merged safely across multiple
  generations (never `max(v1, v2)`; `docs/KEY_STRATEGY.md` §4.3.1 / §4.5.2).
* Overlapping or multi-generation rotation is outside the supported model.

---

## 6. Normalization Rules

To ensure stability and collision resistance:

* UA normalized to major version only
* Languages normalized, ordered, and truncated
* Screen resolution bucketed coarsely
* Platform identifiers canonicalized
* Missing values normalized explicitly (never omitted)
* Normalization rules MUST be versioned

The default resolver's canonical user-agent output is one of:

```text
chrome/<major>
firefox/<major>
edge/<major>
opera/<major>
safari/<major>
other/0
```

Opera and Edge tokens are matched before Chrome; Safari uses the browser
`Version/<major>` token and never its `Safari/<build>` token. Unknown user agents
use `other/0` and never retain a raw substring.

---

## 7. Churn, Evasion & Flood Protection

### 7.1 Churn Detection (Mandatory)

The system MUST detect:

* Rapid fingerprint changes under same IP + UA
* Fingerprint disappearance after prior presence
* Excessive “first-seen” fingerprints on auth endpoints
* Same fingerprint reused across many IP prefixes
* Oscillation between present/missing fingerprints

---

### 7.2 Mandatory Responses

Detected churn or evasion MUST trigger:

* Increased scoring weight
* Accelerated escalation
* Correlation-based enforcement

Churn MUST NOT create unlimited keys.

---

### 7.3 New Device Flood Guard (Mandatory) — Ephemeral Bucket Contract

To prevent storage exhaustion and account poisoning:

* Hard caps MUST exist on:

  * New fingerprints per AccountID
  * New fingerprints per IP prefix
* Caps MUST be time-windowed and bounded
* After cap is exceeded:

  * DO NOT create new fingerprint keys
  * Route attempts to an **ephemeral device bucket**

#### 7.3.1 Ephemeral Bucket Properties (LOCKED)

Ephemeral bucket MUST:

* Have TTL ≤ 30 minutes
* NOT inherit historical **penalties**
* NOT create persistent device identities / keys
* Continue to accumulate **K4 (Account)** scoring and budget signals
* Escalate **correlation signals** only (bounded sets/counters)

#### 7.3.2 Critical Safety Invariants (Anti “Ephemeral Ghost”)

Ephemeral bucket MUST NOT be a bypass:

1. **Active blocks are always enforced**
  * Pre-attempt hard-block checks (K4/K5/K3/K1) MUST run **before** deciding to route to ephemeral.
  * If a request presents a DeviceFP that is already under active `HARD_BLOCK` (K3/K5), that block MUST apply.

2. **Ephemeral routing cannot “erase” a block**
  * Ephemeral mode may avoid creating new keys, but MUST still consult existing active block state.

3. **Ephemeral routing is scoped**
  * Ephemeral applies per (IP_PREFIX, AccountID) context; it MUST NOT become a global “unknown device” bucket.

---

## 8. Replay, Pollution & Impersonation Resistance

* Fingerprints MUST NOT be treated as secrets
* Replay MUST NOT grant trust or stability
* Captured fingerprints MUST NOT allow impersonation
* Fingerprint dilution MUST NOT permanently block legitimate users
* Account-level signals MUST dominate all outcomes

Device fingerprints accelerate suspicion; they do not define guilt.

---

## 9. Usage in Rate Limiting

Device fingerprints are used to construct:

* `IP_PREFIX + DeviceFP` (K3)
* `AccountID + DeviceFP` (K5)

Rules:

* Device-aware keys are preferred over IP-only
* Device-only enforcement MUST NOT override account protection
* Device trust MUST decay naturally
* Absence is less severe than inconsistency
* `K5` presence alone does NOT mean the device was previously verified for the account
  (§4.3); only the host can prove a verified association
* Passive-only fingerprints cannot trigger `HARD_BLOCK(DeviceFP)` (see `DECISION_MATRIX.md` 5.3)

---

## 10. Privacy & Compliance Guarantees

This system guarantees:

* No raw fingerprint inputs stored
* No cross-context or cross-module tracking
* No permanent identifiers
* Mandatory TTL on all fingerprint data
* Privacy-by-design compliance

Frequency analysis MUST NOT be used for identity inference.

---

## 11. Stability & Versioning

* Fingerprint algorithms are versioned
* Any change requires:

  * Version bump
  * Migration strategy
  * Changelog entry
* Published old versions MUST remain readable during transition

For this pre-release WU, the `v1` normalized identity has no published or
deployed consumer evidence and therefore receives no compatibility shim. If
such evidence appears, implementation MUST stop for Lead review before any
state reset or migration strategy is chosen.

---

**This document is authoritative.
Any deviation requires explicit versioning and security approval.**
