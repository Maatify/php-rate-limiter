# Architecture — Host-Managed Client Instance Identity (HMCI)

Status: **architectural study only.** Nothing here is a Runtime, a contract or a decision. The model in §3 is an
unimplemented, unmeasured direction (`[ANALYTIC]`); its security acceptance is **UNPROVEN** (see `VALIDATION_PLAN.md`).
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
* Verdict: **NO-GO as the instance identity** (measured). Remains valid as what it is: an optional low-entropy hint.

### B — widen `sessionDeviceId` to work before authentication

* Contract: Level 3 is "Post-authentication only", "Bound to authenticated AccountID"; the trusted-session definition
  (`DECISION_MATRIX.md` §0) requires the binding. DEC-021's `isKnownForAccount` builds on it.
* Untrusted use (M2) is decision-identical to A (`[H01]` O3): LOW confidence **and** a distinct DeviceFP per browser.
* Trusted pre-auth use (M3) — what B would have to mean to give any relief — is a contract violation with measured
  harm `[H01]`: a stable sprayer reaches the verifier 200/200 (O4, trusted K1 advisory); per-account brute force reach
  3 → 7 (O6, every attempt classified as a known device); the benign NAT still loses 97/100 (O5, K2 churn is authoritative
  for trusted sessions).
* Verdict: **NO-GO** (contract-breaking and measured).

### C — an explicit, separate instance-identity contract

* One new optional input that is **not** folded into the DeviceFP and **not** a trust signal. Its value comes only from
  state the package records itself after host-proven outcomes (§3).
* Only direction that adds the missing observable without redefining an existing field. Cost: a public-contract
  addition, a new decision record, canonical changes, new bounded state (`CONTRACT_FEASIBILITY.md`).
* Verdict: **feasible direction, security UNPROVEN** (no candidate built or measured).

### D — keep the inputs, reorganise interpretation / consumption

* Every D-variant uses only today's observables. Five merged studies measured such re-interpretations of the same
  observables: EQL/EQV (#105), PSC (#107), OQC (#111), GNH5 (#115), OAAA (#116). All were NO-GO or conditional, each for a
  measured attacker gain or remaining collateral (`[PRIOR]`; summary in `SECURITY_ANALYSIS.md` §1).
* A "reserved key" inside `clientFingerprint` (e.g. treating `clientFingerprint['instance']` specially) is a hidden schema
  (`DEVICE_FINGERPRINT.md` §3.2 "the package does not … invent a … schema") and is direction C in disguise, without a
  contract.
* Verdict: **NO-GO as a NAT remedy** by the A06 indistinguishability argument and prior measurements.

### Comparison

| Criterion | A | B | C | D |
|---|---|---|---|---|
| Separates instance from composite FP | no | no | **yes** | no |
| Separates instance from trust | yes | **no** | **yes** | n/a |
| Survives a browser update | no (O10) | no | **yes** (by design) | no |
| Benign NAT effect | worse (O2) | worse (O5) | better for *returning* instances only (§4, not measured) | prior candidates: partial, with attacker gain |
| Attacker gain | none on S0 (O9) | 200/200 sprayer (O4) | bounded by earned standing; not measured | measured gains (#111, #115, #116) |
| DEC-021 bound | kept | weakened (O6) | kept by construction (§3.4) | kept |
| Public API change | none | none (semantic break) | one optional field | none |
| Consumer complexity | low | low but unsafe | low: issue a cookie / installation id, pass it | none |

**Least consumer complexity that keeps the required guarantees: C**, with a single optional field and no new Host facts
beyond the identifier itself — standing is computed by the package from outcomes the Host already reports
(`recordSuccess` / `recordFailure`). A and B are cheaper for the consumer but measured unsafe or useless; D is free for the
consumer but cannot distinguish the cases.

## 3. Proposed model (direction C) — "identity is free, standing is earned"

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

* per account: at most `E_acct` new establishments per 24 h (bounded set keyed by K4, like the device caps);
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
3. **The account side is untouched.** Per-account verifier reach is governed by K4/K5/DEC-021 for every state, so the
   DEC-021 bound holds by construction.
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
3. First successful login (`recordSuccess` with `accountId`): establishment, subject to `E_acct` / `E_net`.
4. Return visits (same or another day): `ESTABLISHED`; office colleagues' logins no longer deny it through K1/K2.
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
| Identity rotation must not buy quota | new ids are `NEW` = S0; standing needs a successful authentication and is capped per account and per network |
| Shared NAT must not punish independent users | established instances are not judged by other clients' K1 / K2 evidence; newcomers remain S0 (**day-0 and cookie-less users get no relief**) |
| Distributed attack must keep K1 / K2 / spray | NEW / ABSENT traffic keeps S0 rules; established traffic has per-instance and network-ceiling bounds; no general exemption |
| UA / session change | the instance id is the stable part; DeviceFP and session may change; instance standing and K6 history persist |
