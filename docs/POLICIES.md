# Rate Limiter — Policy Presets (Official)

**Module:** RateLimiter
**Namespace:** `Maatify\RateLimiter`
**Status:** LOCKED — Policy Contract
**Spec Version:** `1.3.0`
**Change Class:** Hardening Alignment

This document defines the **official policy presets** provided by the Rate Limiter module.

Policies are **pre-configured, production-ready rule sets** built strictly on top of:

* `DECISION_MATRIX.md`
* `KEY_STRATEGY.md`
* `FAILURE_SEMANTICS.md`
* `DEVICE_FINGERPRINT.md`

Consumers SHOULD use these presets instead of defining custom rules.

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

On the current `recordFailure`, read only **prior** actually-issued `SOFT_BLOCK` events
for the same account **before budget cooldown acquisition**. If **≥ 3 such events
within 6 hours** exist:

* The current failure MUST add `HARD_BLOCK(Account)` at minimum **L2** as an
  Anti-Equilibrium candidate.
* The budget `SOFT_BLOCK` is ineligible and its cooldown MUST NOT be acquired.

After final aggregation, record exactly one Anti-Equilibrium event only when the final
issued decision is `SOFT_BLOCK`; the event affects future requests only. The third soft
event remains a `SOFT_BLOCK`, and recording it MUST NOT turn that same request into
`HARD_BLOCK`. `ALLOW` and `HARD_BLOCK` final decisions record no soft event.

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
`PenaltyLadder` duration. The multiple-block-cycle pause remains deferred to
Stage 3 and is not implemented.

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
multiple-block-cycle pause remains deferred to Stage 3 and is not implemented.

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
