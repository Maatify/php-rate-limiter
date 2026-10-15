# Validation Plan — what must be proven before any instance-identity Runtime is considered

This plan is **not executed**, except for:
* `[H01]`: the MAIN observation of the existing inputs;
* `[H02 MODEL]`: the bootstrap model. Its NEW part runs the real MAIN pipeline; its ESTABLISHED part is idealized.

No candidate exists. Passing this plan would make a candidate *reviewable*, not *accepted*. Acceptance remains an Owner
decision under DEC-019 §5–§6 and Decision Governance. The plan applies to proposal C of `ARCHITECTURE.md` §3, to its
account-scoped variant C-acct, and to any other instance-identity candidate (`REPORT.md` §3).

## 1. Arms for a future candidate study

| Arm | Runtime |
|---|---|
| S0 | historical NAT baseline (pipeline sha `4b259d33…f4fc`), for continuity with #103–#116 goldens |
| MAIN | `main@a6c79f4` (S0 + DEC-021), pipeline sha `40213945…6eab` — **the control**; this study's `expected/` is its golden for input modes M0–M4 and for the MAIN / ATKM bootstrap rows |
| CAND | isolated patched tree (patch + hash pins + provenance refusal, as #116) |

## 2. Scenario matrix

Streams reuse the merged #103–#116 streams where they exist (D1, D2, N3–N12, Nu*, SP1n, SP3–SP6, ABA, TMS*, TMX*, MIX*,
OWN*), plus this study's streams (OFF12, OFF100, SPR_S/R, BRU_S/R, UAS/UAC, and the H02 bootstrap variants). Each runs in the
Host flows `pre+out`, `pre` and `out`.

| ID | Scenario | Measures |
|---|---|---|
| V1 | **Bootstrap from a fresh store**: offices of 12 / 50 / 100 / 250 browsers over ≥ 14 days, real `checkOnly` admission → recorded success → capped establishment; retries; no pre-established browser anywhere | per day: established fraction, browsers with no login, users denied, attribution — the CAND version of H02 with **no idealization** |
| V1i | Idealized control (pre-established office) — **allowed only as a labelled upper bound**, never as the fairness result | as V1 |
| V2 | Cookie clearing (10 / 50 % per day), standing expiry (fixed / sliding), browser-update waves | as V1 |
| V3 | Attacker interference during bootstrap (T1 on the NAT, T3 insider) | office bootstrap delay and attacker reach together (H02 B5 / B6 on CAND) |
| V4 | T1 sprayer: stable id / rotating id / no id; one and many IPs; IPv4, IPv6 `/64`, `/48`; **with and without an established office on the same K1** | admitted verifications vs MAIN, per 600 s and per 24 h |
| V5 | T2 with k = 1, 2, 5, 20 credentials, accumulating established instances over the **whole standing TTL** (renewing them), then spraying | admitted verifications vs MAIN per 600 s / 24 h / TTL; established stock over time |
| V6 | T3 insider in the V1 office | office collateral and attacker reach together |
| V7 | T4 replay of an established id from N networks | suspension latency; admitted verifications before suspension |
| V8 | T5 one-account brute force: stable / rotating ids, an established id of another account | per-account admitted reach equal to MAIN (DEC-021) |
| V9 | T6 trusted-session streams of #113 (TMS, TMSd, TMX, TMXb) and the DEC-021 known-device streams | equal to MAIN; for C-acct, the re-evaluation called for in `SECURITY_ANALYSIS.md` §4 |
| V10 | Absent / malformed / oversized / empty / non-UTF-8 id | bit-identical to MAIN; invalid-input policy decided |
| V11 | UA and session change on one established instance | instance history kept; K5 as MAIN |
| V12 | Storage: 10⁵ fresh ids per K1 / per account | bounded key and member counts |
| V13 | Concurrency (real Redis, separate processes released at one instant): established instances racing `S_inst` / `C_net`; concurrent `recordSuccess` establishing on one account and one K1 | admitted counts ≤ caps exactly |
| V14 | Redis failures on every new read / write (DEC-015) through `RateLimiterEngine::limit()` | typed failure → `FAIL_CLOSED` → degraded fallback; never "established" on failure |
| V15 | Mixed versions (MAIN worker + CAND worker on one store), rollback, roll-forward, with established and non-established traffic | per-class decision comparison (§3 G-MIX-*) |
| V16 | Outer-key and fingerprint-secret rotation during standing | standing, stock caps and sets continuous across one rotation |
| V17 | OTP, a DEC-008 custom authentication policy, `API_OVERUSE` | OTP same gates; custom = MAIN unless opted in; API = MAIN |

## 3. Gates (pre-registered for a future candidate; never relaxed after the run)

| Gate | Condition |
|---|---|
| G-S0 | every cell with no instance id is bit-identical to MAIN (decisions, levels, retry-after, persisted keys) |
| G-T1 | for every T1 stream, admitted verifications ≤ MAIN + the **Owner-approved pool term**, measured with an established office on the same K1 (V4). A design claiming Δ = 0 must show it with that office present; H02 B5 shows that excluding established subjects from the network set raises T1 reach (13 → 22 per day) |
| G-ROT | rotating ids never beat the same-mode stable id **nor** no id (MAIN property `[H01]` O9 preserved) |
| G-T2 | V5 admitted-verification gain ≤ the Owner-approved bound per 600 s, per 24 h and per standing TTL, with the established stock accumulated over the whole TTL and under concurrency (V13). Bounds are stated in admitted verifications, never in distinct subjects |
| G-D21 | V8 / V9 per-account reach and DEC-021 decisions equal to MAIN |
| G-BOOT | V1 (no idealization): the established fraction and the browsers-with-no-login per day meet the Owner-approved fairness target **from a fresh store**; V1i may be reported only as a labelled upper bound |
| G-FAIR | V1 / V2 users denied per day ≤ target, reported per day from day 1, not only at steady state |
| G-REPLAY | V7 admitted verifications before suspension ≤ bound |
| G-STOR | V12 key / member counts bounded by the declared caps, including the concurrent stock caps |
| G-FAIL | V14 follows DEC-015; no partial standing |
| G-MIX-OLD | an **old (MAIN) worker** makes exactly the MAIN decision for every input, including inputs that carry an instance id and state written by CAND |
| G-MIX-NEW | a **CAND worker** makes exactly the MAIN decision for traffic with no id or a `NEW` id |
| G-MIX-EST | a CAND worker may differ from MAIN **only** for established traffic, **only** in the approved direction (MAIN deny → CAND allow), and only within the same quantified envelope as G-T1 / G-T2 / G-D21. These limits must hold under concurrency (V13), mixed state (V15), rollback / roll-forward and backend failures (V14) |
| G-ROTSEC | V16 standing and caps continuous across one rotation |
| G-PRIV | no raw id in any key, member, log line or result metadata (code scan + store dump) |

The first version's single gate "no worker issues a decision weaker than MAIN" contradicted the purpose of the proposal,
which is to allow some established browsers that MAIN denies. It is replaced by G-MIX-OLD / -NEW / -EST (Lead review F118-03).

## 4. Comparison baselines

* **S0** for continuity of the NAT evidence (DEC-019, #108, #113, #115, #116).
* **MAIN (post-DEC-021)** for every decision: DEC-021 is part of current Runtime (Issue #110 post-merge reconciliation,
  2026-10-15).
* This study's `[H01]` cells and the H02 MAIN / ATKM rows are the MAIN goldens for these streams.

## 5. Evidence still missing before any Runtime approval

1. An implemented candidate and V1–V17 results (none exist). In particular V1 **without** idealization: H02 bounds only the
   upper limit.
2. A solution to the bootstrap problem (H02 B1–B4): on MAIN about 87 % of a 100-browser office cannot log in on a given day,
   and that login is the only way into standing.
3. A design whose T1 pool term (H02 B5) is either zero with an established office present, or quantified and approved.
4. Concurrent stock caps (`M_acct`, `M_net`) and a credential-term bound in admitted verifications (`SECURITY_ANALYSIS.md`
   §4), or the account-scoped variant whose credential term is zero.
5. A resolved `C_net` design (T-CNET), or a design that does not need one.
6. Owner-approved parameters and accepted gains.
7. Real-Redis concurrency and failure evidence (V13, V14), and mixed-version / rollback evidence (V15).
8. A Host reference integration (issuance, MAC check, rotation, mobile installation id) through the public Builder /
   `limit()` API.
9. A canonical contract and an ADR draft — only after the Owner chooses a direction.
