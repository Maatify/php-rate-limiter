# G1 verification addendum — hardened gates, boundary split and `U_acct` sweep (#97)

> **Status:** G1 VERIFICATION EVIDENCE. This addendum answers the Principal Architect's independent G1 evidence review
> ([PR #126 comment 6228274086](https://github.com/Maatify/php-rate-limiter/pull/126#issuecomment-6228274086), findings
> F126-G1-01 … 04) under the Owner's R-1 authorization
> ([comment 6227307995](https://github.com/Maatify/php-rate-limiter/pull/126#issuecomment-6227307995)).
> **Not a policy approval, not G2.** It decides none of D1–D7 and selects no parameter. DEC-019 stays ACTIVE. No change
> to `src/`, `tests/`, `docs/`, ADRs, thresholds, defaults or issues; no new API or DTO.
> **Pre-declared:** [`g1/verify/VERIFY_PLAN.md`](g1/verify/VERIFY_PLAN.md), committed in `0aec4c1` **before** any
> verification result.
> **Original evidence unchanged:**
> * [`g1/PLAN.md`](g1/PLAN.md), the original harness and [`g1/expected/`](g1/expected/) are untouched.
> * `run_verify.sh` re-runs the original `run.sh` first and requires it to compare clean.
> * [`G1_STUDY.md`](G1_STUDY.md) keeps its original gate results, including the disclosed first C4 FAIL and its correction. Only a pointer to this file was added at the top.
> **Candidate unchanged:** `g1/patches/g1-candidate.diff` and the R / R-src4 / R-src40 parameter sets were not tuned.

## 1. Answer in brief

| Question | Answer (`MEASURED` unless marked) |
| --- | --- |
| Do the hardened gates hold? | **Yes, all four:** C1-H, C9-H, C10-H and R3-H all PASS. Every detector control fired where its defect can show (§3). The original checkers missed the C9 mutants and the same-key R-3 mutant |
| Is the proof boundary proven? | **Only through the Host.** C4 holds as a property of Host mapping M1: 9 662 events re-derived from the DTO, 0 mismatches. At the **package boundary** it is `UNPROVEN`: a proof minted for another operation and a direct lane claim are both accepted (§4) |
| Did U traffic reach the P lane anyway? | **Only through the declared I4b exception.** No U request wrote P state: 0 violations over 152 071 U events, 72 of them on accounts already holding P state, plus 154 on the mixed fixture. One indirect path exists. A U request that **presents the P device's `sessionDeviceId`**, together with other devices' U failures on the account, turns the U-only distributed-account rule into **HARD blocks on the proven owner** (8 vs 3) through the shared K5 (§5) |
| Did the candidate remove the unfair collective denials? | **Yes for K1/K2/K3 and correlation:** 0 benign denials of those origins, every denial attributed. **No for the account device-population rule:** D2 and MIX office users are still denied 24 times each by `distributed_account_attack`. That rule is kept for U and its blocks persist on K5 (§3.3) |
| Does any sampled parameter set beat S0 on both axes? | **No.** None of the 20 (arm × set) cells dominates S0 on the sample. With `U_acct ≥ 16` and `U_src = 40` no benign stream is worse than S0. But six distributed-spray streams (POI, SP6, SP3, SP1n, ABA_A, MIX) reach more than S0 in **every** set, and raising `U_src` makes them worse (§6). The harm moved from collective penalties to source and account capacity |
| Verdict | All hardened core gates PASS ⇒ the pre-registered rule still gives the harness's **conditional** "GO for later policy consideration". It is conditional on Host mapping M1, on device secrets not leaking, and on the residuals of §6. No sampled set is a plausible default without an Owner trade-off between benign denial and distributed-spray reach |

## 2. Provenance and reproduction

| Item | Value |
| --- | --- |
| Base / candidate | as `G1_STUDY.md` §1: baseline `main@4c6ca61`, candidate pipeline sha-256 `1840d6e2…13dfd9` |
| Trace build | candidate + [`g1/verify/patches/g1-trace.diff`](g1/verify/patches/g1-trace.diff) (sha-256 `54502e61…924262`), pipeline `9c23ec24…5c4661`. **Instrumentation only:** a record-only `g1Trace` of active blocks, threshold candidates, score levels and the final candidate set; the pipeline never reads it |
| Mutant build (M-C10) | trace + [`g1/verify/patches/g1-mutant-correlation.diff`](g1/verify/patches/g1-mutant-correlation.diff) (`186dda4c…28165`): the D1 correlation guard `if (false && …)` removed; pipeline `ecc9928d…5c4661` |
| Builder | [`g1/verify/build_verify.sh`](g1/verify/build_verify.sh): every patch and resulting pipeline is hashed; it fails on any difference outside the pipeline. `g1/lib/g1.php` provenance accepts these two builds only with `G1_BUILD=TRACE` or `MUTANT` |
| Toolchain | PHP 8.4.4, Redis 7.2.6 (ephemeral, owned by the runner, shut down on exit) |
| Run | `bash investigations/k2-identity-admission-97/g1/verify/run_verify.sh` (diffs against [`g1/verify/expected/`](g1/verify/expected/)); `UPDATE=1` regenerates |
| Archived | 8 JSONL files (1 573 rows), [`gates_verify.json`](g1/verify/expected/gates_verify.json), [`summary_verify.md`](g1/verify/expected/summary_verify.md) |

The run that produced `verify/expected/` was repeated and compared clean. The original study compared clean in both runs.

## 3. Hardened gates (F126-G1-01, 03)

| Gate | Original definition (kept) | What the review showed | Hardened result | Detector control |
| --- | --- | --- | --- | --- |
| **TRACE-EQUIVALENCE** | — | — | **PASS:** 805 / 805 matrix cells on the trace build reproduce the archived `dec`, `lanes`, `reach` and `kinds` exactly | precondition for C10-H |
| **C9-H** (I4a) | end state; accounts **with** any P request skipped | could not see U→P on a mixed account | **PASS:** 0 P-state changes around 152 071 U commands and admissions in the matrix (72 on accounts with live P state) and 154 on the mixed fixture MX (all on the P account, across a secret rotation; both pipeline secrets and admission generations inspected) | **M-C9a** lane leak: detected. **M-C9b** admission leak: detected. The **original checker detected neither** |
| **C10-H** (D1) | no `pen:…K[123]` cause string | `soft` hid the originating key | **PASS:** 0 benign denials of K1 / K2 / K3 / correlation / credential-spray origin, 0 unattributed, across every CAND and ALLU cell | **M-C10** (correlation re-enabled) reproduces S0's own denials exactly with forbidden origins: D1 596/600, L3 104, SESS 6, D2 117, ABA_B 118, N12V 56, X2L 29 |
| **C1-H** (I1) | log recount + race; R-1m recorded but not required | R-1m not part of the gate | **PASS:** admission-log recount and an **independent event recount** both stay within caps on every cell; the two counts agree on every cell; Redis race 3/3/3; R-1m detected | **M-C1** (+1 over every cap) is flagged by both recounts on every cell where a cap binds (X1, BRU, SP3, D24K1). On KS50 and X1API no cap binds, so over-admission cannot appear |
| **R3-H** (I10) | refused + key names unchanged | values not required | **PASS** on the reference store and on real Redis: refused, key names **and** values unchanged | **M-R3** (partial write): caught by the hardened check on both stores. The original key-only check **misses** it when the first counter already exists |

### 3.1 Coverage of C9-H

A U event counts as *mixed* when its account already holds P state at that moment. The matrix contributes 72 such events. The fixture MX contributes 154: a P owner on `acct-mx` with failures, successes, K4P score and budget; four interleaved U families on the same account; and a mid-fixture rotation to `lab-secret-2` / `g1-secret-2`, with the previous secret and generation still read.

### 3.2 Mutant placement disclosed

The lane-leak mutant was first placed on the first U failure of MX, which is a historical-known device. A known-device failure writes no P-specific key even on the P lane: its K4 delta is 0, so only K5 and the shared allowance move. The checker therefore correctly reported nothing. That row is kept as `M-C9a0`. `M-C9a` places the same leak on the first unknown-device U failure that reaches the record step, which writes K4P on the P lane. The checker reports it there.

### 3.3 What the trace re-attribution changed

| Stream | Archived cause | Attributed origin |
| --- | --- | --- |
| KD | `soft` 6, `pen:K5` 1 | K5 score 6, device watcher 1 |
| UAC | `soft` 3 | K5 score 3 |
| **D2** | `pen:K5` 24 | **`distributed_account_attack` 24** |
| **MIX** | `pen:K5` 24 | **`distributed_account_attack` 24** |

The account device-population rule is a collective U-lane rule that D1 kept (P-2 only removes it from P). Its blocks are persisted on every member K5 of the device window. It is not a K1/K2/K3 penalty, so C10-H passes. But it is the remaining collective source of benign denials in CAND, and it is the mechanism of §5.

## 4. C4 split (F126-G1-02)

| Claim | Result | Meaning |
| --- | --- | --- |
| **(a) Host mapping M1** | **PASS, conditional on the Host.** Each archived CAND lane was re-derived from the `RateLimitContextDTO` the library receives (trusted flag, session id naming the target), independently of `G1Cell::lane()`: 9 662 events, 2 444 P lanes, 0 mismatches | B1 (proof names another account) → U. B2 (empty or null device secret) → U. B3 (trusted session of another target, all SP5 events) → U |
| **(b) Package boundary** | **`UNPROVEN`** | B4: a proof for Login presented on OTP is accepted as P, because M1 has no operation binding. B5: the isolated pipeline accepts `g1SetLane('P')` and the admission layer accepts a P reservation for a foreign account. Neither checks a typed proof. Condition C8 of `SECURITY_FEASIBILITY.md` ("the library checks `proof.account = target` and `proof.operation = operation`") is **not implemented**; implementing it needs a new API or DTO, which is not authorized |

**Consequence if the boundary fails** (a Host that accepts a forged P claim for victim `acct-vb`; 240 attempts in 1 h; the owner has a real proof):

| Attacker device secrets | Attacker reach | Owner denied |
| --- | --- | --- |
| one fixed forged secret | 8 / 240 | 0 / 12 |
| a new secret per attempt | 9 / 240 | 1 / 12 (`cap:pacct`) |

K4P writes land on the owner's lane. The P caps and the K5 / K4P penalties bound it; nothing at the boundary stops it.

## 5. I4b: the declared exception, measured (diagnostic after the pre-declared MX run)

The MX run reported the owner's P decisions differing from the no-U control **with 0 C9-H violations**. Decomposing it by U family:

| U families on `acct-mx` | Presents the owner's `sessionDeviceId` | Owner HARD (of 25) | Identical to control |
| --- | --- | --- | --- |
| 0 (rotating sources) / 1 (historical device) / 3 (untrusted session), alone or all together | no | 3 | **yes** |
| 2 alone, 1 + 2, 2 + 3 | yes | 3 | yes |
| **0 + 2** | yes | **5** | **no** |
| **0 + 1 + 2 + 3** | yes | **8** | **no** |
| control (owner only) | — | 3 | — |

The mechanism has two parts. The U-only distributed-account rule persists L2 on every K5 member of the account's device window. A U request carrying the owner's device secret makes the owner's K5 a member. Other devices' U failures then qualify the window, and the owner's next P request meets an active block on its own K5.

`SECURITY_FEASIBILITY.md` I4b already excludes "a request presenting the same `sessionDeviceId` (same device without a proof, or a stolen secret)". So I4b is **not falsified**: every combination without the secret matches the control. But the exception's consequence is larger than its text implies. It is a **HARD** block from a rule that P-2 calls U-only, triggered jointly by other devices. It also arises without theft, whenever the owner's own device runs once unproven (for example after its proof expires).

## 6. `U_acct` sweep (F-04, pre-declared, nothing selected)

Flow `pre+out`. L = legitimate denied; F = best-response `reach_foreign`. Full grid, with per-target reach and verifier calls, in [`summary_verify.md`](g1/verify/expected/summary_verify.md) §5 and `v07_sweep.*.jsonl`.

| Stream | S0 | Ua4-s12 (= R) | Ua8-s12 | Ua16-s12 | Ua16-s40 | Ua32-s40 | Uaoff-s40 |
| --- | --- | --- | --- | --- | --- | --- | --- |
| L1 | L 0 | 78/134 | 50 | 50 | **0** | 0 | 0 |
| ABA_B | L 118 | 104/120 | 96 | 96 | 56 | 40 | 40 |
| D2 | L 117 | 72/121 | 72 | 72 | 27 | 27 | 27 |
| Y6b | L 28 | 17/30 | 14 | 14 | 0 | 0 | 0 |
| UAC | L 6 | 7/12 | 6 | 6 | 6 | 6 | 6 |
| HAR (unproven owner) | L 14 | 14/15 | 14 | 14 | 14 | 14 | 14 |
| HARp (owner with proof) | L 14 | **0/15** | 0 | 0 | 0 | 0 | 0 |
| POI | F 80 | 180 | 180 | 180 | 180 | 180 | 180 |
| SP6 | F 20 | 72 | 72 | 72 | **240** | 240 | 240 |
| SP3 / SP1n / ABA_A | F 7 / 4 / 3 | 24 / 12 / 24 | same | same | 80 / 40 / 80 | same | same |
| MIX (attack) | F 0 | 10 | 10 | 10 | 55 | 55 | 55 |
| X2 | F 121 | 121 | 121 | 121 | 121 | 121 | 121 |

**Screen** (`B` = benign streams worse than S0; `A` = attack streams with more `reach_foreign` than S0's best response):

| Arm | Sets | B | A |
| --- | --- | --- | --- |
| CAND and ALLU | `U_acct` = 4 | 2 (L1, UAC) | 6 |
| CAND and ALLU | `U_acct` ≥ 8 with `U_src` = 12, and Ua8-s40 | 1 (L1) | 6 |
| CAND and ALLU | Ua16-s40, Ua32-s40, Uaoff-s40 | **0** | 6 |

**No set dominates S0.** The six `A` streams (POI, SP6, SP3, SP1n, ABA_A, MIX) are distributed sprays over fresh accounts. Per-target reach is 1 per account and window, so `U_acct` cannot bound them. They are bounded only by `U_src` (and `U_glob`), and their reach grows with `U_src`.

What the grid shows:
* **`U_acct` changes only the benign axis.** It cannot fix the attack axis.
* **`U_src` trades the two axes against each other.** It moves both, in opposite directions.
* **Targeted harassment of an unproven owner (HAR) is unchanged by either.** It runs through K4 penalties in S0 and CAND alike: 14/15.
* **Only a proof protects the owner (HARp 0/15).** On day-0 (ALLU) the owner with a session is still denied 14/15. That is the D3 dependency, measured.

## 7. Lead matrix

| Invariant | Evidence now | Rests on (harness / Host assumption) | Status |
| --- | --- | --- | --- |
| I1 capacity | C1-H: log and event recounts, R-1 race, R-1m and M-C1 detectors | single Redis primary (C1); caller clock with relative TTL | **MEASURED** (single instance) |
| I1′ 2× per interval | PA-5; D24K1 day buckets (`G1_STUDY.md` §6.2 item 9) | fixed buckets | **MEASURED** |
| I2 verifier ≤ admissions | event recount equals log count on every `pre` / `pre+out` / API cell | Host contract H1; `out` routes excluded (H-5) | **MEASURED in the harness**, Host-dependent |
| I3 rotation | C2 (468 attack cells) + C1-H event recount | C3: parents from server facts | **MEASURED** |
| I4a account-lane isolation | C9-H with mixed accounts and detectors | DEC-021 allowance shared (declared coupling) | **MEASURED** |
| I4b device isolation | DV-1/2/4; MX decomposition | the device secret is never presented by U (§5) | **MEASURED, with a measured exception consequence (HARD via distributed rule)** |
| I5 cross-account | C4-H(a) | **Host mapping M1 (C8 not implemented in the library)** | Host-conditional; **package boundary UNPROVEN** |
| I6 per-account bound | sweep per-target reach; C1-H | D4 | **MEASURED** for sampled sets |
| I7 per-class bound | `U_glob` binds only on X2 / X2API | D5 (no approved globals) | partly measured |
| I8 no new-account capacity | C2; spray streams | C3, C4 | **MEASURED** |
| I9 outcome independence | C5 (`pre` = `pre+out` bounds) | — | **MEASURED** |
| I10 storage | C6; R3-H with value-level check, reference store and Redis | — | **MEASURED** |
| D1 no K1/K2/K3 collective harm | C10-H with trace attribution and M-C10 | — | **MEASURED**; the distributed-account rule remains collective for U (§3.3) |

Still `UNPROVEN`: the package proof boundary (C8 / I5); DEC-015 degraded mode; real failover; Redis Cluster; concurrent mixed fleet; any production value.

## 8. Verdict

* **The harness result stands, now independently checked.** All hardened core gates pass, and each has a detector shown to fire. The pre-registered rule therefore still yields the harness's **conditional** "GO for later policy consideration". That result is conditional on:
  * the Host implementing M1;
  * device secrets not being presented by U traffic;
  * the residuals above.
* **It is not an engineering recommendation and not a G2 entry.**
* **Two findings are new to the Lead:**
  * the package proof boundary is unproven: C8 is not implemented, and implementing it needs an API decision;
  * the distributed-account rule turns the I4b exception into HARD blocks on a proven owner.
* **The sweep's answer to "did we reduce unfair blocking or move the harm":** both. Collective K1/K2/K3 harm is gone (D1 596 → 0). Harm moved to `U_acct` / `U_src` capacity refusals and to higher distributed-spray reach. No sampled set removes both. Choosing between them is an Owner trade-off, not a parameter fix.
