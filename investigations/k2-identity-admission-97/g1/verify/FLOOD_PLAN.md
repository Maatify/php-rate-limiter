# G1 final verification — New Device Flood counterexample FLOOD-P (pre-declared)

> **Status:** PRE-DECLARATION, committed **before** any result of this step. It answers F126-G1-R3-01 of the Principal Architect's
> G1 DA/T1 review ([PR #126 comment 6229298358](https://github.com/Maatify/php-rate-limiter/pull/126#issuecomment-6229298358)).
> This is the last G1 verification. The next deliverable is the single Lead-ready final security disposition, `../../G1_FINAL_DISPOSITION.md`.
> Scope: R-1 / G1 isolated evidence only. No `src/`, DTO, contract, preset, standard, ADR, decision or threshold change; no G2.
> **Append-only:** every earlier plan, harness and archived evidence set stays unchanged, and the runner requires all of them to compare clean.

## 1. Arms (all on the trace build, so every denial is attributed)

| Arm | Build | Flood rule |
| --- | --- | --- |
| **DAO** | `CAND-no-DA-punishment`, unchanged (`G1_BUILD=DAO`) | as the candidate: account `flood_stage` → SOFT + K4U L1, then HARD + current-request K5 L2 |
| **DAOF** | DAO + `patches/g1-ablation-no-flood-punishment.diff` (`G1_BUILD=DAOF`) | **observation only**. The account device count and the `flood_stage` watch flag are still recorded, but neither the SOFT nor the HARD candidate is added: no decision and no K4 / K5 persistence |

## 2. Fixture FLOOD-P (code-derived counterexample)

Account `acct-fl`, policy Login, parameters R, flow `pre+out`, `t` in seconds.

* **Population:** six distinct U devices (distinct UA majors, no session, unverified) from six distinct IPs. One request every 10 s at t = 0 … 50, outcome `fail`. That is under the 10-per-account and 50-per-IP `EphemeralBucket` caps. Variant **FLOOD-Pok** uses outcome `ok` for the population (a household, not an attacker).
* **Owner device:**
  * Firefox 130, IP `198.51.100.170`, server-issued `sessionDeviceId` `sd-fl-owner`.
  * t = 60: **unproven U** request. The proof has expired: same device secret, untrusted, no proof. Outcome `ok`.
  * t = 120, 180, 240, 300: **proven P** requests. Same device, proof for `acct-fl`. Outcome `ok`.
* **Control:** the same owner sequence without the population.

**Recorded per owner request:**
* decision;
* lane;
* trace origin (inherited K5 blocks included);
* the owner device's K5 block level after the request;
* whether `flood_stage` is active.

The population's own reach is recorded too.

**Counterexample confirmed** iff, under DAO, at least one proven P request of the owner after t = 60 is denied with origin `flood`, while the control's is not. If it is not exercised, the report names the cap or rule that intervened and the parameters, and makes **no** safety claim.

## 3. DAOF rule (fixed now)

**DAOF is NO-GO** if any of these holds:

| ID | Condition |
| --- | --- |
| F1 | a hardened gate fails on its matrix (C1-H both recounts, C9-H, C10-H) |
| F2 | any benign matrix denial has origin `distributed_account_attack` or `flood` |
| F3 | in FLOOD-P, FLOOD-Pok, MX (all subsets) or MXE, a proven owner's P decision differs from its control **with** a population origin |
| F4 | any attack cell exceeds the admission caps (event recount) |

Otherwise DAOF is **GO as an engineering component**. Its cost is reported as `reach_foreign`, `reach_own`, per-target reach and verifier deltas against DAO on:
* the full matrix (R, R-src4, R-src40; CAND and ALLU);
* the X03 diagnostic sets.

The best response is reported only for the modelled strategies.

The original DV and lifecycle scenarios are re-run on DAOF and compared with the archived CAND.

## 4. Outputs

* `expected_flood/`, reproducible with `bash investigations/k2-identity-admission-97/g1/verify/run_flood.sh`. That runner first runs `run_da_t1.sh`, which runs `run_verify.sh`, which runs `run.sh`.
* Then `../../G1_FINAL_DISPOSITION.md`: one Lead-ready disposition covering DA and flood punishment, T0 prerequisites, the typed proof boundary, the residual U-owner / spray trade-off, and GO / NO-GO for any **complete** candidate.
