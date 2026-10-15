# Architecture — Host-Managed Client Instance Identity (HMCI)

Status: **architectural study only.** Nothing here is a Runtime, a contract or a decision. §3 is **one** unimplemented
proposal (`[ANALYTIC]`). Its bootstrap and its effect on the shared pool were examined with the H02 model (`[H02 MODEL]`,
ESTABLISHED part idealized), which found structural problems. Its security acceptance is **UNPROVEN** and it is not
recommended as specified (see `REPORT.md`, `VALIDATION_PLAN.md`).
Evidence labels as in [`CURRENT_STATE.md`](CURRENT_STATE.md).

## 1. Three things that today share one value

The shipped runtime folds three different concepts into the single DeviceFP (`CURRENT_STATE.md` §3):

| Concept | Question it answers | Who can prove it | Today |
|---|---|---|---|
| **Client instance identity** | "Is this the same browser / app installation as before?" | Host (it issued the identifier) — proves *issuance*, not independence of people | not represented; approximated by the DeviceFP |
| **Composite fingerprint** | "What does this client look like right now?" (UA major, hints) | nobody; it is observed and changes naturally | DeviceFP (LOW / MEDIUM) |
| **Account trust** | "Has this client proven a relation with this account?" | Host, per account (trusted session, previously verified) | `isSessionTrusted` + `sessionDeviceId`, `isDevicePreviouslyVerifiedForAccount` (DEC-021) |

The NAT problem is that the runtime has no instance identity, so it cannot tell **"many stable instances"** (an office)
from **"one instance changing appearance"** (an evader): both are "many DeviceFP values under one IP + UA". The #104 A06 pairs
proved that, for the observables the runtime has, the two cases are byte-identical (`[PRIOR]`
`workstream-b-anti-evasion/README.md:128-134`). A new observable is therefore necessary, not merely a new rule.

The observable must not be confused with trust: a host-issued identifier proves only that **the Host issued it**. An
attacker obtains as many as they want by clearing cookies or by scripting the first visit. Any value the identifier
carries must therefore be **earned**, not granted at issuance.

## 2. The four directions

### A — carry the instance id in `clientFingerprint`

* Contract: allowed as a Level 2 input ("Client-generated random ID", `DEVICE_FINGERPRINT.md` §3.2) but "MUST NOT be treated
  as stable identity".
* Runtime effect: the id enters the composite DeviceFP, confidence becomes MEDIUM.
* Measured `[H01]`: NAT collateral **increases** (OFF12 8 → 10, OFF100 90 → 97 of 100 users), because each browser is now a
  distinct K2 churn member (O2); a browser update still splits the identity (O10).
* The id is never available as itself: it is a hash input mixed with UA and hints, so the package cannot key anything by
  "this browser" independently of its appearance.
* Verdict, scoped: **NO-GO for this mapping on the current runtime** (measured on the H01 streams). It does not exclude a
  future contract that gives a client id in `clientFingerprint` a different meaning — that would be a new contract (C-like).
  The id remains valid as what it is today: an optional low-entropy hint.

### B — widen `sessionDeviceId` to work before authentication

* Contract: Level 3 is "Post-authentication only", "Bound to authenticated AccountID"; the trusted-session definition
  (`DECISION_MATRIX.md` §0) requires the binding. DEC-021's `isKnownForAccount` builds on it.
* Untrusted use (M2) is decision-identical to A (`[H01]` O3): LOW confidence **and** a distinct DeviceFP per browser.
* Trusted pre-auth use (M3) — what B would have to mean to give any relief — is a contract violation with measured
  harm `[H01]`: a stable sprayer reaches the verifier 200/200 (O4, trusted K1 advisory); per-account brute force reach
  3 → 7 (O6, every attempt classified as a known device); the benign NAT still loses 97/100 (O5, K2 churn is authoritative
  for trusted sessions).
* Verdict, scoped: **NO-GO for this mapping** (contract-breaking under `DEVICE_FINGERPRINT.md` §3.3, and measured on the H01
  streams).

### C — an explicit, separate instance-identity contract

* One new optional input that is **not** folded into the DeviceFP and **not** a trust signal. Its value comes only from
  state the package records itself after host-proven outcomes (§3).
* Adds an observable without redefining an existing field. Cost: a public-contract addition, a new decision record, canonical
  changes, new bounded state (`CONTRACT_FEASIBILITY.md`).
* §3 describes **one** proposal of this kind. It is unimplemented and unmeasured, and has open problems: the bootstrap
  (H02 B1–B4), the pool term (H02 B5), the credential term (`SECURITY_ANALYSIS.md` §4) and T-CNET. It is not shown to be
  the only viable design.

### D — keep the inputs, reorganise interpretation / consumption

* Every D-variant uses only today's observables. Five merged studies measured such re-interpretations of the same
  observables: EQL/EQV (#105), PSC (#107), OQC (#111), GNH5 (#115), OAAA (#116). All were NO-GO or conditional, each for a
  measured attacker gain or remaining collateral (`[PRIOR]`; summary in `SECURITY_ANALYSIS.md` §1).
* A "reserved key" inside `clientFingerprint` (e.g. treating `clientFingerprint['instance']` specially) is a hidden schema
  (`DEVICE_FINGERPRINT.md` §3.2 "the package does not … invent a … schema") and is direction C in disguise, without a
  contract.
* Scoped result: the **measured** D candidates were each rejected or left conditional, and A06 shows that the *tested*
  observables cannot separate four benign / attacker pairs. That rejects those candidates; it is **not** a proof that every
  reinterpretation of today's inputs must fail.
* Untested D variant identified by this revision: use the **existing** Host fact `isDevicePreviouslyVerifiedForAccount`
  (or a trusted session) to exempt a device from network-scope collective K1 / K2 blocks **for that account only**. The
  Host already knows which browser authenticated to which account. Such relief gives an instance **zero new cross-account
  subjects**. It is **not** zero attacker gain: it can raise admitted verifications against the associated account, multiple
  known sessions multiply that path, and it has the B5 pool effect and the bootstrap problem (`SECURITY_ANALYSIS.md` §4).
  It needs measurement against the #113 trusted-session streams and DEC-021. Not measured.

### Comparison

| Criterion | A | B | C | D |
|---|---|---|---|---|
| Separates instance from composite FP | no | no | **yes** | no |
| Separates instance from trust | yes | **no** | **yes** | n/a |
| Survives a browser update | no (O10) | no | **yes** (by design) | no |
| Benign NAT effect | worse (O2) | worse (O5) | only for established instances; bootstrap takes days and is capped / eroded (H02 B1–B4, ESTABLISHED idealized) | measured candidates: partial, with attacker gain; account-scoped variant untested |
| Attacker gain | none on MAIN (O9) | 200/200 sprayer (O4) | T1 pool term observed in the H02 model (B5: 13 → 22 / day); subject stock accumulates over the standing TTL; admitted-verification gain not bounded (`SECURITY_ANALYSIS.md` §4) | measured gains (#111, #115, #116); account-scoped variant: zero new cross-account subjects, other gains unmeasured |
| DEC-021 / per-account reach | kept | weakened (O6) | account rules unchanged, but per-account admitted reach vs MAIN **not shown equal** once K1 / K2 denials are removed for established traffic | measured candidates kept it; account-scoped variant: same-account delta unmeasured |
| Public API change | none | none (semantic break) | one optional field | none |
| Consumer complexity | low | low but unsafe | low: issue a cookie / installation id, pass it | none |

**Consumer complexity versus guarantees.** No option is shown to keep the required guarantees:
* The A and B mappings and `correlationId` reuse are measured unsafe or useless on the current runtime.
* The C proposal needs one optional field, but its guarantees are unproven and partly contradicted by H02.
* The account-scoped D variant needs no new input (the Host already supplies the known-device fact), but it is unmeasured
  and not zero-gain.

The study therefore does **not** name a least-complex safe option. It names the evidence each candidate still needs
(`VALIDATION_PLAN.md`).

## 3. One proposal of direction C — "identity is free, standing is earned"

This section records the proposal as reviewed, with the problems found since. It is a design under study, not a
recommendation.

### 3.1 Inputs

* `clientInstanceId: ?string` — opaque, random (≥ 128 bits), **issued and integrity-checked by the Host** (signed /
  MAC'd HttpOnly Secure cookie on Web; installation id on mobile). The Host passes it only when its own integrity check
  passes; otherwise `null`. The package never trusts its content, only its stability.
* Unchanged: `ip`, `ua`, `clientFingerprint`, `accountId`, `correlationId`, `sessionDeviceId`, `isSessionTrusted`,
  `isDevicePreviouslyVerifiedForAccount`.
* Not added: any Host-supplied "standing", "age" or "trust" for the instance. The package derives standing itself.

### 3.2 Derived states of an instance (per policy, keyed by HMAC of the id)

| State | How entered | Treatment |
|---|---|---|
| `ABSENT` | `clientInstanceId === null` | **exactly S0-on-main** (bit-identical decisions required, `VALIDATION_PLAN.md` G-S0) |
| `NEW` | id present, no standing record | **exactly S0-on-main** for every shared rule; per-instance observations recorded (bounded) |
| `ESTABLISHED` | a `recordSuccess` with `accountId` on this instance, admitted under the establishment caps | per-instance rules replace the *network-shared* K1/K2 judgments for this instance (§3.3); all account rules unchanged |
| `SUSPENDED` | an established instance seen from too many IP prefixes, or tripping its own bounds | treated as `NEW` until its standing expires |

Establishment is the only way to gain anything. It requires a **successful authentication** reported by the Host, i.e.
knowledge of a valid credential for some account. It is bounded:

* per account: at most `E_acct` new establishments per 24 h (bounded set keyed by K4, like the device caps). This is a
  *daily admission* cap. Over a standing TTL of `T` days the established **stock** reaches `k · E_acct · T`. A concurrent stock
  cap (`M_acct`, `M_net`) is required and was missing from the first version (`SECURITY_ANALYSIS.md` §3, F118-01);
* per network scope: at most `E_net` establishments per K1 per 24 h;
* standing expires (TTL, e.g. 30 days) — `DEVICE_FINGERPRINT.md` §10 requires TTL on all fingerprint data.

Establishment is **network standing only**. It is not "known for account": `isKnownForAccount` remains exclusively the
Host facts of DEC-021 (§3.4).

### 3.3 Key & Consumption Matrix

New key: **K6 = policy + instance** (`HMAC(…:k6:v1:{env}:{HMAC(clientInstanceId)})`). The matrix shows, per state, which
key consumes and which enforces.

| Rule / key | ABSENT / NEW / SUSPENDED | ESTABLISHED |
|---|---|---|
| Pre-attempt active blocks | K1, K2, K3, K4, K5 (S0) | K4, K5, K3, **K6**; K1 / K2 blocks created by *other* clients are **not** applied to this instance |
| K1 credential spray (network) | S0: distinct subjects per K1 at `checkOnly`, cap 5 | subject **not** added to the network K1 set; instead **per-instance spray**: distinct subjects per K6 at `checkOnly`, cap `S_inst` (proposal 2 per 24 h) → HARD on K6 |
| Network ceiling for established instances | — | distinct subjects of *established* instances per K1, cap `C_net` → HARD on a separate "established" K1 scope that applies to all established instances on that K1 (bounds an attacker who holds several established instances). **Whether it counts attempted subjects at `checkOnly` or failed subjects at `recordFailure` is unresolved** — each choice has a measured or structural weakness (`SECURITY_ANALYSIS.md` §5, tension T-CNET) |
| K2 churn | S0: distinct DeviceFP per K2 | DeviceFP members of established instances are **not** added to the K2 population set; **per-instance churn**: distinct DeviceFP per K6, cap 3 / 600 s → HARD on K6 (a single browser cycling appearance is still evasion) |
| Dilution / K3 | S0 | S0 (DeviceFP-based, unchanged) |
| Instance replay across networks | — | distinct K1 per K6 within 600 s ≥ `R` → SUSPENDED |
| K4 / K5 scoring, thresholds, budget, Anti-Equilibrium, flood, distributed account | S0 | **unchanged** (same keys, same thresholds) |
| DEC-021 known-device classification and allowance | Host facts only | **unchanged**; establishment never sets `isKnownForAccount` |

Design properties `[ANALYTIC]`:

1. **No gain from issuing identifiers.** A fresh identifier is `NEW` and judged exactly as today. Rotating it can never
   be better than not sending it.
2. **No general exemption.** An established instance loses only the *collective* judgment by others' traffic; it keeps a
   cross-account bound of its own (per-instance spray, `S_inst`) and a shared ceiling (`C_net`). This answers the #113
   result that removing K2 for trusted traffic removes the only cross-account IP + UA control (TMSd 2/200 vs TMX 200/200).
3. **The account rules are untouched.** K4 / K5 / DEC-021 apply in every state. That does **not** make per-account admitted
   reach equal to MAIN: an established instance no longer meets shared K1 / K2 denials, so it may reach its own account's
   verifier more often than on MAIN. The same-account delta must be measured (gate G-D21, `SECURITY_ANALYSIS.md` §4).
4. **Absent = S0.** A Host that does nothing, an old Host, a broken cookie and a rollback all fall back to S0-on-main.

### 3.4 Separation from trust and from DEC-021

| | Instance standing (HMCI) | Trusted session (`sessionDeviceId` + `isSessionTrusted`) | Previously verified (`isDevicePreviouslyVerifiedForAccount`) |
|---|---|---|---|
| Who asserts | package, from recorded outcomes | Host | Host |
| Scope | network-level judgment of one instance | one account | one account |
| Effect on K1/K2 | replaces *collective* K1/K2 by per-instance bounds | K1 advisory (unchanged) | none |
| Effect on K4/K5 | **none** | K5 classification, budget floor | K5 classification |
| Survives logout | yes (instance outlives session) | no | Host decides |

A trusted session is issued **after** login and is a different value from the instance id. A Host may record which
instance a session was issued to, but the package must not infer one from the other.

## 4. Lifecycles

### 4.1 Browser / installation

1. First visit: Host issues `clientInstanceId` (Web: HttpOnly, Secure, SameSite cookie, MAC'd; Mobile: installation id
   stored by the app). Package state: `NEW` (nothing stored until observed, then bounded).
2. Anonymous traffic and the first login attempts: judged as S0. The same instance trying several accounts is counted on K6
   (observation) and on K1 (S0 enforcement).
3. First successful login (`recordSuccess` with `accountId`): establishment, subject to `E_acct` / `E_net`. **That login must
   first pass MAIN.** In H02, about 87 of 100 office browsers have no successful login on a given day, so this step is the
   bottleneck (B1).
4. Return visits after establishment: `ESTABLISHED`; office colleagues' logins no longer deny it through K1/K2. Under the
   idealized H02 model, a 100-browser office reaches this state in full only on day 9 without caps. With `E_net = 5` per day
   it is 70 % after 14 days. With 10 % daily cookie clearing or 7-day standing it plateaus near 75–83 % (B2–B4).
5. Browser update: DeviceFP changes; the instance id does not; standing is kept; per-instance churn sees one change (not
   churn). K5 history still follows DeviceFP unless K5 is re-keyed (open option O-4 in `REPORT.md`).
6. Cookie cleared / app reinstalled: a new id → `NEW`; the old standing is orphaned and expires. No quota is created.
7. Theft / replay of the cookie on other networks: distinct K1 per K6 → `SUSPENDED`.

### 4.2 Account (unchanged)

K4 accumulates failures from every instance; DEC-021 allowance and K5 thresholds bound known devices; budget and
Anti-Equilibrium as today. An instance switching the targeted account keeps its K6 history (this is what the anonymous
phase lacks today, `CURRENT_STATE.md` §8).

### 4.3 Session (unchanged)

The Host issues `sessionDeviceId` after authentication and binds it to the account; `isSessionTrusted` stays a Host fact.
Logout ends the session, not the instance.

## 5. Questions of the brief, answered at architecture level

| Situation | Answer in the model |
|---|---|
| Before login, attempts follow the browser even when the targeted account changes | K6 observes every attempt of the instance regardless of `accountId`; per-instance spray counts subjects per instance |
| After login, browser risk continues while account / known-device limits apply | K6 keeps instance history; K4 / K5 / DEC-021 unchanged and additive |
| Identity rotation must not buy quota | a new id's own decisions are MAIN's. Standing needs a successful authentication; daily caps alone let the stock accumulate over the TTL, so concurrent stock caps are required (`SECURITY_ANALYSIS.md` §3) |
| Shared NAT must not punish independent users | only once established, and establishment is slow and partial (H02 B1–B4). Day-0 and cookie-less users get no relief |
| Distributed attack must keep K1 / K2 / spray | NEW / ABSENT traffic keeps MAIN rules, **but** removing established traffic from the shared spray set raised a credential-less sprayer's reach in the H02 model (B5: 13 → 22 per day). The proposal does not keep the K1 bound unchanged |
| UA / session change | the instance id is the stable part; DeviceFP and session may change; instance standing and K6 history persist |
