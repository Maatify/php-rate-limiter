# Pre-Registered Sliding Admission Window Measurements and Results (#185, DEC-033)

## 1. Overview and Pre-Registered Contract

This document records the empirical results of the sliding admission window implementation specified in **DEC-033**. Every claim in DEC-033 §5 was pre-registered prior to implementation. Below is the side-by-side comparison of pre-registered expectations versus measured actual values.

The measurements were executed using:
- Dedicated measurement harness: `investigations/sliding-admission-windows-185/harness.php`
- Clean, isolated Redis instance via `scripts/ci/run-with-redis-service.sh`
- System and Integration suites: `tests/System/AdmissionArchiveG7Test.php`, `tests/Integration/Store/AdmissionNm10RedisParityTest.php`, and `tests/Integration/Store/AdmissionSlidingWindowBurstRegressionTest.php`

Raw console output is captured in `investigations/sliding-admission-windows-185/measurements.log`.

---

## 2. Pre-Registered vs Actual Measurements Table

| Metric / Experiment | DEC-033 §5 Pre-Registered Target | Actual Measured Value | Status | Explanation / Derivation |
| :--- | :--- | :--- | :--- | :--- |
| **NM-10 Store Parity** | 100% decision and state parity | **100% parity** (0 mismatches across all shares 0.10, 0.25, 0.50, 1.00) | **PASS** | Exact lockstep parity between `RedisAdmissionOperations` and `AdmissionInMemoryReferenceStore`. |
| **G7 PX4: TMS reach** | $\le 29$ | **21 / 61** | **PASS** | Well below pre-registered ceiling $\le 29$. |
| **G7 PX4: TMSd reach** | $\le 40$ | **40 / 200** | **PASS** | Exactly meets pre-registered ceiling $\le 40$. |
| **G7 PX4: TPAIRT co-located office cost** | Expected ~21 / 61 (provisional) | **26 / 61** | **EXPLAINED** | Divergence analyzed below in §3. |
| **G7 PX4: TPAIRT attacker reach** | Reference: 120 | **40 / 120** | **PASS** | Malicious burst severely suppressed (was 72 under tumbling, now 40). |
| **DEC-025: $U_{\text{src}}$** | 24 per 600s sliding window | **24 / 600s** | **PASS** | Sliding sorted set with score $t + 600$. |
| **DEC-025: $U_{\text{fail}}$** | 4 per 600s sliding window | **4 / 600s** | **PASS** | Sliding sorted set with score $t + 600$. |
| **DEC-025: $U_{\text{pfail}}$** | 4 per 600s sliding window | **4 / 600s** | **PASS** | Sliding sorted set with score $t + 600$. |
| **DEC-025: $P_{\text{acct}}$** | 10 per 600s sliding window | **10 / 600s** | **PASS** | Sliding sorted set with score $t + 600$. |
| **DEC-025: $P_{\text{proof}}$** | 6 per 600s sliding window | **6 / 600s** | **PASS** | Sliding sorted set with score $t + 600$. |
| **DEC-025: $P_{\text{acct, day}}$** | 60 per 86400s sliding window | **60 / 86400s** | **PASS** | Sliding sorted set with score $t + 86400$. |
| **DEC-025: $P_{\text{proof, day}}$** | 30 per 86400s sliding window | **30 / 86400s** | **PASS** | Sliding sorted set with score $t + 86400$. |
| **Boundary Burst: `ufail`** | 4 not 8 | **4 admitted, 5th blocked** (`source_failure_cap`) | **PASS** | 5th failure at $t=604$ blocked despite crossing $t=600$. |
| **Boundary Burst: `pfail`** | 4 not 8 | **4 admitted, 5th blocked** (`source_proof_failure_cap`) | **PASS** | 5th failure at $t=604$ blocked despite crossing $t=600$. |
| **Boundary Burst: `src`** | 24 not 48 | **24 admitted, 25th blocked** (`source_capacity`) | **PASS** | 25th request at $t=604$ blocked despite crossing $t=600$. |
| **Boundary Burst: `pacct`** | 10 not 20 | **10 admitted, 11th blocked** (`proof_capacity`) | **PASS** | 11th request at $t=604$ blocked despite crossing $t=600$. |
| **Boundary Burst: `pproof`** | 6 not 12 | **6 admitted, 7th blocked** (`proof_capacity`) | **PASS** | 7th request at $t=604$ blocked despite crossing $t=600$. |
| **Boundary Burst: `pacctd`** | 60 not 120 | **60 admitted, 61st blocked** (`proof_capacity`) | **PASS** | 61st request at $t=86410$ blocked despite crossing day boundary. |
| **Boundary Burst: `pproofd`** | 30 not 60 | **30 admitted, 31st blocked** (`proof_capacity`) | **PASS** | 31st request at $t=86410$ blocked despite crossing day boundary. |

---

## 3. In-Depth Root Cause: TPAIRT Co-Located Office Cost Divergence (21 to 26 / 61)

In DEC-033 §5, the office shared cost in `TPAIRT` was provisionally expected to stay at 21/61 based on tumbling window baselines. Measured actual cost is **26 / 61**.

### Mathematical and Physical Derivation
1. **The Tumbling Window Artifact:**
   Under fixed tumbling windows, failure sets expired at the fixed boundary $t = 600$. An attacker failing repeatedly on lane P prior to $t = 600$ (e.g., at $t = 590$) caused the cross-account failure set (`cap:pfail_src`) to fill. However, at $t = 600$, the tumbling bucket instantly dropped to zero. Legitimate office requests arriving in the window $t \in [600, 610]$ found an empty bucket and were admitted.
2. **The Sliding Window Reality:**
   Under sliding windows (DEC-033), failure set records are stored in a sorted set where each member expires at $t_{\text{event}} + W$ ($t + 600$). Failures recorded at $t = 590$ remain active until $t = 1190$. Legitimate office requests that share the same IP/source prefix arriving between $t = 600$ and $t = 1190$ are refused because the attacker's failures have not expired.
3. **Attacker Reach Reduction:**
   As a direct consequence of this sustained suppression, the attacker's reach under the cap dropped from **72** under tumbling windows down to **40** under sliding windows (a 44.4% reduction in attacker reach).
4. **Office Harm vs Security Tradeoff:**
   The benign office co-located on the same IP as the active attacker pays 5 additional refusals (26 instead of 21 out of 61 requests) during the overlap window, while attacker reach is suppressed by 32 attempts. This is the exact, intended security behavior of a sliding cross-account failure cap.

---

## 4. Reproducibility

To re-run and verify all measurements independently:

```bash
# 1. Run the standalone measurement harness against an isolated Redis service
scripts/ci/run-with-redis-service.sh php -d memory_limit=1G investigations/sliding-admission-windows-185/harness.php

# 2. Run the boundary burst integration test suite (7 scopes)
scripts/ci/run-phpunit-with-redis.sh tests/Integration/Store/AdmissionSlidingWindowBurstRegressionTest.php

# 3. Run the NM-10 Redis parity test suite across all 4 drop shares
scripts/ci/run-phpunit-with-redis.sh tests/Integration/Store/AdmissionNm10RedisParityTest.php

# 4. Run the G7 archive test suite
vendor/bin/phpunit tests/System/AdmissionArchiveG7Test.php
```
