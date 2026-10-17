# G1 — Pre-registered plan (isolated comparative study)

> **Status:** PRE-REGISTRATION, committed **before** any G1 result was produced. Authorized by Owner R-1
> ([PR #126 comment 6227307995](https://github.com/Maatify/php-rate-limiter/pull/126#issuecomment-6227307995)) after G0
> ([comment 6227201516](https://github.com/Maatify/php-rate-limiter/pull/126#issuecomment-6227201516)). **Evidence only:** no
> change to `src/`, `tests/`, `docs/`, ADRs, thresholds or issues. Every parameter below is a **test parameter**, never an
> approved or recommended production value. S0 is a historical comparator, not a security oracle.
> **Design under test:** [`../ARCHITECTURE_PROPOSAL.md`](../ARCHITECTURE_PROPOSAL.md) and
> [`../SECURITY_FEASIBILITY.md`](../SECURITY_FEASIBILITY.md) at `8cf985a8ec8568b67392d61531d6c22649627fc5`.
> **Code baseline:** `main@4c6ca618d8206ba502961eaf06ffb82a5d61b50d` (`src/` byte-identical to the baselines of #118, #119 and #125).

## 1. Arms

| Arm | Runtime | Purpose |
| --- | --- | --- |
| **S0** | repository `src/`, unpatched (pipeline sha-256 asserted) | historical comparator |
| **CAND** | isolated tree: `git archive` of the baseline + `patches/g1-candidate.diff` (pipeline only), run with `G1_ARM=CAND`, plus the study admission layer (`lib/`) | the recommended architecture (`P§12`) |
| CAND-NOADM | CAND pipeline, admission layer disabled (no caps) | ablation: what the caps contribute |
| CAND-ALLU | CAND with every request treated as U (no proof honoured) | ablation: Login without D3 (GA-1) |

The patched pipeline throws unless `G1_ARM=CAND`, so the candidate tree cannot run as S0.

## 2. Candidate as implemented (traceable to the design)

* Lanes: P iff the event carries a proof for its own account (mapping M1 below) **and** a non-empty `sessionDeviceId` (P-1); else U.
* Pipeline (patch): K4 key `k4p` for P (M-A); account Anti-Equilibrium watcher and budget cooldown keys lane-scoped; correlation
  (churn, dilution) and credential-spray candidates removed (D1); P skips both `EphemeralBucket` caps (P-3), distributed-account
  and flood (P-2); K1/K2/K3 blocks not read for P; API Heavy returns ALLOW from the pipeline (admission-only, LT-7). K5 formula,
  DEC-021 allowance (shared) and DEC-007 primitives unchanged.
* Admission layer (study code, reference store + Redis Lua store): atomic all-or-nothing reservation at `checkOnly` (T0), lanes
  U {`U_src`, `U_acct`, `U_glob`}, P {`P_acct` window + day, `P_proof` window + day, `P_glob`, P device set `N_P`/`T_P`}, API
  {`A_src` = K1 window, `A_glob`}; refusal = capacity `SOFT_BLOCK` with cause `cap:<scope>`; no refunds; optional T1 permits.
* **Proof mapping M1** (stated, not inferred): a stream event with `trusted = true` and session `sess-<acct>` carries a proof
  for `<acct>` (same-account re-authentication or step-up, D3/GA-2). A trusted session naming another account (SP5
  `sess-stolen`) is **not** a proof for the target. `verified` (historical) is never a proof.
* **Ownership mapping:** an attacker event with outcome `ok` is on an attacker-owned account (`reach_own`; X7 `own`, Y6b/Y4d `or*`).

## 3. Test parameters (fixed before the run)

| Set | `W` | `U_src` | `U_acct` | `U_glob` | `P_acct` W / day | `P_proof` W / day | `P_glob` | `N_P` / `T_P` | `A_src` / `A_glob` |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| **R** (reference) | 600 s | 12 | 4 | 1000 | 10 / 60 | 6 / 30 | off | 10 / 86400 s | 300 / off |
| R-src4 | as R | **4** | as R | as R | as R | as R | as R | as R | as R |
| R-src40 | as R | **40** | as R | as R | as R | as R | as R | as R | as R |

Chosen to span tight → loose source ceilings for the trade-off curve; they will not be changed after results are seen. Small
capacity tests (DV-3, PA, race) use their own explicit values stated in the scenario.

## 4. Workload

Merged streams only, from their own libraries, unchanged: #125 `ci97Streams()` (D1, L1, L3, OFF12, X1, X2, X7, X2L, BRU, BRD,
POI, KD, UAC, SESS, D1API, X1API, X2API), #115 `gnhStreams()` (D2, Nu4, Nu12, N12, N12T, N12V, ABA_A, ABA_B, SP1n, SP3, SP6,
SP5, MIX, TPAIRT, Y6b, TMS, TMSd), #119 `akdrVStreams()` (TMUA, D24K1). Attack strategies of #125 (`none`, `uarot`, `rotate`)
for attack streams. Flows `pre+out`, `pre`, `out` (API: `out`, `pre+out`). New G1 fixtures are only those the assignment requires
(DV-1 … DV-6, PA, K5 lifecycle, LT-1 S1–S6, host-mode probes, SC-1 … SC-6, race), each defined in its scenario file.

## 5. Gates (core — any FAIL ⇒ NO-GO for that variant)

| ID | Invariant (`SECURITY_FEASIBILITY.md`) | Pass condition |
| --- | --- | --- |
| C1 | I1 capacity | no scope admits more than its cap in any window — reference store on every cell; real Redis under a barrier race |
| C2 | I3 rotation | for every attack stream and strategy, per-(source, window) U admissions ≤ `U_src` and per-(account, window) ≤ `U_acct` / P caps |
| C3 | I4b device isolation | DV-1, DV-2, DV-4: CAND decisions identical to the control without the U (or P) population; DV-3: refusal `cap:p_device`, no K4P/K5/budget change, existing devices unaffected |
| C4 | I5 cross-account | no P admission whose proof names another account (SP5, POI) |
| C5 | I9 outcome independence | C1 and C2 hold in `pre` as well as `pre+out` |
| C6 | I10 storage | new admission keys per window ≤ (`s` + `p`) · `A_j` + hint keys; refused requests create none |
| C7 | PA | concurrent membership atomic; `N_P` + 1 refused; current + previous generation counted once; no P → U |
| C8 | T1 | at most one scoring per permit for duplicate, replay, conflicting, late and unknown outcomes |
| C9 | I4a | no U request writes `k4p`, P watcher, P budget cooldown or P admission keys |
| C10 | D1 | no benign denial in CAND is caused by a K1/K2/K3 penalty |

**Reported, not gated** (S0 is not an oracle): `reach_foreign`, `reach_proof`, `reach_own`, total verifier calls, legitimate
denials by cause, per-window maxima, deltas against S0 and ablations, SC-1 … SC-6, LT-1 S1–S6 (CAND vs S0).

**Declared not exercised** (will be reported `UNPROVEN`, not PASS): DV-6 and any mixed-version fleet, real Redis failover with
replication (only a simulated state loss is run), DEC-015 degraded mode of the candidate admission layer, Redis Cluster.

## 6. Decision rule

* Any core gate FAIL ⇒ **NO-GO** for later policy consideration of the variant, failing fixtures kept.
* All core gates PASS ⇒ **GO for later policy consideration (G2/G3)**, with the measured residual harms and every `UNPROVEN`
  item listed. This is never a policy approval.
