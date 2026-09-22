# Rate Limiter — Decision Matrix (Official Specification)

**Module:** RateLimiter
**Namespace:** `Maatify\RateLimiter`
**Status:** LOCKED — Behavioral Contract
**Scope:** Login, OTP, API Heavy Endpoints
**Spec Version:** `1.8.0`

This document defines the **deterministic decision rules** used by the Rate Limiter.
It is a **behavioral contract**, not explanatory documentation.

Any implementation, policy, or test MUST comply with this matrix exactly.

---

## 0. Definitions

### Signals (Inputs)

* `IP` — Client IP address (**IPv4** or **IPv6 prefix-aware**)
* `UA` — Normalized User-Agent (major version only)
* `DeviceFP` — Device Fingerprint (passive / client / session)
* `DeviceConfidence` — `{LOW, MEDIUM, HIGH}` derived per `DEVICE_FINGERPRINT.md`
* `AccountID` — Account identifier (blind index or internal ID)
* `CorrelationID` — Optional stable opaque Host-provided subject identity for correlation
* `Action` — Logical action (e.g. `auth.login`, `auth.otp`, `api.heavy`)
* `PreviouslyVerifiedForAccount` — host-provided boolean proving a prior verified device ↔ `AccountID` association (default `false`; see `DEVICE_FINGERPRINT.md` §4.3)

### Decisions (Outputs)

* `ALLOW`
* `SOFT_BLOCK` — Temporary throttle with Retry-After
* `HARD_BLOCK` — Active block for a calculated duration

### Evaluation Keys

* `K1 = IP_PREFIX`
* `K2 = IP_PREFIX + UA`
* `K3 = IP_PREFIX + DeviceFP`
* `K4 = AccountID`
* `K5 = AccountID + DeviceFP`

### Correlation Subject

For credential-spray correlation only:

```text
correlationSubject = CorrelationID ?? AccountID
```

The Host owns the stable opaque `CorrelationID`. When both values are null, the package does not create a spray observation. The package derives a domain-separated keyed-HMAC member from the subject before calling `CorrelationStoreInterface`; raw account, username, email, or correlation values are never stored as members. `AccountID` remains the enforcement identity for K4/K5.

### Trusted Session Device (Definition)

A request is considered from a **trusted session device** only if:

* A **Level 3 session-bound device identifier** is present (per `DEVICE_FINGERPRINT.md`), **and**
* It is bound to the same `AccountID`, **and**
* It matches the server’s stored association for that account.

No other “trust” signal is allowed *for the trusted-session definition*.

### Known Device for Account (Definition)

A device is **known for an account** (`isKnownForAccount`) if either:

* It is a **trusted session device** for that account (definition above), **or**
* It is a **previously verified device for this account** — the host proves a prior
  verified association between the device and the `AccountID`
  (`PreviouslyVerifiedForAccount = true`, host-provided).

```
isKnownForAccount = isTrustedSession OR isDevicePreviouslyVerifiedForAccount
```

Rules:

* The **host** is the only authority for proving a previous verified association.
* The existence of a `K5` counter in RateLimiter storage is NOT, by itself, proof that the
  device was previously verified.
* `DeviceConfidence = HIGH` alone is NOT a substitute for “previously verified”.
* Full device semantics are owned by `DEVICE_FINGERPRINT.md` §4.3.

---

## 1. Decision Evaluation (Orchestration)

Evaluation order is **strict and non-negotiable**:

1. Resolve device/account signals (trusted session, previously-verified device, confidence).
2. Resolve **active HARD_BLOCK state** on all relevant keys (`K4`/`K5`/`K3`/`K1`/`K2`).
   An active `HARD_BLOCK` fails fast and terminates evaluation.
3. Load effective `V2`/`V1` budget state (count + epoch start). Loading is **non-enforcing**.
4. Evaluate normal scores / thresholds / correlation (`K4`, `K5`, `K3`, `K1`/`K2`).
5. On failure, process **all** score, correlation, and eligible budget updates
   (`recordFailure` MUST continue even while `BudgetActive`).
6. Apply the OTP **Recovery Collision Guard** if the exact transition qualifies.
7. On `recordFailure`, evaluate Anti-Equilibrium escalation using **only** soft-block
   events recorded by prior requests. If at least 3 prior actually-issued
   `SOFT_BLOCK` events exist within 6 hours, add an Anti-Equilibrium
   `HARD_BLOCK(Account)` candidate at minimum L2. The current request MUST NOT count
   toward this prerequisite.
8. Resolve the normal, Recovery Guard, and Anti-Equilibrium candidate class.
   If any `HARD_BLOCK` candidate exists, the budget `SOFT_BLOCK` is ineligible and
   budget cooldown MUST NOT be acquired.
9. Otherwise, if `BudgetActive` and the command is eligible, atomically acquire the
   budget cooldown. If acquired, add the budget `SOFT_BLOCK` candidate.
10. Aggregate by decision class first:

   ```
   HARD_BLOCK > SOFT_BLOCK > ALLOW
   ```

   Then resolve the highest level and longest duration **only among candidates in the
   winning class**.
11. After final aggregation, if and only if the final issued decision is `SOFT_BLOCK`,
    record exactly one Anti-Equilibrium soft event for future requests. A current
    request MUST NOT record a soft event and then read that same event to become hard.

**The budget is a decision candidate, not a fail-fast gate.** An active budget MUST NOT
short-circuit stages 4–6. Normative budget behavior is defined in §2.4, §2.5, §3.3.

---

## 2. Login / Password Attempts

### 2.1 Pre-Attempt Hard Block Check

| Condition                      | Key | Decision   |
| ------------------------------ | --- | ---------- |
| Active block on account        | K4  | HARD_BLOCK |
| Active block on account+device | K5  | HARD_BLOCK |
| Active block on device         | K3  | HARD_BLOCK |
| Active block on IP / IP prefix | K1  | HARD_BLOCK |

**Invariant:** Safety-valves (ephemeral routing, degraded mode, etc.) MUST NOT bypass this step.

---

### 2.2 Failed Login Scoring

| Scenario                                    | Key | Score Delta | Notes                  |
| ------------------------------------------- | --- | ----------- | ---------------------- |
| Failure from same known device              | K5  | +2          | Lower risk, not safe   |
| Failure from new / unverified device        | K4  | +3          | Suspicious             |
| Missing device fingerprint                  | K2  | +4          | Evasion indicator      |
| Repeated missing fingerprint (same account) | K4  | +6          | Accelerated escalation |
| IP attempts multiple accounts               | K1  | +5          | Credential spray       |

#### 2.2.1 “Repeated missing fingerprint” Rule (Deterministic)

A “Repeated missing fingerprint (same account)” event applies if:

* `DeviceFP` is missing, **and**
* The **previous failed login** for the same `AccountID` and the same policy flow within the last **30 minutes** was also missing `DeviceFP`.

The marker is policy-scoped: Login history MUST NOT satisfy the OTP rule, and OTP history
MUST NOT satisfy the Login rule. API Heavy has no repeated-missing-fingerprint contract and
MUST NOT create or read this marker.

---

### 2.3 Login Thresholds (Per Account Score)

| Account Score | Decision   | Block Level |
| ------------- | ---------- | ----------- |
| < 5           | ALLOW      | —           |
| 5 – 7         | SOFT_BLOCK | L1          |
| 8 – 11        | HARD_BLOCK | L2          |
| ≥ 12          | HARD_BLOCK | L3+         |

---

### 2.4 Login Failure Budget (24h) — Anti-Harassment Contract

A budget exists to stop low-and-slow abuse **without enabling remote permanent lockout**.

| Condition                                  | Decision                                                 |
| ------------------------------------------ | -------------------------------------------------------- |
| ≥ 20 failed login attempts within 24 hours | **SOFT_BLOCK (Account)** with **minimum block level L3** |

#### 2.4.0 Budget Is a Decision Candidate (Not Fail-Fast)

* An active login budget is a **candidate** in the final aggregation (§1, §8) only.
* It MUST NOT hide a stronger decision: K4/K5 score thresholds and correlation
  `HARD_BLOCK` still apply and win over the budget `SOFT_BLOCK`.
* Decision class wins before level or duration. A budget `SOFT_BLOCK` MUST NOT
  contribute its level, duration, `retryAfter`, or persistence to a winning
  `HARD_BLOCK` candidate; it can never upgrade hard-block semantics directly or
  indirectly.
* `checkOnly()` MUST evaluate normal score/correlation state before selecting the final result.
* `recordFailure()` MUST continue normal failure scoring, correlation, and budget counting
  even while `BudgetActive`.
* The budget MUST NOT stop `processUpdates()`.

**Budget-issued `SOFT_BLOCK` is not `BlockState`:**

* The budget `SOFT_BLOCK` is not stored through `RateLimitStoreInterface::block()`.
* It does not create a K4 hard block and is not a level-1 `BlockState` cooldown marker.
* It does not change the semantics of an active hard block.
* Its repetition is governed by the **independent budget cooldown** state (§2.4.4).
* Normal score / anti-equilibrium `HARD_BLOCK` keeps using `BlockState` as usual.

#### 2.4.1 Budget Eligibility (Deterministic)

##### 2.4.1.1 Known-Device vs New-Device Classification

Login failures are **classified**, not all scored with K4 + K5 together:

| Classification              | Scoring                                      | Budget eligibility                                        |
| --------------------------- | -------------------------------------------- | --------------------------------------------------------- |
| **Known device failure**    | K5 score delta applies (**+2**); K4 “new device” delta does **NOT** apply | Budget-eligible **only after** `failed_login_count(K5) ≥ 8` within the fixed epoch (known-device micro-cap) |
| **New / unverified device failure** | K4 new-device delta applies (**+3**) | Budget-eligible immediately |

* “Known device” means `isKnownForAccount` (§0; `DEVICE_FINGERPRINT.md` §4.3).
* Missing-fingerprint rules (§2.2) remain an independent contract and are neither removed
  nor weakened by this classification.

##### 2.4.1.2 Budget Counter Composition

Budget counters are counted at **K4 (AccountID)** and include:

* Any failed login that increments **K4** (new/unverified device, repeated missing
  fingerprint), and
* Any failed login **without DeviceFP** (K2 missing fingerprint), and
* Any failed login from a **known device (K5)** **after** a per-device micro-cap:

    * If `failed_login_count(K5) ≥ 8 within 24h`, subsequent failures from that same `K5`
      become budget-eligible.

This prevents “same-device equilibrium” from bypassing the budget indefinitely.

#### 2.4.2 Budget Epoch (Fixed Window, No Extension)

To prevent “rolling-window prisoning”, the budget uses a fixed **Budget Epoch**:

* The epoch starts at the timestamp of the **first budget-eligible failure** that contributes to the threshold crossing.
* Once the threshold is crossed, the epoch becomes **BudgetActive** and ends exactly **24h** after epoch start.
* **Additional failures MUST NOT extend the epoch end time.**
* **No cooldown, key rotation, or Recovery Collision Guard MAY move `epochStart` or extend
  the epoch end.**
* The budget counter MAY continue counting for analytics, but **must not** extend enforcement.

> This is a behavioral guarantee: “44 attempts cannot stretch a 24h prison into an infinite prison.”

#### 2.4.3 Command Eligibility (Explicit)

While **BudgetActive**, the budget `SOFT_BLOCK` candidate is permitted **only** on:

* `recordFailure(login_protection)` — a failed login attempt, and
* `checkOnly(login_protection)` — an unauthenticated attempt/preflight that would have
  become `ALLOW` were it not for the active budget.

For `checkOnly(login_protection)`, the budget candidate is eligible only when the
normal result is `ALLOW`. If the normal result is `SOFT_BLOCK` or `HARD_BLOCK`, the
budget candidate MUST NOT be attempted and its cooldown MUST NOT be acquired.
For `recordFailure(login_protection)`, the budget candidate may participate after all
normal updates: it may join a normal `SOFT_BLOCK` in the `SOFT_BLOCK` class, may be
issued when the normal result is `ALLOW`, and MUST NOT be attempted when a normal or
recovery `HARD_BLOCK` exists.

The budget MUST NOT affect:

* `recordSuccess(login_protection)` — a successful login MUST return `ALLOW` and MUST NOT
  be blocked by an account budget.

The budget MUST NOT re-issue on a timer.

#### 2.4.4 Cooldown (Anti-Spam Guard)

A budget-issued `SOFT_BLOCK(Account)` has an enforcement cooldown:

* Login budget cooldown: **60 minutes** (`BudgetConfigDTO.cooldown_seconds = 3600`).

Cooldown semantics:

* Cooldown belongs to **Budget enforcement**, not to the budget epoch.
* The cooldown marker represents an actually issued budget `SOFT_BLOCK`, not merely
  the presence of `BudgetActive`. It may be acquired only after the normal/recovery
  candidate is resolved and the budget candidate can participate in the winning
  `SOFT_BLOCK` result.
* While `BudgetActive`, the first eligible budget issuance outside cooldown MAY produce
  the budget `SOFT_BLOCK`; during cooldown, the budget alone MUST NOT re-issue it.
* If cooldown acquisition fails or loses the atomic race, the budget `SOFT_BLOCK` is
  not issued and the normal/recovery candidate is not altered.
* During (and after) cooldown, the request still completes normal score/correlation/failure
  processing; any normal `HARD_BLOCK` still wins (§8).
* Cooldown MUST NOT **extend** the budget epoch and MUST NOT **restart** it.
* Cooldown acquisition is atomic (see `KEY_STRATEGY.md` §4.5).

**Budget-issued `retryAfter` = remaining/current budget cooldown** — not the remaining 24h
epoch. The 24h epoch is the **lifetime** of the budget state; it is not a client
retry-prevention window.

#### 2.4.5 Trusted Session Downgrade

If a request is from a **trusted session device**:

* The budget decision MUST be downgraded by one level (e.g., L3 → L2),
* but never below the **trusted-session floor level L2** while BudgetActive.

---

### 2.5 Anti-Equilibrium Gate (Deterministic)

To prevent mathematically planned “low-and-slow” equilibrium:

#### 2.5.1 Read / Escalation

On the current `recordFailure`, and **before budget cooldown acquisition**, read only
Anti-Equilibrium soft events from **prior requests** for the same `AccountID` and the same
policy flow.

If **≥ 3 actually-issued `SOFT_BLOCK` events within 6 hours** exist in that prior
history:

* Add `HARD_BLOCK (Account)` at **minimum level L2** as an Anti-Equilibrium candidate.
* The current request MUST NOT count toward the three-event prerequisite.
* This `HARD_BLOCK` candidate makes the budget `SOFT_BLOCK` ineligible; budget cooldown
  MUST NOT be acquired.

The Anti-Equilibrium read/escalation operation is separate from recording the current
request. It occurs before budget cooldown acquisition so an eligible prior history
cannot be bypassed by a budget soft. Login and OTP histories are independent.

#### 2.5.2 Write / Record

After final aggregation, if and only if the final issued decision is `SOFT_BLOCK`,
record exactly one Anti-Equilibrium event. This event affects future requests only.

The third `SOFT_BLOCK` remains a `SOFT_BLOCK`; recording it MUST NOT turn that same
request into `HARD_BLOCK`. A request whose final decision is `ALLOW` or `HARD_BLOCK`
records no Anti-Equilibrium soft event.

Therefore, Anti-Equilibrium has two distinct operations:

```text
READ / ESCALATION:
  current failure → read prior soft-event history → may add HARD_BLOCK L2

WRITE / RECORD:
  final result is SOFT_BLOCK → record exactly one event for future requests
```

This is deterministic, testable, and breaks stable decay arithmetic.

---

## 3. OTP / Step-Up Verification

OTP actions are **stricter** than password attempts.

### 3.1 Failed OTP Scoring

| Scenario                                  | Key | Score Delta |
| ----------------------------------------- | --- | ----------- |
| OTP failure from same device              | K5  | +4          |
| OTP failure from new device               | K4  | +5          |
| OTP failure without device fingerprint    | K2  | +6          |
| Repeated OTP failures without fingerprint | K4  | +8          |

#### 3.1.1 “Repeated OTP failures without fingerprint” Rule (Deterministic)

Applies if:

* `DeviceFP` is missing, **and**
* The **previous OTP failure** for the same `AccountID` and the OTP policy flow within the last **30 minutes** was also missing `DeviceFP`.

---

### 3.2 OTP Thresholds

| Score | Decision   | Block Level |
| ----- | ---------- | ----------- |
| < 4   | ALLOW      | —           |
| 4 – 6 | SOFT_BLOCK | L1          |
| 7 – 9 | HARD_BLOCK | L2          |
| ≥ 10  | HARD_BLOCK | L3+         |

---

### 3.3 OTP Failure Budget (24h) — Anti-Weaponization

| Condition                         | Decision                                                 |
| --------------------------------- | -------------------------------------------------------- |
| ≥ 10 OTP failures within 24 hours | **SOFT_BLOCK (Account)** with **minimum block level L4** |

#### 3.3.0 Budget Is a Decision Candidate (Not Fail-Fast)

* The same non-short-circuit rules as §2.4.0 apply to the OTP budget.
* The OTP budget MUST NOT prevent `recordFailure(otp_protection)` from continuing normal
  OTP failure scoring, correlation, and budget counting.
* Decision class wins before level or duration; the OTP budget `SOFT_BLOCK` cannot
  contribute properties to a winning `HARD_BLOCK` candidate.
* The OTP budget-issued `SOFT_BLOCK` is **not stored as hard-block persistence** and is
  not a level-1 `BlockState` cooldown marker.

#### 3.3.1 Budget Eligibility & Epoch

* **Every OTP failure is budget-eligible** at K4; there is **no Login-style K5 micro-cap**
  for OTP.
* OTP budget uses the same **fixed Budget Epoch** rules as §2.4.2
  (`epochStart` fixed inside the 24h epoch; failures never extend the epoch end).

#### 3.3.2 Command Eligibility (Owner Safety)

While OTP BudgetActive, the budget `SOFT_BLOCK` candidate is permitted **only** on:

* `recordFailure(otp_protection)`.

Budget enforcement is **forbidden** on:

* `checkOnly(otp_protection)`, and
* `recordSuccess(otp_protection)` — a successful OTP MUST return `ALLOW` and MUST NOT be
  blocked by an account budget.

#### 3.3.3 Cooldown

* OTP budget cooldown: **120 minutes** (`BudgetConfigDTO.cooldown_seconds = 7200`).
* Cooldown semantics are identical to §2.4.4 (enforcement-owned; no epoch extension or
  restart; budget `retryAfter` = remaining budget cooldown, not the epoch remainder).

#### 3.3.4 Trusted Session Downgrade

Trusted session devices downgrade by one level, never below the **trusted-session floor
level L3** while BudgetActive.

#### 3.3.5 Recovery Collision Guard (Deterministic)

To mitigate “preload 9 then wait for victim” attacks:

If the **effective OTP budget count before failure = 9** within the epoch, and the failing
request is from:

* a device with `DeviceConfidence = HIGH` (session-bound device), OR
* a device **previously verified for this `AccountID`** (`isKnownForAccount`, §0),

THEN the atomic budget increment returns `count = 10`, but:

* **BudgetActive MUST NOT start on this request.**
* Instead, apply `SOFT_BLOCK (L2)` **once**; the count stays `10` inside the same epoch.
* The **next** failure (`count = 11`) activates `BudgetActive` normally.

Decision rule — no persistent Recovery-Guard flag is needed because the trigger keys off
the **atomic returned budget count**:

```
returned count == threshold (10) AND qualifying recovery device
```

Because it keys off the atomic increment result, exactly **one** request is guarded even
under concurrency (concurrent initializations cannot duplicate the guard).

Duration:

* The Recovery Guard `SOFT_BLOCK (L2)` uses the **PenaltyLadder L2 duration** (60 s), NOT
  the OTP budget cooldown (BudgetActive has not started yet).
* The Recovery Guard is a normal `SOFT_BLOCK` candidate and MUST NOT acquire the budget
  cooldown. If a normal or Anti-Equilibrium `HARD_BLOCK` is also present, `HARD_BLOCK`
  wins without budget cooldown acquisition; otherwise it aggregates with other
  `SOFT_BLOCK` candidates by the class-local rule in §8.

---

## 4. API Heavy / Brute-Force Endpoints

### 4.1 Rate Enforcement

| Condition               | Key | Decision / maximum level |
| ----------------------- | --- | ------------------------ |
| Minor limit exceeded    | K2  | SOFT_BLOCK / L1          |
| Moderate limit exceeded | K3  | HARD_BLOCK / L2          |
| Severe limit exceeded   | K1  | HARD_BLOCK / L3          |

API Heavy persists each scope's own enforcement candidate. It does not create
K4 or K5 thresholds, candidates, or blocks, and active current or previous K4
or K5 blocks do not block an API Heavy request. It also does not create or read
the account-only repeated-missing-fingerprint auxiliary marker. A LOW-confidence moderate K3
signal remains `HARD_BLOCK` L2 but is persisted against K2, never K3.

---

## 5. Correlation Rules (Attack Detection)

### 5.0 Near-Threshold Watch (Mandatory Anti N-1 Gaming)

Many rules use thresholds. To prevent stable `N-1` bypass:

* If a correlation metric reaches **(threshold − 1)** within a window,
    * set a **WATCH flag** for the same scope (key) with TTL **30 minutes**.
* If the same WATCH flag is observed **twice** within its TTL,
    * upgrade the decision to the same action as if the threshold were met.

Credential-spray observation is performed by `checkOnly()` for authentication policies only. `recordFailure()` and `recordSuccess()` do not repeat that observation for the same command lifecycle; `checkOnly()` therefore is not globally read-only because bounded correlation state may be updated during the pre-check.

When `previousKeySecret` is configured, spray observation requires the additive
`CorrelationRotationStoreInterface` capability. The existing `CorrelationStoreInterface`
remains the source-compatible base contract for the no-rotation path. Missing rotation
capability, corrupt previous state, or a previous state without a valid TTL is an explicit
failure through the normal engine failure semantics; it must not become a current-only
reset.

All distinct correlation observations use the additive bounded capability. The no-rotation
path requires `BoundedCorrelationStoreInterface`; a current/previous observation requires
`BoundedCorrelationRotationStoreInterface`. `BoundedDistinctResultDTO` reports the logical
count and whether the member was accepted. Duplicates are accepted without mutation, while
new members at the cap are rejected atomically without set growth. Bounded scopes use
purpose/version/environment-separated keyed-HMAC references; raw account, IP, correlation,
and fingerprint values never cross the store boundary. Missing capabilities and corrupt
bounded state fail explicitly rather than falling back to unbounded operations.

Distributed-account device observations additionally require the additive
`BoundedCorrelationSnapshotStoreInterface` (or its rotation companion). The snapshot returns
the complete bounded logical member set, fixed expiry, and separate `accepted`/`added` flags;
the pipeline validates count, uniqueness, membership, expiry, and flag consistency before
using it for enforcement. Snapshot members are canonical opaque K5 keys and are applied
directly to the involved-device block candidates.

This is deterministic and testable (no randomness), and blocks “hover forever at N-1”.

---

### 5.1 Credential Spray Detection (IP-Based)

| Rule                      | Condition                                   | Decision        |
| ------------------------- | ------------------------------------------- | --------------- |
| IP attempts many correlation subjects | `distinct(correlationSubject) ≥ 5 within 10 minutes` | HARD_BLOCK (IP) |

**Advisory Constraint:**
IP-only blocks, including K1 hierarchy blocks and K1 score-derived candidates, are advisory for **trusted session devices** under Login and OTP. The K1 state remains stored and active for untrusted traffic; K2/K3/K4/K5 remain authoritative.

During key-secret rotation, the previous K1 spray set is read-only. The current K1
uses current-secret HMAC members, and a current bridge namespace
`credential_spray:bridge:{currentK1}` carries only subjects absent from the previous
set. While the previous window is active, the distinct count is previous cardinality
plus bridge cardinality; the differently-keyed current and previous sets are never
unioned or added directly. The current and bridge windows have fixed TTLs, with the
bridge capped by the previous remaining TTL, and the previous set and WATCH flag are
never written or extended.

The spray distinct set is capped at five members in its 600-second window. This cap is a
storage-safety invariant as well as an enforcement threshold; rejected new subjects do not
create additional members or keys.

---

### 5.2 Distributed Account Attack (Device Rotation)

| Rule                               | Condition                                  | Decision                      |
| ---------------------------------- | ------------------------------------------ | ----------------------------- |
| Account accessed from many devices | `distinct(DeviceFP) ≥ 4 within 10 minutes` | HARD_BLOCK (each involved K5) |

**IMPORTANT:**

* The AccountID MUST NOT be hard-blocked immediately by this rule.
* Only the **involved devices for that account** are blocked (apply blocks on each **K5**).
* Account-wide blocking requires **repeated occurrences** (see 5.2.1).

#### 5.2.1 Repeated Occurrence (Account Escalation Gate)

If the condition in 5.2 occurs **≥ 3 times within 24 hours** for the same `AccountID`, then:

* Apply **HARD_BLOCK (Account)** at **minimum level L4**.

This is the only correlation-based path that may hard-block the account.

Operational contract:

* The device window is a 600-second fixed-TTL bounded set capped at four logical K5 members.
* Three members create a 30-minute account watch marker. The second qualifying three-member
  observation is equivalent to the threshold; the fourth member meets it directly.
* On the first qualification of a logical device window (`occurrenceSnapshot.added = true`),
  persist every member in the complete bounded snapshot at HARD L2. A later qualification in
  that same device window must not refresh historical K5 TTLs: persist only the current
  non-ephemeral K5; an Ephemeral request creates no new K5 persistence. The distributed set
  remains capped at four members.
* These involved-device decisions do not block K4 on the first or second occurrence.
* A qualifying device snapshot contributes exactly one account-only occurrence member derived
  from that snapshot's fixed expiry. The occurrence window is 86400 seconds and is capped at
  three members; rejected overflow does not create a fourth member.
* Exactly the third occurrence adds HARD_BLOCK K4 at L4 for 1800 seconds. There is no K4
  escalation at occurrence N-1.
* Login and OTP `checkOnly()` are the only observation path. API Heavy, missing account or
  fingerprint, and missing real K4/K5 do not observe this rule or require its capability.
* During rotation, the previous device set is read-only, new logical members belong to the
  current generation and bridge, and the bridge expiry is bounded by the previous expiry.
  Outer-only, fingerprint-only, and both-rotated inputs use one coordinated pair; no
  Cartesian generation pairing is allowed. Fingerprint-only rotation has no prior occurrence
  namespace.

---

### 5.3 Fingerprint Evasion / Churn

| Rule                             | Condition                            | Decision              |
| -------------------------------- | ------------------------------------ | --------------------- |
| Rapid fingerprint changes        | `≥ 3 changes within 10 minutes`      | HARD_BLOCK (IP + UA)  |
| Same fingerprint across many IPs | `distinct(IP) ≥ 6 within 10 minutes` | HARD_BLOCK (DeviceFP) |

**Confidence Constraint (Anti-Passive Poisoning):**

`HARD_BLOCK(DeviceFP)` in 5.3 is allowed only if:

* `DeviceConfidence ≥ MEDIUM` (client-assisted or session-bound), AND
* the dilution is confirmed in a second window (two consecutive 10-minute windows).

If `DeviceConfidence = LOW` (passive-only), the decision MUST downgrade to:

* `HARD_BLOCK (IP + UA)` (K2), not DeviceFP.

Churn uses a bounded 600-second distinct set capped at three members. The third logical
member produces a K2 hard-block candidate, and the second qualifying N-1 WATCH observation
also produces the same candidate. During current/previous generation rotation, the previous
set is read-only and a current-secret bridge is used with its TTL capped by the previous
remaining TTL.

Fingerprint dilution uses a separate bounded 600-second set capped at six IP-scope members.
The fifth member creates a 30-minute WATCH; a second qualifying WATCH or the sixth member
meets the threshold. LOW confidence targets K2 directly. MEDIUM/HIGH confidence requires a
confirmation observation in a second 10-minute window before K3 enforcement. Ephemeral
overflow does not create or update per-fingerprint dilution state or confirmation, but the
bounded churn signal remains active.

---

### 5.4 New Device Flood Protection

| Rule                              | Condition                            | Decision                 |
| --------------------------------- | ------------------------------------ | ------------------------ |
| Excessive new devices per account | `≥ 6 new DeviceFP within 15 minutes` | SOFT_BLOCK (Account)     |
| Continued flood after soft block  | Same window                          | HARD_BLOCK (admitted new K5 only) |

New DeviceFP creation MUST be capped to prevent storage exhaustion.

Flood-stage state is policy-scoped. A Login stage MUST NOT make the first qualifying OTP
flood hard, and an OTP stage MUST NOT make the first qualifying Login flood hard. The stage
uses current-generation writes and may read one active previous outer-key generation.

**Invariant:** Ephemeral routing MUST NOT erase active blocks (see `DEVICE_FINGERPRINT.md`).

Device-cap observations use a 900-second bounded contract: at most 10 distinct account
members and 50 distinct IP-scope members. The pipeline performs the observation once using
the real current/previous generation tuple, routes only rejected new members to ephemeral,
and never creates a fake K3/K5 key. Flood evaluation begins at the sixth logical account
member even when that member is admitted; a rejected overflow remains eligible for the active
flood HARD decision but cannot create or persist new K5 score/block state. When a distributed-
account HARD candidate and the flood SOFT candidate coexist, the HARD candidate wins while
the flood state still advances. A distributed overflow does not admit a new K5 member.

---

## 6. Progressive Blocking (Penalty Ladder)

| Block Level | Duration   |
| ----------- | ---------- |
| L1          | 15 seconds |
| L2          | 60 seconds |
| L3          | 5 minutes  |
| L4          | 30 minutes |
| L5          | 6 hours    |
| L6          | 24 hours   |

Each escalation increases the level.
Levels decay **slower** as severity increases.

---

## 7. Decay Rules (Penalty Persistence)

| Scope         | Base Decay          |
| ------------- | ------------------- |
| Account score | −1 every 10 minutes |
| Device score  | −1 every 5 minutes  |
| IP score      | −1 every 3 minutes  |

### 7.1 Deterministic Decay Modifiers

* After reaching **L2 or higher**, decay rate is **halved**
* The **multiple-block-cycle 10-minute pause** remains deferred to Stage 3 and
  is not implemented by the current runtime.
* Budgets (Section 2.4, 3.3) are **fixed 24h epochs** and are **not affected by score decay**

### 7.2 Retry-After Semantics

For a score-threshold candidate with no active persisted block, `retryAfter`
means the time until the score becomes strictly lower than the threshold that
ends the current decision class:

* `SOFT_BLOCK` exits below L1.
* `HARD_BLOCK` exits below L2 for both L2 and L3 score levels.
* The calculation uses the raw stored score, `updatedAt`, the current block
  level used by decay, the scope-specific package interval, and elapsed partial
  or complete intervals. `DecayCalculator` is the single source of truth.
* A LOW-confidence K3 decision downgraded to effective L1 uses L1.
* If multiple score scopes participate in the winning class, the longest
  applicable decay wait is selected within that class.

An active persisted block always takes precedence and returns its remaining TTL;
it is not combined with the decay wait. Score-derived response retry time does
not change the `PenaltyLadder` persistence duration. Budget cooldown and all
non-score candidates retain their existing retry semantics.

---

## 8. Decision Aggregation Rule

When multiple rules produce decisions:

```
HARD_BLOCK > SOFT_BLOCK > ALLOW
```

* The **budget candidate** participates in final aggregation only; it MUST NOT
  short-circuit earlier evaluation stages (§1, §2.4.0, §3.3.0).
* First select the winning decision class. A `HARD_BLOCK` from normal scoring,
  anti-equilibrium, recovery, or correlation always wins over a budget `SOFT_BLOCK`.
* Lower-ranked candidates MUST NOT contribute `blockLevel`, duration, `retryAfter`,
  persistence, or any other decision property to the winning class.

If multiple blocks apply:

* Among candidates in the winning class only, select the **highest block level**.
* Among candidates in the winning class only, select the **longest duration** or
  `retryAfter` applicable to that class.

Therefore, a budget `SOFT_BLOCK` can never upgrade a `HARD_BLOCK` level or duration,
and a lower-ranked `SOFT_BLOCK` cannot affect a winning `HARD_BLOCK`'s persistence.

Account safety always overrides IP convenience.

### 8.1 Normative Examples

#### Example A — Hard class wins over budget soft

```text
Normal HARD L2 / 60s
Budget SOFT L3 / 3600s

Final:
HARD L2 / 60s
No budget cooldown acquired
```

#### Example B — Budget soft joins a normal soft

```text
Normal SOFT L1 / 15s
recordFailure
BudgetActive
Cooldown acquired

Final:
SOFT L3 / 3600s
```

#### Example C — Login checkOnly normal soft is not budget-eligible

```text
Normal SOFT L1
checkOnly(login)
BudgetActive

Final:
SOFT L1
Budget not attempted
No budget cooldown acquired
```

#### Example D — Login checkOnly normal allow can issue budget soft

```text
Normal ALLOW
checkOnly(login)
BudgetActive
Cooldown acquired

Final:
Budget SOFT L3 / 3600s
```

#### Example E — Recovery Collision Guard is a normal soft

```text
Normal ALLOW
recordFailure(otp)
Recovery transition count 9 → 10
qualifying recovery device

Final:
SOFT L2 / 60s
No budget cooldown
BudgetActive suppressed for this request
```

#### Example F — Prior Anti-Equilibrium history blocks budget issuance

```text
Three prior actually-issued SOFT_BLOCK events exist within 6h.

Next recordFailure:
Normal = ALLOW
BudgetActive = true

Anti-Equilibrium candidate:
HARD L2

Final:
HARD L2
Budget not attempted
No budget cooldown acquired
No new Anti-Equilibrium soft event recorded
```

#### Example G — Anti-Equilibrium wins over Recovery Guard

```text
Three prior actually-issued SOFT_BLOCK events exist within 6h.
Recovery Guard = SOFT L2
Anti-Equilibrium = HARD minimum L2

Final:
HARD L2
No budget cooldown acquired
No new Anti-Equilibrium soft event recorded
```

---

## 9. Failure Semantics (Reference)

| Context     | Failure Mode |
| ----------- | ------------ |
| Login / OTP | FAIL_CLOSED  |
| API Heavy   | FAIL_OPEN    |

(See `FAILURE_SEMANTICS.md` for authoritative rules.)

---

## 10. Guarantees

* No decision is based on a single signal
* Remote permanent account lockout via budget-only rules is impossible
* Budget epochs cannot be extended (“no renewable 24h prisons”)
* An active budget never hides a stronger score/correlation `HARD_BLOCK`
* Budget `SOFT_BLOCK` never uses hard-block persistence (`RateLimitStoreInterface::block()`)
* Budget cooldown never extends or restarts the fixed budget epoch
* Same-device equilibrium cannot bypass budgets indefinitely
* Device rotation does not reset account memory
* K4 budget / K5 micro-cap counters are never merged with `max(v1.count, v2.count)` across
  key rotation (`KEY_STRATEGY.md` §4.3.1)
* IP-only blocking is never final
* Device awareness is mandatory
* Progressive escalation with persistence
* Deterministic and atomic behavior
* Fully testable and auditable

---

**This document is authoritative.
Any deviation requires a version bump and changelog entry.**
