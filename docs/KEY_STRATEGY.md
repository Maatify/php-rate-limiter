# RateLimiter — Key Strategy (Official)

**Package:** maatify/php-rate-limiter
**Namespace:** `Maatify\RateLimiter`
**Status:** LOCKED — Design & Security Contract

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
  * Amendment under DEC-022 (2026-10-20), for a policy that opts into the admission reservation
    (DEC-024) only: the **source** (an IPv4 address or an IPv6 `/64`) may bound unproven traffic as a
    per-window **capacity** (the source capacity, the net failure cap and the cross-account
    proven-door cap of DEC-034, `DECISION_MATRIX.md` §12). It never identifies anyone, never persists,
    never escalates and never blocks: a refusal is a non-persisted `SOFT_BLOCK`. For identity the
    source stays advisory, as above
* Device-aware keys are preferred whenever available
* Account-scoped keys are mandatory for authentication flows
* Keys must be deterministic and stable
* Keys must be privacy-safe (no raw PII)
* Keys MUST be bounded: no unbounded per-request key creation

---

## 3. Canonical Evaluation Keys

The package defines a **fixed, minimal key set**.

### 3.1 K1 — IP / IP Prefix (Hierarchical IPv6 Aggregation)

```
K1 = IP_PREFIX
```

**Definition:**

* IPv4: exact IP address
* IPv4-mapped IPv6 (`::ffff:0:0/96`, e.g. `::ffff:192.0.2.1`): treated as the embedded
  IPv4 address (`192.0.2.1`). The canonical source prefix is the unwrapped dot-decimal IPv4
  address. Enforcement and Operational Read use the same K1, K2 and K3 keys as plain IPv4.
  Local fallback and admission source scopes also share state with plain IPv4. IPv4-mapped addresses never participate in adaptive IPv6
  hierarchical aggregation.
* Other transition formats (e.g. deprecated IPv4-compatible IPv6 `::a.b.c.d` / `::0:0/96`)
  are out of scope for unwrapping. Their IPv6 prefix comes from their own leading bits
  (for example, `::192.0.2.1` has `/64` prefix `0000000000000000`).
* IPv6: canonical enforcement normalization is `/64`; adaptive aggregation is
  correlation-only:

#### IPv6 Hierarchical Aggregation (Required)

Correlation storage MUST support grouping IPv6 prefixes into larger detection-only scopes:

* `/64` → `/48` → `/40` → `/32`

**Adaptive Activation (Deterministic):**

Within one fixed 600-second window, the bounded activation chain is:

1. `/48` becomes active only after **2 distinct participating `/64` members**;
2. `/40` becomes active only after **4 distinct active `/48` members**;
3. `/32` becomes active only after **8 distinct active `/40` members**.

The activation sets are capped at `2`, `4`, and `8` respectively. Duplicate
members do not increase cardinality or refresh the fixed TTL. Inactive child
scopes do not participate. These activation thresholds do not use the general
`N-1` WATCH rule: `1/2`, `3/4`, and `7/8` are not decisions or enforcement
states.

The only participants are eligible Login/OTP `checkOnly()` credential-spray
observations and requests with a fingerprint entering the bounded churn or
dilution path. IPv4 never creates this hierarchy state.

`/48`, `/40`, and `/32` MUST NOT be represented as K1 enforcement keys. They
have no score, block, level, retry-after, or `PenaltyLadder` state. Spray and
churn may observe active macro scopes, but enforcement persists only the
current canonical `/64` K1 or `/64 + UA` K2. Dilution remains fingerprint-global
over canonical `/64` members and never replaces them with macro members.

Hierarchy scopes, bridges, churn anchors, and adaptive churn/spray observations use canonical
length-prefixed framing under `DEC-047` (`NonAccountScopeKeyDeriver`, magic
`maatify.rate-limiter.non-account.f1\x00`, HMAC key domain
`maatify.rate-limiter.key-domain.non-account.f1`, with registered `ipv6_adaptive.*` purposes and
`cidr` encoded as a canonical decimal string).
The `/48` member is the opaque canonical `/64` K1; `/40` and `/32` members are
opaque adaptive child-scope keys. Raw IPv6 addresses and textual prefixes never
cross the correlation-store boundary.

When an outer key secret rotates, the hierarchy uses the current generation,
one read-only previous generation, and a current-owned bridge whose TTL is
bounded by the previous remaining TTL. Fingerprint-only rotation changes only
macro churn members and does not create a previous IPv6 hierarchy generation.

**Purpose:** Detect large-scale IPv6 spray without blanket-banning all users in a macro-scope.

**Usage:**

* Correlation detection only
* Never used as an enforcement or block key

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

**Admission family K4P (DEC-026, DEC-034 4.A; LOCKED amendment, active since 2026-10-20).** For a policy that declares
the admission capability, a request carrying the proven lane derives the account key from the key
type `k4p` instead of `k4`:

```
K4P = AccountID          // E1 purpose k4p, policy/env/account framed (§4.2)
```

Rule A only: account-wide and **not** cleared at a password change; there is no scope suffix
(rules B and C are not implemented). The slot of the pipeline's key map stays `k4`, so the DEC-007
lifecycle primitives and the DEC-003 cycle history apply to K4P with no change, as a distinct
logical identity from K4. The unproven lane and every policy that did not opt in keep `k4`. This
amendment of a LOCKED section is made under DEC-026.

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

**Admission family K5p and the K5 material (DEC-026, DEC-034 9.1; LOCKED amendment, active since 2026-10-20).** For a policy that
declares the admission capability: (1) the proven lane derives the device key from the key type
`k5p` (E1 purpose k5p with policy/env/account/material framed under §4.2) and keeps the unsuffixed
`k5` for the unproven lane; (2) `{material}` is the instance-id component `deviceIdHash` when the
device carries one, else the fingerprint component (today's derivation). The pipeline selects a
component and never re-hashes it (`DEVICE_FINGERPRINT.md` §5.1.1). The micro-cap key
(`microcap:k5` / `microcap:k5p`) and the device Anti-Equilibrium watcher
(`anti_equilibrium_device` / `anti_equilibrium_device_p`) follow the same family and material.
K3, K2, churn, dilution, the device caps and the distributed-account snapshots (whose member is
the K5 enforcement key itself) are not re-derived. A request without a lane, and every policy
that did not opt in, ignore any instance id and derive today's keys. A device that starts sending
an instance id therefore restarts its K5 once (a declared cost of DEC-026); there is no transition
window that reads the fingerprint-derived key as a previous derivation.

---

## 4. Key Construction Rules

### 4.1 Normalization

All key components MUST be normalized before hashing:

* IP normalized to canonical form
* IPv6 normalized per hierarchical aggregation rules
* UA normalized (major version only)
* Device fingerprints normalized per `DEVICE_FINGERPRINT.md`
* Account identifiers pre-hashed or blind-indexed

For K2, the canonical UA component is browser family plus major version only.
The supported canonical values are:

```text
chrome/<major>
firefox/<major>
edge/<major>
opera/<major>
safari/<major>
other/0
```

The operating system is not part of the canonical K2 UA component. Raw or
unknown UA text MUST NOT enter the key. The normal path and `LocalFallback`
use the same canonical UA semantics.

Normalization MUST be deterministic and versioned.

---

### 4.2 Hashing

* Keys MUST be hashed using a keyed hash (HMAC)
* Plain hashes are forbidden
* HMAC secrets MUST be server-side only
* Key rotation MUST be supported
* Hash output MUST NOT be reversible

Correlation members MUST also use a domain-separated keyed HMAC derived from the package key secret. Raw correlation subjects, account identifiers, usernames, and email addresses MUST NOT cross the `CorrelationStoreInterface` member boundary.

**E1 (DEC-046, mandatory for account identities).** K4/K4P, K5/K5p, micro-cap, budget cooldown and registered account auxiliary keys use only the length-prefixed framing and HMAC domain in [KEY_SCOPE_ENCODING_E1](KEY_SCOPE_ENCODING_E1.md) §3. Bytes and vectors are unchanged. Writer and operational reader instantiate the same final internal implementation from their validated environment. There is no selector, V2 account implementation or deriver injection. The Pipeline constructs its store-bound helpers on the actual validated account views and keeps EphemeralBucket on the root; advanced callers supply each existing clock role explicitly. Views, resource binding and execution use the same captured handles. See that contract's §4/§8 for custom adapter limits and the breaking new-traffic boundary.

**Non-account canonical framing `f1` (DEC-047, mandatory for K1–K3, correlation and adaptive IPv6).** K1–K3, correlation (`device_cap_ip`, `device_cap_account`, `churn`, `dilution`, `distributed_account_devices`, `distributed_account_occurrences`, and their bridges/watches/confirmations) and adaptive IPv6 keys use length-prefixed framing (`maatify.rate-limiter.non-account.f1\x00 || u32be(N) || enc(f_1)...`) with closed registered purposes (`NonAccountScopePurpose`), checked `u32be` bounds (`0..4294967295` before `pack('N')`), and HMAC subkey domain `maatify.rate-limiter.key-domain.non-account.f1` (64 lowercase hex output; no `e1.` prefix; physical store root layout `v1` unchanged). Writer and operational reader instantiate the same immutable internal implementation for K1–K3. Compound correlation anchors (`device_cap_account` `k4`/`k1`, `dilution_confirmation` `scope`/`windowId`) are framed as separate fields. Single-field `credential_spray:subject:v1:{subject}`, fixed wrappers around K1 (`credential_spray:{k1}`, `credential_spray:bridge:{k1}`, `{scope}:watch`), `LocalFallbackLimiter`, and `DeviceIdentityResolver` / `FingerprintHasher` retain their proven-injective formats. See [DEC-047](decisions/DEC-047_NON_ACCOUNT_CANONICAL_KEY_FRAMING.md) and [#281](https://github.com/Maatify/php-rate-limiter/issues/281) for the complete 33-purpose tuple table, vectors, and breaking pre-Stable boundary.

In §4.3 and existing source variable names, **V2/Current and V1/Previous are secret generations**, not legacy key encodings. Rotation and atomic budget seeding within E1 and `f1` remain required. Physical store layout `v1` is unchanged by G1/G2.

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
  * **K5 micro-cap — implemented.** The K5 micro-cap key uses the two-generation resolution
    (`previousFingerprintHash`, §4.3.3 and `docs/DEVICE_FINGERPRINT.md` §5.1). The runtime
    treats Current as authoritative and uses `incrementBudgetWithSeed()` to carry a valid
    Previous epoch into Current when required; a store without the capability fails through
    the existing failure semantics.

This preserves source compatibility for existing store implementations while keeping the
rotation-survival contract intact.

#### 4.3.3 Device-Derived Key Pairing Under Rotation (Two-Generation Model)

§4.3.1/§4.3.2 handle outer-storage-key rotation for budget state. The outer key secret and
the fingerprint secret are independently rotatable components, but runtime continuity is
represented as **one coordinated current generation and at most one previous generation** —
it is NOT a set of independent Cartesian combinations of outer-secret and fingerprint
versions.

**Runtime integration status:** the identity layer and runtime pipeline resolve both
`fingerprintHash` and the optional `previousFingerprintHash` as one coordinated current and
previous generation. The pipeline now consumes the previous-generation fingerprint component
with the previous-generation outer secret for historical lookup. This covers:

* K3 active block lookup
* K5 active block lookup
* K3/K5 score fallback
* K5 micro-cap migration

K4 is unaffected because it does not embed `DeviceFP`.

**Pipeline boundary:** the pipeline MUST NOT rebuild the raw fingerprint or re-hash device
material; it consumes the already-resolved `fingerprintHash` / `previousFingerprintHash`
from the identity layer (`docs/DEVICE_FINGERPRINT.md` §5.1).

**Generation resolution (locked):**

| Version     | Outer secret                              | Fingerprint component                         |
| ----------- | ----------------------------------------- | --------------------------------------------- |
| Current     | `currentOuterSecret`                      | `currentFingerprintHash`                      |
| Previous    | `previousKeySecret ?? currentOuterSecret` | `previousFingerprintHash ?? currentFingerprintHash` |

Informally, the previous generation is computed conceptually as:

```
previousOuterSecret =
    configured previousKeySecret
    ?? currentOuterSecret

previousFingerprintHash =
    resolved previousFingerprintHash
    ?? currentFingerprintHash
```

The **previous generation is present** when at least one component changed:

```
previousKeySecret !== null
OR
previousFingerprintHash !== null
```

This covers three rotation shapes without ambiguity:

| Rotation shape              | Previous generation                 |
| --------------------------- | ----------------------------------- |
| Outer-only rotation         | `previous outer + current fingerprint`  |
| Fingerprint-only rotation   | `current outer + previous fingerprint`  |
| Both rotated                | `previous outer + previous fingerprint` |

If neither component changed, there is no previous generation.

**Forbidden — Cartesian probing/merging:** the prohibition is NOT on individual "cross
pairings"; it is on **arbitrary Cartesian probing or merging of the four historical
combinations**. The runtime MUST NOT search all permutations of outer-secret and fingerprint
versions, and MUST NOT merge state between such combinations. Only the Current and (when
present) the Previous generation keys exist for lookup. If the Fingerprint HMAC secret itself
was rotated, the host/default resolver MUST provide the previous fingerprint hasher during the
rotation window; the library does not guess whether the host rotated the fingerprint secret or
not.

**Write-current / read-previous (generation) rule:** every new runtime write uses only the
**Current generation** key. The Previous generation is **read/migration-compatibility state
only**; writing to any historical generation key is forbidden.

**Non-overlapping generations invariant:** because the model supports a current generation
plus at most one previous generation, a second rotation MUST NOT begin while an earlier
previous generation still needs enforcement continuity
(`docs/DEVICE_FINGERPRINT.md` §5.1.6). This guarantees that at most one historical cumulative
state exists at any time.

**K3 / K5 active block lookup:** the pipeline checks the Current generation key first, then the
Previous generation key when present, so an active historical block does not disappear during
rotation.

**K3 / K5 score state:** score fallback uses the historical Previous generation key. Existing
score merge/decay semantics are unchanged by this decision; this is an architecture gate, not
a score-semantics redesign.

**Implementation verification targets:** the runtime System coverage proves each scenario:

* outer-only rotation continuity
* fingerprint-only rotation continuity
* simultaneous outer + fingerprint rotation continuity
* no-rotation compatibility
* a second overlapping rotation is outside the supported two-generation window

---

#### 4.3.4 Credential-Spray Correlation Rotation

Credential-spray correlation has an additive capability boundary so existing
`CorrelationStoreInterface` consumers remain source-compatible:

```php
interface CorrelationRotationStoreInterface extends CorrelationStoreInterface
{
    public function addDistinctAcrossRotation(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
    ): int;

    public function incrementWatchFlagAcrossRotation(
        string $currentKey,
        string $previousKey,
        int $ttlSeconds,
    ): int;
}
```

The base path, when `previousKeySecret` is absent, uses only the existing
`CorrelationStoreInterface` methods. Their concrete implementation owns atomic
initialization and fixed TTL behavior; later mutations must not refresh a window.

When `previousKeySecret` is configured for a Login or OTP pre-check, the store
MUST implement `CorrelationRotationStoreInterface`. A missing capability is an
explicit failure through the normal engine failure semantics; current-only fallback
and silent reset are forbidden.

The rotation operation writes only the current generation. It reads the previous
set or WATCH state without changing its members or TTL. For distinct spray state,
the effective count is:

```text
previous cardinality + current bridge cardinality
```

The bridge key is `credential_spray:bridge:{currentK1}` and contains only
current-secret HMAC members. A logical subject already present in the previous set
is not added to the bridge. The bridge receives a fixed TTL capped by the remaining
previous-generation TTL, and later writes do not extend it. A previous key without
a valid TTL is corruption and fails before any partial current/bridge mutation.
The WATCH operation increments only the current flag and returns current plus active
previous count; the previous flag remains read-only. The package ships the optional
official Redis full-capability store, which implements this contract behind a
Host-supplied command executor. No PDO or other alternative backend adapter is
included; Redis client and connection lifecycle remain Host-owned. Custom backends
remain available through the public storage contracts.

#### 4.3.5 Bounded Correlation and Ephemeral Rotation

The unchanged `CorrelationStoreInterface` remains source-compatible. Bounded device-cap,
churn, dilution, and credential-spray state use additive capabilities:

```php
interface BoundedCorrelationStoreInterface extends CorrelationStoreInterface
{
    public function addDistinctBounded(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO;
}

interface BoundedCorrelationRotationStoreInterface
    extends BoundedCorrelationStoreInterface, CorrelationRotationStoreInterface
{
    public function addDistinctBoundedAcrossRotation(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO;
}
```

`BoundedDistinctResultDTO` returns the logical distinct count and whether the observed member
was accepted. A duplicate is accepted without mutation. A new member is accepted only when
the logical count is below the cap; a new member at the cap is rejected without growing the
set. The concrete operation is atomic, uses fixed TTLs without refresh, and fails on invalid
TTL/cap, missing TTL, malformed over-cap state, or bridge overlap.

The rotation operation writes only current state, reads the previous set read-only, and uses
current-secret bridge members for new logical members. The bridge TTL is capped by the
remaining previous TTL. If current and previous physical keys alias (a fingerprint-secret rotation
without a key-secret rotation), the aliased set is the previous set and stays read-only: a member
known in either form (current or previous) is deduplicated without any write, and a new member is
written to the bridge, never to the aliased set; the logical count is previous plus bridge.
The pipeline supports only one current and one previous generation and requires the additive
rotation capability; current-only fallback is forbidden.

The fixed device-cap contract is 900 seconds, maximum 10 distinct members per account scope
and 50 per IP scope. Ephemeral overflow is routing state, not a synthetic identity: the
pipeline keeps real current/previous enforcement keys, removes only K3/K5 scoring writes for
the overflow request, continues K4/flood handling, and retains bounded churn detection. An
overflow request may receive the active flood decision, but it cannot persist new K5 score or
block state. It does not create per-request keys or durable overflow identities.

**Time authority (#153 phase 2, DEC-016 application note).** Bounded, distinct,
watch, and bridge state record a logical `expiresAt` equal to the caller's time
plus the TTL, and the store judges them at the caller's time: the request's
single reading of the Host clock, passed through the additive `CallerTime*`
capabilities. Physical key lifetimes stay relative on the Redis clock. A window
whose logical `expiresAt` has been reached while its key is still physically
present is expired, not malformed, and restarts (LT-P2-03). The previous
generation stays read-only: an expired previous window is ignored, never
deleted.

#### 4.3.6 Distributed Account Attack Snapshots

Login and OTP `checkOnly()` requests with an account, a device fingerprint, and real K4/K5
keys observe a separate distributed-device window. The window is 600 seconds and admits at
most four logical K5 members. The additive snapshot capabilities are:

```php
interface BoundedCorrelationSnapshotStoreInterface extends BoundedCorrelationStoreInterface
{
    public function addDistinctBoundedWithSnapshot(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctSnapshotDTO;
}

interface BoundedCorrelationSnapshotRotationStoreInterface
    extends BoundedCorrelationSnapshotStoreInterface, BoundedCorrelationRotationStoreInterface
{
    public function addDistinctBoundedWithSnapshotAcrossRotation(/* ... */): BoundedDistinctSnapshotDTO;
}
```

`BoundedDistinctSnapshotDTO` returns `count`, `accepted`, `added`, the complete bounded
logical `members` set, and its fixed `expiresAt` (the caller's time plus the TTL when the
window was created, §4.3.5). A duplicate is accepted without being
added; a new member at the cap is rejected without set growth. Snapshot validation is
fail-closed for malformed counts, duplicate or unknown members, inconsistent flags, and
invalid expiry. Snapshot members are the canonical opaque K5 enforcement keys, so no extra
HMAC layer or raw account, fingerprint, or IP value crosses the store boundary.

Three members create a 30-minute distributed WATCH marker; the second qualifying three-
member observation or the fourth member produces HARD_BLOCK L2 for every K5 in the complete
snapshot. K4 is not blocked by the first occurrence. A qualifying snapshot creates one
account-only occurrence member derived from the fixed device-window expiry in an 86400-second
window capped at three occurrences. The exact third occurrence produces HARD_BLOCK L4 on K4
for 1800 seconds; the first two occurrences do not.

Rotation reads one previous generation without changing its members or TTL. The current
generation owns new logical members through a current-secret bridge whose TTL cannot exceed
the previous remaining TTL. Outer-only, fingerprint-only, and both-rotated inputs use one
coordinated current/previous pair and never probe Cartesian combinations. Fingerprint-only
rotation has no previous occurrence namespace; occurrence continuity is account/outer-secret
scoped. API Heavy and requests missing account, fingerprint, or real K4/K5 do not observe
this rule and do not require the snapshot capability. Missing required capability fails
through the package failure semantics before this observation is partially applied.

#### 4.3.7 Multiple-block-cycle Decay Pause

The additive hard-block cycle capability is:

```php
interface HardBlockCycleStoreInterface extends RateLimitStoreInterface
{
    public function blockWithCycleTracking(
        string $currentKey,
        ?string $previousKey,
        int $level,
        int $durationSeconds,
        int $now,
        int $cycleWindowSeconds,
        int $cycleThreshold,
        int $pauseSeconds,
        int $pauseHistoryRetentionSeconds,
    ): HardBlockCycleResultDTO;

    public function readDecayPauseState(
        string $currentKey,
        ?string $previousKey,
        int $fromTimestamp,
        int $now,
    ): DecayPauseStateDTO;
}
```

`blockWithCycleTracking()` is the atomic replacement for `block()` whenever a
persisted L2+ block is written. The operation persists only Current, classifies
the transition from no active hard block to active hard block, updates cycle
history, and activates a fixed decay pause. L1 continues to use
`RateLimitStoreInterface::block()`. Before any L2+ block write in a batch, the
pipeline requires this capability and fails explicitly if it is absent; it
never falls back silently to `block()`.

Current and Previous are one logical history. Writes target Current, Previous is
read-only, and the first Current mutation adopts still-relevant Previous cycle
and pause timestamps without refreshing or rewriting Previous. An opaque
historical snapshot member with no known pair is passed as Current with
`previousKey = null`.

The package passes a 21,600-second cycle window, threshold `2`, a 600-second
pause, and an 86,400-second pause-history retention. Cycle and pause state is
scoped by policy, canonical enforcement key type, and logical enforcement
identity. Pause state is read-only during decay; retained pause intersections
with `[fromTimestamp, now)` are unioned without double counting, including
completed pauses so lazy decay remains accurate.

Per DEC-017, `$now` (both methods) and `$fromTimestamp` are semantic Unix
timestamps and MUST be non-negative (`>= 0`); a negative value fails
explicitly with `RateLimiterException` before any backend mutation, or
before it is relied upon in `readDecayPauseState()`'s interval arithmetic.
Caller-time authority for `$now`/`$fromTimestamp` itself remains DEC-016 —
DEC-017 only narrows the value domain, it does not change which clock is
authoritative. Persisted cycle/pause timestamps are likewise non-negative
canonical integers. `0` is both a valid Unix-epoch timestamp and, separately,
the documented sentinel `HardBlockCycleResultDTO::$pauseUntil` /
`DecayPauseStateDTO::$activePauseUntil` use for "no active pause"; the two
meanings do not collide in practice because an active pause's end timestamp
is always strictly greater than the `$now` it was activated relative to.

### 4.4 Namespacing & Scoping

All keys MUST include:

* Action / policy identifier
* Algorithm & version
* Environment scope
* Module scope (`rate_limiter`)

This prevents cross-action and cross-module leakage.

The removed colon-joined account and non-account derivations joined these fields with `:`. Under sole-E1 (`DEC-046`) and canonical non-account `f1` (`DEC-047`), each field is length-prefixed with checked `u32be` bounds (`0..4294967295`), so a field boundary can never move; policy names, environment scopes, and semantic inputs are opaque byte strings. The physical key of an E1 account-bearing key is `maatify:rate-limiter:e1:{sha256(namespace)}:{family}:{sha256(E1 key)}`; non-account `f1` and simple fixed-window keys keep `maatify:rate-limiter:v1:…`. The layout is carried by the typed view `E1KeyspaceStoreInterface::e1Keyspace()` of the same store, never inferred from the shape of a string. Neither E1 nor `f1` reads legacy colon-joined state. Existing-traffic transfer, mixed workers and live rollback are unsupported; no active-block preservation is claimed for that transfer.

### 4.5 Auxiliary Budget Keys (Locked)

Budget owner-safety uses **dedicated auxiliary keys** — never the K4 score key and never the
K4 budget epoch/count key.

#### 4.5.1 Budget Cooldown Marker

Canonical E1 derivation (`DEC-046`, [`docs/KEY_SCOPE_ENCODING_E1.md`](KEY_SCOPE_ENCODING_E1.md) §3.4):

```text
E1AccountScopeKeyDeriver tuple (N = 4):
  (purpose, policy, env, account)
  where purpose ∈ { "budget_cooldown", "budget_cooldown_p" }
```

* Derived via `E1AccountScopeKeyDeriver` (checked `u32be` framing, `maatify.rate-limiter.key-domain.e1` subkey, textual form `e1.<64hex>`, physical `maatify:rate-limiter:e1:…:score:…`). The removed historical colon-joined preimage (`{policy}:rate_limiter:budget_cooldown:v1:{env}:{accountId}`) is no longer emitted or read.
* The marker uses the **existing atomic fixed-TTL primitive** `RateLimitStoreInterface::increment(key, cooldownTTL)`:
  first creation sets the TTL, subsequent increments do **not** extend it — a no-extension
  cooldown by construction (no new Store primitive required).

Atomic acquisition (locked; `Current` / `Previous` denote active/previous outer key-secret generations inside E1, §4.3.1):

```
if active Current marker exists      → cooldown active
else if active Previous marker exists → cooldown active
else atomic increment(Current, cooldownTTL)
     issuance allowed only when returned value == 1
```

* `get()` + `set()` acquisition is **forbidden** (not atomic under concurrency).
* Only the first increment inside a cooldown returns `1`; the issuer acquires the cooldown,
  later concurrent issuers return `> 1` and MUST NOT re-issue.
* The marker is part of Budget enforcement, never part of the 24h epoch, and never a
  level-1 `BlockState`.
* The marker represents an actually issued budget `SOFT_BLOCK`, not merely an active
  `BudgetActive` state. Do not acquire it when a normal `HARD_BLOCK` wins, even if a
  Recovery Guard candidate is also present, or when command eligibility prevents the
  budget candidate from participating.
  If the candidate is eligible and no higher-priority condition prevents a budget soft,
  acquisition is the issuance gate: returned `1` permits the budget `SOFT_BLOCK`; a
  returned value `> 1` loses the race, so no budget soft is issued and the normal
  candidate remains unchanged.
* Rotation survival (§4.3.1) applies: writes → Current; reads → Current then Previous.

#### 4.5.2 K5 Micro-Cap Counter (Known-Device Budget Eligibility)

Canonical E1 derivation (`DEC-046`, [`docs/KEY_SCOPE_ENCODING_E1.md`](KEY_SCOPE_ENCODING_E1.md) §3.4):

```text
E1AccountScopeKeyDeriver tuple (N = 5):
  (purpose, policy, env, account, material)
  where purpose ∈ { "microcap.k5", "microcap.k5p" }
```

* Derived via `E1AccountScopeKeyDeriver` (physical `maatify:rate-limiter:e1:…:budget:…`). The removed historical colon-joined preimage (`{policy}:rate_limiter:microcap:k5:{ver}:{env}:{accountId}:{deviceFp}`) is no longer emitted or read.

* A fixed-epoch (24h) counter per known device used to decide when same-device failures
  become budget-eligible (`DECISION_MATRIX.md` §2.4.1).
* Subject to rotation survival (§4.3.1): never `max(v1, v2)`, atomic seeding per §4.3.2.
* Under rotation the micro-cap follows the same **two-generation resolution** as
  device-derived keys (§4.3.3):

  ```
  Current micro-cap key  = current outer secret + current fingerprintHash
  Previous micro-cap key = (previous outer secret ?? current outer secret)
                           + (previous fingerprintHash ?? current fingerprintHash)
  ```

  The previous-generation micro-cap key exists only when `previousKeySecret !== null` OR
  `previousFingerprintHash !== null`.
* Locked micro-cap rotation rule:

  ```
  Current exists                    → Current authoritative (normal Current increment)
  Current absent + valid Previous
    → atomic seed Previous → Current
      via BudgetSeedStoreInterface::incrementBudgetWithSeed()
  neither exists                    → normal Current start
  ```
* Because the micro-cap is a **cumulative budget state**, Current and Previous MUST NOT be read
  as `max(current, previous)` (see §4.3.1). No `max()`, no Cartesian merge, and at most **one**
  previous-generation state (non-overlapping generations, §4.3.3 and
  `docs/DEVICE_FINGERPRINT.md` §5.1.6). The fixed epoch/count do not change because of rotation.
* Implementation status: **implemented.** The runtime resolves the current and previous
  micro-cap keys using the two-generation model, increments the Current state authoritatively,
  and atomically seeds a valid Previous state into Current through
  `BudgetSeedStoreInterface::incrementBudgetWithSeed()` when Current is absent. A store that
  lacks the capability fails through the existing failure semantics when migration is required.

### 4.6 Account/Policy Auxiliary State Keys (Locked)

Account-derived auxiliary state in the E1 evaluation pipeline (including the proven-lane `_p` purposes when an opted-in policy runs on the proven lane, separate from the Admission-runtime keys `binding_epoch` and `admission_fence` below per `DEC-048`) uses `E1AccountScopeKeyDeriver` (`DEC-046`, [`docs/KEY_SCOPE_ENCODING_E1.md`](KEY_SCOPE_ENCODING_E1.md) §3.2–§3.6, physical `maatify:rate-limiter:e1:…` layout):

```text
E1AccountScopeKeyDeriver tuple (N = 4):
  (purpose, policy, env, account)
  where purpose ∈ {
    "aux.last_missing_fp",
    "aux.flood_stage",
    "aux.anti_equilibrium",
    "aux.anti_equilibrium_p",
    "aux.known_device_allowance",
    "aux.known_device_allowance_p"
  }
```

The removed historical colon-joined preimages (`{policy}:rate_limiter:aux:{purpose}:v1:{env}:{accountId}`) are no longer emitted or read (DEC-021, now DEC-026, E1 encoding under DEC-046).

The admission layer adds the following purposes (LOCKED amendment under DEC-026, active since
2026-10-20). They are derived only for a policy that opts into the admission reservation.

* `binding_epoch` — one account-wide non-negative integer per account across all budget groups within `(cohort, env, admissionTrustDomain)` (`DEC-048` D2–D4). Derived via `AdmissionKeyDeriver::epochKey($accountId)` using checked `u32be` framing (`KeyPreimageFraming::encodeTuple()`), magic `maatify.rate-limiter.admission.epoch.a1\x00`, and HMAC subkey domain `maatify.rate-limiter.key-domain.admission.epoch.a1` keyed solely by the durable `admissionStateSecret` (logical form `a1.epoch.<64hex>`). Because it does not depend on the rotating outer `keySecret`, revocations survive arbitrary outer key-secret rotations without lazy seeding or revival. It is **not** a §10.1 generation-bound namespace; `AdmissionReservationStoreInterface::readBindingEpoch(string $key)` and `bumpBindingEpoch(string $key)` operate on a single opaque key with no previous key and no TTL; see §4.8.
* `admission_fence` — the operator-controlled fleet fence of the admission runtime (contract §6, M2; `DEC-048` D2–D4): one integer per budget group, derived via `AdmissionKeyDeriver::fenceKey($budgetGroup, $outerSecret)` using checked `u32be` framing, magic `maatify.rate-limiter.admission.fence.a1\x00`, and HMAC subkey domain `maatify.rate-limiter.key-domain.admission.fence.a1` (logical form `a1.fence.<64hex>`). Stored in its own dedicated physical `:adm:a1:fence:` family via `AdmissionReservationStoreInterface::readAdmissionFence(string $key, ?string $previousKey)` and `bumpAdmissionFence(string $key, ?string $previousKey)` (strictly separate from `:adm:a1:epoch:`). Read under the active outer `keySecret` and, when configured, `previousKeySecret` (current, else previous), so one outer rotation does not close it. Opened through `AdmissionFenceControlInterface::openAdmissionFence()`, which writes `max(current, previous) + 1` to the current key (checking native integer and Lua exact bounds before writing). While the fence is closed (`< 1`), `checkOnly` and permit-less outcomes follow the non-opted S0 path; explicit permit-bearing outcomes of a healthy opted policy continue to settle through permit verification/claim (`DEC-050`). See `docs/guides/USAGE_GUIDE.md` ("Operating the admission fence").
* The `_p` purposes of the proven (P) lane: `aux.known_device_allowance_p`, `aux.anti_equilibrium_device_p`,
  `aux.anti_equilibrium_p` and `budget_cooldown_p`, each registered in `E1AccountScopeKeyDeriver` (`KEY_SCOPE_ENCODING_E1.md` §3.4) with the same E1 framing and rotation rules, alongside `k4p` and `k5p` of §3.4 / §3.5.
  They are distinct names so the P family cannot collide with the unsuffixed family. The evaluation
  pipeline derives them only for a call that carries the proven lane, which the engine passes for a
  policy that opts in while its fence is open; a policy that did not opt in never reads or writes
  them.

The device Anti-Equilibrium watcher (DEC-021, now DEC-026, E1 encoding under DEC-046) uses the 5-field E1 tuple extending `(purpose, policy, env, account)` with the device `material`:

```text
E1AccountScopeKeyDeriver tuple (N = 5):
  (purpose, policy, env, account, material)
  where purpose ∈ { "aux.anti_equilibrium_device", "aux.anti_equilibrium_device_p" }
```

The removed historical colon-joined preimage (`{policy}:rate_limiter:aux:anti_equilibrium_device:v1:{env}:{accountId}:{fingerprintHash}`) is no longer emitted or read.

It exists only for a non-ephemeral, fingerprinted, account-bound request under a
budgeted, non-`API_OVERUSE` policy with K5 thresholds. Its previous
generation uses `previousKeySecret ?? currentKeySecret` and
`previousFingerprintHash ?? fingerprintHash`, exists only when either is present, and is
read-only.
The raw `AccountID` exists only inside this preimage and MUST NOT cross either the
`RateLimitStoreInterface` or `CorrelationStoreInterface` boundary. Policy, environment,
module, purpose, and algorithm namespace version are all part of the derivation, so Login,
OTP, API Heavy, and different environments cannot share auxiliary state accidentally.

The current outer-key generation is writable. When `previousKeySecret !== null`, the
previous-generation auxiliary key is read-only and may be used as one historical fallback;
no previous auxiliary key is created merely because `previousFingerprintHash` exists. A
fingerprint-secret rotation therefore does not invent an account-only auxiliary generation.

For `last_missing_fp`, state is eligible only for a failure with a missing fingerprint, an
account, and a positive policy `k4_repeated_missing_fp` delta. The current marker is
authoritative; the previous marker is read only when the current marker is absent. A marker
whose timestamp is no more than 1800 seconds old adds the policy's repeated-missing delta.
The current marker is written with the existing 3600-second TTL only; the previous marker is
never written or refreshed. API Heavy has no repeated-missing-fingerprint contract and MUST
not create or read this marker.

Anti-Equilibrium uses policy-scoped opaque state keys. Its threshold remains three soft events
within the six-hour window. The effective count is the current count plus the active previous
count, except that a null or identical previous key is not read twice. Recording a final
`SOFT_BLOCK` increments only the current key.

The known-device account allowance (`known_device_allowance`) is a fixed 24-hour epoch
counter stored through the budget primitives and is not derived from any fingerprint. It
follows the budget rotation survival contract (§4.3.1, §4.3.2): writes go to the current
outer-key generation; when only the previous generation holds a valid epoch it is seeded
atomically into the current key through `BudgetSeedStoreInterface`, never reset and never
merged with `max`.

New Device Flood stage is also policy-scoped. An active stage is the logical OR of the
current flag and an active distinct previous flag. The first qualifying stage writes only the
current flag with the existing 900-second TTL; the previous flag remains read-only. Flood
thresholds, Ephemeral counting, decision levels, and persistence semantics are unchanged.

### 4.7 Simple Fixed-Window Throttling Keys (Locked)

Generic/simple fixed-window throttling (DEC-013) is a semantic family separate from K1-K5
score/budget state and uses its own package-owned, versioned keyed-HMAC contract so its state
can never collide with authentication budgets or score state, even when a Host reuses a
score-policy name for a simple policy.

The logical components, in this exact order, are:

```
rate_limiter
simple_fixed_window
v1
environmentScope
policyName
limit
intervalSeconds
subject
```

Like E1 account keys (`DEC-046`) and canonical non-account `f1` keys (`DEC-047`), this namespace uses
canonical, collision-safe, length-prefixed component encoding to remove any ambiguity from raw
delimiter concatenation; unlike E1 and `f1`, it retains its pre-G2 8-component wire layout (no magic/count header and direct HMAC-SHA256 without a derived subkey). Each component is encoded as:

```
pack('N', strlen(component)) . component
```

and the preimage is the concatenation of the encoded components in the order above (using the
shared checked `u32be` framing primitive of `DEC-047`, which refuses any component byte length
outside `0..4294967295` before `pack('N')` while preserving identical wire bytes for writer and
operational reader). `limit`
and `intervalSeconds` are encoded as their canonical decimal string representation (`(string)
$limit`, `(string) $intervalSeconds`). The stored key is:

```
HMAC-SHA256(preimage, active-or-generation-key-secret)
```

using the default hex output. The raw `subject` exists only inside this preimage and MUST NOT
cross the `RateLimitStoreInterface` boundary directly.

Including `limit` and `intervalSeconds` in the preimage is intentional and MUST NOT be removed:
changing a simple policy's configured limit or interval changes its semantic state namespace.
A reconfigured policy therefore never reinterprets a persisted epoch under the new limit or
interval; it starts a fresh window under its own distinct key.

Rotation follows the same Current/Previous contract as §4.3.1/§4.3.2: the current outer-key
generation is authoritative and writable; when `previousKeySecret !== null`, a valid previous
generation epoch is atomically seeded into Current through
`BudgetSeedStoreInterface::incrementBudgetWithSeed()` and Previous remains read-only. Neither a
`max()` nor a `sum()` merge is used. A store that lacks the capability when a valid Previous
migration is required fails explicitly through `RateLimiterException` rather than silently
resetting the window.

---

### 4.8 Admission Keys (Locked)

The atomic admission reservation (DEC-024, stage 5-b2) is a store capability
(`AdmissionReservationStoreInterface`). Opt-in outcome recording uses
`AtomicAdmissionPermitStoreInterface`, the additive extension of `AdmissionPermitStoreInterface`
required by `RateLimiterBuilder::build()` for an admission policy. The package derives opaque,
hash-based scope keys per Budget Group and hands them to the store; the store never receives a raw
account identifier, IP address or fingerprint, and a request that carries one is the caller's error,
not something the store can detect.

The official Redis adapter stores them under one namespace-level hash tag in the explicit `adm:a1` layout (`DEC-048` D3, `DEC-049`) so that every admission key of a namespace (hence of every verifier class) sits in one hash slot on the single, non-clustered Redis it supports. Legacy `:adm:` keys are never read. Two-field physical keys frame `(verifierClass, key)` via `KeyPreimageFraming::encodeTuple()` with a family-specific magic header:

```
{prefix}:{sha256(namespace)}:adm:a1:{family}:{sha256(encodeTuple(scopeMagic(family), [verifierClass, key]))}   scope keys (c, d, s, b)
{prefix}:{sha256(namespace)}:adm:a1:p:{sha256(encodeTuple(pendingMagic, [verifierClass, key]))}                pending members of a failure set
{prefix}:{sha256(namespace)}:adm:a1:permit:{sha256(permitRecordId)}                                            permit records
{prefix}:{sha256(namespace)}:adm:a1:fence:{sha256(key)}                                                        fleet fences (outer-keyed)
{prefix}:{sha256(namespace)}:adm:a1:epoch:{sha256(key)}                                                        durable binding epochs (state-secret-keyed)
{prefix}:{sha256(namespace)}:adm:a1:cfg:{sha256(encodeTuple(configMagic, [verifierClass, key]))}               durable shared admission config (DEC-049)
```

(the braces around the namespace hash are literal Redis hash-tag braces; `{prefix}` is
`maatify:rate-limiter:v1`). The scope families are `c` (counter), `d` (device-member set), `s` (set-size
set) and `b` (bucket); one logical key never changes family. All 12 logical scope/member purposes are closed in `AdmissionScopePurpose` (`src`, `acct`, `ufail`, `pacct`, `pacctd`, `pproof`, `pproofd`, `pfail`, `ppend`, `pdev` with label `'set'`, `member`, `dev`) and derived by `AdmissionKeyDeriver` using checked `u32be` framing (`DEC-048` D2).

| Family | Redis type | Content and lifetime |
| --- | --- | --- |
| `c` counter | sorted set | windowed counter elements (member = derived opaque `permitRecordId` `a1.permit.<64hex>`, `DEC-033`/`DEC-048`) scored by member expiration timestamp (`now + ttl`); active count is `ZCOUNT (now +inf)`; a refusal writes nothing and never prunes; an admitted write prunes expired entries (`score <= now`), adds the permit record ID with score `now + ttl`, and refreshes `EXPIRE` to `ttl`; a refund (`ZREM permitRecordId`) removes the member if present and leaves TTL untouched. The key carries no window tag (`:w` / `:d`), enforcing a continuous sliding window. |
| `d` device members | sorted set | member scored by its expiry (caller time + member lifetime); a member is live when its score is above the caller's `now`; the key is pruned only by an admitted write to the current set, and `EXPIRE` is refreshed only by that write |
| `s` set | sorted set | accounts of the net-failure set (U, `ufail`) or the cross-account proven failure set (P, `pfail`, DEC-033), member = opaque keyed failing-subject member scored by expiry (`now + W`, where W = 600 s); active count is `ZCOUNT (now +inf)`; a refusal writes nothing and never prunes; a failure outcome prunes expired entries, adds the member with score `now + W`, and refreshes `EXPIRE` to `W`; on success the member is removed (`ZREM`). Enforces a continuous sliding window with no window tag (`:w`). |
| `b` bucket | hash `credit`, `last` | credit-seconds, refilled 1 per second of caller time up to `C x T`; absent means full; `EXPIRE` is the time to full, so an expired bucket and an absent bucket are the same state. |
| `p` pending (5-b2.1; **written only when a scope settles on expiry, 5-b2.2; the admission runtime builds no settling scope, so nothing writes or reads it**) | sorted set | pending members of the failure set with the same logical key: member scored by its permit `deadline` (an earlier deadline wins on a second reservation). A member counts toward the set only when its score is `<= now` and it is not already in the set; a success removes a pending member only while its score is `> now`. `EXPIRE` equals the scope `ttlSeconds`, set when the key is created and never renewed; a reservation without a permit never writes it; a refusal never writes it |
| permit | hash `state`, `lane`, `subject`, `operation`, `deadline`, `grace`, `outcome` | created by the admitting reservation in the same script under `:adm:a1:permit:{sha256(permitRecordId)}`, lifetime `deadline + grace - now`; `subject` stores the 64-hex keyed `permitContextCommitment` (`ctx`, `DEC-048` D6 — never raw account/subject/IP/credential); `ISSUED` then `CLAIMED`; the runtime verifies the v2 permit and `ctx` before claiming and applying the admission outcome atomically |
| cfg (`DEC-049`, superseding `DEC-038`) | string | the registered effective pending-permit limit of a budget group (`off` or a positive integer), keyed solely by `admissionStateSecret` under one durable key with **no `previousKey`** and **no TTL**, written by the first accepted reservation and by explicit `rebindAdmissionSharedConfig()`; survives arbitrary outer key-secret rotations without intermediate reservations; every reservation compares its worker's value with it before any gate or write |
| fence (`DEC-048` D3–D4) | string | canonical non-negative integer **with no TTL** under `:adm:a1:fence:{sha256(key)}` (outer-keyed, current else previous; overflow checked before write) |
| epoch (`DEC-048` D3–D4) | string | canonical non-negative integer **with no TTL** under `:adm:a1:epoch:{sha256(key)}` (keyed solely by `admissionStateSecret`, single durable key, account-wide across budget groups; overflow checked before write); survives arbitrary outer key-secret rotations |

Rotation. A counter or a device-member scope may name one previous-generation key. Counters sum the
current and previous values; the device-member gate counts the live members of both sets and treats a
member of either as existing; **a previous-generation key is never written** (not on admit, not on
refuse, not pruned, not refreshed). A set-size or bucket scope has no previous generation in this
contract (the store rejects one): a key-secret rotation restarts them, which is the capacity
re-issue the rotation interval rule (at least the window) already prices. Measured on the admission
runtime (stage 5-c, LT-27): one rotation keeps the spent source capacity (0 further admissions from an
exhausted source); a second rotation inside the same window re-issues it in full (24); at one
rotation the net failure set and the account bucket restart in full (4 further failing accounts,
4 further bucket takes).

Refund after a rotation (#209). This concerns the capacity of the account and proof counters (`pacct`, `pacctd`,
`pproof`, `pproofd`) only. Their success refund is `ZREM` on the **current** key (DEC-033). In the same generation a
successful outcome removes the reservation's member from all four, which restores that capacity at once. A permit
reserved before a rotation holds its member in the previous-generation key; that key is read-only, so the member is
neither removed nor changed, its key's expiry is untouched, and it continues to consume account/proof counter capacity
until its own score lapses, as for every counter member (the counter contract). The public test shows the refusal
that follows a success after the rotation; it does not wait out the expiry. This capacity is independent of the
pending-permit slot of `DEC-049` (superseding `DEC-038`), which is a separate counter released by either outcome and is not affected by this rule.
Verified on a real Redis by `AdmissionProofCounterRotationRedisTest`.

For device-member recognition, the runtime supplies the current member and, when a previous
outer-key or fingerprint generation is available, one previous member. The previous member uses
the previous fingerprint and outer-key secret where present and the current value for either
missing component. Redis checks both supplied members against each available device set. This
follows the coordinated two-generation model in §4.3.3. Finish the previous enforcement window
or migrate or expire the required state before starting another rotation. During fingerprint
rotation, the Host provides the previous fingerprint component as described in
[Device Fingerprint §5.1.3](DEVICE_FINGERPRINT.md#513-host-responsibility-during-fingerprint-secret-rotation).

Validation. The scope keys a store operation touches are validated (type, fields, an existing key
without a TTL) **before the first write**, because Redis does not roll back the writes of a failed
script. This includes every score of a sorted set the script reads: each member of the `d` sets
(current and previous generation) and of the `p` set on a reservation, and the `p` score of its own
member on an outcome. A score that is not a canonical non-negative integer is malformed (DEC-017,
#185), so the prune of an admitted write removes only members that have really expired. Malformed
stored state is a Lua error reply `malformed admission state: …`, returned before any write. The
reference test executor (`RespRedisCommandExecutor`) raises it as `RateLimiterException`; a Host
executor whose client throws on an error reply (phpredis: `RedisException`) propagates that
exception unchanged, as `RedisCommandExecutorInterface` requires. It is never a
`BackendFailureException` and never a fallback. A refusal writes nothing and names the first
refusing scope in request order. The scripts take the caller's time as a parameter and never call
`TIME`.

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

* Many correlation subjects under one K1 → credential spray
* Many K3 under one K4 → distributed account attack
* Rapid churn of K3 under one K2 → device evasion
* Same DeviceFP across many K1 prefixes → fingerprint dilution

Credential spray uses as `correlationSubject` the `correlationId` when present, else the `accountId` when present, else `null`, where an identity string is present when it is `!== null && !== ''` (`"0"` is present, `""` is absence; #185, F-185-04); it uses a fixed 600-second K1 window and is observed during authentication `checkOnly()` only. With no subject (each value `null` or `""`) there is no observation. The threshold is five distinct subjects; at four subjects the same K1 scope uses the mandatory 1800-second WATCH flag, and a second qualifying observation escalates as if the threshold were met. Distinct state is capped at five and rejected new subjects do not grow the set. During outer-secret rotation, the additive bounded rotation capability preserves the previous spray window through current-only bridge members without merging differently-keyed HMAC members directly.

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

All bounded correlation scopes use purpose/version/environment domain separation and keyed
HMAC references. Raw account IDs, IP addresses, correlation subjects, and fingerprint hashes
MUST NOT be stored as keys or members. Device-cap overflow requires
`BoundedCorrelationStoreInterface`; rotated observations require
`BoundedCorrelationRotationStoreInterface`. Missing capabilities fail explicitly through the
package failure semantics. Distributed-account snapshots and repeated-occurrence escalation
are implemented by the additive snapshot capabilities described in §4.3.6.

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
* E1 alone derives account keys (DEC-046); its bytes are unchanged, and the former opt-in/default selection is removed. A security review covering E1 (injectivity of the encoding, key-domain separation, layout carriage, closed carrier creation, ACL prefix, no V2 read) is recorded in the activation PR for #284.
* Canonical non-account framing `f1` derives K1–K3, correlation, and adaptive IPv6 keys (DEC-047, #281, #284), while retaining E1 and simple fixed-window bytes and checked `u32be` bounds.
* Canonical Admission framing `a1` (`DEC-048`, `DEC-049`, `DEC-050`, #281, #284) derives all Admission scope, member, fence, durable binding epoch, durable `pcfg`, permit context commitment, permit record ID, and v2 credential/permit MAC keys with checked `u32be` bounds under explicit `:adm:a1:*` physical layout (separate `:fence:` and `:epoch:` families) while retaining E1, `f1`, and simple fixed-window bytes unchanged.
* Any change requires:

  * version bump
  * documentation update
  * migration strategy with rotation survival

---

**This document is authoritative.
Key strategy MUST NOT be altered without explicit versioning and security review.**

## 10.1 Generation-Bound K4 Lifecycle Namespaces

The lifecycle uses logical namespaces rather than host-visible physical key
names: `K4 Current` is writable, `K4 Previous` is read-only during rotation,
and the one-shot claim marker is Current-only and independent from evidence.
Evidence is bound to the selected score generation and exact score expiry.
Mutation, block publication, and evidence publication must remain atomic; raw
account identifiers and physical backend key names are never part of this
public contract.
