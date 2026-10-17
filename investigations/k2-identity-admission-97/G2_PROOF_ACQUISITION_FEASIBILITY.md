# G2 — Proof acquisition under account exhaustion: feasibility and isolated verification (#97)

> **Status:** G2 INVESTIGATION EVIDENCE for independent architectural review. Bound to the SHA and scope below; it is not an
> ADR, not a current-runtime reference and not a policy. **Not** an Architecture GO, not a G3 GO, not a statement that Runtime
> is ready. No change to `src/`, `tests/`, `composer.json`, public contracts, presets, thresholds, ADRs, standards or the G1
> evidence. DEC-015, DEC-019 and DEC-021 stay ACTIVE and unchanged.
> **Pre-registration:** [`g2/proof_acquisition/PLAN.md`](g2/proof_acquisition/PLAN.md) v1, committed in `c7c3fef` **before**
> any harness code or result. Gates, values and verdict rules were not changed afterwards. One post-hoc diagnostic was added and
> is labelled as such (§8.5); it is excluded from every gate and from the verdict.
>
> **Revision (F127-01 … F127-03, documentation only).** Corrected after the Direct Lead Review of PR #127
> ([comment 6235493895](https://github.com/Maatify/php-rate-limiter/pull/127#issuecomment-6235493895)) on `02cc9fe`. The
> correction narrows the scope of three claims (§8.3, §8.6, §8.7, §10, §11). `PLAN.md` v1, the harness, the fixtures and the
> archived `expected/` outputs are unchanged, and the verdict is unchanged.
> **Evidence classes:** `CODE` (read from the source at the baseline), `MEASURED` (archived harness output), `DERIVED`
> (follows from stated premises), `CONDITIONAL` (holds only under a named Host premise), `UNPROVEN`.

## 1. Baseline and governance

| Item | Value |
| --- | --- |
| Repository | `Maatify/php-rate-limiter`, `origin` verified |
| Source baseline | `main@06b68ab976a04948840382f7412375222f70916b` (`origin/main` verified equal before branching) |
| `src/` against the G1 baseline | byte-identical to `4c6ca618d8206ba502961eaf06ffb82a5d61b50d` (`git diff --stat 4c6ca61 06b68ab -- src tests composer.json composer.lock` is empty) |
| Branch | `study/97-g2-proof-acquisition`, created from `origin/main` |
| Governance read | `AGENTS.md`; `STANDARDS_MANIFEST.md`; Adoption Standard §10.1; AI Collaboration Workflow 12.0.0 (§2, §6); Testing 2.0.0; Decision Governance 1.0.0; Documentation Lifecycle 3.1.0 (§2.1: durable technical documentation in English; this file is a verification report bound to a SHA, §3); GitHub Phase Stack 4.0.0; PHP Coding Style 1.0.1 (PER-CS) |
| Architecture read | `docs/KEY_STRATEGY.md` §2 (LOCKED), `docs/POLICIES.md` §1, `docs/DECISION_MATRIX.md`, `docs/FAILURE_SEMANTICS.md`, DEC-007, DEC-015, DEC-019, DEC-021, `ARCHITECTURE_PROPOSAL.md`, `SECURITY_FEASIBILITY.md`, `G1_FINAL_DISPOSITION.md`, `G1_VERIFICATION.md`, `G1_DA_T1.md`, `nat-architecture-proposals-110/COMPARISON.md`, issue #120 |

## 2. The G1 gap

G1 closed with the complete candidate **NO-GO** and P3 open: an owner without a proof is in U, and U is subject to `U_acct`,
which G1 shows is load-bearing once the device-population punishments are removed (`G1_FINAL_DISPOSITION.md` §2.2). G1 also
**proved** (`SECURITY_FEASIBILITY.md` §2.9, `DERIVED`) that over the library's observables no rule both bounds attacker
admissions on a target independently of the attacker's sources and never refuses the owner; only a proof escapes. It measured
that an owner who **already** holds a proof is protected (HARp 0/15 against HAR 14/15). It never modelled how the owner
**obtains** a proof while `U_acct` is exhausted, and P1 (a typed, library-enforced binding of a proof to account and
operation) stayed `UNPROVEN`: the G1 patch accepted a direct lane claim and a Login proof on OTP.

## 3. The new hypothesis

An independent acquisition path, charged to its own scopes and never to U, from which the owner obtains an integrity-protected
proof token bound to tenant, account, operation, assurance, device and revocation epoch; a boundary then puts only such
requests in P. The exact hypothesis H1–H5 and the mechanism are in `PLAN.md` §1–§2. What is new against G1: acquisition
**during** the attack, attacks **on** the acquisition path, and a boundary that verifies the proof instead of trusting a lane
claim.

## 4. Code-level findings (`CODE`, at the baseline)

| # | Finding | Source |
| --- | --- | --- |
| K-1 | The library receives no proof. Its trust inputs are `sessionDeviceId` + `isSessionTrusted` and `isDevicePreviouslyVerifiedForAccount`, all Host booleans or opaque strings; nothing names an operation or carries integrity | `src/DTO/RateLimitContextDTO.php` (constructor), `src/Service/DeviceIdentityResolver.php:28` |
| K-2 | The library checks nothing about them. A trusted session only changes confidence and makes K1 advisory; "known" is `isTrustedSession ‖ isDevicePreviouslyVerifiedForAccount` | `src/Service/EvaluationPipeline.php:2388-2390`, `:513` (K1 skip), `src/Service/DeviceIdentityResolver.php` |
| K-3 | Every Host flag is therefore claimable by whatever the Host forwards: a client id, a cookie or a Host assertion cannot be distinguished from a proof at the package boundary. This is why P1 needs a new typed input (D3) and why the boundary in this study is study code | K-1, K-2 |
| K-4 | Nothing is reserved before the verifier today: `checkOnly` is read-only for scores and the account / device state is written only on outcomes | `EvaluationPipeline.php:380` |
| K-5 | Active K1–K5 blocks fail fast before any evaluation for every requester of the keys; an account-wide K4 block reaches every device of the owner | `EvaluationPipeline.php:198`, `:498-549` |
| K-6 | K5 = account + fingerprint hash; the fingerprint material is `ua ‖ clientFingerprint ‖ sessionDeviceId` and does not include the trust flag, so a device's K5 family (score, block, DEC-021 device watcher) is the same whether it runs with or without a proof | `EvaluationPipeline.php:2614-2615`, `DeviceIdentityResolver.php` (raw string) |
| K-7 | DEC-007 re-entry treats K4 as 0 for **any** requester of the account while evidence is valid; the claim is one-shot but the relief is not bound to the owner, so it cannot serve as owner-specific acquisition | `EvaluationPipeline.php:255-270`, DEC-007 |
| K-8 | DEC-015: a typed backend failure opens the circuit; during an outage `DEGRADED_MODE` uses process-local counters (`LocalFallbackLimiter`) with no lanes, so no P exists while it lasts (`DERIVED`) | `RateLimiterEngine.php:308-345`, DEC-015 |
| K-9 | Mode limits: `out` (record-only) calls the library after the verifier, so no pre-verifier bound is possible in that mode whatever the design | `docs/guides/USAGE_GUIDE.md`, `ARCHITECTURE_PROPOSAL.md` §6.4 |

What a real design would need (none is created here): a typed proof input (D3), a lane-aware admission store (G1 T0), and the
library-side checks this study models in `G2Boundary`. What the current contracts can reuse: the command model (`checkOnly`,
`recordFailure`, `recordSuccess`) unchanged, the `RateLimitMetadataDTO::$cause` string for capacity causes, and the existing
DEC-015 failure path.

## 5. Feasibility gate (Gate 1)

### 5.1 Verdict per case

| Case | Gate 1 | Why |
| --- | --- | --- |
| **A** — pre-registered possession credential | **QUALIFIED FOR ISOLATED VERIFICATION** | Usable without a session; needs a fresh challenge, which can be stateless and per requester, so the attacker can neither exhaust challenge issuance on the account nor invalidate the owner's challenge. Verification is a signature, not a guess, so it needs no per-account attempt cap and does not use the password or OTP verifier. Availability then depends only on per-source and global proof-path capacity |
| **B** — proof issued over a pre-registered channel | **QUALIFIED, narrowed** | Anyone can trigger issuance, and a per-account cap is required against message bombing, so the attacker **can** exhaust it. It still does not deny the owner when links are high-entropy, never invalidate earlier links, are scoped per (account, operation) and live at least one issuance window (`T_valid ≥ W_iss`): every link goes to the owner's channel, so either the window has capacity or a valid link issued in it is already there (`DERIVED`, PLAN §2.3) |
| B with short codes | **NO-GO (derived)** | A guessable code needs a per-account redemption cap; the attacker exhausts it (circular). Without it, guessing scales with sources |
| B with requester-bound links | **NO-GO (derived)** | Attacker-triggered links are useless to the owner, and the owner's own issuance is exhaustible |
| **C** — no independent credential or channel | **NO-GO** | No new input separates this owner from the attacker; `SECURITY_FEASIBILITY.md` §2.9 applies unchanged |
| CAPTCHA as "proof" | **NO-GO** | Not account-bound; a solved challenge is a cost, not a bound (#110 comparison, design 08) |

### 5.2 Trust boundary and proof accounting (conceptual model)

| Requirement | Mechanism in the study | Status |
| --- | --- | --- |
| Tenant / Host boundary | `tenant` in challenge, link, token; per-tenant relying-party id in the signed message | `MEASURED` (C) |
| Target account and operation | equality checks in the boundary; challenge and link bound to them | `MEASURED` (C) |
| Kind and assurance | per-operation policy: `login` ≥ 1 (key or link), `otp` ≥ 2 (key only), `recovery` ≥ 1 | `MEASURED` (C) |
| Freshness / expiry | `T_ch` 120 s, `T_valid` 1020 s, `T_proof` 1800 s; `iat > now` refused | `MEASURED` (C) |
| Replay | single-use nonce and link (atomic on Redis); token bound to `H(sessionDeviceId)` | `MEASURED` (C, F) |
| Revocation | per-account epoch (bumped by credential revocation) and per-token id | `MEASURED` (C) |
| Separation of Login / OTP / Recovery | operation-scoped tokens and per-(account, op) issuance caps | `MEASURED` (C, N-B-SHAREDOP) |
| Client id, Host flag, lane claim | have no input at all into the boundary | `MEASURED` (C) |
| Renewal does not renew the account budget | `P_acct` is shared by every proof of the account; `P_proof` is per token | `MEASURED` (D) |
| New device or session creates no budget | P device cap `N_P` and shared `P_acct` | `MEASURED` (D) |
| A proof is not good faith | own and stolen proofs stay inside `P_proof` / `P_acct` / `P_acct_day` | `MEASURED` (D) |
| Who mints | the Host, after verifying; the boundary proves only that a token it accepts was minted for this request by the holder of `K_proof` | `CONDITIONAL` (Host) |

**Alternative authentication.** A case-A assertion with user verification is itself a complete strong authentication. Sending
that owner back to the password verifier is not required for security; a Host may treat the assertion as the authenticated
operation (then U exhaustion of the password verifier is simply irrelevant to that owner), or, for a possession-only assertion,
require the password inside P. The study models the second, stricter form so that no assurance is lowered; a case-B link is a
possession factor and is never treated as a full login here.

## 6. Proof-acquisition paths (results in brief)

| Path | Owner availability while `U_acct` is exhausted and the proof path is flooded | Evidence |
| --- | --- | --- |
| A (key) | 12/12 arrivals admitted (11 in P after acquisition, 1 in U by the window-start race) in `ACQ`, `ACQ-x10` and `ACQ-LONG`; 4 owner requests, 10 s | `MEASURED` |
| B (link) | 12/12 likewise; 4 owner requests, 40 s; in 9 of 11 P arrivals the owner's own issuance request was refused and the owner used a link the **attacker** had triggered | `MEASURED` |
| No proof path (control) | 11/12 refused (G1 HAR reproduced) | `MEASURED` |
| C | not testable; no mechanism | Gate 1 |

## 7. Attack and availability findings

| Attack | Result | Evidence |
| --- | --- | --- |
| Rotating sources / UA / client ids / devices on U | `reach_foreign` 4 per window whatever the number of ids (1 vs 100), sources (1 vs 30) or devices (1 vs 50); proof path adds no U capacity | `MEASURED` (A-3, D) |
| Flooding challenge issuance (A) | no effect (no per-account state); the detector with a per-account challenge cap denies 11/12 owners | `MEASURED` |
| Invalidating the owner's challenge (A) | impossible without per-account state; the "one outstanding challenge" detector denies 11/12 | `MEASURED` |
| Charging acquisition to U (A) | the detector denies 11/12: acquisition must not depend on U capacity | `MEASURED` |
| Exhausting issuance (B) | owner still admitted through attacker-triggered links; detectors: requester-bound links 9/12 denied, `T_valid` < `W_iss` 9/12, shared cap across operations 10/12, short codes with per-account redemption cap 11/12 | `MEASURED` |
| Invalidate-on-reissue (B, measured only) | 2/12 owners denied (race between reading and redeeming) | `MEASURED` |
| Replay, cross-account / operation / tenant reuse, forged or claimed proofs | 0 of 27 (A) and 0 of 24 (B) probes accepted; the weak-boundary detector accepts 9 | `MEASURED` (C) |
| Many own accounts with proofs | `reach_own` grows linearly: 20 accounts → 1200 verifier calls per hour (20 × `P_acct`/window); victim `reach_foreign` unchanged | `MEASURED` (D) |
| Stolen credential | one token ≤ `P_proof` = 6 per window; renewal ≤ `P_acct` = 10 per window and 60 per day; device rotation stops at `N_P` = 10 devices per day | `MEASURED` (D) |
| Co-located attacker on the proof path (COLOC) | the owner's first source is refused when the attacker has already used its per-source proof-path capacity (A: 4/12 arrivals, B: 4/12), and the owner succeeds from a second source of its own (12/12 eventually; up to 70 s A, 132 s B) | `MEASURED`, **residual** |
| Global proof-path ceilings (GLOB) | a distributed attacker exhausts them: A 8/12, B 2/12 owners admitted (each count includes the one window-start U admission). Any global ceiling is a lane-wide denial lever (B4-5 of G1); without one the proof path's aggregate cost has no ceiling | `MEASURED`, **residual, needs an Owner policy** |
| Harassment through issuance (B) | under a sustained attack the victim receives 576 attacker-triggered messages per day at the test values (96 in the 4 h of `ACQ-LONG`; the bound `ISS_acct · 86400 / W_iss` = 288 per linkable operation, two operations); the floor of any non-denying configuration is one message per `T_valid` per operation | `MEASURED` / `DERIVED`, **residual, needs an Owner policy** |
| Outcome-only Host | the proof changes the owner's post-verifier decision only; attacker reach 240/240 | `MEASURED` (G), by call order |

## 8. Experimental evidence

### 8.1 Harness and provenance

| Item | Value |
| --- | --- |
| Harness | `g2/proof_acquisition/`: `lib/proof.php` (Host model, Ed25519 via libsodium, HMAC-SHA256 tokens, boundary), `lib/sim.php` (timeline), `lib/redis.php` (Lua primitives), `scenarios/` (A/B, C, D, E, F, G), `report.php` (gates), `run.sh` |
| Reused unchanged | G1 admission layer `g1/lib/admission.php` (U/P, parameter set R); G1 trace replay `g1/verify/lib/verify.php`; the G1 `DAOF` pipeline built by the G1 builders (`EvaluationPipeline.php` sha-256 `0b6a52a7…1984c`, pinned by G1) |
| Toolchain | PHP 8.4.4, Redis 7.2.6 (ephemeral instance on a free local port, owned and shut down by the runner) |
| Archived | `g2/proof_acquisition/expected/`: 6 JSONL files, `summary.md`, `gates.json` |
| Reproduction | `bash investigations/k2-identity-admission-97/g2/proof_acquisition/run.sh` — compared clean against `expected/` on three runs (two before and one after the final formatting and typing edits). Exit 3 = outputs identical, harness valid, a pre-registered gate fails (this result) |

### 8.2 Gates (`MEASURED`, `expected/gates.json`)

| Gate | PA | PB |
| --- | --- | --- |
| Fixture validity (NOPROOF refuses ≥ 10/12) | 11/12 ✔ | — |
| A-1 / A-2 owner availability, no U dependency | PASS | PASS |
| A-3 attacker bound unchanged | PASS | PASS |
| B-1 proof-path DoS (`ACQ-x10`, `ACQ-LONG`) | PASS | PASS |
| C integrity (27 / 24 probes) | PASS | PASS |
| D multiplication | PASS | PASS |
| **E isolation on the real candidate pipeline** | **FAIL** | **FAIL** |
| F atomicity on Redis (outage case: proof minting only, §8.6) | PASS | PASS |
| G host modes | PASS | PASS |
| Detectors (12) | all fired | all fired |

### 8.3 Gate E (the failure)

E2 (`ACQ-EXPIRY`, MXE-shaped): the owner's proof expires on the same device at 600 s, the device runs unproven for three
requests, re-acquires at 900 s and returns to P while unknown devices fail on the account. Against the no-population control:

| | Owner decisions | P admission counter |
| --- | --- | --- |
| with population | `AAASS SSS SSSHSSSSHSSS` | 7 |
| control | `AAASS SHH HSSSSSSSSHSS` | 6 |

* (i) **fails**: P counters 7 vs 6.
* (ii) passes: 0 population-origin denials.
* (iii) passes: acquisition identical.
* (iv) passes: every difference has a declared origin.
* No U request changed any P state: c9 = 0 over 128 U operations.

The chain, traced (`expected/summary.md` §E): population failures raise the account's K4U. The owner's unproven requests at
720 s and 840 s are refused by K4U before the verifier (`S origin:k4`), so their outcomes are never recorded. In the control
they are recorded, and the device's DEC-021 watcher escalates (`H origin:anti_equilibrium:device`). The owner's own-device
history therefore differs when P resumes, and the P decisions shift (four rows, two in each direction, same number of HARD).

**What E2 shows and what it does not (F127-01).** Three results must be kept apart:

1. **Verdict under PLAN v1.** E-(i) requires identical owner P admissions and P counters with and without the population. On
   E2 they are 7 and 6, so E-(i) fails. Rule §6.3 then makes the complete candidate **NO-GO AFTER EXPERIMENT**. That verdict
   stands. It is the verdict of the complete pre-registered candidate under PLAN v1, not a verdict on proof acquisition alone.
2. **The isolation result itself** (`MEASURED`). There is no direct U → P effect:
   * no U request wrote P state (c9 = 0 over 128 U operations);
   * there is no population-origin penalty on P (0 denials);
   * acquisition results are identical in both runs.

   The difference is a **history-mediated decision divergence** of one device. The population raises K4U, which refuses the
   owner's unproven attempts before the verifier, so their outcomes are not recorded. The device's own K5 history and DEC-021
   device watcher therefore differ when the re-proven device returns to P (K-6).

   The difference runs in the owner's favour on the measured count: 7 P admissions with the population against 6 without.
   This is not evidence that the population consumed or exhausted P capacity, and it must not be read as a direct breach of
   P isolation. What it does show is that the owner's P decisions are **not identical** to the control after a re-proof on a
   device that ran unproven. Complete isolation is therefore not established for that case. This is G1's MXE coupling,
   still present after acquisition.
3. **Proof-acquisition feasibility for A and B.** E2 does not refute it. In E2, acquisition succeeded identically with and
   without the population (iii). Acquisition is also tested directly by A-1 … D, F and G, which pass with every detector
   firing (§8.2). E2 is therefore not evidence that mechanisms A or B fail in principle. Their feasibility remains
   **conditional** and is limited to the isolated Host models of this study (§7, §10).

**Disclosed label mapping.** (iv) treats the DEC-021 device watcher (`anti_equilibrium:device`) as part of the "own-device K5"
coupling: its escalation is a K5 `HARD_BLOCK` on the same account + device only (DEC-021 §5). The mapping was fixed in code after
the first run. A narrower reading would make (iv) fail on E2 as well. This does not change the verdict, which already follows
from (i).

**Tension in the pre-registration, disclosed, not corrected.** (i) and (iv) are not contradictory in general. They can
conflict when a coupling that (iv) accepts as declared changes the **number** of owner admissions, as it does on E2. The
property behind (i) is that U traffic never consumes or writes P state. c9 measures that property directly, and it holds on
every E fixture. Neither gate is changed retroactively: under the plan's rule 5 that would require plan v2. That was not done,
and the verdict follows v1.

### 8.4 Other E fixtures

* **E1** (a new device acquires a proof amid a failing population, including six attacker devices at the owner's NAT), **E3**
  (plus the owner's own known tablet failing in U) and **E4** (benign household devices at the owner's NAT): every owner P
  decision is identical to its control. P counters are identical. There are 0 population-origin denials.
* The only differences are on the owner's single unproven attempt before acquisition (K4U, declared).
* The flood counterexample of G1 (FLOOD-P-NAT) does not reappear on DAOF with acquisition.
* **Owner usability inside P** (same in run and control): a newly proven device is "unknown" and scores the new-device delta on
  K4P (`ARCHITECTURE_PROPOSAL.md` §5.1 row 2). Two typos put it in L1 `SOFT_BLOCK` for the remaining 14 minutes of the fixture
  (`AASSSSSS`). A proof bounds the owner by its own evidence, not by the attacker's. Whether that friction is acceptable is a
  policy question.

### 8.5 Post-hoc diagnostic (not pre-registered; excluded from gates and verdict)

E2 copies G1's MXE fixture expression `n % 3`. Because `n` advances by 6 between owner rows, that expression makes the owner
**fail every attempt**. Rerunning E2 with an owner who fails one attempt in three (`E2d`) gives P counters 17 vs 17 and P
decisions identical to the control after re-acquisition. The only differences are the three unproven U requests (K4U). The
pre-registered failure is therefore specific to an owner whose own attempts all fail. It shows the coupling exists. It does not
show a large availability harm. The diagnostic does not change the result.

### 8.6 F and G in brief

* **F.** Over 32 workers × 20 rounds on real Redis, each challenge nonce and each link was consumed exactly once per round, and
  issuance stopped at exactly 3 at cap 3. The non-atomic mutants over-admit in 20/20 rounds. Retries after lost replies return
  `used` and never mint a second proof. This is **at-most-once consumption**. It is not recoverable completion (§8.7).
  Issuance retries count against the cap (3 issued, 2 refused). Malformed key types answer an error with no write
  (value-level snapshot).
* **F6 scope (F127-03).** F6 stops the ephemeral Redis that backs the **proof path**. The challenge step then raises a
  `RedisException`, and no proof token is minted (`tokenMinted = false`, `proofsMinted = 0`). The result is precisely:
  *proof minting fails during this simulated proof-store outage*.

  F6 does not show the following:
  * **Admission refusal.** F6 never calls `G1Admission::reserve()`, so `admissions = 0` in `expected/f.jsonl` is an unused
    counter, not evidence.
  * **A boundary decision during an outage.** `lane = U` only restates that the boundary refuses a missing token.
  * **DEC-015 behaviour or integration**, Redis failover, replication loss or Cluster. These remain `UNPROVEN` (§9 P5, §10).
* **G.** In `pre` and `pre+out`, every owner P request after acquisition is admitted and the attacker is held to ≤ 4 per window.
  In `out` the attacker reaches 240/240. NOPROOF: owner `HHSS`.

### 8.7 Gap: recoverable completion after redemption (F127-02, `UNPROVEN`)

**The gap.** Single-use consumption does not let the owner recover the proof when the response is lost (`CODE`, `DERIVED`):

1. **Ordering.** Redemption first commits `ISSUED → REDEEMED` atomically (`lib/redis.php` `REDEEM`, `lib/proof.php`
   `redeem()`). Only after that commit does the Host mint the proof token. The study persists no result between the two steps.
2. **Failure window.** The owner gets nothing if either of these happens: a crash between the commit and the mint, or a token
   that is minted but whose response never reaches the owner.
3. **No recovery.** A retry with the same link returns `used`. F4 measures exactly this, as the safety property "no second
   proof".
4. **Exposure in case B.** Issuance is capped per (account, operation). If the attacker has also consumed the current issuance
   window, the owner may be without a usable link until the window rolls over or another valid link reaches the mailbox.

So F4 proves **at-most-once consumption**. It does **not** prove **recoverable completion**. This is an availability gap of
the proof path, classified `UNPROVEN`.

**Case A** has the same ordering: the nonce is consumed before minting. There the owner can request a fresh challenge, because
challenges carry no per-account cap. Recovery is then bounded only by the per-source proof-path caps (`DERIVED`; lost replies
were not exercised for case A).

**Architectural conditions any recoverable completion would have to meet** (analysis only; no remedy, fixture or test is part
of this study):

* **Binding of the completion record.** The stored result must be bound to the same tenant, account, operation and link or
  nonce, and to the redeeming device (`H(sessionDeviceId)`) or an equivalent requester secret presented at redemption.
* **Retrieval rules.** A retry may retrieve the result only with that binding. A retry from another device, or for another
  account or operation, must get nothing.
* **No new authority.** Retrieval returns the **same** token (same `jti`, same `exp`) and never mints a second independent
  proof. `P_proof` / `P_acct` accounting is therefore unchanged by retries.
* **Bounded retention.** The result is retained for a short window, no longer than the link's remaining validity, and expires
  with it. Revocation (account epoch, `jti`) applies to a retrieved token exactly as to a fresh one.
* **Atomic state and result.** Commit and result storage are atomic, or the result is stored first and published atomically
  with the commit, so that a crash cannot leave a consumed link without a retrievable result.
* **Integrity at rest.** The stored result is protected against disclosure and tampering, because it is a bearer credential.
* **Uniform failure answers.** Failures stay indistinguishable for an attacker. Retrieval must not reveal whether a link
  exists for another device.

Whether such a mechanism is needed, and its design, require an independent experiment gate with its own pre-registered plan.
Neither is authorized here.

## 9. G1 P1–P6 mapping

| G1 item | After G2 | Effect on the rest |
| --- | --- | --- |
| **P1** proof binding | **Mechanism shown in isolation** (`MEASURED`, C): 51 probes refused, including the two G1 found accepted (cross-operation and a direct lane claim). **Still `UNPROVEN` in the library**: it needs a typed proof input and library-side checks (D3); Host minting stays `CONDITIONAL` | prerequisite for any P lane |
| **P3** unproven-owner availability | **Narrowed, not closed.** Owners with a case-A credential or a case-B channel can leave U during the attack (`MEASURED`). Day-0 and case-C owners are unchanged (§2.9). Residuals: co-location, global ceilings, harassment, and (E) re-proven devices that ran unproven | needs D3 and Owner policy on the residuals |
| **P4** distributed spray | **Unchanged.** The path adds no U capacity (D), but proven own accounts add `reach_own` linearly and #120's multi-account trust question remains | needs D5 / D7, as before |
| **P2** T0 store integrity | Not retested. The new primitives (nonce, link, issuance cap) are atomic on one Redis instance | implementation-stage evidence |
| **P5** operations | Cluster (the primitives use one hash tag), failover with lost acknowledged consumption (a lost nonce record re-opens a challenge until it expires), mixed fleet, DEC-015 (no lanes during an outage) — `UNPROVEN`. F6 shows only that proof minting fails during a simulated proof-store outage (§8.6); recoverable completion after redemption is `UNPROVEN` (§8.7) | as before |
| **P6** values | None. Every value here is a test value | after P1–P5 |

## 10. Residual risks

1. **Isolation after re-proof (E2).** A device that ran unproven during the attack carries history into P that the population
   influenced (MXE coupling). Its owner P decisions are therefore not identical to the control. This is not a direct U → P write
   and not a population-origin penalty (§8.3). Acquisition does not repair it.
2. **MXE / K-6.** One K5 family per device, regardless of proof state.
3. **Proof-path ceilings.** Per-source caps refuse a co-located owner. Global caps are a lane-wide denial lever. Without them,
   the aggregate cost of the path (signature checks, store writes, messages) grows with attacker sources.
4. **Harassment.** Under case B the victim receives at least one unsolicited message per `T_valid` per linkable operation.
5. **Stolen credential or channel.** A thief holds the owner's P lane up to `P_acct` and can exhaust it, denying the owner in P.
   Revocation is a Host process.
6. **Host trust.** Minting, revocation feed and channel delivery are Host behaviour. The library can verify only integrity and
   binding.
7. **#120.** Multi-account trusted sessions with UA rotation stay open. P removes K2 from P decisions but scales reach with
   proven accounts.
8. **Outage.** DEC-015 degraded mode has no lanes, so no owner protection exists during an outage (`DERIVED`, K-8). The study
   measured only that proof minting fails while its own proof store is down (F6). Admission behaviour and DEC-015
   integration during an outage were not tested.
10. **Recoverable completion.** A crash or a lost reply after redemption leaves a consumed link and no proof. In case B this
    can leave the owner temporarily without a path while issuance is exhausted (§8.7, `UNPROVEN`).
9. **Not modelled.** Real WebAuthn (attestation, origin, UV, counters), real mail / SMS, attacker control of the channel,
   timing side channels, production Redis topologies.

## 11. Final verdict and next gate

**Final verdict: NO-GO AFTER EXPERIMENT**, under the pre-registered rule (PLAN §6.3): gate E-(i) fails for both cases on E2.

**Scope of this verdict.** It is the verdict of the complete pre-registered candidate under PLAN v1. It is not a finding that
proof acquisition fails in principle. E2 shows a history-mediated divergence of a re-proven device's P decisions:
* 7 against 6 P admissions;
* no U → P state write;
* no population-origin penalty.

E2 does not refute mechanism A or B (§8.3).

What the evidence supports and does not support:

* **Supported in isolation** (every acquisition-specific gate passes, every detector fires):
  * an owner holding a pre-registered credential (A), or a pre-registered channel used with high-entropy, non-invalidating,
    operation-scoped links that live at least one issuance window (B), can acquire a proof while `U_acct` is exhausted and the
    path itself is flooded;
  * the attacker gains no proof, no P admission and no extra U capacity;
  * the boundary refuses every forged, replayed, cross-bound or merely claimed proof;
  * the new primitives are at-most-once on a single Redis instance; proof minting fails during a simulated proof-store outage.
* **Not supported:**
  * complete owner isolation on the candidate pipeline for a device that ran unproven during the attack (E2);
  * any guarantee for case C or day-0;
  * any guarantee against co-located or global-ceiling denial;
  * any answer to P4;
  * recoverable completion after redemption (§8.7);
  * admission behaviour or DEC-015 integration during a store outage;
  * any production guarantee: every supported result is limited to the isolated Host models of this study.
* **Not decided here:**
  * whether a v2 study should re-scope E to newly acquired devices (E1/E3/E4 hold) or change K-6 (one K5 family per device);
  * every policy question above (global proof-path ceilings, harassment budget, P friction on new devices, D3 contract shape).

These belong to the independent architectural review and the Owner.

Nothing here opens G3 or a runtime change. The work stops at the review gate.
