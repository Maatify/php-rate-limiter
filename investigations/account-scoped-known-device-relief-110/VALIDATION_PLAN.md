# Validation Plan — what any later known-device relief candidate would owe

This study's verdict is NO-GO (`REPORT.md`). This plan does **not** schedule a candidate. It records, for the Owner and the Lead, what would
have to be true **before** a candidate could be opened, and what it would then have to pass. Nothing here sets a numeric threshold or an
acceptable attacker gain; those are Owner decisions.

## 1. Preconditions (none is met today)

| ID | Precondition | Why | Evidence |
|---|---|---|---|
| P1 | An Owner-approved envelope for **credentialed** attacker gain: verifier reaches per 60 / 600 / 1800 s and per day, per account and summed over accounts, as a function of the accounts held | Relief cannot separate the known office from an attacker holding the same proofs. The gain is the per-account bound × the accounts held | R2, R3, R5, R6; `SECURITY_ANALYSIS.md` §2–§4 |
| P2 | A design under which a **check-only** Host does not collapse to complete reach, without the library knowing the Host's flow | The Lead requirement in #110 comment 6156215783 §3 makes complete reach on a protected stream a NO-GO. Relief + `pre` gives exactly that | R4; OAAA #116 X3 |
| P3 | A **binding** of the association proof that the library can check, or Host guidance that the Owner accepts as sufficient | `isDevicePreviouslyVerifiedForAccount` is a bare boolean. Loose Host use turns relief into a spray bypass | R8; `CURRENT_STATE.md` §1 |
| P4 | A separate answer for **unknown / new / cookie-less** users and day 0 | Relief does not reach them. Without it #97 / #100 / #106 stay open for that population | R10, R13 |
| P5 | A rule for **custom policies** without K5 thresholds or with the allowance disabled | There, relief has no per-account bound even in `pre+out` (S0 TMX 200/200 `[PRIOR]`) | `SECURITY_ANALYSIS.md` §10 |

P2 and P3 would almost certainly need a public contract change (a Host declaration of its flow, or a verifiable proof). That falls outside
the brief's "no new public field" premise and would need independent Owner approval.

## 2. Gates for a candidate, if one is ever authorized

Every gate compares against **main at that time**. S0 is kept as a historical column. Results are reported per cell, never as an average.

| Gate | Requirement | Streams (minimum) |
|---|---|---|
| G-OFFICE | Reported improvement for known browsers; reported unchanged-or-better for unknown browsers | O01 matrix (12 / 50 / 100 / 250, LOW / MED, 0 / 50 / 90 / 100 %), N12T, N12M |
| G-T1 | Credential-less attackers: reported Δ per window, including pool effects with a known office present | SP1n, SP2, SP3, SP4*, SP6, NC in O01 and B01, the #110 B-list |
| G-T2 | Credentialed known attackers: Δ per 60 / 600 / 1800 s / day, per account and summed, **within the P1 envelope** | TMS, TMSd, TMSs, TMR, TMUA, VMSd, VMSdL, VMR, KS1 / KS2 / KS5 / KS50, MD3 / MD10, D24T, D24V, D24K1, TPAIRT, TPAIRM |
| G-HOST | Host-violating and misconfigured controls are measured and covered by the P3 answer | TMX, TMXb, VMX, SPV, SP5 |
| G-FLOW | `pre+out`, `pre` and `out` reported separately; no complete reach in `pre` on a stream main protects (P2) | all G-T2 streams |
| G-CONC | Real Redis, parallel bursts: one per held account, several per account, and mixed with an office. Report reach over main and the D1-type allowance race | TMSd-burst, MD10-burst, KC-burst |
| G-IPV6 | `/64` and macro `/48` `/40` `/32` behaviour of known traffic (counted? relieved?) | IPv6 variants of TMSd, NC, office |
| G-ROT | Current / previous secret and fingerprint generations: both enforced keys handled, bridge sets read-only | TMSd, office, under rotation |
| G-MIX | Old worker = main; new worker relief only on known traffic; rollback leaves main-readable state; mixed fleet reported per worker | office + TMSd on mixed workers |
| G-DEC015 | Degraded mode: no relief, typed failures, fallback unchanged | backend-failure sequences on known traffic |
| G-OTP | The same matrix on OTP (allowance 2, no budget pre-check) | G-T2 subset, office |
| G-CUSTOM | Custom authentication policies with and without K5 thresholds / allowance | TMSd, TMX on each |
| G-BOOT | Bootstrap without idealization from an empty store, with and without NC | B01 |
| G-PROV | Pinned runtime hashes, fail-closed validation, per-request evidence, mutation self-tests (as `run.sh` here) | all |

## 3. What this study already provides for those gates

* The streams and the office / bootstrap engine (`lib/akdr.php`), runnable against a candidate by changing the provenance pin and dropping
  the store-view decorators.
* main baselines for every G-T2, G-OFFICE and G-BOOT cell (`expected/`).
* The fail-closed validator and the mutation self-tests as a template.

## 4. Missing evidence (explicitly not produced here)

* No candidate runtime. All variant numbers are `[MODEL]`.
* No Redis or concurrency, IPv6, secret rotation, mixed version, DEC-015 or custom-policy measurement.
* No real-world office traffic. All streams are synthetic and deterministic, and counts are requests, not people.
