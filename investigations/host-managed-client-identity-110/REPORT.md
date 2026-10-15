# Report — Host-Managed Client Instance Identity for shared NAT (#110)

Baseline `main@a6c79f45e79171e26031fc720822428e09e6e36c`. DEC-019 ACTIVE, DEC-021 ACTIVE, DEC-020 not approved. #97, #100,
#106, #110 stay OPEN. **Nothing here is a Runtime approval, a decision or a fix.**

## 1. Executive summary

* The runtime has **no client-instance identity**. It folds instance, appearance (UA major, hints) and session into one
  DeviceFP. So it cannot tell an office of stable browsers from one browser that keeps changing its fingerprint. Earlier
  studies proved those two cases are observably identical with today's inputs (#104 A06).
* A host-issued browser id carried through **today's** inputs does not help, measured on post-DEC-021 `main` (`[H01]`):
  * In `clientFingerprint` or in an untrusted `sessionDeviceId`, NAT collateral **rises**: 90 → 97 of 100 browsers
    denied, 8 → 10 of 12. Each browser becomes a K2 churn member.
  * As a pre-authentication trusted session, a credential sprayer reaches the verifier **200/200** (instead of 7).
    Per-account brute force reaches it 3 → 7 times.
  * As `correlationId`, the sprayer also gets **200/200**, and the office gains nothing.
  * A browser update still splits the identity.
* Only one direction survives: **C**, an explicit, separate `clientInstanceId`, under the rule "identity is free,
  standing is earned".
  * A new or absent id is judged exactly as today, so rotation can never buy quota.
  * Value comes only after a successful authentication, capped per account and per network.
  * Even then, the instance is judged by its own cross-account bounds instead of other clients' K1/K2 evidence.
  * Account rules and DEC-021 are untouched.
* C needs a public-contract addition, canonical changes and a new ADR. **Its security is unproven.** It also has one
  unresolved design tension (T-CNET, §5) that decides whether it can be safe.
* It gives **no relief** to a browser on its first day, or after its cookies are cleared.

**Verdict on the architectural direction: `INCONCLUSIVE`.** C is feasible and is the only direction not refuted. It is not
GO, because no candidate was built or measured, and T-CNET is open. A, B, `correlationId` reuse and D are **NO-GO**. This
is a verdict on direction feasibility, not a Runtime acceptance.

## 2. Results established by this study

| # | Result | Kind |
|---|---|---|
| R1 | Field responsibilities, pre/post-auth availability, DeviceFP derivation, K1–K5 scope, limits (`CURRENT_STATE.md` §1–§9) | `[CODE]` / `[CONTRACT]` |
| R2 | `correlationId` is a spray subject; reusing it as browser id disables spray for a stable-id sprayer | `[TEST]` + `[H01]` O7, O8 |
| R3 | Browser id in `clientFingerprint` (A) or untrusted `sessionDeviceId` (B) increases NAT collateral; the two are decision-identical | `[H01]` O2, O3 |
| R4 | Trusted pre-auth session (B with trust) = sprayer 200/200, per-account reach 3 → 7, office not relieved | `[H01]` O4, O5, O6 |
| R5 | A NAT above the 50-device IP cap is already 90/100 denied without any id, first denial always K1 | `[H01]` O1 |
| R6 | On MAIN, id rotation never gains reach over a stable id or no id | `[H01]` O9 |
| R7 | A UA-major update gives a stable browser a new DeviceFP / K5 in every mode (known owner reach 5 → 8 / 12) | `[H01]` O10 |
| R8 | The missing-fingerprint rules are unreachable with the default resolver (F-HMCI-01) | `[CODE]` + `[TEST]` |
| R9 | Contract intent "fair under shared IPs" is not met for one NAT + one browser major; §3.2 and §7.1 conflict (F-HMCI-02) | `[CONTRACT]` + `[H01]` + `[PRIOR]` |

Not established (`[ANALYTIC]` only): every property of direction C (`ARCHITECTURE.md` §3, `SECURITY_ANALYSIS.md` §3–§9).

## 3. Alternatives

| Direction | Verdict | Basis |
|---|---|---|
| A `clientFingerprint` | NO-GO as instance identity | R3, R7; still valid as a low-entropy hint |
| B `sessionDeviceId` before auth | NO-GO | R3, R4; breaks `DEVICE_FINGERPRINT.md` §3.3 and DEC-021's trust basis |
| `correlationId` reuse | NO-GO | R2 |
| C explicit `clientInstanceId` with earned standing | INCONCLUSIVE — feasible, unproven | `ARCHITECTURE.md` §3, `CONTRACT_FEASIBILITY.md` |
| D reinterpret existing inputs | NO-GO as NAT remedy | #104 A06 indistinguishability; #105 / #107 / #111 / #115 / #116 measured |
| C variant: standing from age only (no authentication) | rejected | cookie farming is free; gives T1 value — breaks G-T1 |
| C variant: standing granted at issuance | rejected | identical to "more ids = more quota" |
| C variant: id folded into DeviceFP | rejected | that is A (R3) |

## 4. Engineering recommendation

1. **Do not** advise Hosts to put a browser id in `clientFingerprint`, `sessionDeviceId` or `correlationId` to fix NAT.
   The measured effect is harm (R2–R4).
2. If the Owner wants to pursue the idea, pursue **C only**, in this order:
   * (a) resolve T-CNET on paper;
   * (b) Owner sets the quantified bounds (`E_acct`, `E_net`, `S_inst`, `C_net`, `R`, TTL) and the acceptable T2/T3 gain;
   * (c) build an isolated candidate and run `VALIDATION_PLAN.md` V1–V17 against MAIN;
   * (d) only then draft the ADR and canonical changes.
3. Accept honestly that C cannot remove day-0 collateral (new or cookie-less browsers stay at MAIN behaviour). Day-0 and
   K1 spray for newcomers remain DEC-019 territory.

## 5. Findings and open questions

Findings concern `main`; this study does not fix them (Runtime and canonical edits are out of scope). Per the Owner rule,
each needs an explicit disposition.

| ID | Finding | Evidence | Proposed disposition (Owner) |
|---|---|---|---|
| F-HMCI-01 | `DECISION_MATRIX.md` §2.2, §2.2.1, §2.4.1.2, §3.1, §3.1.1 define "missing DeviceFP" scoring (K2 +4/+6, repeated K4 +6/+8, budget eligibility). With the default `DeviceIdentityResolver`, `fingerprintHash` is always a non-empty HMAC, so these paths run only with a custom resolver. Tests reach them only with hand-built DTOs. | `DeviceIdentityResolver.php:39-40`, `FingerprintHasher.php:33-36`, `EvaluationPipeline.php:1619`, `:1764`, `:2693`; `RateLimiterBudgetOwnerSafetyTest.php:135`, `:153`; `AccountAuxiliaryStateIsolationTest.php:347-350` | decide: document as custom-resolver-only, or define "absent" for the default resolver (Runtime change, separate issue) |
| F-HMCI-02 | `DECISION_MATRIX.md` §5.3 says "≥ 3 changes"; the code counts distinct DeviceFP per IP + UA. `DEVICE_FINGERPRINT.md` §3.2 promises the client ID "improves differentiation under NAT/VPN", while §7.1 churn turns that differentiation into a K2 HARD. | `EvaluationPipeline.php:1174-1222`, `[H01]` O2, `[PRIOR]` #108 N3 56/61 | contract wording correction or a canonical decision; overlaps #100 |
| F-HMCI-03 | `DEVICE_FINGERPRINT.md` §3.3 restricts `sessionDeviceId` to post-auth, account-bound use. The runtime accepts it without `accountId` or trust and folds it into DeviceFP (LOW but distinct). No Host guidance warns against pre-auth use. | `DeviceIdentityResolver.php:34-39`, `[H01]` O3 | Host guidance (documentation), no Runtime change proposed |
| F-HMCI-04 | `USAGE_GUIDE.md` gives no guidance for `clientFingerprint`, `sessionDeviceId` or `correlationId`. The measured misuses (M1–M4) are each a plausible Host reading of the field names. | `docs/guides/USAGE_GUIDE.md` (no matches), `[H01]` O2–O8 | documentation |

Open questions:

* **O-1 / T-CNET:** count the network ceiling at `checkOnly` (office hits it) or at `recordFailure` (concurrency leak)?
  This decides whether C can be both fair and safe.
* **O-2:** what T2/T3 gain, if any, is acceptable for an attacker who holds valid credentials?
* **O-3:** does establishment need an atomic "admit + write standing" Store capability, or is the two-step fail-safe
  acceptable?
* **O-4:** should K5 be re-keyed by instance id (survives browser updates; R7) or stay DeviceFP-based? This touches DEC-021
  evidence and needs its own measurement.
* **O-5:** standing TTL and the privacy classification of the instance cookie (Host / legal).
* **O-6:** how does an anonymous endpoint (no `accountId`, no outcome) benefit? In the model it cannot establish standing,
  so it stays at MAIN.

## 6. Owner decisions requested

1. Accept this study as evidence only, and record A, B, `correlationId` reuse and D as NO-GO directions for the NAT
   problem.
2. Decide whether direction C is worth a candidate study: yes / no / after other #110 work.
3. If yes, set the parameters and the acceptable T2/T3 gain (O-1, O-2, O-5), or ask for a T-CNET design note first.
4. Dispose of F-HMCI-01 … F-HMCI-04: separate issues, documentation follow-up, or accepted as designed.
5. Confirm that no Host guidance recommending a browser id is published before a decision on C.
