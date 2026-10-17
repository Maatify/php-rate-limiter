# G1 final security disposition — identity-aware bounded admission (#97)

> **Status:** FINAL G1 DISPOSITION for Lead review. This closes the G1 evidence loop requested in
> [PR #126 comment 6229298358](https://github.com/Maatify/php-rate-limiter/pull/126#issuecomment-6229298358).
> **Not a policy approval.**
> * It does not enter G2/G3, approve any of D1–D7, or set a threshold or default.
> * It changes no `src/`, DTO, contract, preset, standard, ADR or decision. DEC-019 stays ACTIVE.
> * It does not ask the Owner to choose; every item below is an engineering result or a stated prerequisite.
>
> **Evidence chain.** Each step was pre-declared before its results and every step is append-only:
>
> | Step | Report | Pre-declared |
> | --- | --- | --- |
> | Original study | [`G1_STUDY.md`](G1_STUDY.md) | `0f40ce5` |
> | Verification addendum | [`G1_VERIFICATION.md`](G1_VERIFICATION.md) | `0aec4c1` |
> | DA ablation and T1 | [`G1_DA_T1.md`](G1_DA_T1.md) | `8e6c2e9` |
> | Flood (this step) | [`g1/verify/FLOOD_PLAN.md`](g1/verify/FLOOD_PLAN.md) | `af9eaa5` |
>
> `bash investigations/k2-identity-admission-97/g1/verify/run_flood.sh` re-runs the whole chain and requires every archived evidence set to compare clean. The last run did.

## 1. Disposition

| Subject | Disposition | Basis |
| --- | --- | --- |
| **Complete candidate (any assembly of the tested parts)** | **NO-GO** | the prerequisites of §3 are unmet; §4 says what a complete candidate must show |
| Removing collective K1 / K2 / K3 and correlation penalties (D1 core) | **supported by evidence** | D1 596/600 → 0, D1API 595 → 0, L3 104 → 0. C10-H: 0 benign denials of K1/K2/K3/correlation origin, detector proven (`G1_VERIFICATION.md` §3) |
| `distributed_account_attack` **punishment** | **remove; keep observation** (engineering GO for observation only) | CAND and CAND-DA-M NO-GO; observation-only GO (`G1_DA_T1.md` §1–3) |
| New Device Flood **punishment** | **remove; keep observation** (engineering GO for observation only) | §2 |
| Per-target U bound (`U_acct` or equivalent) | **required (load-bearing)** | without the DA and flood punishments it is the only per-account bound against device-rotating guessing (§2.2) |
| T1 permits (original) | **NO-GO / NOT RECOMMENDED** | single issuance and all-or-nothing broken (`G1_DA_T1.md` §4) |
| T1 with the fixed study store | **`UNPROVEN` for production**; optional, not needed for a T0-only design | all fixtures pass on a single Redis instance |
| U/P lanes with M-A (K4P), P device cap, atomic T0 reservation | **mechanism evidenced** in the harness (C1-H, C9-H, R3-H, DV, PA) | conditional on §3 |

## 2. Flood counterexample (F126-G1-R3-01)

### 2.1 Result (`MEASURED`, `g1/verify/expected_flood/`)

**The declared fixture FLOOD-P / FLOOD-Pok (six U devices on six distinct IPs) does not exercise the flood rule.** The rule's device count comes from `EphemeralBucket::check()` on the observation anchored at `accountAnchor()` = K4 + K1. That makes it a count **per (account, source)**: six devices on six IPs give a count of 1 per anchor (measured), so `flood_stage` is never set. This is a reachability condition, not a safety property.

**The added shared-IP fixture FLOOD-P-NAT / FLOOD-Pok-NAT confirms the counterexample.** It is disclosed in the fixture code and in the plan's spirit: six devices behind the owner's NAT (a household or office, or an attacker in the same IPv4 or IPv6 /64), and one more proven request at t = 90, inside the 60 s L2 duration. Owner timelines:

| Arm | Owner at t = 60 (same device, proof expired, U) | Owner at t = 90 (proven again, P) | Control (no population) |
| --- | --- | --- | --- |
| CAND | HARD, origin DA + flood; K5 L2 | **HARD**, inherited DA + flood | U A, P A |
| CAND-no-DA-punishment | **HARD, origin flood; K5 L2** | **HARD, inherited flood** | U A, P A |
| CAND-no-DA-no-flood-punishment | SOFT (`k4` / `cap:acct`, account-level U) | **ALLOW**, identical to control | U A, P A |

The same result holds with a benign household (FLOOD-Pok-NAT). No stolen secret is needed; other devices behind the same source suffice. **The flood punishment is the same group-attribution error as DA**, and P-2 does not isolate its effect.

### 2.2 Cost of removing the flood punishment (DAOF against DAO, same parameters)

| Item | Result |
| --- | --- |
| Rule F1–F4 | all pass: no hardened-gate failure, 0 benign DA / flood denials, owner P identical to control in FLOOD-P*, MX and MXE, no cap exceeded. DV and lifecycle outputs byte-identical to the archived CAND |
| Matrix | 16 of 644 cells change, all device-rotating strategies in `pre` only: BRU 16 → 20 and X1 4 → 5 (R, R-src4, R-src40, ALLU) |
| Account cap not binding (X03) | BRU 20 → 80 / 100, X1 5 → 17 / 41 |
| Best response | **unchanged in every sampled cell** (modelled strategies only) |

Combined with `G1_DA_T1.md` §2, the two device-population punishments only constrain device-rotating attackers against checkOnly-only Hosts, and only up to the per-account U cap. With them removed, **`U_acct` is the per-target bound**. A design that drops or loosens it lets single-target guessing grow to `U_src` / `U_glob`.

## 3. Prerequisites a complete candidate does not yet meet

| # | Prerequisite | State | Owner of the next step |
| --- | --- | --- | --- |
| P1 | **Typed, library-enforced proof binding** (proof → account **and** operation), condition C8 / invariant I5 | `UNPROVEN`. The isolated patch accepts a direct lane claim and a Login proof on OTP (`G1_VERIFICATION.md` §4). A forged claim reaches the victim 8–9× per hour | an API / DTO decision, outside G1 |
| P2 | **T0 device-set integrity**: no write before the decision, previous generation read-only | the original Redis store fails T1-N4b / N5; the fixed study store passes | a store design for any implementation |
| P3 | **Unproven-owner and day-0 availability** | HAR: an unproven owner is denied 14/15 in S0 and every candidate set, and on day-0 (no proof honoured) the owner is denied 14/15 as well. Only a proof protects (HARp 0/15). After removing DA / flood, the remaining owner harm on the U lane is account-level K4U, which is individual to the account, not to device groups | depends on D3 (proof issuance), D4 |
| P4 | **Distributed-spray reach** | no sampled `U_acct` × `U_src` set is free of regression: POI, SP6, SP3, SP1n, ABA_A and MIX exceed S0's best response in every set, while `U_acct ≥ 16` with `U_src = 40` is needed for zero benign regression (`G1_VERIFICATION.md` §6). S0's collective denials are not the target, but the reach increase is a measured harm without a bounded answer | a source-capacity and global-bound design (D5 / D7), not a parameter pick |
| P5 | **Operational envelope** | DEC-015 degraded mode, real failover with replication, Redis Cluster and concurrent mixed fleets are `UNPROVEN`. The mechanism holds on a single Redis instance only | implementation-stage evidence |
| P6 | **Production values** | none; every parameter here is a test parameter | after P1–P5 |

## 4. What a complete candidate must demonstrate to be GO

These are objective, and are restated from the evidence, not newly chosen:

1. **The hardened gates.** C1-H, C9-H, C10-H and R3-H, with their detector controls firing, on the complete assembly.
2. **No device-group punishment.** Zero benign denials of `distributed_account_attack` or `flood` origin. Proven-owner P decisions identical to the no-population control in MX, MXE and FLOOD-P-NAT.
3. **A library-enforced proof boundary.** B1–B5 of `G1_VERIFICATION.md` §4 must refuse **inside the library**, not only through the Host mapping.
4. **T0 store integrity.** The T1-N4b / N5 equivalents hold for the production store, plus R-1 / R-2 races.
5. **A stated, bounded answer for distributed-spray reach and unproven-owner availability.** It must be measured on the same streams and must not count S0's false-positive denials as protection.

## 5. Recommended engineering selection for the next design iteration (not a policy)

Carry forward:
* U/P admission with atomic T0 reservation;
* M-A lane-scoped K4P;
* the P device cap with capacity refusal;
* D1 removal of collective K1 / K2 / K3 / correlation penalties;
* `distributed_account_attack` and New Device Flood as **observation only**;
* a mandatory per-target U bound.

Do **not** carry forward:
* T1 in its original form;
* any device-group-attributed K4 / K5 persistence.

Resolve P1 and P2 before any implementation. Treat P3 / P4 as design problems with measured targets.

**Not done here.** No G2 / G3, no merge, no Runtime, no Owner question.
