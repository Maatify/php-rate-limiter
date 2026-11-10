# Admission In-Flight Concurrency Measurements (#185, LT-185-37a)

This document records the empirical measurements of in-flight admission concurrency envelopes and subsequent request behavior under sliding admission windows for both the U-lane (`source_capacity = 24`, `source_failure_cap = 4`) and the P-lane (`proof_proof_window = 6`, `source_proof_failure_cap = 4`).

Measurements were produced by `measure.php` against Redis running locally with the sliding window Lua admission scripts and `RedisFullCapabilityStore`.

---

## 1. U-lane In-Flight Concurrency (`source_capacity = 24`, `source_failure_cap = 4`)

Scenario: A single client IP issues concurrent requests before any admission outcomes are registered. Each admitted permit subsequently records a login failure (`registerOutcome` with `FailureOutcomeDTO`), incrementing the sliding failure set (`ufail`).

| Concurrency | Admitted | Refused (Cause) | Final `ufail` ZCARD | Subsequent Request Decision (Cause) |
| :---: | :---: | :--- | :---: | :--- |
| 1 | 1 | 0 | 1 | ALLOW (none) |
| 4 | 4 | 0 | 4 | SOFT_BLOCK (`source_failure_cap`) |
| 10 | 10 | 0 | 10 | SOFT_BLOCK (`source_failure_cap`) |
| 20 | 20 | 0 | 20 | SOFT_BLOCK (`source_failure_cap`) |
| 24 | 24 | 0 | 24 | SOFT_BLOCK (`source_failure_cap`) |
| 25 | 24 | 1 (`source_capacity`) | 24 | SOFT_BLOCK (`source_failure_cap`) |
| 30 | 24 | 6 (`source_capacity`) | 24 | SOFT_BLOCK (`source_failure_cap`) |

### U-lane Concurrency Analysis
- **In-flight envelope**: Bounded by `source_capacity = 24`. Even if 25 or 30 concurrent requests hit Redis simultaneously, Redis atomicity admits at most 24; any excess is refused immediately at admission with reason `source_capacity`.
- **Failure accumulation**: When admitted requests report failure, `ufail` cardinality reaches the count of admitted failures (up to 24).
- **Post-failure state**: Once 4 or more failures settle, any subsequent request is refused with `SOFT_BLOCK` (`source_failure_cap = 4`).
- **Safety guarantee**: At no point can concurrent in-flight requests exceed `source_capacity = 24`.

---

## 2. P-lane In-Flight Concurrency: Single Proof (`proof_proof_window = 6`, `source_proof_failure_cap = 4`)

Scenario: Concurrent requests reuse the exact same proof token from a single IP. Each admitted permit records a failure with that proof.

| Concurrency | Admitted | Refused (Cause) | Final `pfail` ZCARD | Subsequent Request Decision (Cause) |
| :---: | :---: | :--- | :---: | :--- |
| 1 | 1 | 0 | 1 | ALLOW (none) |
| 4 | 4 | 0 | 1 | HARD_BLOCK (none) |
| 6 | 6 | 0 | 1 | HARD_BLOCK (none) |
| 8 | 6 | 2 (`proof_capacity`) | 1 | HARD_BLOCK (none) |
| 10 | 6 | 4 (`proof_capacity`) | 1 | HARD_BLOCK (none) |
| 20 | 6 | 14 (`proof_capacity`) | 1 | HARD_BLOCK (none) |

### P-lane (Single Proof) Concurrency Analysis
- **In-flight envelope**: Bounded by `proof_proof_window = 6`. When a single proof is reused concurrently, Redis limits in-flight permits to 6. Any requests beyond 6 are refused with `proof_capacity`.
- **Proof invalidation**: Once a single proof failure is recorded, the proof is marked consumed/failed, resulting in subsequent refusal (`HARD_BLOCK`).

---

## 3. P-lane In-Flight Concurrency: Distinct Proofs & Accounts from Same IP (`source_proof_failure_cap = 4`)

Scenario: An attacker from a single IP obtains distinct proof tokens for distinct target accounts and issues requests concurrently before any failure outcome is registered.

| Concurrency | Admitted | Refused (Cause) | Final `pfail` ZCARD | Subsequent Request Decision (Cause) |
| :---: | :---: | :--- | :---: | :--- |
| 1 | 1 | 0 | 1 | ALLOW (none) |
| 4 | 4 | 0 | 4 | SOFT_BLOCK (`source_proof_failure_cap`) |
| 10 | 10 | 0 | 10 | SOFT_BLOCK (`source_proof_failure_cap`) |
| 20 | 20 | 0 | 20 | SOFT_BLOCK (`source_proof_failure_cap`) |
| 24 | 24 | 0 | 24 | SOFT_BLOCK (`source_proof_failure_cap`) |
| 25 | 25 | 0 | 25 | SOFT_BLOCK (`source_proof_failure_cap`) |
| 30 | 30 | 0 | 30 | SOFT_BLOCK (`source_proof_failure_cap`) |

### P-lane (Distinct Proofs) Concurrency Analysis
- **In-flight admission behavior**: Because each request carries a distinct proof for a distinct account, and no failure has yet been recorded in Redis (`pfail` is empty prior to outcome registration), all concurrent requests pass the admission check.
- **Declared Cost / Accepted Trade-off (LT-185-37a / DEC-034 §8.2)**:
  - If 30 concurrent requests arrive simultaneously with distinct proofs from the same IP, all 30 may be admitted before any outcomes register.
  - When outcomes are recorded, `pfail` for the source IP accumulates all 30 failures (`pfail` ZCARD = 30).
  - Immediately following outcome registration, the source IP has exceeded `source_proof_failure_cap = 4`, and any subsequent attempt from that IP is strictly refused with `SOFT_BLOCK` (`source_proof_failure_cap`).
  - As ruled under LT-185-37a, no new artificial in-flight bound is introduced for lane P, and this empirical behavior is formally documented as a declared design cost accepted by the Owner.
