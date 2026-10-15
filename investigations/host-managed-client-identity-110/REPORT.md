# Report — Host-Managed Client Instance Identity for shared NAT (#110)

Baseline `main@a6c79f45e79171e26031fc720822428e09e6e36c`. DEC-019 ACTIVE, DEC-021 ACTIVE, DEC-020 not approved. #97, #100,
#106 and #110 stay OPEN. **Nothing here is a Runtime approval, a decision or a fix.** This revision answers the Lead review
on PR #118 (F118-01 … F118-05, §6).

## 1. Executive summary

* The runtime has **no client-instance identity**. It folds instance, appearance (UA major, hints) and session into one
  DeviceFP. So the tested observables cannot tell an office of stable browsers from one browser that keeps changing its
  fingerprint.
* A host-issued browser id carried through **today's inputs, as the current runtime interprets them**, did not help on the
  measured streams (H01, real runtime):
  * In `clientFingerprint` or an untrusted `sessionDeviceId`, NAT collateral rose: 90 → 97 of 100 browsers, 8 → 10 of 12.
  * As a pre-authentication trusted session, a credential sprayer reached the verifier 200 of 200 times instead of 7.
    Per-account brute force rose from 3 to 7.
  * As `correlationId`, the sprayer also reached 200 of 200.
* **Proposal C** is a new `clientInstanceId` input, where "identity is free, standing is earned". It is **one unimplemented
  proposal**, not the only viable direction. Examining it found three problems:
  * **Bootstrap (H02).** On MAIN, about 87 of 100 office browsers cannot log in on a given day, and that login is the only
    way into standing. Even with the established browsers idealized, the office is fully established only on day 9. With a
    cap of 5 per day it is 70 % after 14 days. With cookie clearing or expiry it plateaus near 75–83 %.
  * **Pool term (H02, B5).** Taking established traffic out of the shared spray set raised a credential-less sprayer's
    reach from 13 to 22 per day. Unchanged MAIN stays at 13. The proposal's own gate "T1 Δ = 0" is not met by construction.
  * **Credential term (corrected bound).** Daily establishment caps let the established stock accumulate over the standing
    TTL (60 instances per credential with `E_acct = 2`, `T = 30`). Distinct-subject caps do not bound admitted
    verifications. As first written, the gain could reach thousands of admitted verifications per day per credential.
* An **untested** direction-D variant was identified: account-scoped relief for host-proven known devices, using the
  existing `isDevicePreviouslyVerifiedForAccount`. Its credential term is zero by construction. It shares the bootstrap and
  pool problems.

**Verdicts (each scoped to its evidence):**

| Item | Verdict |
|---|---|
| Overall: "a host-managed instance identity can relieve shared NAT safely" | **INCONCLUSIVE** |
| Proposal C as specified | **Not acceptable as specified**: model-level and analytic contradictions with its own gates. Not a measured candidate NO-GO |
| A / B / `correlationId` mappings on the current runtime | **NO-GO** on the measured streams |
| Direction D as a class | **Not excluded**. Only the measured D candidates were rejected |

None of this is a Runtime acceptance.

## 2. Results

| # | Result | Kind |
|---|---|---|
| R1 | Field responsibilities, pre/post-auth availability, DeviceFP derivation, K1–K5 scope, limits (`CURRENT_STATE.md` §1–§9) | `[CODE]` / `[CONTRACT]` |
| R2 | `correlationId` is a spray subject; reusing it as a browser id disables spray for a stable-id sprayer, with no office gain | `[TEST]` + `[H01]` O7, O8 |
| R3 | A browser id in `clientFingerprint` or in an untrusted `sessionDeviceId` increases NAT collateral on the measured streams; the two give identical decisions | `[H01]` O2, O3 |
| R4 | A trusted pre-auth session gives the sprayer 200/200 and raises per-account reach 3 → 7; the office is not relieved | `[H01]` O4–O6 |
| R5 | A NAT above the 50-device IP cap is already 90/100 denied with no id; every first denial is K1 | `[H01]` O1 |
| R6 | On MAIN, a rotating id never beats the same-mode stable id nor no id | `[H01]` O9 (now checked against both) |
| R7 | A UA-major update gives a stable browser a new DeviceFP / K5 in every mode (known owner reach 5 → 8 / 12) | `[H01]` O10 |
| R8 | On MAIN with retries, 86–88 of 100 office browsers have no successful login on each of 14 days | `[H02]` B1 (real pipeline) |
| R9 | Idealized standing: whole office only on day 9; `E_net = 5`: 70 % on day 14; cookie clearing / 7-day standing plateau 75–83 %; an attacker slows bootstrap (day 7: 64 vs 83) | `[H02 MODEL]` B2–B4, B6 |
| R10 | Excluding established traffic from the shared set raises T1 reach 13 → 22 per day (MAIN control: flat 13) | `[H02 MODEL]` B5 |
| R11 | The first bound was wrong in units and ignored accumulation over the TTL; corrected accounting in admitted verifications | `[ANALYTIC]` `SECURITY_ANALYSIS.md` §3–§4 |
| R12 | Missing-fingerprint rules are unreachable with the default resolver (F-HMCI-01) | `[CODE]` + `[TEST]` |
| R13 | Contract intent "fair under shared IPs" is not met for one NAT + one browser major; §3.2 and §7.1 conflict (F-HMCI-02) | `[CONTRACT]` + `[H01]` + `[PRIOR]` |

How to read the evidence:
* H02's NEW part is the real MAIN pipeline. Its ESTABLISHED part is idealized, so B2–B6 are upper bounds for the office.
* B5 arises in the real pipeline once the office's subjects are absent. It does not depend on how established browsers
  would themselves be treated.

## 3. Alternatives (scoped)

| Direction | Verdict | Scope of the evidence |
|---|---|---|
| A — id in `clientFingerprint` | NO-GO for this mapping on the current runtime | H01 streams (R3, R7). A future contract giving the field another meaning is not covered |
| B — `sessionDeviceId` before authentication | NO-GO for this mapping | H01 streams (R3, R4); also breaks `DEVICE_FINGERPRINT.md` §3.3 |
| `correlationId` as browser id | NO-GO for this mapping | R2 |
| D — measured candidates (EQL/EQV, PSC, OQC, GNH5, OAAA) | rejected or conditional, in their own studies | those candidates and streams only |
| D — account-scoped relief for known devices (existing `isDevicePreviouslyVerifiedForAccount` / trusted session) | **untested**; credential term 0 by construction; bootstrap and B5 still apply; #113 needs re-evaluation | `SECURITY_ANALYSIS.md` §4 |
| C — proposal of `ARCHITECTURE.md` §3 | not acceptable as specified | R9–R11; no candidate built |
| C — standing from age only, or granted at issuance | rejected | free cookie farming gives T1 value |
| Other new-contract directions (per-attempt identity #116, host-attested outcome / signed device token #104) | not studied here | out of scope |

## 4. Engineering recommendation

1. **Do not** advise Hosts to put a browser id in `clientFingerprint`, `sessionDeviceId` or `correlationId` to fix NAT. The
   measured effect is harm (R2–R4).
2. **Do not** open a candidate study of proposal C *as specified*. It needs three things first:
   * a bootstrap solution (R8–R9);
   * a design whose pool term is zero or approved (R10);
   * concurrent stock caps with a bound stated in admitted verifications (R11).
3. If the Owner wants to continue this line, the cheapest next question is the **account-scoped known-device variant**. It
   needs no new input, and its credential term is zero by construction. A paper evaluation should come first: does B5 apply
   to it, can bootstrap work, and what do the #113 trusted-session results mean for it. Whether to do this, and when, is an
   Owner / Lead decision. This study does not start it.
4. Day-0 and cookie-less collateral remain under DEC-019 for every instance-identity design examined here.

## 5. Findings on `main` and open questions

Findings concern `main`. This study does not fix them, because Runtime and canonical edits are out of scope. Each needs
an explicit disposition.

| ID | Finding | Evidence | Proposed disposition (Owner) |
|---|---|---|---|
| F-HMCI-01 | `DECISION_MATRIX.md` §2.2, §2.2.1, §2.4.1.2, §3.1 and §3.1.1 define "missing DeviceFP" scoring. With the default `DeviceIdentityResolver`, `fingerprintHash` is always a non-empty HMAC, so these paths run only with a custom resolver. Tests reach them only with hand-built DTOs | `DeviceIdentityResolver.php:39-40`, `FingerprintHasher.php:33-36`, `EvaluationPipeline.php:1619`, `:1764`, `:2693`; `RateLimiterBudgetOwnerSafetyTest.php:135`, `:153`; `AccountAuxiliaryStateIsolationTest.php:347-350` | document as custom-resolver-only, or define "absent" for the default resolver (separate issue) |
| F-HMCI-02 | `DECISION_MATRIX.md` §5.3 says "≥ 3 changes"; the code counts distinct DeviceFP per IP + UA. `DEVICE_FINGERPRINT.md` §3.2 promises NAT differentiation that §7.1 churn turns into a K2 HARD | `EvaluationPipeline.php:1174-1222`, `[H01]` O2, `[PRIOR]` #108 N3 56/61 | contract wording correction or a canonical decision; overlaps #100 |
| F-HMCI-03 | `sessionDeviceId` is accepted before authentication and without trust, and enters the DeviceFP; there is no Host guidance against that | `DeviceIdentityResolver.php:34-39`, `[H01]` O3 | Host guidance (documentation) |
| F-HMCI-04 | `USAGE_GUIDE.md` gives no guidance for `clientFingerprint`, `sessionDeviceId` or `correlationId`; each measured misuse is a plausible reading of the field name | `[H01]` O2–O8 | documentation |

Open questions:

* **O-1 / T-CNET:** count the network ceiling at `checkOnly` (an office reaches it) or at `recordFailure` (concurrency leak)?
  Not needed by the account-scoped variant's credential term.
* **O-2:** what gain, if any, is acceptable for an attacker holding valid credentials, stated in admitted verifications per
  600 s, per 24 h and per standing TTL?
* **O-3:** does establishment need an atomic "admit + write standing" Store capability?
* **O-4:** should K5 be re-keyed by instance id (survives browser updates, R7)? This touches DEC-021 evidence.
* **O-5:** standing TTL and the privacy classification of an instance cookie (Host / legal).
* **O-6:** anonymous endpoints have no outcome, so they cannot earn standing in any design examined here.
* **O-7 (new):** is any bootstrap path possible that does not pass through a MAIN-admitted login, without giving T1 value?

## 6. Reconciliation of the Lead review (PR #118, review 5539925676 on `654ea72`)

| Finding | Resolution in this revision |
|---|---|
| F118-01 bound units / accumulation | `SECURITY_ANALYSIS.md` §3–§4 rebuilt:<br>• stock `I` accumulating over the TTL;<br>• admitted verifications = distinct subjects × per-account admitted reach `r_W` / `r_D` (measured inputs cited);<br>• attempted vs admitted separated;<br>• worked example showing the first bound understated the gain;<br>• concurrent caps required.<br>`ARCHITECTURE.md` §3.2 updated |
| F118-02 unreachable day-1 office | New scenario H02: bootstrap from a fresh store over 14 days, with real MAIN admission for NEW browsers, retries, caps, cookie clearing, expiry and attacker interference. Results are tracked per day, and the idealized part is labelled. `VALIDATION_PLAN.md` V1 now requires bootstrap without idealization (G-BOOT), with V1i as a labelled upper bound only |
| F118-03 contradictory mixed-version gate | G-MIX split into G-MIX-OLD (old worker = MAIN), G-MIX-NEW (no / new id = MAIN) and G-MIX-EST (established traffic may differ only MAIN-deny → allow, within the approved envelope, under concurrency, mixed state, rollback and failures) |
| F118-04 harness not fail-closed | `report.php` now enforces:<br>• exact per-class schemas;<br>• no duplicate or unknown cells / rows, and completeness;<br>• the decision alphabet and the check → record transition;<br>• confidence per mode;<br>• every metric re-derived from the archived traces and the deterministic schedule.<br>O9 compares rotation to both the same-mode stable id and no id. `run.sh` has 20 mutation self-tests with expected exit codes (corrupt-but-length-correct trace, duplicate cell, mismatched reach / users, masked rotation, H02 continuity and masked attack gain, …) plus 2 foreign-label refusals. Goldens re-run and archived |
| F118-05 exclusivity overstated | Every NO-GO is scoped to its mapping and streams. C is described as one unimplemented proposal. D is not excluded, and an untested account-scoped D variant is named |

## 7. Owner decisions requested

1. Accept this study as evidence only, with the scoped verdicts of §1 and §3.
2. Decide whether the account-scoped known-device variant deserves a paper evaluation, and when. Proposal C as specified is
   not recommended for a candidate study.
3. Dispose of F-HMCI-01 … F-HMCI-04.
4. Confirm that no Host guidance recommending a browser id is published before a decision.
