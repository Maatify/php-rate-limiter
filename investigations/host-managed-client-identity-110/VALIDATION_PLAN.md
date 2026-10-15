# Validation Plan — what must be proven before any HMCI Runtime is considered

This plan is **not executed** except where marked `[H01]` (the S0-on-main observation of existing inputs). No candidate
exists. Passing this plan would make a candidate *reviewable*, not *accepted*: acceptance remains an Owner decision under
DEC-019 §5–§6 and Decision Governance.

## 1. Arms for a future candidate study

| Arm | Runtime |
|---|---|
| S0 | historical NAT baseline (pipeline sha `4b259d33…f4fc`), for continuity with #103–#116 goldens |
| MAIN | `main@a6c79f4` (S0 + DEC-021), pipeline sha `40213945…6eab` — **the control**; this study's `expected/` is its golden for the identity input modes |
| HMCI | isolated patched tree (patch + hash pins + provenance refusal, as #116) |

Every HMCI cell must also run with `clientInstanceId = null` and reproduce MAIN bit-for-bit.

## 2. Scenario matrix

Streams reuse the merged #103–#116 streams where they exist (D1, D2, N3–N12, Nu*, SP1n, SP3–SP6, ABA, TMS*, TMX*, MIX*,
OWN*), plus the new streams of this study (OFF12, OFF100, SPR_S/R, BRU_S/R, UAS/UAC), each in Host flows `pre+out`,
`pre`, `out`.

| ID | Scenario | Instance-id behaviour | Measures |
|---|---|---|---|
| V1 | Office of 12 / 50 / 100 / 250 browsers, one NAT, one UA major | day 0 (all `NEW`), then day 1 (all returning, established) | users denied, denied checks, attribution |
| V2 | Office with cookie clearing (10 / 50 % of browsers clear per day) | mixed `NEW` / established | as V1 |
| V3 | Browser update wave (UA major change for all) | stable ids | standing kept; no per-instance churn trip from one change |
| V4 | T1 sprayer, stable id / rotating id / no id, one and many IPs, IPv4 and IPv6 `/64`, `/48` | — | verifier reach vs MAIN (must be equal) |
| V5 | T2 with k = 1, 2, 5, 20 valid credentials, establishing the maximum instances, then spraying | established | verifier reach gain vs MAIN, per window and per 24 h |
| V6 | T3 insider in the V1 office (with and without credentials) | mixed | office collateral and attacker reach together |
| V7 | T4 replay of an established id from N networks | replayed | suspension latency; reach gained before suspension |
| V8 | T5 one-account brute force, stable / rotating ids, established id of another account | — | per-account reach equal to MAIN (DEC-021 bound) |
| V9 | T6 trusted-session streams of #113 (TMS, TMSd, TMX, TMXb) and the DEC-021 known-device streams | ± ids | equal to MAIN |
| V10 | Absent / malformed / oversized / empty-string / non-UTF-8 id | `null` and invalid | bit-identical to MAIN; package exception policy for invalid input decided |
| V11 | UA and session change on one established instance | stable id | K6 history kept; K5 behaviour as MAIN |
| V12 | Storage: T7 with 10⁵ fresh ids per K1 / per account | `NEW` | bounded key and member counts |
| V13 | Concurrency (real Redis, separate processes released at one instant): 16 / 64 established instances on one K1 hitting `S_inst`, `C_net`; 16 concurrent `recordSuccess` establishing on one account | — | admitted counts ≤ caps exactly |
| V14 | Redis failures on every new read / write (DEC-015) through `RateLimiterEngine::limit()` | — | typed failure → `FAIL_CLOSED` → degraded fallback, never "established" |
| V15 | Mixed versions (MAIN worker + HMCI worker on one store) and rollback / roll-forward | — | no decision weaker than MAIN on any worker; availability deltas reported |
| V16 | Outer-key and fingerprint-secret rotation during established standing | — | standing and caps survive current / previous generation; no Cartesian pairing |
| V17 | OTP and a DEC-008 custom authentication policy; `API_OVERUSE` | — | OTP same gates; custom policy = MAIN unless opted in; API = MAIN |

## 3. Gates (pre-registered for a future candidate; not relaxed after the run)

| Gate | Condition |
|---|---|
| G-S0 | every cell with `clientInstanceId = null` is bit-identical to MAIN (decisions, levels, retry-after, persisted keys) |
| G-T1 | for every T1 stream, HMCI verifier reach = MAIN reach (Δ = 0), all flows, all IP shapes |
| G-ROT | rotating ids never gain reach over a stable id or over no id (MAIN property `[H01]` O9 preserved) |
| G-T2 | T2 / T3 reach gain ≤ the Owner-approved quantified bound, per 600 s and per 24 h, including concurrency (V13) |
| G-D21 | V8 / V9 per-account reach and DEC-021 decisions equal to MAIN |
| G-FAIR | V1 day-1 users denied ≤ the Owner-approved fairness target; V1 day-0 reported, not hidden (expected = MAIN) |
| G-REPLAY | V7 reach before suspension ≤ bound |
| G-STOR | V12 key / member counts bounded by the declared caps |
| G-FAIL | V14 follows DEC-015; no partial standing |
| G-MIX | V15: no worker issues a decision weaker than MAIN for the same inputs |
| G-ROTSEC | V16 standing and caps continuous across one rotation |
| G-PRIV | no raw id in any key, member, log line or result metadata (code scan + store dump) |

## 4. Comparison baselines

* **S0** for continuity of the NAT evidence (historical numbers in DEC-019, #108, #113, #115, #116).
* **MAIN (post-DEC-021)** for every decision: DEC-021 is part of current Runtime, so a candidate is judged against MAIN,
  not against S0 (Issue #110 post-merge reconciliation, 2026-10-15).
* This study's `[H01]` cells are the MAIN golden for the existing identity input modes M0–M4.

## 5. Evidence still missing before any Runtime approval

1. An implemented candidate and V1–V17 results (none exist).
2. A resolved design for `C_net` (tension T-CNET, `SECURITY_ANALYSIS.md` §5).
3. Owner-approved numbers: `E_acct`, `E_net`, `S_inst`, `C_net`, `R`, standing TTL, and the accepted T2 / T3 gain.
4. Real-Redis concurrency and failure evidence (V13, V14).
5. Mixed-version and rollback evidence (V15).
6. Host reference integration (issuance, MAC check, rotation, mobile installation id) exercised through the public
   Builder / `limit()` API, like #116 a07.
7. A canonical contract and an ADR draft reviewed under Decision Governance — only after the Owner chooses to pursue C.
