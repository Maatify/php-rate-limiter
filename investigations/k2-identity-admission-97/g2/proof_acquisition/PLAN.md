# G2 — Proof acquisition under account exhaustion: pre-registered plan (#97)

> **Status:** PRE-REGISTRATION, plan version **v1**. Committed **before** any harness code exists and before any result.
> Evidence only. **Not** an architecture, policy, threshold or contract approval. No change to `src/`, `tests/`,
> `composer.json`, public contracts, presets, ADRs, standards or the G1 evidence. DEC-015, DEC-019 and DEC-021 stay ACTIVE
> and unchanged.
> **Baseline:** `main@06b68ab976a04948840382f7412375222f70916b`. `src/` there is byte-identical to the G1 baseline
> `4c6ca618d8206ba502961eaf06ffb82a5d61b50d` (`git diff --stat 4c6ca61 06b68ab -- src tests composer.json composer.lock` is
> empty), so the G1 candidate builds apply unchanged.
> **Companion:** the Gate 1 analysis and the final report are in
> [`../../G2_PROOF_ACQUISITION_FEASIBILITY.md`](../../G2_PROOF_ACQUISITION_FEASIBILITY.md) (written after the experiment;
> the Gate 1 verdict below is final and is not revised by the results).

## 0. Gate 1 verdict (summary)

The G1 gap (P3): an owner without a proof is in U, and G1 proved (`SECURITY_FEASIBILITY.md` §2.9) that no rule over the
library's observables both bounds attacker admissions on a target independently of the attacker's sources **and** never
refuses that owner. Only a proof escapes. G1 measured the protection of a proof that already exists (HARp 0/15); it never
modelled how an owner **gets** one while `U_acct` is exhausted.

| Case | Gate 1 verdict | Reason |
| --- | --- | --- |
| **A** — pre-registered possession credential (WebAuthn-like key pair) | **QUALIFIED FOR ISOLATED VERIFICATION** | The proof is a signature over a fresh server challenge. Verifying it is not guessing, so no per-account attempt cap is needed for its security; challenge issuance can be stateless and per-requester, so issuing more challenges neither consumes nor invalidates anyone else's. No circular dependency on the password / OTP verifier. Concrete, testable conditions (§2) |
| **B** — newly issued proof over a pre-registered channel (mail / phone) | **QUALIFIED, narrowed** to high-entropy, non-invalidating, operation-scoped links with validity `T_valid ≥ W_iss` | Anyone can trigger issuance, and a per-account issuance cap is needed against message bombing, so the cap **is** attacker-exhaustible. It does not deny the owner only because every issued link goes to the owner's channel: if the cap of the current issuance window is consumed, a link issued in that window is still valid (§2.3). Short guessable codes need a per-account redemption cap, which the attacker exhausts (circular); requester-bound links make attacker-triggered links useless to the owner. Both are rejected by derivation and kept only as detector arms |
| **C** — no independent credential or channel | **NO-GO** | No new input distinguishes this owner from the attacker. G1 §2.9 applies unchanged. Nothing is tested for C beyond the control arm that reproduces the gap |
| CAPTCHA / generic challenge as "proof" | **NO-GO** | Not account-bound: a solver gets the same entitlement for any account, and a challenge passed is a cost, not a bound (#110 comparison, design 08). Not tested |

Why this is not G1 again: G1 assumed the proof and accepted a direct lane claim (P1 `UNPROVEN`). G2 tests (i) acquisition of
the proof **while** `U_acct` is exhausted and the proof path itself is attacked, and (ii) a boundary that verifies a typed,
integrity-protected proof (account, operation, tenant, assurance, expiry, revocation, device binding) before any request is
put in P. It does not re-run the G1 matrix.

## 1. Exact hypothesis tested

**H.** For an owner who holds a case-A credential or a case-B channel, there is a proof-acquisition path such that, while an
attacker who rotates sources, user agents, client ids and devices keeps `U_acct` of the owner's account exhausted and also
attacks the proof path:

1. **H1 (availability).** The owner obtains a proof and is admitted in P for the bound account and operation. Acquisition
   never needs U capacity.
2. **H2 (integrity).** The boundary refuses every proof that is missing, forged, expired, revoked, replayed, for another
   account, operation or tenant, of insufficient assurance, or merely claimed.
3. **H3 (no multiplication).** No new identity, device, source, proof or renewal creates capacity on a foreign target beyond
   `U_acct`, and a proof holder (owner, thief or attacker on its own accounts) stays within `P_proof` / `P_acct` / `P_acct_day`.
4. **H4 (isolation).** On the real (G1 candidate) pipeline, U-lane traffic and other devices do not consume the owner's P
   capacity, do not disable acquisition, and produce no population-origin denial on the owner's P requests; every remaining
   difference against a no-population control is attributed to a declared coupling.
5. **H5 (atomicity).** The single-use and capped steps the path adds (challenge nonce, link redemption, issuance cap) hold
   under real concurrency on Redis, under lost replies and malformed state, and fail closed on backend failure.

## 2. Mechanism

### 2.1 Common pieces (Host model + modelled boundary)

| Piece | Role | Implemented with |
| --- | --- | --- |
| Proof minter (Host) | After a successful acquisition, mints a **proof token** `{v, tenant, account, operation, kind, assurance, deviceBinding = H(sessionDeviceId), iat, exp = iat + T_proof, jti, epoch}` | HMAC-SHA256 under a proof key `K_proof` |
| **Boundary** (stands for the library-side check P1 needs) | P **iff** MAC valid, version known, tenant / account / operation equal the request, `kind` allowed and `assurance ≥` the operation's minimum, `now < exp`, `epoch` = the account's current revocation epoch, `jti` not revoked, `deviceBinding = H(request sessionDeviceId)` with a non-empty id. Otherwise U with a reason. There is no lane parameter: a "P" claim, a client instance id, a trusted-session flag or a Host boolean never makes P | `hash_equals` on the MAC, field checks |
| Revocation | Per (tenant, account) epoch, bumped on credential revocation; per-`jti` revocation list | arrays / Redis |
| Admission | U and P lanes exactly as G1: `U_acct`, `U_src`, `U_glob`; `P_acct` (window and day), `P_proof` keyed by `jti` (window and day), P device cap `N_P` | **reused unchanged:** `g1/lib/admission.php` (`G1Admission`, `G1RefStore`) |
| Password verifier (Host) | Counts calls per party; the attacker never knows the password | counter |
| Proof-path scopes (new) | per source and optional global caps on proof-path steps; **no** per-account cap on any step except case-B issuance | fixed-window counters, all-or-nothing |

Operation policy (study values): `login` accepts kind `key` (assurance 2) or `link` (assurance 1), minimum 1; `otp` accepts
`key` only, minimum 2; `recovery` accepts `key` or `link` minted for `recovery`, minimum 1. A token is valid only for the
operation it names.

### 2.2 Case A — registered key

1. `challenge(tenant, account, op)`: stateless; returns `payload ‖ HMAC(K_ch, payload)` with a 128-bit random nonce and
   `exp = now + T_ch`. Charged to `CH_src` (per source) only. No per-account counter, no stored state, no invalidation of
   earlier challenges.
2. `assert(challenge, credentialId, signature)`: charged to `PV_src` (and `PV_glob` when enabled) **before** any work. Then,
   in order: challenge MAC, expiry, tenant / account / operation equal the request; credential registered for (tenant,
   account) and not revoked; **Ed25519** signature (libsodium) over `rpId ‖ challenge ‖ "g2.get"`; atomic single-use
   consumption of the nonce. Success mints a proof token bound to the presenting device.
3. Not modelled: CBOR, attestation, origin and UV flags, signature counter, authenticator UX. Ed25519 stands for the
   assertion signature only.

### 2.3 Case B — out-of-band link

1. `issue(tenant, account, op)`: charged to `ISS_acct(account, op)` per `W_iss` **and** `ISS_src` (and `ISS_glob` when
   enabled), all-or-nothing. On admission a 256-bit random token is stored by hash `{tenant, account, op, exp = now + T_valid,
   ISSUED}` and delivered to the account's registered mailbox — never to the requester. Earlier links stay valid.
2. `redeem(token)`: charged to `PV_src` (and `PV_glob`). Hash lookup; must be ISSUED, unexpired, same tenant and operation as
   the request; atomic ISSUED → REDEEMED; mints a proof token bound to the redeeming device.
3. **Availability invariant (`DERIVED`, to be measured).** If `T_valid ≥ W_iss` and issuance never invalidates earlier links,
   then at every instant either the current issuance window of (account, op) has capacity (the owner issues one), or it was
   consumed, in which case a link issued in the current window is in the owner's mailbox and still valid. Owner flow:
   request a link (a refusal is not a failure), read the mailbox, redeem the newest valid link for the operation.
4. **Harassment bound (`DERIVED`).** Attacker-triggered messages per victim and day `≤ ISS_acct · 86400 / W_iss`, which is
   `≥ 86400 / T_valid`. Availability through issuance therefore costs at least one unsolicited message per `T_valid` under a
   sustained attack. Reported, not judged.

### 2.4 Arms

| Arm | What changes | Role |
| --- | --- | --- |
| **NOPROOF** | Host has no proof path; the owner stays U | control; reproduces the G1 gap (expected owner refusals) |
| **PA** | case A as §2.2 | candidate |
| **PB** | case B as §2.3 | candidate |
| N-A-ACCTCAP | challenge issuance also capped per account (`CH_acct` = 3 / `W`) | **detector** |
| N-A-SINGLE | one outstanding challenge per (account, op); a new one invalidates the previous | **detector** |
| N-U | assertion verification is charged to `U_acct` + `U_src` instead of `PV_src` | **detector** (acquisition depends on U capacity) |
| N-B-BOUND | link redeemable only on the requesting device | **detector** |
| N-B-SHORT | 6-digit code instead of a 256-bit token; redemption capped per account (`RD_acct` = 5 / `W`) because codes are guessable | **detector** |
| N-B-TTL | `T_valid` = 300 < `W_iss` = 900 | **detector** |
| N-B-SHAREDOP | `ISS_acct` keyed on the account only (shared by every operation) | **detector** |
| N-BIND | boundary checks only MAC and expiry (no tenant / account / operation / assurance / device / revocation) | **detector** for §5 C |
| N-B-INVAL | issuance invalidates earlier links of (account, op) | measured only (not a detector: with the issuance cap its effect depends on timing) |

A detector arm is an arm that **must** produce the failure its gate looks for. If a detector does not fire, the gate is not
shown to be sensitive and the corresponding PASS of PA / PB is void (§6).

## 3. Values (test values only; never production thresholds)

| Name | Value | Meaning |
| --- | --- | --- |
| `W`, `U_src`, `U_acct`, `U_glob`, `P_acct`, `P_acct_day`, `P_proof`, `P_proof_day`, `N_P`, `T_P` | G1 set `R`: 600 s, 12, 4, 1000, 10, 60, 6, 30, 10, 86400 s | unchanged G1 admission parameters, so U/P results compare with G1 |
| `T_ch` | 120 s | challenge lifetime |
| `CH_src` | 120 / `W` | challenges per source and window |
| `PV_src` | 60 / `W` | proof-verification steps (assertions, redemptions) per source and window |
| `PV_glob` | off; 200 / `W` in the GLOB residual run | global proof-verification ceiling |
| `W_iss`, `ISS_acct`, `ISS_src` | 900 s, 3 per (account, op), 30 | link issuance |
| `ISS_glob` | off; 100 / `W_iss` in the GLOB residual run | global issuance ceiling |
| `T_valid` | 1020 s (`W_iss` + 120 s user slack) | link lifetime |
| `T_proof` | 1800 s | proof-token lifetime |

## 4. Actors and fixtures

Clock: integer seconds; events at the same second run attacker first (worst case). All randomness from a fixed seed
(`mt_srand`) or from libsodium for keys, nonces and tokens; decisions never depend on random values except through equality
checks, so archived outputs are deterministic.

**Attacker** (does not know the password, holds no owner credential unless stated): U login attempts on the victim every 3 s
from 30 rotating sources with a new UA and client id each time (keeps `U_acct` exhausted from the 4th attempt of each
window). Proof-path flood on the victim: a login challenge every 2 s and an otp / recovery challenge every 3 s (case A); a
link issuance every 5 s, recovery first in each issuance window, then login (case B); a junk assertion (attacker key over an
attacker-requested challenge) and a junk redemption (random token) every 5 s; all from rotating sources.

**Owner**: a fresh browser (new `sessionDeviceId`, not trusted, not previously verified) at a source the attacker does not
use, unless stated. Flow: one U login attempt; if refused, acquire a proof (A: challenge at +3 s, assertion at +8 s; B: issue
at +3 s, read mailbox at +30 s, redeem at +35 s), then a P login attempt with the correct password. One retry of the
acquisition after 60 s if a step fails. Each arrival is an independent run from clean state.

**Arrivals**: `t ∈ {1, 300, 599, 615, 900, 1199, 1234, 1815, 2101, 2415, 2999, 3300}` (12), run length 3600 s. The
attacker exhausts `U_acct` 9 s into each window, so the arrival at 1 s is the one window-start race in which the owner is
expected to get a U admission; the other 11 meet an exhausted `U_acct`.

| Section | Fixtures |
| --- | --- |
| A — exhaustion and recovery | `ACQ`: the above, every arm |
| B — proof-path DoS | `ACQ-x10` (proof-path flood ×10 over 300 sources); `ACQ-LONG` (14 400 s, arrivals at `1 + 1199k`, `k = 0…11`); residual runs `COLOC` (the attacker sends its whole proof-path flood from the owner's source; the owner retries from a second source of its own after a refusal) and `GLOB` (`PV_glob`, `ISS_glob` on; the ×10 flood from 300 sources, issuance spread over random other accounts) |
| C — integrity | one probe per case (§5 C), PA, PB, N-BIND |
| D — multiplication | `D-cid` 1 vs 100 client ids, `D-ip` 1 vs 30 sources, `D-dev` 1 vs 50 devices (U on the victim, 6 windows); `D-stolen` (attacker holds the owner's private key: one proof reused / a new proof before each attempt / a new proof and a new device each time, every 15 s, 1 h and 24 h); `D-own` (1 vs 20 attacker-owned accounts with own keys, plus the U attack on the victim) |
| E — isolation on the real pipeline | G1 trace-based `DAOF` build (no DA, no flood punishment), unchanged, `pre+out`, Login. `E1 ACQ-POP` (6 attacker devices at the owner's NAT + 20 rotating devices failing on the account; owner's new device acquires a proof at 300 s and makes 8 P attempts with typos); `E2 ACQ-EXPIRY` (MXE-shaped: proof 0–600 s, same device U 600–900 s, re-acquisition at 900 s, P to 2400 s, rotating failing devices); `E3 ACQ-KD` (E1 plus the owner's own historical known tablet failing in U); `E4 ACQ-NAT-HH` (six benign household devices pre-checking behind the owner's NAT, owner acquires at 90 s). Each against a control without the population (E3 also against a tablet-only control) |
| F — concurrency (real Redis 7.2.6, ephemeral, own port) | 32 forked workers behind a barrier, 20 rounds each: same challenge nonce consumed; same link redeemed; issuance of one (account, op) at cap 3; non-atomic mutants of all three; lost reply then retry; malformed key type before a write; backend unreachable |
| G — host modes (real pipeline, DAOF) | `MODES`: U attack on the victim (30 sources, every 15 s, 3600 s), owner acquires at 1234 s and makes P attempts; flows `pre`, `pre+out`, `out`; plus NOPROOF |

## 5. Metrics and gates

Metrics per run: owner acquisition success, owner admission (lane, decision, cause), owner refusals by step and cause,
owner requests and seconds from arrival to admission, password-verifier calls per party, proof-path operations per party
(challenges minted, signature verifications, links issued / delivered, redemptions), attacker `reach_foreign` (U on the
victim, per window and total), `reach_proof`, `reach_own`, attacker acquisitions, messages delivered to the victim's mailbox,
store writes.

| Gate | PASS condition (PA and PB unless stated) | Detector that must fire |
| --- | --- | --- |
| **A-1** owner availability | 12/12 arrivals admitted (U or P); after a U refusal the proof was acquired and the P attempt admitted | NOPROOF must refuse the owner in ≥ 10/12 arrivals (fixture validity) |
| **A-2** no U dependency | owner never refused on a proof-path step, except PB issuance refusals with a valid link already in the mailbox (counted separately); acquisition reserves no U counter: attacker and owner U admissions identical to NOPROOF | N-U ≥ 1 owner acquisition failure |
| **A-3** attacker bound | attacker `reach_foreign` ≤ `U_acct` per window and its total identical to NOPROOF; attacker acquisitions = 0; attacker P admissions = 0 | — |
| **B-1** proof-path DoS | A-1 and A-2 hold in `ACQ-x10` and `ACQ-LONG` | N-A-ACCTCAP, N-A-SINGLE, N-B-BOUND, N-B-SHORT, N-B-TTL, N-B-SHAREDOP each ≥ 1 owner acquisition failure in `ACQ` |
| **B-2** residuals | **measured, no PASS:** owner refusals in `COLOC` (first source) and `GLOB`; messages per victim and day in `ACQ-LONG` against §2.3 item 4; N-B-INVAL owner failures | — |
| **C** integrity | 0 accepted probes among: missing, forged (random, wrong key, tampered field), expired, revoked (epoch and `jti`), replayed (assertion nonce, link, token from another device), another account, another operation, another tenant, insufficient assurance, lane claim without a proof, client instance id only, unverified Host trust flag; plus challenge-level probes (challenge for another account / tenant, expired challenge, unregistered key, revoked credential) | N-BIND accepts ≥ 1 probe |
| **D** multiplication | `reach_foreign` per window ≤ `U_acct` and identical for 1 vs N in `D-cid`, `D-ip`, `D-dev`; `D-stolen`: one proof ≤ `P_proof` per window, renewal ≤ `P_acct` per window, ≤ `P_acct_day` per day, device rotation additionally ≤ `N_P` devices; 0 acquisitions without the key; `D-own`: ≤ `P_acct` per own account and window, victim `reach_foreign` identical with 1 or 20 own accounts. Aggregate cost is **reported**, not judged | — |
| **E** isolation | (i) owner P admissions and P counters identical with and without population; (ii) 0 owner P denials of `distributed_account_attack` / `flood` origin; (iii) owner acquisition results identical with and without population; (iv) every owner decision that differs from its control is attributed by the trace to a declared coupling (own-device K5, own P evidence on K4P, the DEC-021 shared allowance, K4U on owner U requests, a capacity cap); an unattributed or population-origin difference fails. Complete decision parity is reported separately and is **not** claimed unless measured | — |
| **F** atomicity | exactly one success per nonce and per link in every round; issuance never above cap; a retry after a lost reply never mints a second proof and never issues above cap; malformed state answers an error with no write; unreachable backend mints nothing and admits nothing | each non-atomic mutant over-admits in ≥ 1 round |
| **G** host modes | `pre` and `pre+out`: owner admitted after acquisition, attacker reach ≤ `U_acct` per window. `out`: **reported only** (no pre-verifier bound is claimed). NOPROOF: owner refused | — |

## 6. Verdict rules (fixed now)

1. Any detector that does not fire ⇒ the gate it guards is **not evidenced**; PA / PB cannot be called supported on it.
2. PA (or PB) passes A-1, A-2, A-3, B-1, C, D, E, F and G ⇒ that case is **EXPERIMENTALLY SUPPORTED — LIMITED SCOPE**
   (owners holding that credential or channel; the residuals of B-2 and the conditions of §7 stated).
3. A failed mandatory gate for a case ⇒ **NO-GO AFTER EXPERIMENT** for that case; the failure is archived as is.
4. If both cases fail ⇒ overall NO-GO AFTER EXPERIMENT. If one passes ⇒ the overall verdict is the passing case's, limited
   to it. Case C stays NO-GO. No result here is an Architecture GO, a G3 GO, or a statement that Runtime is ready.
5. A substantive change to fixtures, values or gates after a result requires plan v2 with the reason, keeping v1 results.

## 7. What this cannot prove

* Real WebAuthn (CBOR, attestation, origin, UV, counters, platform UX) and real mail / SMS delivery. Delivery is a
  deterministic mailbox; an attacker who blocks, delays or reads the channel is out of model (a read channel is the
  stolen-proof case of D).
* That a Host mints proofs correctly. The boundary proves only that a token it accepts was minted for this tenant, account,
  operation, device and epoch by the holder of `K_proof`.
* That the library implements anything: the boundary is study code; P1 needs a public contract decision (D3).
* Production Redis behaviour (Cluster, failover, replication loss), DEC-015 degraded mode (it has no lanes, so P is absent
  during an outage — `DERIVED`), mixed fleets, rotation and production values (P2, P5, P6).
* Distributed spray over many targets (P4): the proof path adds no U capacity, which D measures; it does not reduce P4.

## 8. Reproduction

```bash
bash investigations/k2-identity-admission-97/g2/proof_acquisition/run.sh
```

The runner builds the G1 `DAOF` tree with the G1 builders (sha-256 pinned, into the git-ignored `results/`), starts and owns
an ephemeral Redis on a free local port, runs every scenario, writes `summary.md` and `gates.json`, and diffs everything
against `expected/`. It exits non-zero on any gate violation, any detector that does not fire, or any difference.
`UPDATE=1` regenerates `expected/`.
