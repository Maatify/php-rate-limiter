# Security Analysis — Host-Managed Client Instance Identity

Evidence labels as in [`CURRENT_STATE.md`](CURRENT_STATE.md), plus `[H02 MODEL]` for the bootstrap model whose NEW part
is the real MAIN pipeline and whose ESTABLISHED part is idealized. The proposal analysed is proposal C of
[`ARCHITECTURE.md`](ARCHITECTURE.md) §3 — **one unimplemented, unmeasured proposal**, not the only possible design. Claims
about it are `[ANALYTIC]` unless marked `[H02 MODEL]`, and become gates in [`VALIDATION_PLAN.md`](VALIDATION_PLAN.md).

## 1. What prior evidence already rules out

| Approach (study) | Uses which observable | Result `[PRIOR]` |
|---|---|---|
| Disable K1 spray (#108 arm C) | none | SP1n 4 → 200, SP3 7 → 1000, SP6 20 → 1200 attacker verifications |
| EQL / EQV (#105) | existing, re-interpreted | EQL: L1/L2/L3/D1 legit correlation denials 127/104/104/596 → 0, but X2/X7 attacker reach 6 → 121 |
| PSC (#107) | existing | NO-GO; D1 180/600 still denied, 90 attacker verifications sustained / 30 min |
| OQC (#111) | outcome at `recordFailure` | D2 117 → 30 but ABA_A 3 → 23, SP6 20 → 79; concurrency 16/16 through a live K1 scope |
| Trust family 2 + 3 (#113) | trusted sessions | K2 is the only cross-account IP + UA bound: TMSd 2/200 vs TMX 200/200 |
| GNH5 / TH5 (#115) | existing + network headroom | NO-GO; TMSd 2 → 200 (TH5) / 8 (GNH5) of 200, SP4 78 → 576 / 294 of 576 |
| OAAA (#116) | atomic outcome-aware admission | NO-GO (E4 / E1 / E3); co-located attacker +2 / 600 s, OWN2/OWN4 +6 |
| Two observably identical cases (#104 A06) | `(t, ip, UA, fingerprint, account, outcome)` | four legit / attacker pairs are byte-identical; any rule on those observables must hurt one of the pair |

Scope of that table: each row rejects **the specific candidate measured**, on the streams of that study. Together with #104
A06 (identical *tested* observables for four benign/attacker pairs) they show that the tested reinterpretations do not
separate office from sprayer. They do **not** prove that every reinterpretation of the existing inputs must fail. The
account-scoped use of the existing known-device fact (§4) is one such untested reinterpretation. A host-issued instance id is
a new observable, but only its *stability* is new information; its *issuance* is free to an attacker.

## 2. Threat model

Assets: the verifier (each `checkOnly` ALLOW lets the Host verify a credential), account availability, shared-network
availability, store capacity.

| Actor | Capabilities | Cannot |
|---|---|---|
| T1 credential sprayer, no valid credential | one or many IPs; any UA; obtain unlimited fresh instance ids (clear cookies, script the first visit); choose any `accountId` | complete a successful login (by definition) |
| T2 sprayer holding k valid credentials | as T1, plus k successful logins (own or stolen accounts) | succeed on other accounts without guessing |
| T3 insider in a shared NAT | as T2, on the same IP / UA as the office | — |
| T4 cookie thief | replays one victim's instance id (malware / device access) from any network | read an HttpOnly cookie through XSS alone |
| T5 targeted brute force on one account | any number of ids and IPs | bypass K4 |
| T6 holder of trusted sessions (Host-proven, #113) | valid trusted sessions on several accounts | — |
| T7 storage attacker | many ids / FPs to grow state | — |
| B1–B4 benign | office of tens–hundreds of browsers on one NAT and UA major; browser updates; cookie clearing; shared PCs | — |

Host trust assumptions (all already stated for existing inputs, none new except the first):

* H1 (new): the Host issues the instance id randomly and passes it only after its own integrity check; otherwise `null`.
* H2 (existing): `accountId` is a blind index / internal id; `recordSuccess` is reported only after a real successful
  authentication of that account.
* H3 (existing, DEC-021): `isSessionTrusted` and `isDevicePreviouslyVerifiedForAccount` are Host-proven account facts.

## 3. Identifier rotation and accumulation of standing

* **MAIN.** Rotation never gains reach: in every input mode a rotating id is ≤ the same-mode stable id **and** ≤ no id
  (`[H01]` O9: SPR_R 7/3/3/3/7 vs SPR_S 7/7/7/200/200; BRU_R 3/2/2/2/3 vs BRU_S 3/3/3/7/3). With the id inside the DeviceFP,
  rotation trips K2 churn.
* **Proposal C, fresh ids.** A fresh id is `NEW` and its *own* decisions are those of MAIN, so issuing ids buys nothing
  directly. This does **not** make the T1 effect zero: see §5, B5.
* **Proposal C, established stock (corrected, F118-01).** `E_acct` and `E_net` are *daily admission* caps, while standing
  lives for a TTL of `T` days and can be renewed by the attacker logging in to an account it controls. The number of
  established instances an actor holds is a **stock that accumulates** `[ANALYTIC]`:

  `I(t) ≤ min(k · E_acct · T,  n · E_net · T)` — `k` valid credentials, `n` K1 scopes used, `T` in days.

  With the illustrative `E_acct = 2`, `T = 30`: **60 established instances per credential**, not 2. The proposal as first
  written had no cap on the *concurrent* stock; any viable variant needs one (`M_acct` per account, `M_net` per K1), giving
  `I ≤ min(k · M_acct, n · M_net)`.
* Loss of protection to watch: removing established instances' DeviceFP from the K2 churn set must not let an actor
  rotate *appearance* under one established id; per-instance churn (distinct DeviceFP per K6) is proposed for that. Not
  measured.

## 4. Credential spray — verifier-reach bound (corrected, F118-01)

The first version bounded verifier **attempts** by `min(k·E_acct·S_inst, C_net)`. That was wrong in units: `S_inst` and
`C_net` are cardinalities of **distinct subjects**. Repeated attempts against one subject do not grow either set, so they bound
how many accounts are touched, not how many verifications are admitted. Corrected accounting `[ANALYTIC]`:

| Symbol | Meaning | Unit |
|---|---|---|
| `I` | established instances held (§3) | instances (stock) |
| `S_inst` | distinct subjects one established instance may touch | subjects per instance per 24 h (proposal) |
| `C_net` | distinct subjects of all established instances on one K1 | subjects per K1 per its window (window and counting point unresolved: T-CNET) |
| `r_W(acct)`, `r_D(acct)` | verifications the **account rules** (K4, budget, Anti-Equilibrium, DEC-021) admit on one account | admitted `checkOnly` per 600 s / per 24 h |
| attempted | requests sent | unbounded — the attacker chooses |
| admitted | `checkOnly` ALLOW = verifier reached | the security metric |

Additional admitted verifications over MAIN, for an actor with established instances:

`Δ_W ≤ min(I · S_inst, C_net) · r_W(acct)`  and  `Δ_D ≤ min(I · S_inst, C_net,D) · r_D(acct)`  plus the pool term of §5 (B5).

Measured inputs for `r` on MAIN (an unknown device on one account, no K1 help):
* per 600 s, from the archived BRU_S M0 trace (one attempt every 30 s): **2, 0, 1, 0, 0** admitted in the first five windows
  `[H01]` (`expected/summary.md`). This is an observed sequence, not a proven maximum.
* per 24 h: **37–39** for an unknown device on the Login streams of the DEC-021 measurement `[PRIOR]` (measured on S0; DEC-021
  states it does not change the unknown-device path).

Illustration, proposal as first written (`E_acct = 2`, `T = 30`, `S_inst = 2`, no concurrent cap, `C_net` absent):
`I = 60` per credential ⇒ up to 120 distinct subjects per day ⇒ `Δ_D` up to **≈ 120 × 39 ≈ 4,700** admitted verifications per
day per credential. That is **not acceptable**, and it shows the original bound understated the gain by orders of
magnitude. With a concurrent cap `M_acct = 2`, it is still `4 × 39 ≈ 156` per day per credential.

**Account-scoped variant (C-acct, not measured).** If an established instance's relief applies **only to the account(s) it
established with** (`S_inst` = its own establishing accounts, no cross-account allowance), it touches no new subjects, and the
credential term is **0**: attacking one's own account is pointless. The relief then matches the benign need (each employee
logs in to their own account from their own browser), and per-account reach stays governed by K4/K5/DEC-021. This variant
is close to an **existing-input** design: the Host already can assert `isDevicePreviouslyVerifiedForAccount` for a browser
that previously authenticated to that account (DEC-021). A runtime that exempted *known-for-this-account* devices from
network-scope collective K1/K2 blocks would be a direction-D variant **not covered** by any measured study (see
`REPORT.md` §3). It is listed as untested; the #113 result (TMULTI-S ≡ N12T; TMX 200/200 with Host-tampered trust) must be
re-evaluated against it, since a per-account scope changes what that attacker can gain.

| Actor | MAIN | Proposal C as specified `[ANALYTIC]` + `[H02 MODEL]` |
|---|---|---|
| T1 (no credential) | `[H01]` SPR_S 7/200 over 20 min; `[H02]` ATKM 13 per day (71 attempts) | own decisions = MAIN, **but** pool term B5: 13 → up to 22 per day as the office is established |
| T2 / T3 with k credentials | as T1 | `Δ_D ≤ min(I·S_inst, C_net,D) · r_D` with `I` accumulating over `T` (above) — unbounded in practice without `M_acct` / `M_net` |
| T6 trusted sessions (#113) | TMSd 2/200, TMX 200/200 | unchanged: HMCI does not touch trusted K1 advisory |

Concurrency: `S_inst` and a `checkOnly`-counted `C_net` would use the existing atomic bounded-set primitive, like S0 spray;
establishment at `recordSuccess` would be bounded by an atomic `E_acct` set. Not measured.

## 5. Shared NAT — can the office reach the relief? (F118-02, measured with H02)

The first version assumed returning office browsers are established. H02 tests that assumption from a fresh store. NEW
browsers run through the **real** MAIN pipeline with retries. Established browsers are **idealized** (assumed admitted and
absent from the shared sets), so every H02 number is an *upper bound* for the office.

| Result `[H02]` | Value |
|---|---|
| B1 — on MAIN, browsers with no successful login at all, per day (100 browsers, ≤ 3 retries each) | 86–88 every day |
| B2 — idealized, uncapped: established at end of day 1…14 | 13, 27, 39, 50, 63, 73, 83, 94, 100 — full office only on **day 9** |
| B3 — `E_net = 5` per day | 5, 10, …, 70 on day 14 (linear, the cap binds) |
| B4 — 10 % cookie clearing per day / fixed 7-day standing | plateau 75–79 / 79–83; never the whole office |
| B6 — a T1 sprayer on the same NAT | slower bootstrap: 64 vs 83 established on day 7 |
| **B5 — T1 reach as the office leaves the shared pool** | **13 → 22 admitted per day (ATK), vs a flat 13 on MAIN (ATKM)** |

Consequences:

* **Bootstrap is the binding constraint.** The way into standing is a successful login through MAIN. On MAIN, about 87 % of
  this office cannot log in on a given day, so the relief reaches the office only gradually, over days. Every per-network
  cap (`E_net`), cookie clearing or expiry stretches or caps it.
* **B5 contradicts the original gate G-T1 (Δ = 0).** On MAIN, the office's own login subjects keep K1 saturated, which also
  stops the sprayer. Removing established traffic from the shared set returns that capacity to the attacker. Any design
  that excludes established subjects from the network spray set has this effect. It is a property of the proposal, not
  of the idealization.
* Mixed office + insider T3 holding credentials: as §4. A `C_net` breach would block all established instances on the K1
  (fail-safe, collateral returns).

**Open tension T-CNET (unresolved).** `C_net` is the only bound on the *sum* over established instances on one network.
* Counted as distinct subjects at `checkOnly` (concurrency-safe, like S0 spray): an office of `N` established users reaches
  it once `N ≥ C_net`. So `C_net` must exceed the largest office to give relief, and it then admits that many subjects to an
  insider.
* Counted as **failed** subjects at `recordFailure`: it separates office from sprayer, but inherits the outcome-time
  concurrency leak measured for OQC (#111: 16/16 through a live scope) and the record-only / check-only Host limits of #116.

The account-scoped variant above does not need `C_net` for the credential term, because it has no cross-account allowance.
It still has the B5 pool term and the bootstrap constraint.

Not assumed: that many browsers means attack, or that distinct ids mean distinct people.

## 6. Trust boundaries

* The instance id is **not** a trust signal and must never set `isSessionTrusted`, raise `confidence`, or make a device
  `isKnownForAccount`. B-trusted (M3) shows what happens otherwise: sprayer 200/200, per-account reach 3 → 7 `[H01]` O4,
  O6.
* `correlationId` keeps its spray-subject role; it must not become the instance id: M4 sprayer 200/200 `[H01]` O7, no
  benign gain O8.
* DEC-021 is preserved because no account-scoped rule reads standing (architecture §3.3, §3.4). Gate G-D21.

## 7. Privacy

* The instance id is a persistent identifier. `DEVICE_FINGERPRINT.md` §10 ("No permanent identifiers", "Mandatory TTL",
  "No cross-context tracking") and `KEY_STRATEGY.md` §9 apply: the package must store only domain-separated HMACs of it,
  per policy and environment, with TTL on every record (standing TTL, set TTLs), and never log it raw.
* `DEVICE_FINGERPRINT.md` §3.2 currently says a client ID "MUST NOT be treated as stable identity". Direction C treats the
  instance id as stable *for rate-limit state only*. That is a contract change, not an interpretation
  (`CONTRACT_FEASIBILITY.md` §2).
* Web cookie consent / ePrivacy classification of a security cookie is a Host / legal question, outside the package;
  listed as an Owner question.

## 8. Storage

New state per policy (all bounded, all HMAC, all TTL) `[ANALYTIC]`:

| State | Bound |
|---|---|
| K6 score / block | one per observed instance; `NEW` instances only create K6 state when they act — must be capped per K1 like the device cap (proposal: reuse the 50 / 900 s IP cap semantics), else T7 grows state with fresh ids |
| per-instance spray set | cap `S_inst` members |
| per-instance churn set | cap 3 |
| per-instance network set (replay) | cap `R` |
| establishment sets | `E_acct` per account, `E_net` per K1, fixed windows |
| established stock | concurrent caps `M_acct` per account and `M_net` per K1 (required, §3); without them the stock grows to `k · E_acct · T` |
| standing record | one per established instance, TTL |

Rule `KEY_STRATEGY.md` §8 "Per-request random keys" forbidden: satisfied only if `NEW` instance state is capped; an uncapped
K6 for every fresh id would violate it. Gate G-STOR.

## 9. Failure, Redis, concurrency

* Backend failure on any new read/write must follow DEC-015 typed failure semantics (fail-closed for authentication
  policies) — never "treat as established".
* Pipeline is not transactional (unchanged); establishment must be a single atomic bounded-set admission so a partial
  failure cannot leave standing without its cap accounting.
* Mixed versions: an old worker ignores `clientInstanceId` (does not know the field) and applies S0 ⇒ only availability
  differs, never weaker security. Rollback: new keys are ignored; established users fall back to S0 collateral.
  Requires measurement (gate G-MIX-*, `VALIDATION_PLAN.md` §3). An old worker deliberately makes the decision MAIN makes,
  including the denial an upgraded worker would lift for an established browser. That difference is the expected effect,
  not a security regression.
