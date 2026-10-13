# #110 OAAA — pre-registered security / fairness envelope (Stage B)

**Status: STUDY / EVIDENCE ONLY.** Written and committed **before** the full candidate run, so that the success conditions cannot move after
the results are seen. The numeric limits in §2 are the Technical Lead's **provisional screening hypotheses** from the task, copied unchanged;
they are **not** Owner-approved production limits, and passing them is **not** a GO (task §4).

Disclosure: before this file was committed, the candidate was smoke-run on 12 streams (D2, Nu12, ABA_B, ABA_A, SP1n, SP2, OWN1, OWN2, D1,
MIX, TGTM, D5) to check that the harness and the patch execute. No parameter of §1 and no limit of §2 was changed after that run; the limits
are the Lead's, verbatim. The smoke run already showed a co-located-attacker delta of +2 in one fixed 600 s window of MIX (S0 = 0) — the gate
E3 below is kept as written and is expected to FAIL there.

## 1. Candidate parameters (fixed; identical to the patch constants and to `lib/cells.php` `OAAA_PARAMS`)

| Parameter | Value |
|---|---|
| units per rule scope per epoch (fixed 600 s, from first reservation) | 2, and never more than the number of distinct accounts with a Host-verified success in that scope in the current or previous 600 s window |
| units per rule scope per day (fixed 86 400 s) | 6 |
| global units per policy (all scopes) | 12 per 600 s, 48 per 86 400 s |
| tuple lifetime (pairing / same-tuple refund window) | 600 s |
| credit members per scope per 600 s window | 2 |
| same-tuple failures returned by one success | 2 (MEDIUM / HIGH devices only; LOW: 0) |

## 2. Gates (each evaluated by `report.php` from `expected/*.jsonl`; any missing input ⇒ the gate is UNPROVEN, never PASS)

Definitions. *Attack cell*: a matrix cell whose stream class is `attack`, `evasion` or `poisoning`, or the attack half of a `both` stream, on
the Login / OTP / Login-custom shapes. *Reach*: attack requests that reached the Host's verifier. Reach deltas are judged on the `pre+out` and
`pre` flows; on `out` (record-only) every attempt is verified before the library is called in **both** arms, so `out` reach is reported, not
gated. *Scope*: the attacker's IP (the K1 scope; every modelled attacker uses one UA per IP, so it is also its K2 scope). *Δ* = OAAA − S0 for
the same cell, stream, flow and window. Fixed windows start at the first event of the stream; rolling windows are 60 s-aligned (computed from
per-60 s buckets).

### Forbidden (any FAIL ⇒ NO-GO)

| ID | Gate | Rule |
|---|---|---|
| E4 | No full security collapse | no attack cell with S0 reach < attempts and OAAA reach = attempts |
| E5 | No replay- or concurrency-based unbounded admission | every replay / duplicate / late / cross-account / concurrency assertion of `a02` and `a06` holds on OAAA; real-Redis concurrency must have run (else UNPROVEN) |
| E6 | Enforceable cross-scope aggregate bound | in every multi-scope attack cell: Δ per fixed 600 s window summed over all attacker scopes ≤ 12 and Δ over the whole stream ≤ 48 per 86 400 s; plus `a02` L13 (global exhaustion) holds |

### Provisional numeric screening (Lead hypotheses; any FAIL ⇒ NO-GO for this parameterisation, the measured excess is reported for the Owner)

| ID | Lead dimension | Rule |
|---|---|---|
| E1 | Additional verification admissions / correlation scope / 600 s ≤ +6 vs S0 | max over attack cells, scopes and fixed 600 s windows of the per-scope Δ ≤ 6 |
| E2 | Additional admissions per concurrent burst ≤ +2 vs S0 | in every real-Redis burst of `a06` (one scope), OAAA admitted − S0 admitted ≤ 2 |
| E3 | Cells with S0 = 0: ≤ +1 / 600 s, with an independent total cap | for every attack cell with S0 reach = 0: Δ ≤ 1 in every fixed 600 s window **and** every 60 s-aligned rolling 600 s window, and the total Δ ≤ 6 per scope (the day tier) |

### Measured and reported, not gated by a number (task §4 list)

Δ per 60 s, 600 s, 1 800 s (fixed and rolling); total Δ per stream; attacker multi-IP / multi-account / multi-session aggregates; extra
verifier reach while S0 is blocking (requests S0 denied and OAAA let through); legitimate requests newly admitted and newly denied; worst-case
burst; worst-case rolling window; extra reach not explained by granted reservations (state-path drift).

### Fairness (reported per issue; no acceptance threshold is pre-set — that is an Owner decision)

| ID | Gate | Rule |
|---|---|---|
| F1 | Benefit per issue | legitimate denials S0 → OAAA per cell for **#97** D1, L1, L1f, X2L; **#100** ABA_B, ABA_B1, GAP, N12; **#106** Nu4, Nu6, Nu12, D2, MIX, MIXN, MIXT, N12T (all three flows) — reported, never merged across issues |
| F2 | No fairness regression | no cell in which OAAA denies more legitimate requests than S0 (FAIL otherwise, with the cells listed) |

### Operational

| ID | Gate | Rule |
|---|---|---|
| O1 | `pre`-only Hosts = S0 | every `pre` cell: identical legitimate-denial and attack-reach bit strings in both arms |
| O2 | Out-of-scope policies = S0 | every `api`, `api_custom` and `login-anon` cell bit-identical |
| O3 | Custom store without the capability = S0 | `a02` L19 |
| O4 | Bounded storage | `a04` S1/S2 and `a05` T1: fixed TTL ≤ 86 400 s on every OAAA key, credit sets ≤ 2 members, tuples ≤ granted reservations, no key without TTL on Redis |
| O5 | Rotation | `a03`: no reset of any tier by rotation, no write to a previous generation, macro scopes reserved atomically |
| O6 | Backend failure (DEC-015) | `a04` F1/F2 measured through the REAL engine; the bound during an outage is **UNPROVEN** by construction (the gate can never be PASS) |
| O7 | Rollback / mixed version | `a04` R1/R2 + `a05` P3/P4 "no exception" (PASS/FAIL); `a04` R3 S0-family state parity and R4 decision parity are recorded as measured (FAIL when they differ — "no exception" is not parity) |
| O8 | Host integration | `a07`: public Builder + engine + Redis, full `checkOnly → verify → outcome` cycle, both arms |
| O9 | Provenance / evidence integrity | S0 from the unpatched repository tree, OAAA from the pinned isolated tree; fail-closed reporter self-tests |

## 3. Decision rule (pre-registered)

1. Any FAIL in E4, E5 or E6 ⇒ **NO-GO**.
2. Else any FAIL in E1, E2 or E3 ⇒ **NO-GO** for the measured parameterisation; the report states the measured excess per failing row so the
   Owner can decide whether a different limit or parameterisation is worth a new, separately authorised unit.
3. Else ⇒ **GO_FOR_OWNER_RISK_REVIEW**, with the required optional contract extension listed as an Owner decision (it is never assumed).
4. F2, O-gate FAILs and every UNPROVEN item are listed explicitly in the report and the PR body whatever the verdict.
