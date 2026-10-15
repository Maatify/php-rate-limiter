# Report — Account-Scoped Known-Device Relief for shared NAT (#110)

Baseline `main@ebeb0a32c8fd42e0d4eb4d2500f30ea14036ad92`. DEC-019 ACTIVE, DEC-021 ACTIVE, DEC-020 not approved. #97, #100, #106 and #110
stay OPEN. **Nothing here is a Runtime approval, a decision or a fix.** Every number is generated in [`expected/summary.md`](expected/summary.md);
every observation (M1–M3, R1–R16) is a predicate that `report.php` re-checks. Labels as in [`README.md`](README.md).

## 1. Executive summary

The proposal: use the existing Host proof that a device belongs to an account (`isDevicePreviouslyVerifiedForAccount`, or a trusted session) to
lift the collective K1 / K2 penalty from that device, on its own account only.

**What works** `[MODEL]`:

* Every known browser is relieved, in every office size and mode, with no new input:
  * the trusted office N12T: 56/61 denied → 0;
  * N100 / N250 offices that are 100 % known: 88–244 locked out → 0.
* DEC-021 still bounds each account under relief. One account alone, and several devices on one account, gain nothing or one attempt.

**What does not** (structural, not tunable):

1. **The office and the attacker are the same requests.** On main, the trusted office and an attacker holding 12 valid trusted sessions get
   identical decisions request for request (M3). Every variant that frees the office frees the attacker:
   * TMS (the attacker at the office's cadence) 7/61 → **61/61**;
   * TMSd (dense) 2/200 → 60/200;
   * KS50 (50 accounts) 2 → **200/200**;
   * 24 h, 12 accounts: 172 → 504 (D1: 248).

   K2 bounded the **sum over accounts**. Under relief only the per-account DEC-021 bound remains, multiplied by the accounts held.
2. **Check-only Hosts collapse completely.** Pre-checks never teach the per-account bound anything. With K1 / K2 relieved, a known attacker in
   `pre` reaches the verifier on **every** attempt: TMSd 2 → 200/200, D24T 187 → 2880/2880. The library cannot tell a check-only Host from
   a `pre+out` one. The Lead's quantification requirement (#110 comment 6156215783 §3) already classifies this as NO-GO.
3. **The proof is an unbound boolean.** A Host that sets the flag loosely turns K1 relief into a full credential-spray bypass: SPV 4 → 200/200.
   Reusing one account's proof for others is bounded only per account: VMX 4 → 60.
4. **It helps only the known user.** New users and browsers that lost the Host cookie send the same inputs and stay locked out at main's
   rate (N100, 90 % known: 10 → 9 of 10). Relief gives nothing on day 0. In a 14-day bootstrap from an empty store, 10 (LOW) and 41 (MED) of
   100 browsers are still locked out on day 13 under C1.
5. **Pool trade-off.** Keeping the known traffic in the shared sets keeps the credential-less sprayer near main (13 → 14). Excluding it helps
   unknown users but raises the sprayer's reach (13 → 21; bootstrap 13 → 23 per day). This is HMCI's B5 mechanism, reproduced on the real
   pipeline. The variants can have one or the other, not both.

**Verdict: NO-GO** for a candidate investigation of account-scoped known-device relief built on the existing inputs and state. This covers
variants A, B, C1, C2, C3, D1 and D2 as defined in `ARCHITECTURE.md`. It is **not** a proof that every future design is impossible. The
conditions under which the question could be reopened are in §5. They require Owner decisions and, very likely, a public contract change.

## 2. Answers to the ten questions (brief §11)

| # | Question | Answer | Evidence |
|---|---|---|---|
| 1 | Can a Host-proven known device be used without a new public API field? | **Technically yes, safely no.** A–D1 use only existing inputs and state; D2 needs a new store state. No variant is safe without a contract change: a Host-flow declaration (P2) or a verifiable binding (P3) | `ARCHITECTURE.md` §1, §5; R4, R8 |
| 2 | Real protection from shared NAT? | **For known browsers, fully**: every size, LOW and MED, under C1 / C2 / C3 / D1. For the office as a whole, only in proportion to its known share. Nothing on day 0 | R1, R9, R10, R13 |
| 3 | Known user only, or also anonymous / new? | **Known only.** Unknown browsers keep main's rate (within 2), except under C3, which pays with the pool effect | R10, R11 |
| 4 | Do K1 / K2 keep a real security role? | For unknown traffic, unchanged: every credential-less stream is identical to main (G-INERT; SP1n 4/200 everywhere). For known traffic, **no**: K2's role as the cross-account bound against credentialed attackers is gone in every full relief variant | R3, R6; `COMPARATIVE_ANALYSIS.md` §3 B |
| 5 | Trusted Multi-Session attack? | Gate fails: TMS 7 → 61/61, TMSd 2 → 60 (`pre` 200), TMSs 5 → 48, TMR 2 → 36. D2 halves it only by withdrawing the office's relief (N12T 0 → 28 denied) | R2, R3, R14 |
| 6 | More verifier attempts on the same account? | **Yes.** Per associated account: 1 → 5 per 600 s (TMSd); 32 → 42 per day (D24T, C1) or 35 (D1). Distinct accounts reached: 2 → 12. A single account alone is unchanged (KS1 5, D24K1 45 per day); several devices on one account +1 | R3, R5, R7 |
| 7 | Can pool displacement be avoided? | Yes, by keeping consumption (C1 / C2: NC 13 → 14 / 16; bootstrap ≤ 15 per day against 13). Then the unknown users get no help. Dropping it (C3) helps them and raises NC to 21 / 23 | R11; `SECURITY_ANALYSIS.md` §6 |
| 8 | Is DEC-021 effective, by measurement? | **Yes, per account, in `pre+out`.** It is the only bound left under relief: KS1, D24K1 and MD unchanged or +1, about 5 per account per 600 s. It is **not** a substitute for K2 across accounts (linear in accounts, R6). It is inert for check-only Hosts (R4) and absent for custom policies without K5 thresholds (analytic) | R4, R6, R7 |
| 9 | Worth a real candidate investigation? | **No**, not before P1–P3 (§5) | §5 |
| 10 | A fundamental problem that rejects it before any patch? | **Yes, three:** indistinguishability (M3 → R2), check-only collapse (R4), the unbound Host flag (R8). All three follow from facts of the current contract, not from model details. Plus the partial coverage (known users only) | `ARCHITECTURE.md` §3–§4 |

## 3. Alternatives (scoped verdicts)

| Variant | Office (known) | Office (unknown) | Credentialed attacker | Credential-less attacker | Verdict |
|---|---|---|---|---|---|
| A — K2 relief | MED partial (95 → 88), LOW none | unchanged | TMSd 60, TMS 61 | unchanged | NO-GO: R2, R3; insufficient alone |
| B — K1 relief (trusted advisory extended) | LOW full, MED none | unchanged | VMSdL 60, SPV 200 | unchanged | NO-GO: R8; insufficient alone |
| C1 — consume + trigger | full | unchanged | TMS 61, TMSd 60 (`pre` 200), D24T 504, KS50 200 | ≈ main (−3 … +3) | NO-GO: R2, R4, R8 |
| C2 — consume, no trigger | full | ≈ unchanged | as C1 | slightly above main | NO-GO |
| C3 — exclude | full | partly helped | as C1 | **above main** (13 → 21 / 23) | NO-GO: plus R11 |
| D1 — C1 within the DEC-021 allowance | full | unchanged | as C1 in 600 s; D24T 248 per day; `pre` 200 | ≈ main | NO-GO: R4 (complete in `pre`), R2 (first window) |
| D2 — D1 + cross-account cap R = 5 | **mostly withdrawn** (N100 100 %: 80 locked) | unchanged | TMSd 25, KS50 20 | ≈ main | NO-GO: trades the office back (R14) |

## 4. Results index

| ID | Kind | Statement (short) |
|---|---|---|
| M1 | MAIN | main keeps #113's K2 bound (TMSd 2, TMS 7, in `pre+out` and `pre`) |
| M2 | MAIN | DEC-021 lowered TMX 200 → 60 and TMXb 300 → 48 in `pre+out`, not in `pre` |
| M3 | MAIN | N12T ≡ TMS, request for request, in all flows |
| R1 | MODEL | K2-relieving variants free N12T completely |
| R2 | MODEL | wherever N12T is free, TMS reaches 61/61 |
| R3 | MODEL | TMSd 2 → 60, all 12 accounts, in every full relief variant |
| R4 | MODEL | check-only: complete reach (TMSd, D24T, VMSd) |
| R5 | MODEL | 24 h: 172 → 504; D1 248 |
| R6 | MODEL | gain grows with the accounts held (5 / 10 / 25 / 60 / 200) |
| R7 | MODEL | one account: unchanged; several devices: +1 |
| R8 | MODEL | unbound flag: SPV 200/200, VMX 60 |
| R9 | MODEL | LOW needs K1 relief, MED needs both |
| R10 | MODEL | unknown browsers stay locked at main's rate |
| R11 | MODEL | C3 pool effect on the real pipeline (13 → 21; 13 → 23 per day) |
| R12 | MODEL | KC on the office NAT: 0–1 → 60 |
| R13 | MODEL | bootstrap: day 0 = main; still locked on day 13 (MED > LOW) |
| R14 | MODEL | D2: office and attacker move together |
| R15 | MODEL | OTP: TMSd 2 → 36 (C1) / 24 (D1); `pre` 200 |
| R16 | MAIN | pre-existing: D24K1 `pre` 1440/1440; TMUA (20 browser majors) 36 vs TMSd 2 |

## 5. What would have to change before this direction is reopened

From `VALIDATION_PLAN.md` §1; all are Owner / Lead decisions, none is proposed here:

* **P1:** an approved envelope for credentialed attacker gain, per window, per account and summed over accounts.
* **P2:** a way to keep check-only Hosts from complete reach. This most likely needs a Host declaration, i.e. a public contract change.
* **P3:** a verifiable binding of the association proof, or Host guidance accepted as sufficient.
* **P4:** a separate remedy for unknown / new users and day 0.
* **P5:** a rule for custom policies without K5 thresholds.

Even with P1–P5, the measured office benefit is limited to the known share of the office. The origin of #97 / #100 / #106 is collective
judgement of indistinguishable shared-scope traffic, and for unknown users it is untouched.

## 6. Findings on `main` (not fixed here; each needs a disposition)

| ID | Finding | Evidence | Proposed disposition (Owner) |
|---|---|---|---|
| F-AKDR-01 | On main, the K2 cross-account bound is **per browser major**, not per IP. A multi-session trusted attacker that cycles 20 browser majors reaches the verifier 36/200 times, against 2/200 with one major (TMSd), on 12 accounts, at most 3 per account, in `pre+out` and `pre`. K2 is scoped to IP + UA major, so each major has its own churn set (about 2 reaches per scope per 600 s) and its own 60 s block. K1 spray blocks are persisted but advisory for trusted sessions. #113's "K2 is the cross-account bound" therefore holds per UA major; the bound grows with the majors an attacker presents | `[MAIN]` `expected/summary.md` §3 (TMUA, TMSd); `[CODE]` `EP:2603-2618` (K2 = IP + UA major), `EP:1207-1222`, `EP:513`, `EP:1509-1511` | evaluate separately (relevant to any #110 envelope); no Issue opened by this study |

Documented limitations measured again (not new findings):

* DEC-021 does not bound check-only Hosts: D24K1 `pre` 1440/1440; KS1 `pre` 200/200. DEC-021 states this.
* A trusted session sent before authentication bypasses K1. This is HMCI F-HMCI-03.

Main baselines relevant to P1 (facts, not defects):

* a credentialed attacker with 12 accounts already reaches the verifier **172** times per day on main with trusted sessions (D24T), and
  **411** with previously-verified LOW devices (D24V; K1, not K2, bounds them);
* the maximum per account per day is 32 and 36.

## 7. Limits of the evidence

* **Variant results are models.** They come from store-view emulation on the unpatched runtime, not from candidate code. G-EQ, G-INERT and
  G-SUPP establish the model's fidelity on the dimensions it covers (`ARCHITECTURE.md` §4).
* **Not exercised:** Redis, concurrency, IPv6, secret rotation, mixed versions, DEC-015, custom policies. These are analytic in
  `SECURITY_ANALYSIS.md` §10, and each is a gate in `VALIDATION_PLAN.md`. Concurrency is expected to **add** to the credentialed gain
  (bursts across held accounts), not reduce it.
* **Synthetic, deterministic streams.** Counts are requests, not people. One NAT, one browser major.
* **The NO-GO is scoped** to relief on existing inputs and state. It does not cover designs with a new verifiable proof, outcome-qualified
  evidence (studied separately as OQC / OAAA), or a Host-flow declaration.

## 8. Owner decisions requested

1. Accept this study as evidence only, with the scoped NO-GO of §1 and §3.
2. Decide whether an envelope for credentialed attacker gain (P1) should be defined at all. Any relief that does not use outcomes will need
   one.
3. Dispose of F-AKDR-01.
4. Confirm that no Host guidance recommending the previously-verified flag or trusted sessions as a NAT remedy is published before a
   decision. Separately, decide whether Host guidance on the flag's semantics is wanted anyway, since it already drives the DEC-021
   per-account bound (related to F-HMCI-04).
