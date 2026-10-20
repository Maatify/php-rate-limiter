# Rate Limiter — Policy Presets (Official)

**Package:** maatify/php-rate-limiter
**Namespace:** `Maatify\RateLimiter`
**Status:** LOCKED — Policy Contract
**Change Class:** Hardening Alignment

This document defines the **official policy presets** provided by the Rate Limiter package.

Policies are **pre-configured, production-ready rule sets** built strictly on top of:

* `DECISION_MATRIX.md`
* `KEY_STRATEGY.md`
* `FAILURE_SEMANTICS.md`
* `DEVICE_FINGERPRINT.md`

Consumers SHOULD use these presets instead of defining custom rules.

These presets, and `BlockPolicyInterface` custom policies in general, are the
score-based security-policy model. The separate generic/simple fixed-window
throttling capability (`Maatify\RateLimiter\Config\SimpleThrottlePolicyInterface`,
DEC-013) is not a score-policy preset and is documented in
`docs/SIMPLE_THROTTLING.md`.

## 0. Policy identity and reusable capabilities

Policy names identify registry entries, state namespaces, observability, and
same-name replacement. They do not grant reusable security behavior.

Custom policies may implement the additive typed
`PolicyCapabilityProviderInterface` and return package-owned
`PolicyCapabilityEnum` values: `CREDENTIAL_SPRAY`, `DISTRIBUTED_ACCOUNT`,
`TRUSTED_AUTHENTICATION`, and `API_OVERUSE`. A policy without that provider
receives only its base score-policy behavior.

The official presets retain the names `login_protection`, `otp_protection`, and
`api_heavy_protection` while declaring those capabilities explicitly. The
generation-bound K4 `PostPunishmentReentryPolicyInterface` remains a separate
DEC-007 lifecycle opt-in and is validated independently of policy name.

Degraded/failure behavior is a separate typed contract under DEC-011. Policies
that use bounded local fallback implement
`FailureFallbackConfigurationProviderInterface` and return a generic
`FailureFallbackConfigurationDTO` of typed `FailureFallbackRuleDTO` rules
(`ACCOUNT`, `IP_PREFIX`, or `IP_PREFIX_NORMALIZED_USER_AGENT`, each with a
positive limit and window). The official Login, OTP, and API policies resolve
the package-owned `AUTHENTICATION_PRIMARY`, `AUTHENTICATION_STEP_UP`, and
`API_OVERUSE` presets internally and require no Host wiring. A direct custom
reusable policy composes its own bounded configuration through the same
public contract, with numeric values independent of every official preset;
the package validates both identically and applies them through the same
`LocalFallbackLimiter` runtime. Fallback configuration selection never uses
`BudgetConfig` thresholds, route names, or policy names as an inference
input.

---

## 1. Global Policy Principles (Non-Negotiable)

All policies MUST comply with the following rules:

* Decisions are multi-signal (IP, Device, Account)
* Account-level protection is mandatory for authentication flows
* Progressive blocking is mandatory
* Decay is enabled but MUST NOT allow deterministic equilibrium
* Device Fingerprint is evaluated when available
* Correlation rules are enforced with bounded thresholds + near-threshold watch
* Failure semantics are explicitly defined
* **No policy may introduce account-wide hard blocks based solely on attempt counts**
* Budgets MAY increase friction but MUST NOT enable renewable or scheduled denial-of-owner
* Budgets are **decision candidates**, never fail-fast gates: they MUST NOT mask a stronger
  score/correlation `HARD_BLOCK` (`DECISION_MATRIX.md` §2.4.0)
* Budget behavior is expressed through the policy-owned `BudgetConfigDTO`; the Engine MUST
  NOT branch on policy identity (e.g. `if ($policy->getName() === ...)`) to implement budget
  owner-safety

---

## 2. Login Protection Policy

### Policy Identifier

```
login_protection
```

### Intended Use

* Password-based login endpoints
* Username/email + password authentication
* Admin and customer login flows

---

### Signals Used

* K1 — IP Prefix
* K2 — IP + UA
* K3 — IP + DeviceFP
* K4 — AccountID
* K5 — AccountID + DeviceFP

Account-level keys (K4) are authoritative.

---

### Scoring Rules (per `DECISION_MATRIX.md`)

| Scenario                                    | Key | Score |
| ------------------------------------------- | --- | ----- |
| Failed login (same known device)            | K5  | +2    |
| Failed login (new device)                   | K4  | +3    |
| Missing device fingerprint                  | K2  | +4    |
| Repeated missing fingerprint (same account) | K4  | +6    |
| Credential spray (correlation)              | K1  | +5    |

**Notes:**

* Missing fingerprint is treated as an **evasion signal**, not a bypass.
* New device creation is capped per account (see `DEVICE_FINGERPRINT.md`).
* Repeated missing fingerprint requires two consecutive failures within 30 minutes.

---

### Thresholds (Account Score)

| Account Score | Decision         |
| ------------- | ---------------- |
| < 5           | ALLOW            |
| 5 – 7         | SOFT_BLOCK (L1)  |
| 8 – 11        | HARD_BLOCK (L2)  |
| ≥ 12          | HARD_BLOCK (L3+) |

### Known-Device Bound (DEC-021)

| Known-device K5 score | Decision (that account + device only) |
| --------------------- | ------------------------------------- |
| < 8                   | ALLOW                                 |
| 8 – 11                | SOFT_BLOCK (L1)                       |
| 12 – 15               | HARD_BLOCK (L2)                       |
| ≥ 16                  | HARD_BLOCK (L3+)                      |

* A K5 level is persisted on K5 only; it never blocks the account (K4). Repeated K5
  `SOFT_BLOCK`s escalate through the device Anti-Equilibrium watcher to a K5 `HARD_BLOCK`
  L2 on that device only (`DECISION_MATRIX.md` §2.5.3).
* Known-device account allowance: **8** known-device failures per account and fixed 24h
  epoch, independent of the fingerprint. Each further known-device failure also adds +3 to
  K4 like a new device; this is a per-failure scoring rule, not an absolute cap on verifier
  reaches (`DECISION_MATRIX.md` §2.2.2).

---

### Login Failure Budget (Fixed 24h Epoch)

| Condition                                  | Decision                             |
| ------------------------------------------ | ------------------------------------ |
| ≥ 20 failed login attempts within 24 hours | SOFT_BLOCK (Account), minimum **L3** |

**Budget Rules (mandatory):**

* Budget applies at **K4 (AccountID)**.
* Budget uses a **fixed 24h epoch** (no extension; never restarted by cooldown).
* Budget is a **decision candidate**, never a fail-fast gate (`DECISION_MATRIX.md` §2.4.0).
* Budget MUST NOT directly produce `HARD_BLOCK(Account)`; its `SOFT_BLOCK` is never
  persisted via `RateLimitStoreInterface::block()` and is never a level-1 `BlockState`.
* On pre-flight **`checkOnly(login_protection)`**, the budget candidate is eligible only
  when the normal result would be `ALLOW`. A normal `SOFT_BLOCK` or `HARD_BLOCK` makes
  the candidate ineligible, and the budget cooldown MUST NOT be acquired.
* On **`recordFailure(login_protection)`**, after all normal updates, the budget
  candidate may participate when the normal result is `ALLOW` or `SOFT_BLOCK`. With a
  normal `SOFT_BLOCK`, it joins the `SOFT_BLOCK` class aggregation. If a normal or
  recovery `HARD_BLOCK` exists, the budget candidate is ineligible and its cooldown
  MUST NOT be acquired. The budget never applies on `recordSuccess(login_protection)`
  (`DECISION_MATRIX.md` §2.4.3).
* Decision class wins before level or duration. A budget `SOFT_BLOCK` cannot upgrade or
  contribute properties to a winning `HARD_BLOCK`; multiple `SOFT_BLOCK` candidates
  resolve by highest level and longest duration within the `SOFT_BLOCK` class only.
* Requests from **trusted session devices** downgrade the applied level by **one step**,
  never below the trusted floor **L2**.
* **Known-device** failures become budget-eligible only after
  `failed_login_count(K5) ≥ 8` within the epoch; **new/unverified-device** failures are
  budget-eligible immediately (`DECISION_MATRIX.md` §2.4.1).
* Cooldown: **60 minutes**; budget `retryAfter` = remaining budget cooldown, not the epoch
  remainder. The cooldown is acquired only when a budget `SOFT_BLOCK` can actually be
  issued; an existing `BudgetActive` state alone does not consume it
  (`DECISION_MATRIX.md` §2.4.4).

### Login BudgetConfig (Locked Preset)

| Field                          | Value    |
| ------------------------------ | -------- |
| `threshold`                    | 20       |
| `block_level`                  | L3       |
| `cooldown_seconds`             | 3600     |
| `trusted_session_floor_level`  | L2       |
| `precheck_enforcement`         | true     |
| `known_device_micro_cap`       | 8        |
| `recovery_collision_guard_enabled` | false |

---

### Anti-Equilibrium Gate

Soft events are kept per source scope (`DECISION_MATRIX.md` §2.5.3, DEC-021). On the
current `recordFailure`, read only **prior** actually-issued `SOFT_BLOCK` events **before
budget cooldown acquisition**:

* **≥ 3 account-scoped events** for the same account within 6 hours: the current failure
  MUST add `HARD_BLOCK(Account)` (K4) at minimum **L2** as an Anti-Equilibrium candidate.
* **≥ 3 device-scoped events** (K5-only, known device) for the same account and device
  within 6 hours: the current failure MUST add `HARD_BLOCK` on that account + device (K5)
  at minimum **L2**. Device-scoped events never count toward the account watcher.
* In either case the budget `SOFT_BLOCK` is ineligible and its cooldown MUST NOT be
  acquired.

After final aggregation, record exactly one Anti-Equilibrium event, on exactly one watcher,
only when the final issued decision is `SOFT_BLOCK`: the account watcher whenever the
`SOFT_BLOCK` has any account source (mixed K4 + K5 included), the device watcher only for
a K5-only `SOFT_BLOCK` with an eligible device, and the account watcher otherwise. The
event affects future requests only. The third soft event remains a `SOFT_BLOCK`, and
recording it MUST NOT turn that same request into `HARD_BLOCK`. `ALLOW` and `HARD_BLOCK`
final decisions record no soft event.

---

### Progressive Blocking

Uses the global penalty ladder defined in `DECISION_MATRIX.md`.

**Account-level blocks persist regardless of device rotation.**

---

### Decay Rules

Uses `DECISION_MATRIX.md` decay rules + deterministic modifiers.

For a score-derived result, `retryAfter` is calculated by the package-owned
`DecayCalculator` until the score exits the current decision class: below L1
for `SOFT_BLOCK`, or below L2 for every `HARD_BLOCK` level. An active persisted
block returns its remaining TTL, and persistence continues to use the
`PenaltyLadder` duration. After two real persisted L2+ cycles for the same
policy/key-type/logical identity, score decay pauses for a fixed 600 seconds;
completed pause intervals remain part of lazy decay accounting for 24 hours.

---

### Failure Semantics

```
FAIL_CLOSED
```

(Handled strictly per `FAILURE_SEMANTICS.md`.)

---

## 3. OTP / Step-Up Protection Policy

### Policy Identifier

```
otp_protection
```

### Intended Use

* OTP verification endpoints
* Step-Up authentication
* Sensitive confirmation actions

---

### Signals Used

* K4 — AccountID
* K5 — AccountID + DeviceFP
* K2 — IP + UA

IP-only signals are advisory only.

---

### Scoring Rules

| Scenario                                 | Key | Score |
| ---------------------------------------- | --- | ----- |
| OTP failure from same device             | K5  | +4    |
| OTP failure from new device              | K4  | +5    |
| OTP failure without fingerprint          | K2  | +6    |
| Repeated OTP failure without fingerprint | K4  | +8    |

---

### Thresholds

| Score | Decision         |
| ----- | ---------------- |
| < 4   | ALLOW            |
| 4 – 6 | SOFT_BLOCK (L1)  |
| 7 – 9 | HARD_BLOCK (L2)  |
| ≥ 10  | HARD_BLOCK (L3+) |

### Known-Device Bound (DEC-021)

* Known-device K5 thresholds `8 / 12 / 16` (L1 at the 2nd known-device OTP failure, L2 at
  the 3rd, L3 at the 4th), persisted on K5 only.
* Known-device account allowance: **2** per account and fixed 24h epoch; further
  known-device OTP failures are also scored on K4 (+5) (`DECISION_MATRIX.md` §3.1.2).

---

### OTP Failure Budget (Fixed 24h Epoch)

| Condition                         | Decision                             |
| --------------------------------- | ------------------------------------ |
| ≥ 10 OTP failures within 24 hours | SOFT_BLOCK (Account), minimum **L4** |

**Rules:**

* Budget is account-scoped and uses a fixed epoch (no extension; never restarted by cooldown).
* **Every OTP failure is budget-eligible** at K4; there is **no Login-style K5 micro-cap**.
* Budget candidate applies on **`recordFailure(otp_protection)`** only — **never** on
  `checkOnly(otp_protection)` or `recordSuccess(otp_protection)`
  (`DECISION_MATRIX.md` §3.3.2).
* Trusted session devices downgrade one level, never below the trusted floor **L3**.
* Cooldown: **120 minutes**; budget `retryAfter` = remaining budget cooldown, not the epoch
  remainder; it is acquired only for an actually issued budget `SOFT_BLOCK`, not merely
  because `BudgetActive` exists (`DECISION_MATRIX.md` §3.3.3).
* Includes the **Recovery Collision Guard** from `DECISION_MATRIX.md` §3.3.5 (atomic
  returned-count trigger at `count == 10`; one-shot `SOFT_BLOCK (L2)` using PenaltyLadder
  L2 duration; the guard does not acquire the budget cooldown).

### OTP BudgetConfig (Locked Preset)

| Field                          | Value    |
| ------------------------------ | -------- |
| `threshold`                    | 10       |
| `block_level`                  | L4       |
| `cooldown_seconds`             | 7200     |
| `trusted_session_floor_level`  | L3       |
| `precheck_enforcement`         | false    |
| `known_device_micro_cap`       | null     |
| `recovery_collision_guard_enabled` | true  |

---

### Decay Rules

OTP follows `DECISION_MATRIX.md` decay rules (no custom OTP-only decay in policies).
(OTP strictness is driven by higher score deltas + budget + thresholds.)

---

### Failure Semantics

```
FAIL_CLOSED
```

---

## 4. API Heavy / Brute-Force Protection Policy

### Policy Identifier

```
api_heavy_protection
```

### Intended Use

* Public APIs
* Resource-intensive endpoints
* Export, search, bulk operations

---

### Signals Used

* K2 — IP + UA
* K3 — IP + DeviceFP
* K1 — IP Prefix

---

### Rate Enforcement

| Condition        | Scope | Decision / maximum level |
| ---------------- | ----- | ------------------------ |
| Minor overuse    | K2    | SOFT_BLOCK / L1          |
| Moderate overuse | K3    | HARD_BLOCK / L2          |
| Severe overuse   | K1    | HARD_BLOCK / L3          |

API Heavy has no K4 or K5 enforcement contract. A LOW-confidence moderate K3
signal remains a `HARD_BLOCK` L2 decision, but its persisted enforcement target
is K2 rather than K3.

The API Heavy maximum enforced block level is **L3**.

---

### Decay Rules

* IP score: −1 every 3 minutes

L2 and higher uses the package modifier of a doubled effective interval. The
multiple-block-cycle pause is additive to this modifier and never changes
block TTL, penalty duration, budget, correlation, fallback, or circuit-breaker
timers.

---

### Failure Semantics

```
FAIL_OPEN
```

**Constraints:**

* FAIL_OPEN MUST NOT allow unlimited throughput.
* Implementations MUST apply coarse local throttles during outage (see `FAILURE_SEMANTICS.md`).

---

## 5. Correlation Rules (Applied to All Policies)

Correlation rules are mandatory and enforced per `DECISION_MATRIX.md`, including:

* Near-threshold WATCH flags (anti N-1 gaming)
* Confidence constraints for DeviceFP dilution blocks

These rules remain the shipped behavior under the Owner-approved interim Security-first
posture recorded in
[DEC-019](decisions/DEC-019_INTERIM_SECURITY_FIRST_CORRELATION_POSTURE.md). That posture is
not a fix: issues #97, #100, and #106 remain OPEN, and shared-NAT false positives are
unresolved. No rule or threshold changes.

---

## 6. Custom Policies (Restricted)

Custom policies MAY exist, but:

* MUST pass validation
* MUST include:

  * Account-level protection (if auth-related)
  * Device awareness
  * Decay + budgets + anti-equilibrium gate
  * Explicit failure semantics
* MUST NOT weaken defaults
* MUST NOT introduce renewable denial-of-owner vectors

Invalid policies MUST be rejected at runtime.

---

## 7. Versioning & Stability

* Policy behavior is part of the public contract
* Any change requires:

  * Policy version bump
  * Changelog entry
  * Security review

Policy identifiers MUST remain stable.

---

**This document is authoritative.
Any deviation requires explicit approval and versioning.**

## 7.1 Generation-Bound Re-Entry Opt-In

`login_protection` and `otp_protection` implement the generation-bound K4
post-punishment re-entry marker and require `PunishmentLifecycleStoreInterface`;
the builder fails fast when the capability is absent. `api_heavy_protection`
does not opt in. A custom policy must explicitly implement the marker and pass
the same validated authentication-policy requirements before using this
lifecycle; a custom name alone is not sufficient.

---

## 7.2 Admission-Reservation Opt-In (DEC-023, DEC-024, DEC-025) — dormant

**Status: shipped dormant.** No official preset declares this capability, `RateLimiterBuilder::build()`
does not construct the admission runtime, and a policy that declares it is rejected at registration
by an engine that has none. Everything below describes the contract of the opt-in, not a behaviour
any supported composition has today.

* **S1.** `PolicyCapabilityEnum::ADMISSION_RESERVATION` is one capability for the whole measured
  assembly (source ceiling, failure cap, account bucket, proven-lane caps, new-device cap, the
  cross-account failure rule, the K5 family split). There are no sub-capabilities: only the whole
  assembly was measured. It is authentication-related, so every authentication prerequisite of §1
  and §6 applies, and it cannot be combined with `API_OVERUSE`.
* **S2.** A policy that declares it also implements `AdmissionReservationPolicyInterface`
  (`getAdmissionHostMode()`, `getAdmissionBudgetGroup()`). The only eligible Host mode is `pre+out`
  (DEC-023): a Host that calls `checkOnly` and then records the outcome. `pre` (check only) and `out`
  (record only) are not representable. The budget group names the verifier class shared by login,
  re-authentication and password change; admission counters are keyed by it, never by the policy
  name.
* **The `pre+out` declaration is a Host duty (DEC-023 A-2).** Every `checkOnly` of an opted policy
  is followed by exactly one outcome that carries the admission's permit. The library binds every
  outcome it receives to one admission (no outcome is scored twice or without its permit), but it
  cannot recover an outcome that never arrives: an admission without an outcome changes no bound,
  and a Host that drops outcomes runs those attempts with the bounds of a `pre` Host only. The
  library does not compensate for it (`USAGE_GUIDE.md`).
* **Custom policies.** Validation rejects, with a typed exception at registration: the capability
  without the interface (and the interface without the capability), an empty budget group,
  `API_OVERUSE` combined, `FAIL_OPEN`, a missing `BudgetConfig`, missing `ACCOUNT` / `IP_PREFIX`
  fallback dimensions, a store without the admission capability, a missing binding key, and an
  engine with no admission runtime.
* **Owner safety (replaces the unproven-owner caveat of §1 for opted policies).** A request is
  proven only through a verified account-bound credential; every other request, including an
  owner without one, is unproven and is bounded by the source ceiling, the net failure cap and the
  account bucket. A proven owner is never refused by a stranger's source budget. A capacity refusal
  is a non-persisted `SOFT_BLOCK`; it is never a block, never a cycle and never an escalation.
  The package does not issue credentials: the Host does (`USAGE_GUIDE.md`).

