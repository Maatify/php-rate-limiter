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
  established instances an actor holds is a **stock that accumulates** `[ANALYTIC]`, assuming the caps are enforced
  atomically (hypothesis H-a1, §4):

  `I(t) ≤ min(k · E_acct · T,  n · E_net · T)` — `k` valid credentials, `n` K1 scopes used, `T` in days.

  With the illustrative `E_acct = 2`, `T = 30`: **60 established instances per credential**, not 2. The proposal as first
  written had no cap on the *concurrent* stock; any viable variant needs one (`M_acct` per account, `M_net` per K1), giving
  `I ≤ min(k · M_acct, n · M_net)`.
* Loss of protection to watch: removing established instances' DeviceFP from the K2 churn set must not let an actor
  rotate *appearance* under one established id; per-instance churn (distinct DeviceFP per K6) is proposed for that. Not
  measured.

## 4. Credential spray — what is bounded and what is not (F118-01, F118-R2-01)

The first version bounded verifier **attempts** by `min(k·E_acct·S_inst, C_net)`. That was wrong in units: `S_inst` and
`C_net` are cardinalities of **distinct subjects**, not counts of admitted verifications. The second version still wrote an
inequality for admitted verifications, using reach values taken from samples. That is also not a proven bound (Lead review
F118-R2-01). This section now keeps three kinds of statement strictly apart:

| Kind | What it says | Status |
|---|---|---|
| **(a) Subject-cardinality bounds** | how many distinct accounts the established instances can touch | proven **only under stated hypotheses** (below); the caps themselves are unimplemented and unmeasured |
| **(b) Admitted-verification bounds** | how many `checkOnly` ALLOWs an actor obtains | **none proven** in this study |
| **(c) Exposure estimates** | sample-based illustrations of possible scale | **not guaranteed**; neither ceilings nor floors; not usable for Owner limit approval |

Symbols:

| Symbol | Meaning | Unit |
|---|---|---|
| `I` | established instances held (§3) | instances (stock) |
| `S_inst` | distinct subjects one established instance may touch | subjects per instance per 24 h (proposal) |
| `C_net` | distinct subjects of all established instances on one K1 | subjects per K1 per its window (window and counting point unresolved: T-CNET) |
| attempted | requests sent | unbounded — the attacker chooses |
| admitted | `checkOnly` ALLOW = verifier reached | the security metric |

**(a) Subject cardinality.** Hypotheses H-a1 to H-a3:
* **H-a1:** `E_acct`, `E_net` and (if adopted) `M_acct`, `M_net` are enforced atomically.
* **H-a2:** `S_inst` is enforced atomically per instance per 24 h.
* **H-a3:** `C_net` (if adopted) is enforced atomically per K1 per window.

Under these hypotheses:
* the stock satisfies the §3 inequalities;
* the distinct subjects touched through established instances in 24 h number at most `I · S_inst`;
* per `C_net` window they number at most `C_net`.

Example (`E_acct = 2`, `T = 30`, `S_inst = 2`, no concurrent cap): **at most 120 distinct subjects per day per credential**.
With `M_acct = 2`: at most 4.

**(b) Admitted verifications — no bound is claimed.** Each touched subject can be verified as often as that account's rules
admit. Those rules are K4 decay, budget and cooldown, Anti-Equilibrium, the DEC-021 K5 / allowance, and the shared K1 / K2
state that proposal C removes for established traffic. That number depends on the account's history, the policy, the pacing,
other traffic on the same account, and concurrency. This study has **no supremum** of per-account admitted reach over
admissible histories, policies and concurrent multi-instance traffic. So it gives **no upper bound** on admitted verifications
for any variant. A product of the form "subjects × per-account reach" becomes a bound only once such a supremum is proven
or measured as an envelope; that is gate G-T2.

**(c) Exposure estimates (illustrative, not guaranteed).** Two sampled per-account reach values exist:
* The archived BRU_S M0 trace on MAIN (one unknown-device attacker, one account, one attempt every 30 s) shows **2, 0, 1, 0, 0**
  admitted in its first five 600-s windows `[H01]`.
* The DEC-021 measurement on S0 shows **37–39** admitted per 24 h for an unknown device on its Login streams `[PRIOR]`.

Multiplying the cardinalities in (a) by the second value gives about **120 × 39 ≈ 4,700** and **4 × 39 ≈ 156** admitted
verifications per day per credential. These figures:
* show that the first version's arithmetic understated the possible scale by orders of magnitude;
* rest on one pacing and one prior stream, so other pacings, policies or concurrent instances can give more or less;
* are **not ceilings**, and must not be presented to the Owner as limits.

**Account-scoped variant (C-acct, not measured) — scoped claim (F118-R2-03).** An established instance (or host-proven
known device) is relieved **only for the account(s) it is associated with**. **What this guarantees:** the instance gains
**zero new cross-account subjects**, because it cannot use standing to touch accounts it is not associated with. **What it
does not guarantee** — each item needs its own measurement:

* **Same-account delta.** Bypassing a shared K1 / K2 block can increase `checkOnly` admissions against the **already-associated**
  account. K4 / K5 / DEC-021 still apply, but this study does not show they make per-account reach equal to MAIN once the
  K1 / K2 denials are removed for that traffic.
* **Several known sessions or devices for one account.** Each one is a separate relieved path to the same account. Its
  combined reach against DEC-021's allowance and K5 thresholds is unmeasured.
* **Pool side effects.** Removing relieved traffic from the shared K1 / K2 sets frees capacity for a T1 actor on the same
  network. That is the B5 mechanism of §5, measured for proposal C's model.
* **#113 counter-cases.** Trusted multi-session attackers (TMS, TMSd, TMSs) and Host-tampered trust (TMX, TMXb) must be
  re-measured against this variant, including multiple known sessions per account.

It is therefore **not** a zero-gain design. It only removes the cross-account subject term.

| Actor | MAIN | Proposal C as specified |
|---|---|---|
| T1 (no credential) | `[H01]` SPR_S 7/200 over 20 min; `[H02]` ATKM 13 per day (71 attempts) | own decisions = MAIN; pool term B5: **observed** 13 → 22 per day in the H02 model (maximum over its 14 days, not a bound) |
| T2 / T3 with k credentials | as T1 | subjects: at most `I · S_inst` under H-a1 to H-a3; admitted verifications: **no proven bound**; exposure estimate as in (c) |
| T6 trusted sessions (#113) | TMSd 2/200, TMX 200/200 | unchanged for C: HMCI does not touch trusted K1 advisory (C-acct: see above) |

Concurrency: `S_inst` and a `checkOnly`-counted `C_net` would use the existing atomic bounded-set primitive, like S0 spray, and
establishment at `recordSuccess` would use an atomic `E_acct` set. Whether the hypotheses H-a1 to H-a3 hold under real Redis
concurrency is unmeasured.

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

The account-scoped variant above does not need `C_net` to limit cross-account subjects, because it has no cross-account
allowance. It still has the same-account delta, the B5 pool term and the bootstrap constraint (§4).

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
