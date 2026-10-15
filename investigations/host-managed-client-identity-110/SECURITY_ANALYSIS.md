# Security Analysis — Host-Managed Client Instance Identity

Evidence labels as in [`CURRENT_STATE.md`](CURRENT_STATE.md). The model analysed is the direction-C model of
[`ARCHITECTURE.md`](ARCHITECTURE.md) §3; **none of it is implemented or measured** — every claim about it is `[ANALYTIC]`
and becomes a gate in [`VALIDATION_PLAN.md`](VALIDATION_PLAN.md).

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

Conclusion carried into this study: **a new observable is required**; re-interpreting existing ones (direction D) has been
measured five times. The host-issued instance id is such an observable — but only its *stability* is new information;
its *issuance* is free to an attacker.

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

## 3. Identifier rotation

* **S0-on-main.** Rotation never gains reach: SPR_R ≤ SPR_S and BRU_R ≤ BRU_S in every input mode `[H01]` O9. When the id
  is inside the DeviceFP, rotation trips K2 churn (SPR_R 3/200 in M1–M3).
* **Direction C.** A fresh id is `NEW` and judged as S0 ⇒ rotation is never better than no id, by construction. The only
  way to obtain value is establishment, which needs a successful authentication. Bound on established ids an actor can
  hold within 24 h `[ANALYTIC]`:

  `established ≤ min(k · E_acct, E_net per K1 used)` for T2/T3 with k valid credentials; **0 for T1**.

* Loss of protection to watch: removing established instances' DeviceFP from the K2 churn set (§3.3 of the
  architecture) must not let a T2 actor rotate *appearance* under one established id. That is why per-instance churn
  (distinct DeviceFP per K6) replaces the population rule for established instances.

## 4. Credential spray

| Actor | S0-on-main | Direction C `[ANALYTIC]` |
|---|---|---|
| T1 | ~4–7 verifier reaches per K1 per window (`[H01]` SPR_S 7/200 over 20 min; `[PRIOR]` SP1n 4/200) | identical (all its ids are `NEW`) — **gate Δ = 0** |
| T2 / T3 with k credentials | as T1 | additional reach per window ≤ `min(k · E_acct · S_inst, C_net)` over S0; e.g. `E_acct = 2`, `S_inst = 2` ⇒ ≤ 4k, capped by `C_net` (whose design is the open tension T-CNET in §5) — **requires measurement and an explicit Owner decision on the quantified gain** |
| T6 trusted sessions (#113) | TMSd 2/200, TMX 200/200 | unchanged: HMCI does not touch trusted K1 advisory |

Why `S_inst` is a cross-account bound and not an exemption: an established instance that attempts more than `S_inst`
distinct subjects is blocked on K6 regardless of the network; the network ceiling `C_net` bounds the sum over all
established instances on one K1. The #113 lesson (removing K2 removes the only cross-account bound) is respected because
the bound moves to the instance, it is not deleted.

Concurrency: both `S_inst` and `C_net` are observed at `checkOnly` with the existing atomic bounded-set primitive
(`BoundedCorrelationStoreInterface::addDistinctBounded()`), like S0 spray, so the "+N under concurrency" leak of
outcome-time enforcement (#111 OQC 16/16) does not apply to them `[ANALYTIC]`. Establishment itself happens at
`recordSuccess`; concurrent successes on one account are bounded by the atomic `E_acct` set. Not measured.

## 5. Shared NAT

| Case | S0-on-main `[H01]` | Direction C `[ANALYTIC]` |
|---|---|---|
| Office, all browsers returning and established | OFF100 90/100 users denied (K1) with no id; 97/100 with the id in the DeviceFP | established instances are not denied by others' K1 / K2 evidence **unless the network ceiling `C_net` is reached** — see the open tension below |
| Office, day 0 / new browsers / cookie-less | same | **no relief** — `NEW` = S0. Honest limit of the model |
| Mixed: office established + attacker T1 on the same NAT | S0 denies the office when T1 sprays | T1's spray blocks the `NEW` / ABSENT pool (incl. new colleagues); established colleagues keep working |
| Mixed: office + insider T3 holding credentials | — | T3 may establish ≤ `E_acct` instances per credential and is then bounded by `S_inst` and `C_net`; reaching `C_net` blocks **all** established instances on that K1 — a deliberate fail-safe that re-introduces collateral under attack |

**Open tension T-CNET (unresolved, decisive for GO / NO-GO).** `C_net` is the only bound on the *sum* of established
instances on one network. If it counts distinct subjects at `checkOnly` (concurrency-safe, like S0 spray), an office of
`N` established users logging in within one window reaches it as soon as `N ≥ C_net` — so `C_net` must exceed the
largest office to give relief, and then lets a T3 insider with enough established instances use up to `C_net` subjects.
If it instead counts **failed** subjects at `recordFailure` (an office rarely fails), it separates office from sprayer but
inherits the outcome-time concurrency leak measured for OQC (#111: 16/16 through a live scope) and the record-only /
check-only Host limitations of #116. The model is therefore only as good as the `C_net` design, which this study does not
resolve. With `C_net` removed, the model degrades to "per-instance bounds only", whose T2/T3 gain is
`k · E_acct · S_inst` per window with no network cap.

Not assumed: that many browsers means attack, or that distinct ids mean distinct people. The model never gives an id value
for being distinct; it gives value only to an id that has carried a successful authentication.

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
  Requires measurement (gate G-MIX).
