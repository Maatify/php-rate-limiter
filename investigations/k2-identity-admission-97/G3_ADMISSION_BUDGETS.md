# G3 — Admission budgets: failure cap, source midpoint, CGNAT and per-account bucket (#97)

> **Status:** G3 INVESTIGATION EVIDENCE for architectural review. Bound to the SHA and scope below. Not an ADR, not a policy,
> not a production value, not a statement that Runtime is ready. No change to `src/`, `tests/`, `composer.json`, public
> contracts, presets, thresholds, ADRs, standards, or the G1 / G2 evidence. DEC-015, DEC-019 and DEC-021 stay ACTIVE.
> **Pre-registration:** [`g3/admission_budgets/PLAN.md`](g3/admission_budgets/PLAN.md) v1, committed in `b7e94b2` **before** any
> harness code or result. Gates, values and verdict rules were not changed afterwards. Post-hoc diagnostics are labelled and are
> excluded from every gate and verdict.
> **Scope source:** the "actually open" items of [`IDENTITY_ATTRIBUTION_PROPOSAL.md`](IDENTITY_ATTRIBUTION_PROPOSAL.md) §13.
> **Evidence classes:** `MEASURED` (archived harness output), `DERIVED` (follows from stated premises), `POST-HOC` (added after
> the run, not in any gate), `MODEL` (the CGN admission-layer simulation), `UNPROVEN`.

## 1. Baseline and provenance

| Item | Value |
| --- | --- |
| Source baseline | `main@597fda0a2c728232cdf7f3d697ab46f2abeedede`; `src/`, `composer.json`, `composer.lock` byte-identical to the G1 baseline `4c6ca618d8206ba502961eaf06ffb82a5d61b50d` (checked by `run.sh`) |
| Branch | `study/97-g3-admission-budgets`, on top of the proposal commit `819bc01` |
| Builds | `CAND`: the G1 candidate (pipeline sha-256 `1840d6e2…dfd9`, G1 builder unchanged). `NOESC`: CAND + [`g3-ablation-no-k4u-persist.diff`](g3/admission_budgets/patches/g3-ablation-no-k4u-persist.diff) (patch sha-256 `fdd85079…cd2b`, pipeline sha-256 `2926e1c5…1db4`). S0: the repository `src/` (G1 provenance check) |
| Reused unchanged | G1 cell engine, replay, streams and admission layer (`g1/lib/g1.php`, `g1/lib/admission.php`, `g1/verify/lib/verify.php`) |
| New | `g3/admission_budgets/lib/g3.php` (the FC / TB gate around `G1Admission`, and `g3Replay` = `g1Replay` with that gate and an outcome hook), four scenarios, `report.php`, `run.sh`, `build_g3.sh` |
| Toolchain | PHP 8.4.4; reference stores and controlled clock (no Redis in this study) |
| Reproduction | `bash investigations/k2-identity-admission-97/g3/admission_budgets/run.sh` — two consecutive runs produced byte-identical outputs. Exit 3 = outputs identical to `expected/`, harness valid, a pre-registered gate fails (this result) |

**V0 reproduction — PASS.** The 68 `S12` / `S40` rows replayed with the G1 verification replay equal the archived G1 v07 rows
(`Ua16-s12`, `Ua16-s40`) field for field; the G3 gate without FC / TB equals them too (wrapper equivalence); the 34 S0 rows equal
the archived G1 `m01` S0 rows.

## 2. Verdicts (pre-registered rules, `PLAN.md` §6)

| Subject | Verdict | Failed gate(s) |
| --- | --- | --- |
| **FC** (failure cap inside `U_src` = 40) | **NO-GO AFTER EXPERIMENT** | FC4: FC-2 (D2 32 > 27; MIX legitimate 65 > 59). FC8: FC-1 (MIX attack 14 > 10) and FC-2 (MIX legitimate 64 > 59) |
| **TB10**, **TB30**, **TB60** (per-account bucket) | **NO-GO AFTER EXPERIMENT** | TB-3 for every T (UAC 8 or 9 > 6); TB-2 for TB10 and TB30 — a plan defect, see §5.3 |
| MID (`U_src` = 24), CG | no verdict (reporting only) | — |

Every detector fired: `FCALL8` (N12 16 > 0, D2 48 > 27), `FW16` (2,304 > 147), `G1ESC` (owner refused 1,860 s after the attack in
five of six offsets). TB-1 passed for every T, FC-1 passed for FC4, and FC-3 passed for both F.

## 3. Results per stream (`MEASURED`, `pre+out`, legitimate denied / best-response `reach_foreign`)

| Stream | S0 | S12 | **S24** | S40 | **FC4** | FC8 | FW16 | TB30 | **TB30R** |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| L1 | 0 / 0 | 50 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 122 / 0 | 0 / 0 |
| ABA_B | 118 / 0 | 96 / 0 | 72 / 0 | 56 / 0 | 56 / 0 | 56 / 0 | 56 / 0 | 112 / 0 | 40 / 0 |
| D2 | 117 / 0 | 72 / 0 | 39 / 0 | 27 / 0 | 32 / 0 | 27 / 0 | 0 / 0 | 61 / 0 | 0 / 0 |
| N12 / Nu4 | 56 / 47 | 12 / 12 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 1 / 41 | 0 / 0 |
| KD / UAC | 9 / 6 | 9 / 6 | 9 / 6 | 9 / 6 | 9 / 6 | 9 / 6 | 9 / 6 | 9 / 9 | 9 / 9 |
| MIX (legit / attack) | 117 / 0 | 82 / 10 | 63 / 27 | 59 / 55 | 65 / 6 | 64 / 14 | 35 / 55 | 61 / 55 | 35 / 55 |
| SP6 | 0 / 20 | 0 / 72 | 0 / 144 | 0 / 240 | 0 / 24 | 0 / 48 | 0 / 240 | 0 / 240 | 0 / 240 |
| SP3 / SP1n | 7 / 4 | 24 / 12 | 48 / 24 | 80 / 40 | 8 / 4 | 16 / 8 | 80 / 40 | 80 / 40 | 80 / 40 |
| ABA_A | 0 / 3 | 0 / 24 | 0 / 48 | 0 / 80 | 0 / 8 | 0 / 16 | 0 / 80 | 0 / 80 | 0 / 80 |
| POI | 12 / 80 | 0 / 180 | 0 / 180 | 0 / 180 | 0 / 96 | 0 / 180 | 0 / 180 | 11 / 180 | 0 / 180 |
| X1 / BRU / BRD (reach) | 2 / 3 / 3 | 2 / 3 / 3 | 2 / 3 / 3 | 2 / 3 / 3 | 2 / 3 / 3 | 2 / 3 / 3 | 17 / 80 / 80 | 4 / 5 / 5 | 4 / 5 / 5 |
| X2 / X7 (reach) | 121 / 40 | same | same | same | same | same | same | same | same |

(SP3 / SP1n cells show reach only.) The complete table, every TB arm, the `pre` and `out` flows, BURST40 and CGN are in the
generated [`g3/admission_budgets/expected/summary.md`](g3/admission_budgets/expected/summary.md).

## 4. Findings

### 4.1 Failure cap (Q-FC)

* **Spray (`MEASURED`).** FC4 brings single-IP spray close to S0: SP6 24 (S0 20), SP3 8 (7), SP1n 4 (4), ABA_A 8 (3), MIX attack
  6 (0). It even lowers the multi-IP POI from 180 to 96, because each of POI's eight sources hits its own failure cap. FC8 halves
  the S40 values but misses S12 on MIX (14 > 10).
* **Benign cost (`MEASURED`).** Every FC4 benign value is ≤ S0, but two exceed S40: D2 (32 vs 27) and the legitimate part of
  MIX (65 vs 59). D2 is an office whose 8 % typos produce failures on several accounts within a window; MIX is an office with a
  co-located sprayer. FC refuses **every new account from the source** once F accounts have failed there, so the office pays for
  the co-located attacker's failures.
* **The detector shows why counting failures matters.** Counting any outcome (FCALL8) refuses offices that never fail (N12 16,
  D2 48).
* **`pre` and bursts.** In `pre` FC is inert (FC-3 PASS, decision for decision equal to S40). In BURST40 a source gets the whole
  outer ceiling (40) before any outcome arrives — a leak of 32 above `F_src` per window, for the atomic and the registered
  non-atomic arm alike, because no outcome exists yet. That is the outcome-coupling leak of #111, bounded by `U_src`.
* **Co-located attacker (`MODEL`, CGN).** With an attacker spraying at 1 / s from the shared source, FC8 refuses 98.7–100 % of
  legitimate co-tenants, against 92–96 % for S40. FC limits the attacker (3,456 admissions over 72 runs, against 14,514–17,008
  for S40) but does not protect co-tenants. No source cap does (§4.3).

Reading (`DERIVED`): FC4 is the only tested arm that reaches near-S0 spray reach with a benign profile far better than S0, but it
fails the registered comparison with S40 by 5 and 6 denials, both in office-with-failures shapes. It is NO-GO as registered.

### 4.2 Source midpoint (Q-MID)

`MEASURED`: at `U_src` = 24, **no benign stream is worse than S0** and L1 is 0 (S12 broke L1 with 50). Against S40 it costs D2 +12,
ABA_B +16, MIX +4 legitimate denials, and it lowers single-IP spray to 144 per hour on SP6 (S40 240), SP3 48 (80), SP1n 24 (40),
MIX attack 27 (55).

This changes one argument of the proposal (§6.3, reason 1): 40 is **not** the only measured value with no legitimate stream worse
than S0; 24 also satisfies it, at 60 % of the spray reach. The Owner's spray-budget decision now has three measured points.

### 4.3 CGNAT (Q-CG, `MODEL`)

Largest population behind one source with ≤ 1 % of legitimate unproven attempts refused, peak hour, 24 runs:

| λ (unproven logins per client per day) | p (share already proven) | S12 | S24 | S40 |
| --- | --- | --- | --- | --- |
| 1 | 0 | 50 | 500 | 500 |
| 1 | 0.8 | 500 | 500 | 5000 |
| 4 | 0 | 50 | 50 | 50 |
| 4 | 0.8 | 50 | 500 | 500 |

At N = 5,000 and λ = 1 without proof, S40 refuses 61 % (S24 77 %, S12 88 %). A large carrier IP is therefore served only when
most of its traffic is already proven (`p` = 0.8 stands in for device binding; binding itself was not built). With a co-located
attacker at 1 / s, **every** arm refuses 92–100 % of co-tenants at every N: a single sprayer behind a CGNAT exhausts any source cap.
This is the indistinguishability of `F§5` rows 3–4, now with numbers. The mobile client was refused only on its shared-source
attempts while that source was saturated.

### 4.4 Per-account bucket (Q-TB)

* **TB-1, the bound, holds exactly (`MEASURED`).** On BRU24 (one victim, a request every 15 s for 24 h, source and UA rotating on
  every request) the attacker reached the verifier 147 / 51 / 27 times for T = 10 / 30 / 60 minutes, against bounds 148 / 52 / 28,
  with an independent recount equal. The fixed window `FW16` gave 2,304, so recovery time is set by the refill rate, not the
  capacity, as `DERIVED` in the proposal.
* **G1 escalation (`G1ESC`).** 33 admissions in 24 h on BRU24 — stricter than TB30 (51) and close to TB60 (27). It kept the owner
  refused 1,860 s after the attack's last request in five of six offsets (cause `pen:K4`), i.e. it outlives the attack by about
  31 minutes in this fixture. That is the same order as TB60's worst case. The difference that matters is the host mode: with a
  checkOnly-only Host, S40 lets BRU reach 80 and X1 17, while TB30 holds them at 5 and 4 (`pre` table of the summary). Escalation
  needs outcomes; the bucket does not.
* **Recovery.** TB10 and TB30 admitted the owner 60 s after the attack stopped (the refill happened to be close), TB60 after 1,860 s;
  all within `T + 60`. The worst case is T by construction (`DERIVED`).
* **TB-3, the benign cost, fails on one stream for every T.** UAC (an owner's known device failing repeatedly after a browser update)
  goes from 6 to 8 or 9 denials; KD stays at 9. The bucket counts the owner's own failures, while S40 leaves known-device failures to
  the DEC-021 K5 path. Under the proposal such a device would be bound and in P, not on the strangers' bucket; that was not built here.
* **Refunds are essential.** Without refund-on-success the bucket destroys success-heavy streams (L1 114–124 of 134, ABA_B 110–112,
  Nu4 32–45). With refunds every other benign stream is at or below S40 (L1 0, ABA_B 40, D2 0, MIX 35).
* **Confound, stated.** `NOESC` removes every account-scoped reaction on the U lane, including the distributed-account and flood
  reactions (they are account-scoped in the code). Part of the benign gain of TB-R against S40 (D2 0 vs 27, MIX 35 vs 59) comes from
  that ablation, not from the bucket: `FW16` on the same build shows the same D2 and MIX values.

## 5. Deviations and plan defects

1. **Client-id rotation.** The plan's BRU24 / REC attacker rotates "source, UA and client id". The proposal's client-id field is
   not built, so no input carries an id; the attacker rotates source and UA, the free identifiers this pipeline sees.
2. **`NOESC` scope.** The patch drops every U-lane candidate whose scope includes the account (score levels, account watcher,
   distributed-account, flood, recovery guard), keeps device-scoped parts, and suppresses the 24 h budget. The plan named "K4U
   score and 24 h budget"; distributed-account and flood reactions fall under the same account-scope rule and are disclosed here
   (§4.4 confound). The patch was written after the plan and before any result.
3. **TB-2 is unsatisfiable for late arrivals (plan defect).** The gate requires admission ≤ T + 60 s after the attack's last request
   for every offset δ, but an owner who first arrives at δ > T + 60 cannot meet it. All TB-2 failures (TB10 at δ = 900, 1800,
   3600; TB30 at δ = 3600) are such cells, and in each one the owner was admitted on the first attempt (`POST-HOC`: wait from
   arrival 0 s). The v1 verdict stands as computed. A v2 gate should read "wait from arrival ≤ max(0, T + 60 − δ)".
4. **`FCNA8`** equals FC8 in this single-threaded reference harness (no outcome exists during the burst); it is reported, as
   registered.
5. **CGN** is an admission-layer model with assumed `λ`, `p` and peak factor; it shows shape and order of magnitude only.

## 6. What this means for the proposal (`DERIVED`, not decisions)

| Proposal item | Effect of G3 |
| --- | --- |
| Spray budget (§6.3) | A third measured point: `U_src` = 24 has no benign stream worse than S0 and 144 / h on SP6. The "only 40 qualifies" argument no longer holds |
| Failure cap (§13) | NO-GO as registered; the misses are offices with failures (D2) and a co-located attacker (MIX). A v2 could test F between 4 and 8, or count an account only after repeated failures, against the same gates |
| Per-account guessing budget (§6.2) | The 86,400 / T bound is exact under rotation, and refund-on-success is required. NO-GO as registered because of the known-device owner stream (UAC); a v2 should route known or bound devices as the proposal does (DEC-021 path or P lane) before judging TB-3 |
| Escalation versus bucket | In this fixture G1 escalation is as strict as TB60 and outlives the attack by about the same time; the decisive difference is that the bucket also bounds checkOnly-only Hosts |
| Co-located attacker / CGNAT | No source cap protects co-tenants from a co-located sprayer (92–100 % refused in the model). Relief for co-tenants can only come from the proven lane |

## 7. Limits

Reference stores and a controlled clock only: no Redis, no real concurrency, no failover, no DEC-015 degraded mode. Synthetic
deterministic streams; not production statistics. Device binding, K5 separation, the proof lane and `U_glob` design are out of
scope (`PLAN.md` §0). Every value is a test value.
