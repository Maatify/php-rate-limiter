# Security Analysis — attackers, limits and threat model

Numbers are attacker requests reaching the verifier ("reach"), Login, from `expected/summary.md`. Labels as in `README.md`. "Full relief" =
the variants that relieve both K1 and K2 for every known request: C1, C2, C3 and D1 (D1 while the allowance is unspent).

## 1. Threat model

Relief keys on a proof the attacker can hold without being the account owner today:

* a **stolen or copied device cookie** of a previously-verified device. It is a bearer value from the library's point of view; the Host's
  record says "verified for account X";
* a **stolen live session** (infostealer, session fixation). It is a trusted session by the contract until the Host revokes it;
* an account the attacker **used to control**: password changed after a compromise, but the attacker's device is still on the Host's
  verified list;
* a **shared or office device** that is verified for a colleague's account;
* an **insider on the same NAT** who holds verified devices for several accounts (their own and test / shared accounts);
* a **Host that violates the contract** and sets the flag loosely (cookie presence only, or for any claimed account). This is an
  integration-risk scenario, measured as a control and kept separate from compliant traffic (§7).

Several of these attackers have already authenticated to the account once. That does not make further verifier reach harmless: guessing the
new password, the OTP, or a second factor are exactly the attempts the account bound exists for. The #113 review (R23) already declined
to assume this class out of scope, and this study follows that. Whether a gain for this class is acceptable, and how much, is an Owner
decision (HMCI O-2; Lead comment 6156215783). This study does not make it.

## 2. Attacker matrix (Login, `pre+out`; `pre` in brackets where it differs)

| Class | Stream | MAIN | C1 / C2 / C3 | D1 | D2 | What bounds it under relief |
|---|---|---|---|---|---|---|
| No credentials, unknown (spray) | SP1n | 4 | 4 | 4 | 4 | K1 / K2, unchanged |
| 1 account, 1 trusted session | KS1 | 5 [200] | 5 [200] | 5 [200] | 5 [200] | K5 + DEC-021, unchanged; nothing in `pre` (pre-existing) |
| 1 account, 24 h, 1 / 60 s | D24K1 | 45 [1440] | 45 [1440] | 45 [1440] | 45 [1440] | same |
| 2 accounts | KS2 | 2 [2] | 10 [200] | 10 [200] | 10 [200] | per-account K5 / allowance only |
| 5 accounts | KS5 | 2 [2] | 25 [200] | 25 [200] | 25 [200] | same |
| 12 accounts, office cadence | TMS | 7 [6] / 61 | **61 / 61** [61] | 61 [61] | 33 [33] | nothing across accounts; K5 per account after the 6th failure |
| 12 accounts, dense | TMSd | 2 [2] | **60** [**200**] | 60 [200] | 25 [85] | per-account K5 / allowance (5 per account per 600 s) |
| 12 accounts, sequential | TMSs | 5 [18] | 48 [204] | 48 [204] | 24 [89] | same |
| 50 accounts | KS50 | 2 [2] | **200 / 200** [200] | 200 [200] | 20 [20] | 4 attempts per account fit before any per-account level |
| 12 previously-verified MEDIUM devices | VMSd | 2 [2] | 60 [200] | 60 [200] | 25 [85] | as TMSd (A alone: 4, since K1 still applies to untrusted) |
| 12 previously-verified LOW devices | VMSdL | 4 [4] | 60 [200] | 60 [200] | 25 [85] | as TMSd (B alone already gives 60) |
| 24 h, 12 trusted sessions, 1 / 30 s | D24T | 172 [187] | **504** [**2880**] | 248 [2880] | 247 [1440] | D1: K4 after the allowance; 2880 / 2880 in `pre` |
| 24 h, 12 verified LOW devices | D24V | 411 [467] | 504 [2880] | 451 [2880] | 446 [1440] | same |
| Several devices, one account | MD3 / MD10 | 2 / 2 | 3 / 3 | 3 / 3 | 3 / 3 | DEC-021 allowance is fingerprint-independent |
| Fingerprint rotation | TMR / VMR | 2 / 2 | 36 / 36 | 36 / 36 | 15 / 15 | allowance, then K4 |
| UA-major rotation (trusted, 20 majors) | TMUA | **36** | 36 | 36 | 36 | on main K2 bounds each UA major separately, so 20 majors give 36 (Finding F-AKDR-01); relief adds nothing |
| Proof of A used for B (Host-violating), trusted | TMX / TMXb | 60 / 48 [200 / 300] | same | same | same | K1 already advisory for trusted; one DeviceFP, so no churn |
| Proof of A used for B (Host-violating), verified | VMX | 4 [4] | **60** [**200**] | 60 [200] | 25 [85] | per-account only |
| Host misconfiguration: flag on every claimed account | SPV | 4 [4] | **200 / 200** | 200 | 5 | nothing: each fresh account fails once |
| Forged trust (trusted, 200 fresh accounts) | SP5 | 200 [200] | 200 | 200 | 200 | nothing, already on main and S0 |

Lead quantification (absolute Δ over MAIN in the worst aligned 60 / 600 / 1800 s window and over the run; `expected/summary.md` §4):

| Stream | Flow | C1 | D1 | D2 |
|---|---|---|---|---|
| TMSd | pre+out | 20 / 58 / 58 / 58 | 20 / 58 / 58 / 58 | 9 / 23 / 23 / 23 |
| TMSd | pre | 20 / 198 / 198 / 198 | same | 10 / 83 / 83 / 83 |
| TMS | pre+out | 2 / 14 / 41 / 54 | same | 2 / 7 / 20 / 26 |
| KS50 | pre+out | 20 / 198 / 198 / 198 | same | 5 / 18 / 18 / 18 |
| D24T | pre+out | 2 / 19 / 56 / 332 | 2 / 19 / 56 / 76 | 2 / 10 / 29 / 75 |
| D24T | pre | 2 / 20 / 58 / 2693 | same | 2 / 10 / 28 / 1253 |
| SPV | pre+out | 20 / 196 / 196 / 196 | same | 1 / 1 / 1 / 1 |

There are no S0 = 0 cells among the attacker streams. In `pre` every full relief variant reaches **all** attempts on streams main
protects (TMSd 2 → 200, D24T 187 → 2880). The Lead requirement in #110 comment 6156215783 §3 classifies this as "complete protection
collapse … remains NO-GO even if a simple ×S0 ratio technically passes".

## 3. The Trusted Multi-Session gate (brief §6)

| Brief item | Result |
|---|---|
| 1. Re-measure on main, keep #113 as is | Done: `CURRENT_STATE.md` §6 and M1–M2. #113's S0 numbers are read, not re-run. Main keeps TMSd 2/200 and TMS 7/61. DEC-021 changed TMSs (18 → 5), TMX (200 → 60) and TMXb (300 → 48) in `pre+out` only |
| 2. One valid account | KS1 5 / 200, D24K1 45 per day: **unchanged by every variant** (R7) |
| 3. 2 / 5 / 12 / more accounts | KS2 10, KS5 25, TMSd 60, KS50 200 (C1, `pre+out`), against main 2 for each. Reach grows linearly with the accounts held, until every attempt passes (R6) |
| 4. One trusted session per account | TMS / TMSd / TMSs / TMR as above (R2, R3) |
| 5. Several sessions / devices for one account | MD3 / MD10: 2 → 3. The fingerprint-independent allowance holds per account (R7) |
| 6. UA / fingerprint rotation | TMR / VMR 2 → 36. TMUA (20 browser majors) is already 36 on main, because K2 bounds per UA major (F-AKDR-01); relief adds nothing there |
| 7. Proof of A against B | VMX 4 → 60 (`pre`: 200). TMX unchanged at 60, because trusted K1 is already advisory on main and one DeviceFP never churns |
| 8. Host misconfiguration / forged trust | SPV 4 → 200 / 200. SP5 200 / 200 already on main (R8) |
| Reach on associated accounts, separate from distinct accounts | "max / acct" column: TMSd 1 → 5 per account in 600 s; D24T 32 → 42 (C1) or 35 (D1) per account per day. Distinct accounts reached 2 → 12 (TMSd) and 8 → 12 (D24T) |

**Answer:** every full relief variant **raises** the multi-session attacker's reach on the accounts it holds. The cross-account bound K2
gave (#113) is replaced by the per-account DEC-021 bound × the number of accounts. For check-only Hosts it is replaced by nothing.

## 4. Same-account delta versus new accounts

Relief gives an attacker **no new account** beyond those it holds a proof for (`G-STREAM`; compliant streams only). HMCI's "zero new
cross-account subjects" claim is confirmed. It is not zero gain:

* per associated account, reach in the first 600 s goes from 1 to 5 (TMSd) and per day from 32 to 42 (D24T, C1);
* the sum over accounts is what grows: Δ600 = 58 for 12 accounts, 198 for 50.

DEC-021 is what bounds each account under relief. DEC-021 alone is **not** equivalent to K2: K2 bounds the **sum** (2 / 200 for any number
of accounts ≥ 2); DEC-021 bounds each **term**.

## 5. Shared NAT and credential spray (O01, one morning)

Availability (browsers locked out all morning, known + unknown; `expected/summary.md` §7):

* **Known browsers are fully relieved** by C1 / C2 / C3 / D1 in every size and mode, for example N250 LOW 100 % known: 239 → 0. They are not
  relieved by A alone in LOW, nor by B alone in MED (R9). K1 and K2 relief only work together.
* **Unknown browsers are not helped.** New users and browsers that lost the Host cookie send the same inputs. In a 90 % known office they
  stay locked out at main's rate: N100 LOW 10 → 9 of 10, N250 MED 24 → 23 of 25 (R10). C3 helps them somewhat (N100 LOW 10 → 1), at the
  cost of the pool effect below.
* **Delay:** at N100, 90 % known, the mean time to a successful login is 70 s on main versus 4 s under C1 (LOW), and 60 s versus 4 s
  (MED). This counts browsers that got in.
* **Causes on main** (N100, 90 % known): LOW is all K1 (`persisted:K1` 335, `issued:K1` 31); MED is K2 and K1 + K2.

Security on the same NAT:

* **Credential-less sprayer NC** (H02 cadence): N100 LOW reaches 13 on main, 14 under C1, 16 under C2 and **21 under C3**. N12 LOW: 18,
  21, 21 and 23.
* **Credentialed known attacker KC** (12 accounts, 200 attempts): main 0–4. Every full relief variant **60**; at N250 MED, 39. There the
  50-device IP cap makes the attacker's devices ephemeral, so its failures score K4 directly.

## 6. Pool displacement (HMCI B5)

The mechanism is reproduced on the **real pipeline**, not an idealized model:

* C3 (known traffic excluded from the shared sets) raises the credential-less sprayer's reach above main:
  * O01 N100 LOW: 13 → 21;
  * B01 LOW bootstrap: 13 per day on main against 13 … 23 per day under C3, rising as the office becomes known (R11).
* Consuming the known traffic (C1 / C2) mostly avoids it: the bootstrap stays at 12–15 per day under C1 and 12–14 under C2, against 13 on
  main. The gain is not exactly zero (N12 LOW +3).
* The price of consuming is that unknown browsers stay locked out (§5).

**So the variants can avoid the pool effect or help the unknown user, not both.**

## 7. Host-contract violation and spoofing (integration risk, not compliant traffic)

* By contract, `isDevicePreviouslyVerifiedForAccount` is a Host-proven association with the **specific** account; the library does not check
  it independently (`CURRENT_STATE.md` §1). The following are therefore **contract violations**, measured as misconfiguration controls.
  Under B / C / D1:
  * a Host that sets it from cookie presence, or for any claimed account, turns relief into a full credential-spray bypass (SPV 200 / 200);
  * reusing one account's proof for others (VMX) is bounded only per account.
* These results show how much the variants depend on Host compliance. They are not evidence of a failure under a compliant Host, and they
  do not by themselves show that a new verifiable proof is required.
* A trusted session sent before authentication or without an account already bypasses K1 on main. That is HMCI F-HMCI-03 and H01 M3
  (200 / 200).
* The library cannot detect either case. Host guidance (none exists today, HMCI F-HMCI-04) or an independently verifiable binding are
  possible hardening choices for an Owner decision, not current contractual requirements.

## 8. Flows

| Flow | Effect of relief |
|---|---|
| `pre+out` | Per-account DEC-021 bound applies (TMSd 60, D24T 504 / 248) |
| `pre` (check-only) | **No bound at all** for a known attacker: TMSd 200 / 200, D24T 2880 / 2880, VMSd 200 / 200 (R4). Main's only bound for this Host was K2. The library cannot tell a check-only Host from a `pre+out` one (OAAA #116 X3). A design that bounds `checkOnly` itself from existing state was not modelled; whether it avoids this is unresolved |
| `out` (record-only) | Every attempt is verified before the decision in MAIN and in C1 alike. Relief changes decisions after the fact only |

## 9. OTP

Same structure with OTP's smaller allowance (2) and larger K5 delta (+4):

* TMSd: main 2 → C1 36 → D1 24 (`pre+out`); `pre` 200 / 200 (R15);
* TMS: 7 → 37 (C1) / 27 (D1);
* KS1 and D24K1 unchanged (3 and 51 per day).

OTP budget pre-check enforcement is off by preset (`OtpProtectionPolicy.php:92`).

## 10. Dimensions not exercised (analytic)

| Dimension | Assessment |
|---|---|
| **Concurrency / Redis** | On main, the 3rd distinct DeviceFP on a K2 scope is denied at its own pre-check, through one atomic bounded-set add. Under relief nothing cross-account is evaluated before verification, and pre-checks are read-only for K4 / K5 / allowance (DEC-021 "concurrent in-flight attempts on one device are not serialized"). A burst of parallel attempts, one or more per held account, therefore all pass before any per-account state learns. #111 measured the same mechanism for OQC (K1 3 → 16 / 16 under Redis). D1's gate reads the allowance before verification and is subject to the same time-of-check race. **Unmeasured; required gate** |
| **IPv6** | K1 / K2 are keyed on the `/64`. The `/48`, `/40` and `/32` macro scopes observe spray and churn and persist on the canonical `/64` K1 / K2 (`EP:1197-1205`, `EP:1492-1503`). A relief implementation must decide whether known traffic is counted in the macro sets. Hiding the `/64` blocks also removes the macro protection for known requests. Not modelled |
| **Secret rotation** | `checkActiveBlocks` also enforces previous-generation keys (`EP:525-533`). A real relief must hide both generations and keep the bridge / previous sets read-only. The model refuses rotation (throws) rather than guessing |
| **DEC-015** | Under backend failure the typed failure path and the `AUTHENTICATION_PRIMARY` fallback (ACCOUNT + IP_PREFIX) apply, and the relief state is unreadable. D1's allowance read would take the typed failure path, as DEC-021's allowance consume does. No relief in degraded mode; no new failure mode identified |
| **Mixed versions / rollback** | A / B / C1 / C2 / C3 / D1 add **no key**. Old workers keep enforcing K1 / K2 on known traffic, so relief would be per-worker and inconsistent. C1 persists the same collective blocks main would, so rollback is state-safe. C2 / C3 leave fewer members or blocks behind, which main simply re-counts. D2 needs a new bounded key family (a new Store purpose), so rollback leaves orphans until TTL |
| **Bounded storage** | No new state except D2: one bounded set per IP prefix and window, capped at R |
| **Custom policies (DEC-008)** | A preset subclass without K5 thresholds keeps S0 known-device behaviour and a disabled allowance (DEC-021 §4). For such a policy the per-account bound under relief is the S0 one: TMX 200 / 200 and TMXb 300 / 300 on S0 `[PRIOR]` #113. Relief there means **complete reach even in `pre+out`** |
| **API_OVERUSE** | Not an authentication policy; DEC-021 forbids combining it with the allowance. Relief does not apply |
| **Privacy / Host contract** | No new data. The variants rely on Host compliance with `DEVICE_FINGERPRINT.md` §4.3; violations are amplified (§7). No operational Host guidance exists today (F-HMCI-04) |
| **DEC-019** | Every relief variant changes K1 / K2 enforcement, which DEC-019 keeps as S0 until an Owner-approved replacement. None of this is authorized here |
| **DEC-021** | Unchanged and effective per account under relief (R7). It is the **only** bound left for known attackers in `pre+out` |
