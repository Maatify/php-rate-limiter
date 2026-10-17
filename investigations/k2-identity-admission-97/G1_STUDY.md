# G1 — Isolated comparative study of identity-aware bounded admission (#97)

> **Status:** G1 EVIDENCE, authorized by Owner R-1
> ([PR #126 comment 6227307995](https://github.com/Maatify/php-rate-limiter/pull/126#issuecomment-6227307995)).
> **Not a policy approval.** This study does not decide D1–D7, does not approve any threshold, default or ADR, and does not
> move to G2 or G3. [DEC-019](../../docs/decisions/DEC-019_INTERIM_SECURITY_FIRST_CORRELATION_POSTURE.md) stays ACTIVE;
> the LOCKED `KEY_STRATEGY` is not amended. No file under `src/`, `tests/`, `docs/` or `composer.json` was changed.
> **Result:** every pre-registered core gate C1–C10 PASSES. Under the pre-registered decision rule
> ([`g1/PLAN.md`](g1/PLAN.md) §6) the result is **GO for later policy consideration (G2/G3)**. That GO comes with the
> measured residual harms in §6 and the `UNPROVEN` items in §7, and it is not a recommendation to adopt.
> **Design under test:** [`ARCHITECTURE_PROPOSAL.md`](ARCHITECTURE_PROPOSAL.md) and
> [`SECURITY_FEASIBILITY.md`](SECURITY_FEASIBILITY.md) at `8cf985a8ec8568b67392d61531d6c22649627fc5`.
> **Pre-registration:** [`g1/PLAN.md`](g1/PLAN.md), committed in `0f40ce5` before any result existed. S0 is a historical
> comparator, not a security oracle.

| Label | Meaning |
| --- | --- |
| `MEASURED` | Produced by this harness, reproducible with §2, archived in [`g1/expected/`](g1/expected/) |
| `DERIVED` | Reasoning from `MEASURED` rows plus the code of the harness; no separate run |
| `UNPROVEN` | Not exercised; never counted as PASS |

## 1. Provenance

| Item | Value |
| --- | --- |
| Code baseline | `main@4c6ca618d8206ba502961eaf06ffb82a5d61b50d`. `run.sh` refuses to run if `src/`, `tests/`, `docs/` or `composer.json` differ from it or are dirty |
| S0 runtime | repository `src/`, unpatched; `EvaluationPipeline.php` sha-256 `402139452040cb319208ca7a441b2f70210bf1acee4bd4a0ad666e8101ef6eab`, asserted at start |
| CAND runtime | `git archive` of the baseline into `g1/results/work/candidate` (ignored scratch) + [`g1/patches/g1-candidate.diff`](g1/patches/g1-candidate.diff) (sha-256 `0caafe66ade9631ac5186b3c215947899aec8e91a5969b5e8a1f9db6af790d57`, +47 / −10 lines, pipeline only). Patched pipeline sha-256 `1840d6e2b57cd57674beb7101d25d458e97949b719d473580db28f50ac13dfd9`. The builder fails if any other file differs. The patched pipeline throws unless `G1_ARM=CAND` |
| Admission layer | study code only: [`g1/lib/admission.php`](g1/lib/admission.php). It has a reference store and a Redis store (Lua) |
| Toolchain | PHP 8.4.4, ext-redis 6.2.0, Redis 7.2.6 (ephemeral instance owned by `run.sh` on a free port ≥ 6790, `--save "" --appendonly no`, shut down on exit) |
| Workload | merged stream libraries, used unchanged: #125 `ci97Streams()`, #115 `gnhStreams()`, #119 `akdrVStreams()`, with the #103 Lab (`workstream-b-correlation/lib/bootstrap.php`) |
| Parameters | sets R, R-src4 and R-src40 of `PLAN.md` §3, unchanged after results. All of them are test parameters |
| Archived output | `g1/expected/`: 10 JSONL files (1 021 rows) plus `summary.md` and `gates.json`; sha-256 in §2 |

## 2. Reproduce

```bash
composer install
bash investigations/k2-identity-admission-97/g1/run.sh
```

`run.sh` runs these steps in order. It asserts the baseline, builds the candidate, then runs M01, M02 and M04 on S0 and M01–M05 and M07 on CAND. It starts the owned Redis for M06, runs [`g1/report.php`](g1/report.php), and diffs every output against `g1/expected/`. It exits non-zero on any difference, any stderr output, a provenance mismatch, or an S0 reproduction mismatch. `REDIS=0` skips Redis, and the M06 rows then become `UNPROVEN`. `UPDATE=1` regenerates `expected/`; review the git diff afterwards. The run at the commit that adds this file was repeated after regeneration and compared clean (no `.diff` produced).

sha-256 of the archived outputs:

| File | sha-256 |
| --- | --- |
| `gates.json` | `ff1bc023de9c1eeb5bff9f863364f20557b781aebe48c72c006546b58711073a` |
| `summary.md` | `2737e35e53acbd3d98ad622cf165d305b1f13ff07b01fd8df0921fce64f48053` |
| `m01_matrix.S0.jsonl` | `72ea6cac3ada608baa9154622544489b78dc86f8ae1a3a0cbeb842b368461eb6` |
| `m01_matrix.CAND.jsonl` | `2e0f040b18be8985d9d951c1af9df653c900ad75138fe3dff1737feda883c8bd` |
| `m02_device.S0.jsonl` / `.CAND.jsonl` | `b5550a19…251749` / `d36e217b…49672c0` |
| `m03_capacity.CAND.jsonl` | `edf753bb489ccb297ae7ec13fec06d3d4a095765eff2b79c98eb1daba5e575f2` |
| `m04_lifecycle.S0.jsonl` / `.CAND.jsonl` | `8c55d320…1f19608` / `a32fd821…f1db043` |
| `m05_host_modes.CAND.jsonl` | `b6ff41087bf61141a41cf501d39e4af74383ae9578c0fd3a9626593faed5e40c` |
| `m06_redis.jsonl` | `e3524b5e21a8be53a128677ddf6997628dff5b271d196d9ce9e707905460df9d` |
| `m07_source_ceiling.CAND.jsonl` | `245632f6199b4bb55075539c35ae61636ab29cb71a9991f57d4e1861cc20d7d4` |

### 2.1 Harness layout

| Path | Role |
| --- | --- |
| [`g1/PLAN.md`](g1/PLAN.md) | pre-registration (arms, mappings, parameters, gates, decision rule) |
| [`g1/build_candidate.sh`](g1/build_candidate.sh), [`g1/patches/`](g1/patches/) | isolated candidate tree, hash-checked |
| [`g1/lib/g1.php`](g1/lib/g1.php) | provenance, stream selection, proof mapping M1, `G1Cell` (one runtime + admission layer), replay and scoring |
| [`g1/lib/admission.php`](g1/lib/admission.php) | admission layer: atomic all-or-nothing reservation, U / P / API scopes, P device set, T1 permits, recount and storage checks |
| [`g1/scenarios/`](g1/scenarios/) | M01 matrix; M02 device isolation (DV-1 … DV-6, child process for DV-6); M03 capacity (PA); M04 K5 / DEC-021 / DEC-007 / LT-1 (child process for rollback); M05 host modes; M06 Redis (worker processes for races); M07 source ceiling (SC) |
| [`g1/report.php`](g1/report.php) | recomputes every metric from the event rows (integrity check), S0 reproduction, gates, tables |
| [`g1/expected/`](g1/expected/) | archived evidence (full per-event decision, lane, reach and cause strings per cell) |

### 2.2 Scoring

Each event is replayed through the arm's runtime. In flow `pre` and `pre+out`, an event first passes `checkOnly`, with the admission reservation at T0. The event "reaches the verifier" only if the pipeline and the admission layer both allow it. A denial at record time also counts as a denial, as in #125 `ci97Replay`. Attacker reach is split by mapping M1 and the ownership mapping of `PLAN.md` §2:

* `reach_foreign` — the attacker reached the verifier for an account it neither owns nor holds a proof for.
* `reach_proof` — the attacker reached it while carrying a valid proof for the target. These are the trusted-session streams of #113 / #119: the attacker *holds* the target's trusted session.
* `reach_own` — the attacker reached it on an attacker-owned account.

Legitimate denials are attributed to one cause each: `pen:K*` (penalty key), `soft` (soft block), or `cap:<scope>` (admission refusal).

## 3. S0 reproduction (`MEASURED`)

All 16 published numbers this study depends on reproduce exactly on S0. That includes D1 596/600 legitimate denied, X2 6 (`none`) and 121 (`uarot`), BRD 22 (`pre`), TMUA 36, D24K1 1440 (`pre`) and N12T 56. The full table is §1 of [`g1/expected/summary.md`](g1/expected/summary.md). A mismatch on a baseline identical to its source is a hard failure (exit 5).

## 4. Core gates (`MEASURED`)

| Gate | Invariant | Result | Evidence |
| --- | --- | --- | --- |
| C1 | I1 capacity | **PASS** | No scope over its cap in any window: 644 matrix cells, recounted from the admission log. Real Redis race R-1 admits exactly 3 / 3 / 3. The non-atomic mutation control over-admits, so the detector works |
| C2 | I3 rotation | **PASS** | 468 attack cells × strategies (`none`, `uarot`, `rotate`): per-(source, window) and per-(account, window) admissions stay ≤ caps. SC-5: 4 / 4 under per-request UA and fingerprint rotation |
| C3 | I4b device isolation | **PASS** | DV-1 (50 U devices on IP X, then the proven owner from X) and DV-2 (10 U devices on account A, then the proven owner) give CAND decisions identical to the control without the U population. DV-4 (50 P devices, then one U request) is also identical. DV-3: device `N_P + 1` is refused `cap:p_device`, with no state, write or key change and the existing device unaffected. **On S0, DV-1 and DV-4 are not identical** (`pen:K2` / `pen:K1+K2`): the 50U+1P breakage of G0 R5 is real today and absent in CAND |
| C4 | I5 cross-account | **PASS** | SP5 and POI: no P lane. On every cell, `pLaneForeignProof = 0`. TMX has 17 P lanes, all of them the single session `sess-vic0` used on `vic0` itself (own-account proof). See §4.1 |
| C5 | I9 outcome independence | **PASS** | C1 and C2 hold in `pre` as well as `pre+out` |
| C6 | I10 storage | **PASS** | New admission keys per window stay ≤ `(s + p)·A_j` + hints on every cell. Refused requests create no key (PA-6, R-3) |
| C7 | PA | **PASS** | PA-2 … PA-7 pass. Real-Redis device-set race R-2 admits exactly 1 / 1 / 1 |
| C8 | T1 | **PASS** | Duplicate, conflicting, late, unknown and never-issued claims are each scored at most once, on the reference store and on Redis (H-4, R-8) |
| C9 | I4a | **PASS** | No U request wrote `k4p`, the P watcher, the P budget cooldown or P admission keys |
| C10 | D1 | **PASS** | No benign CAND denial is caused by a K1 / K2 / K3 penalty |

### 4.1 Checker correction (disclosed)

The first full run reported **C4 FAIL** and decision NO-GO. The cause was the checker in `report.php`. It listed `TMX` next to `SP5` and `POI` and flagged any P lane at stream level. The pre-registered C4 covers SP5 and POI only. TMX is the #113 Host-violating control, where *one* trusted session bound to `vic0` is used against 12 targets. Its 17 P lanes are exactly the events whose target *is* `vic0`. Those carry a valid own-account proof; the foreign-proof count is 0. The other 183 events fall to U.

The checker now applies the pre-registered stream-level rule (SP5, POI). It also applies a stricter event-level rule to **every** CAND cell: `pLaneForeignProof`, which counts a P lane whose proof does not name the request's own account or that has no `sessionDeviceId`. The candidate, the patch, the parameters and the fixtures were not changed. `gates.json` records the correction in `C4.checkerCorrection`.

## 5. Comparison (`MEASURED`, flow `pre+out`, best attacker strategy per cell)

`F` / `P` / `O` = `reach_foreign` / `reach_proof` / `reach_own` over attacker attempts; `L` = legitimate denied / legitimate attempts. Full tables for flows `pre+out`, `pre` and `out`, with verifier calls, are in §3 of [`g1/expected/summary.md`](g1/expected/summary.md).

### 5.1 Benign and owner streams

| Stream | S0 | CAND R | R-src4 | R-src40 | NOADM | ALLU | Main CAND cause (R) |
| --- | --- | --- | --- | --- | --- | --- | --- |
| D1 | L 596/600 | **L 0/600** | 0 | 0 | 0 | 0 | — |
| D1API | L 595/600 | **L 0/600** | 0 | 0 | 0 | 0 | — |
| L3 | L 104/121 | **L 0/121** | 0 | 0 | 0 | 0 | — |
| SESS | L 6/8 | L 0/8 | 0 | 0 | 0 | 0 | — |
| X2L (legit part) | L 26/30 | L 0/30 | 0 | 0 | 0 | 0 | — |
| D2 | L 117/121 | L 72/121 | 104 | 27 | 27 | 72 | `cap:src` 48, `pen:K5` 24 |
| Nu4 / Nu12 | L 47/61 | L 12/61 | 44 | 0 | 0 | 12 | `cap:src` |
| N12 / N12V | L 56/61 | L 12/61 | 44 | 0 | 0 | 12 | `cap:src` |
| N12T | L 56/61 | L 0/61 | 0 | 0 | 0 | 12 | — (trusted office → P) |
| ABA_B | L 118/120 | L 104/120 | 112 | 104 | 0 | 104 | `cap:acct` 104 |
| MIX (legit part) | L 117/121 | L 82/121 | 107 | 59 | 27 | 82 | `cap:src` 58, `pen:K5` 24 |
| Y6b (legit part) | L 28/30 | L 17/30 | 24 | 17 | 0 | 17 | `cap:acct` 17 |
| **L1** | **L 0/134** | **L 78/134** | 106 | 78 | 0 | 78 | **`cap:acct` 78** |
| OFF12 | L 8/13 | L 1/13 | 9 | 0 | 0 | 1 | `cap:src` 1 |
| POI (owner) | L 0/15 | L 3/15 | 3 | 3 | 0 | 3 | `cap:acct` 3 |
| KD | L 9/12 | L 9/12 | 9 | 9 | 9 | 9 | `cap:acct` 2, `pen:K5` 1, soft 6 |
| UAC | L 6/12 | L 7/12 | 7 | 7 | 6 | 7 | `cap:acct` 4, soft 3 |

### 5.2 Attack streams (reach)

| Stream | S0 | CAND R | R-src4 | R-src40 | NOADM | ALLU |
| --- | --- | --- | --- | --- | --- | --- |
| X1 (1 IP, 1 account) | F 2/61 | F 2 | 2 | 2 | 2 | 2 |
| X2 (121 IPs × 121 accounts, `uarot`) | F 121/121 | F 121 | 121 | 121 | 121 | 121 |
| X7 (`uarot`) | F 40 O 4 /121 | F 40 O 5 | 40 / 5 | 40 / 5 | 40 / 81 | 40 / 5 |
| BRU / BRD | F 3 / 3 | F 3 / 3 | 3 | 3 | 3 | 3 |
| **POI** (8 IPs, fresh account per attempt, `uarot`) | **F 80/180** | **F 180** | 96 | 180 | 180 | 180 |
| ABA_A | F 3/120 | F 24 | 8 | 80 | 120 | 24 |
| SP1n | F 4/200 | F 12 | 4 | 40 | 200 | 12 |
| SP3 | F 7/1000 | F 24 | 8 | 80 | 1000 | 24 |
| SP6 | F 20/1200 | F 72 | 24 | 240 | 1200 | 72 |
| MIX (attack part) | F 0/200 | F 10 | 3 | 55 | 200 | 10 |
| Y6b (own accounts) | O 2/36 | O 20 | 7 | 20 | 36 | 20 |
| SP5 (stolen foreign session) | F 200/200 | **F 12** | 4 | 40 | 200 | 12 |
| TMX (one session, 12 targets) | F 60/200 | **F 12 P 5** | 4 / 5 | 40 / 5 | 55 / 5 | 12 |
| TMS (12 valid trusted sessions) | F 7/61 | P 61 | 61 | 61 | 61 | F 49 |
| TMSd | F 2/200 | P 60 | 60 | 60 | 60 | F 12 |
| TMUA | F 36/200 | P 72 | 72 | 72 | 120 | F 12 |
| KS50 | F 2/200 | P 200 | 200 | 200 | 200 | F 12 |
| TPAIRT (attack part) | F 1/200 | P 60 | 60 | 60 | 60 | F 15 |
| D24K1 (one trusted session, 24 h) | F 45/1440 | P 45 | 45 | 45 | 45 | F 45 |
| X1API / X2API | F 301 / 121 | F 301 / 121 | same | same | same | same |

Flow `pre` (checkOnly only, no outcome scoring) shows the admission bound alone. Two examples: D24K1 drops from S0 F 1440 to CAND P 60, and BRU from 100 to 20. TMSd in `pre` is P 72 = 12 accounts × `P_proof` 6.

## 6. What the evidence says (`MEASURED` unless marked)

### 6.1 Gains against S0

* **The correlation harm is removed.** D1 drops from 596/600 to 0, D1API from 595/600 to 0, L3 from 104/121 to 0, and SESS from 6/8 to 0. No benign CAND denial has a K1 / K2 / K3 cause (C10).
* **Device isolation holds where S0 breaks.** In DV-1 and DV-4, S0 denies the proven owner with `pen:K2` / `pen:K1+K2` caused by unrelated devices. CAND gives decisions identical to the control. This closes the G0 R5 finding (50U+1P, 10U+1P) on this harness.
* **Foreign-proof misuse is bounded.** SP5 drops from 200/200 to 12 and TMX from 60 to 12 F (+5 own-proof P). A proof never opens another account's P lane (C4).
* **The `pre`-only bound is real.** BRU drops from 100 to 20 and D24K1 from 1440 to 60 (S0 is unbounded in `pre`).

### 6.2 Residual harms and regressions (reported, not gated)

1. **`U_acct` refuses legitimate unproven users.** L1 goes from 0/134 to 78/134, ABA_B stays at 104/120 (the cause moves from `pen:K2` to `cap:acct`), Y6b is 17/30 and POI's owner 3/15. With `U_acct = 4` per 600 s, any account with more than 4 unproven attempts in a window is refused, successes included. Only a proof (P lane) avoids this. The R-src sets do not vary `U_acct`, so this curve is **not** measured.
2. **`U_src` refuses NAT co-tenants (SC-6).** At R, D2 is 48/121 and N12 / Nu4 / Nu12 / N12V are 12/61 each. At R-src4, L1 is 106/134, ABA_B 112/120 and N12 44/61. At R-src40 these fall to 0, except MIX 35/121, while spray reach grows 3–10× (ABA_A 80, SP6 240). There is no setting in the measured range without a trade-off.
3. **Distributed unproven sprays reach more than on S0.** ABA_A goes from 3 to 24, SP1n 4 → 12, SP3 7 → 24, SP6 20 → 72, MIX 0 → 10 and POI 80 → 180. S0 stopped them with the collective K1 / K2 penalties that CAND removes (D1). CAND bounds them only by `U_src` / `U_acct` / `U_glob`. For POI (8 IPs × 7.5 attempts per window < `U_src` 12, a fresh account each time) no cap binds at R; R-src4 gives 96. This is the price of D1 that the proposal names (`P§11` D1 / D7), now quantified.
4. **One attempt per (source, account) is bounded only by `U_glob`.** X2 is 121/121 on every arm and set, which equals S0's best response (`uarot`). X1API / X2API are unchanged because API is admission-only, bounded by `A_src` / `A_glob`.
5. **Proof holders are bounded only by the P caps.** TMS P 61/61, TMSd 60, TMUA 72, KS50 200/200 and TPAIRT 60, against S0's F 2 … 36. An attacker that holds valid trusted sessions for the targets is outside the U threat class by construction (D3). Its reach then grows with sessions held × `P_proof` / `P_acct` (`P§11` D5). ALLU (no proof honoured) brings these back to 12–15, at the cost of N12T 12/61.
6. **The DEC-021 allowance is shared across lanes (coupling of `P§7.2` item 3).** Eight historical-known U failures, then one proven failure of the same account: the shared allowance counter reads 9 against 1 in the control, and the proven failure is scored (K4P 3, budget 1). The control's proven failure is not scored.
7. **A leaked `sessionDeviceId` shares K5 (DV-5).** A U request that presents the proven device's id (untrusted, no proof) writes the same K5 key and pushes it to L1. The owner then gets `soft` on P.
8. **Owner harm under a stolen session is unchanged (LT-1).** The candidate neither improves nor worsens it:

   | Case | S0 | CAND |
   | --- | --- | --- |
   | S1 | 44 | 44 |
   | S2 (UA rotation) | 44 | 31 |
   | S3 | 7 | 7 |
   | S4 | 44 | 44 |
   | S5: owner denied | 129/144 | 129/144 |
   | S6: forgetful owner denied | 83/96 | 83/96 |

   M-A preserves the DEC-021 bound; it does not protect the owner from a holder of the same proof.
9. **Fixed buckets allow 2× per interval (`DERIVED` from `MEASURED`).** PA-5 admits 12 + 12 within 2 s across a window edge, as I1′ states. D24K1 `pre` admits 60 in 24 h with `P_proof_day = 30`, because the stream spans two calendar-day buckets (refusals: `cap:pproof_day` 1340, `cap:pproof` 40).
10. **Host modes (M05).**

    | Case | Result |
    | --- | --- |
    | H-1 probe ρ = 2 | halves the real attempts admitted (2 of 3 vs 3 of 3) |
    | H-3 lost reply | charges 2 admissions for 1 attempt |
    | H-2 abandoned attempt | consumes source capacity without scoring |
    | H-5 record-only routes | reach the verifier uncounted (6 calls, 3 admissions); no pre-verifier bound covers them |

11. **PA-4 double rotation re-issues capacity.** A second secret rotation inside one window re-issues 10 units of U source capacity (`uSrcAfterSecondRotationSameWindow` 10). Only current + previous generations are read.

### 6.3 Lifecycle (`MEASURED`)

| Case | Result |
| --- | --- |
| K5-1 passive → secret | starts a new K5 key; the passive K5 is unchanged |
| K5-2 outer-secret rotation | the previous-generation K5 is read |
| K5-3 rollback to S0 with session | continues the K5 (4 → 5) |
| K5-3 rollback without session | leaves it untouched |
| DEC-007 on K4P | record-only failures reach L2 (`blkK4P`) and the proven pre-check returns `post_punishment_reentry`; the unproven pre-check of the same account is unaffected (I4a) |
| DV-6, CAND → S0 handoff | an old worker reads CAND state and treats the owner as S0 would (`ASSSSS`, K4 6) |

DV-6 measures one serialized handoff, which is more than `PLAN.md` §5 declared. Concurrent mixed fleets remain `UNPROVEN`.

### 6.4 Redis (`MEASURED`, single instance)

| Case | Result |
| --- | --- |
| R-1, R-2 races | exact admission (3 and 1), detector verified (R-1m) |
| R-3 all-or-nothing | holds |
| R-4 parity with the reference store | 0 differences over 600 reservations |
| R-6 unknown write | over-charges (2 for 1), as specified |
| R-7 malformed state | writes nothing |
| R-8 T1 parity | holds |
| R-5 simulated state loss | re-issues 5. **DEGRADED, as F§6.9 predicts** |

**Lesson for LT-4.** The first Redis run lost keys. The cause: `EXPIREAT` was computed from the caller clock of DEC-016 (`2026-01-01`), which lies in the past relative to the server clock, so Redis deleted the keys immediately. The store now keeps the caller clock for window indices, set scores and permit deadlines, and uses a **relative** `EXPIRE` for key lifetime. Any production design must not derive absolute expiry from caller time.

## 7. Not proven

| Item | Status |
| --- | --- |
| DEC-015 degraded mode of the admission layer (R-9: Redis unavailable raises; typed integration not built) | `UNPROVEN` |
| Real failover with replication (only R-5 simulated loss) | `UNPROVEN` |
| Redis Cluster (hash-tag co-location of multi-scope reservations) | `UNPROVEN` |
| Concurrent mixed-version fleet (only DV-6 / K5-3 single handoffs) | `UNPROVEN` |
| Production values of any parameter; `U_acct` trade-off curve | not measured |
| Real Host behaviour (proof issuance, D3; probe factor in the field) | outside the harness |

## 8. Decision for later policy consideration

All ten core gates pass on every gated cell (parameter sets R, R-src4 and R-src40, plus the scenario fixtures). Under the pre-registered rule this is **GO for later policy consideration (G2/G3)**. It is not a GO for adoption, and it changes none of D1–D7.

The Lead and the Owner would weigh, in particular:

* the trade between §6.1 and §6.2 items 1–3. D1 removes 596 benign denials but raises distributed spray reach 3–10× and refuses frequent unproven users through `U_acct`;
* the fact that every gain for legitimate users who are not on a NAT depends on D3 (proof issuance by the Host);
* the `UNPROVEN` list of §7, which blocks any production claim.

Nothing in this file selects LT-1 … LT-7, approves D7 or amends `KEY_STRATEGY`. PR #126 stays Draft.
