# Identity-Aware Bounded Admission — Architecture Contract (#97)

> **Status:** ARCHITECTURE PROPOSAL, analysed through Gate A (architecture contract) and Gate B (paper security
> feasibility). **NOT APPROVED. NO PRODUCTION CHANGE.** Not an ADR, not a GO, not a prototype authorization.
> **Verdict:** `CORRECTED PER DIRECT LEAD REVIEW — PENDING LEAD RE-REVIEW`; Gate C not recommended before Owner decisions
> D1–D4 (§12).
> **Workstream:** [#97](https://github.com/Maatify/php-rate-limiter/issues/97); dependencies
> [#100](https://github.com/Maatify/php-rate-limiter/issues/100), [#106](https://github.com/Maatify/php-rate-limiter/issues/106),
> [#120](https://github.com/Maatify/php-rate-limiter/issues/120); ledger [#110](https://github.com/Maatify/php-rate-limiter/issues/110);
> Draft PR [#126](https://github.com/Maatify/php-rate-limiter/pull/126).
> **Code baseline:** `main@4c6ca618d8206ba502961eaf06ffb82a5d61b50d`. Every `CODE` reference points into that tree.
> **Companion:** [`SECURITY_FEASIBILITY.md`](SECURITY_FEASIBILITY.md) holds Gate B (accounting model, invariants, threat
> verdicts, storage feasibility, falsification). This file holds Gate A, the proposed contract, the Owner decisions and the
> recommendation. Neither file repeats the other; cross-references use section numbers (`F§n` = feasibility file).
> **Authority:** the Owner decides every material policy, public contract, ADR, prototype, merge and release.
> [DEC-019](../../docs/decisions/DEC-019_INTERIM_SECURITY_FIRST_CORRELATION_POSTURE.md) stays ACTIVE and unchanged.

| Label | Meaning |
| --- | --- |
| `CODE` | Fact read from source at the baseline, with `file:line` |
| `CONTRACT` | Canonical document or ACTIVE decision at the baseline |
| `MEASURED` | Number from a merged study, quoted with its stream, arm and that study's own baseline |
| `DERIVED` | Reasoning from `CODE` / `CONTRACT` / `MEASURED`; nothing was executed for it |
| `PROPOSED` | Element of this design; not approved, not built |
| `UNPROVEN` | A claim the design needs and that has no evidence yet |

Short paths: `EP` = `src/Service/EvaluationPipeline.php`, `RLE` = `src/Service/RateLimiterEngine.php`,
`DIR` = `src/Service/DeviceIdentityResolver.php`, `DM` = `docs/DECISION_MATRIX.md`, `POL` = `docs/POLICIES.md`,
`DFP` = `docs/DEVICE_FINGERPRINT.md`, `KS` = `docs/KEY_STRATEGY.md`, `FS` = `docs/FAILURE_SEMANTICS.md`.

---

## 1. Governing correction

**S0's decisions are not a security oracle.** S0 can deny many requests because it treats unrelated users who share an IP,
a user agent or a passive fingerprint as one actor. Reproducing S0's denial counts is therefore not an acceptance criterion.
S0 numbers are used here only for comparison and attribution.

The design is judged against independent properties: the scope a penalty is attributed to must be justified by evidence;
verifier admissions must be bounded; rotating identities must not mint capacity; insufficient correlation must not produce
collective denial; attacker admissions and legitimate harm are reported side by side. A move from "S0 denies" to "candidate
admits" is not automatically a regression, and removing an unsound block does not license unbounded verifier access.

---

## 2. Result in brief

Gate A found three contract gaps that the executor cannot close. The first version (`a79c06d`) also over-stated what the
mechanism itself requires; the Direct Lead Review of PR #126 (F126-01 … F126-05) corrected that, and this revision separates
**Lead technical choices** (recommended here, closed by the Lead) from **Owner security decisions** (§11).

| ID | Finding | Kind | Consequence |
| --- | --- | --- | --- |
| **GA-1** | No existing input is a *current, account-bound proof* for the primary Login of a logged-out user. `sessionDeviceId` trust is post-authentication only; `isDevicePreviouslyVerifiedForAccount` is a historical Host boolean (§5) | `CONTRACT` gap | Without a proof contract, Login is **U-only** and proven-owner protection cannot be claimed for Login (D3) |
| **GA-2** | The Level-3 session identifier is contractually "Advisory only" and "NOT proof of device ownership" (`DFP` §3.3). That text does not by itself establish a P entitlement for an account and operation | Proposed change of contractual meaning | Counting a trusted session as proof for same-account re-authentication or OTP is part of D3 |
| **GA-3** | `U_acct` refuses with a capacity reason, not a punitive `HARD_BLOCK`, so its **decision class** does not engage the attempt-count `HARD_BLOCK` clause of `POL` §1. Its **availability effect** does engage the owner-safety clause ("MUST NOT enable renewable or scheduled denial-of-owner"): anyone can exhaust it each window and refuse an owner who has no proof. F§2.9 proves that, for unproven traffic, a per-target bound independent of the attacker's number of sources and protection of the unproven owner from a remote attacker exclude each other — S0's account-wide K4 score is already on the first side | `CONTRACT` tension with a proven trade-off | Three concrete options, with a recommendation, in D4 |
| **GA-4** | K4 is one account key, fed by evidence from any requester and enforced on every requester, including trusted sessions (`EP:509-515`). A reserved P capacity is useless while a K4 block can close it | `CODE` | Owner safety needs proven requests not to be denied by account penalties earned from unproven evidence. Five mechanisms are compared in §7.2; the recommended one keeps the existing K4 key (LT-1). The normative change is D2 |
| **GA-5** | An irreversible reservation taken inside `checkOnly` bounds admissions with **no public type change** (tier T0, §6.5). A public permit token is needed only to bind outcomes to admissions replay-safely (tier T1) | Contract scope, corrected | LT-2 (Lead) |

What survives on paper, under its stated conditions (Gate B):

* Removing collective punishment derived from IP / UA / passive fingerprint correlation, and replacing its *bounding* role
  with non-persistent capacity scopes that rotation of UA, client id, fingerprint or session cannot multiply (F§3 I1–I8).
* Outcome-independent per-window bounds, so `checkOnly`-only Hosts and dropped outcomes are bounded too — something S0 does
  not do (`MEASURED` D24K1 `pre` 1440/1440, #119 on `main@ebeb0a3`).
* Proven requests that unproven traffic can neither drain nor close — **only** where a proof exists (GA-1, GA-2).

What does not survive, whatever is decided:

* A co-located attacker and an unproven office are indistinguishable at the source scope; `U_src` trades one against the
  other (F§5 rows 3–4).
* For an unproven owner, a σ-independent per-target bound and protection from a remote attacker exclude each other (F§2.9).
* A service ceiling (`U_glob`, `P_glob`) is a denial lever for the whole lane, and per-policy ceilings are bypassed by
  splitting traffic across policies that share a verifier (F§7 B4-5, B4-6).
* Bounds weaken to "per window, modulo lost acknowledged writes" under Redis failover and to per-worker local limits under
  DEC-015 degraded mode (F§6). Outcome scoring is at most once per permit, not exactly once (F§2.3).
* Record-only (`out`) Hosts get no pre-verifier bound.

---

## 3. Current architecture contract (Gate A baseline)

### 3.1 What each command does today

`EP::process()` runs one pipeline for every command (`EP:176-492`). The gating is:

| Step (`CODE`) | `checkOnly` | `recordFailure` | `recordSuccess` | plain `limit()` (API access) |
| --- | --- | --- | --- | --- |
| Active-block fail-fast on K1–K5 (API: K1–K3 only; trusted authentication: K1 skipped) `EP:198`, `EP:498-549` | yes | yes | yes | yes |
| Device-cap / ephemeral observation (10 per account, 50 per IP, 900 s) `EP:209-230`, `EphemeralBucket.php:20-21` | yes | yes | yes | yes |
| Threshold candidates on current decayed scores `EP:274-281` | yes | yes | yes | yes |
| Distributed-account observation (`DISTRIBUTED_ACCOUNT`) `EP:286-297` | yes | no | no | no |
| Churn / dilution correlation `EP:300-309` | yes | yes | yes | yes |
| Credential-spray observation (`CREDENTIAL_SPRAY`) `EP:314-325` | yes | no | no | no |
| New-device flood (non-API, with account) `EP:328-373` | yes | yes | yes | no |
| Score updates, known-device allowance, budget counting `EP:380-396` | **no** | yes | no (Login/OTP `access = 0`) | yes (`access > 0`) |
| Anti-Equilibrium escalation read `EP:403-441` | no | yes | no | no |
| Budget `SOFT_BLOCK` candidate `EP:2180-2204`, `EP:2237-2251` | Login only (`precheck_enforcement`) | if eligible | never | no budget |
| Anti-Equilibrium record of an issued `SOFT_BLOCK` `EP:456-474` | yes | yes | no | no budget |

`DERIVED` consequences:

* Nothing is reserved before the verifier. `checkOnly` is read-only for scores, so concurrent pre-checks are not serialized
  (DEC-021 Consequences) and a `pre`-only Host never scores K4/K5 (`MEASURED` D24K1 `pre` 1440/1440, #119 R16).
* `recordSuccess` never credits or resets anything (DEC-007); it still observes correlation and still meets active blocks.
* Correlation observation runs on every command, so a record-only Host still feeds churn/dilution.

Modes used below: `pre` = `checkOnly` only; `pre+out` = `checkOnly` → verifier → `recordFailure|recordSuccess`;
`out` = verifier → record only. **Only `pre` and `pre+out` call the library before the verifier.**

### 3.2 Identity and trust inputs today

| Input | Library use (`CODE`) | Trust status (`CONTRACT`) |
| --- | --- | --- |
| `ip` | K1/K2/K3 prefix: IPv4 exact address, IPv6 `/64` (`EP:2786-2799`); IPv6 `/48`, `/40`, `/32` macro scopes are correlation-only (`EP:242-249`, `KS` §3.1) | Observation |
| `ua` | Normalized to `browser/major` or `other/0` (`DIR:57-74`); part of K2 (`EP:2612`) and of the fingerprint material (`DIR:39`) | Observation |
| `clientFingerprint` | Fingerprint material; any non-empty value makes confidence MEDIUM (`DIR:37`); no size bound (F97-05) | Client-held hint |
| `sessionDeviceId` + `isSessionTrusted` | Trusted session ⇒ HIGH; K1 checks and K1 candidates become advisory (`EP:513`, `EP:1509-1511`) | `DFP` §3.3: post-authentication only, "Advisory only", "NOT proof of device ownership"; the Host proves binding |
| `isDevicePreviouslyVerifiedForAccount` | Part of `isKnownForAccount` (`EP:2388-2391`): DEC-021 K5 scoring instead of K4 | `DFP` §4.3: Host-provided historical association; the library verifies nothing |
| `accountId` | K4, K5 (`EP:2614-2615`); `null` ⇒ no account keys | Host-asserted target |
| `correlationId` | Spray subject only: `correlationId ?? accountId` (`EP:1481`) | Host-owned opaque subject |

The default resolver always returns a fingerprint hash (`DIR:39-40`), so the missing-fingerprint rules are unreachable with it
(F-HMCI-01; `MEASURED` in #125 Q4).

### 3.3 Official presets

| | `login_protection` | `otp_protection` | `api_heavy_protection` |
| --- | --- | --- | --- |
| Capabilities (DEC-008) | `CREDENTIAL_SPRAY`, `DISTRIBUTED_ACCOUNT`, `TRUSTED_AUTHENTICATION` + DEC-007 + DEC-021 markers | same | `API_OVERUSE` |
| Thresholds | K4 5/8/12, K5 8/12/16 (`LoginProtectionPolicy.php:57-58`) | K4 4/7/10, K5 8/12/16 (`OtpProtectionPolicy.php:56-57`) | K1 600/600/600, K2 120/off/off, K3 300/300/off (`ApiHeavyProtectionPolicy.php:46-48`) |
| Deltas | k1_spray 5, k2_missing_fp 4, k4_failure 3, k4_repeated_missing_fp 6, k5_failure 2 | k2_missing_fp 6, k4_failure 5, k4_repeated 8, k5_failure 4 | access 1 |
| Budget | 20 / 24 h, L3, cooldown 3600 s, `precheck_enforcement = true`, micro-cap 8 | 10 / 24 h, L4, cooldown 7200 s, `precheck_enforcement = false`, Recovery Guard | none |
| Known-device allowance | 8 | 2 | n/a |
| Failure mode / fallback | `FAIL_CLOSED`; ACCOUNT 3/600 s, IP 20/600 s | `FAIL_CLOSED`; ACCOUNT 2/900 s, IP 10/900 s | `FAIL_OPEN`; IP 120/60 s, IP+UA 60/60 s (`FailureFallbackProfileEnum.php:37-45`) |

Login defines no K1 thresholds, so its `k1_spray` score never produces a level (`CODE` `EP:2761-2766`); K1 enforcement for
Login comes only from the persisted spray block.

### 3.4 Engine-level contracts

* **DEC-008 validation** (`RLE:68-195`): authentication and `API_OVERUSE` cannot combine; an authentication policy needs K4
  thresholds, a positive `k4_failure`, a budget, a device-aware delta, `FAIL_CLOSED`, and an ACCOUNT + IP_PREFIX fallback; an
  `API_OVERUSE` policy needs K1/K2/K3 thresholds, a positive `access` delta, an IP_PREFIX + IP_PREFIX_UA fallback, and no
  ACCOUNT fallback dimension (`RLE:122`).
* **DEC-015 / DEC-011 failure path** (`RLE:308-345`, `RLE:418-447`): a typed `BackendFailureException` feeds the circuit; with
  the circuit open, the mode is `DEGRADED_MODE` and `LocalFallbackLimiter` applies process-static counters
  (`LocalFallbackLimiter.php:37-43`; 4096 subjects per dimension, then one overflow bucket).
* **DEC-007**: K4 is generation-bound; a qualifying L2+ punishment publishes re-entry evidence atomically. A `checkOnly` on the
  account while evidence is valid treats K4 as 0 for **any** requester (`EP:255-270`).
* **DEC-021**: K5 thresholds, K5-only persistence of K5 levels, a fingerprint-independent account allowance whose overflow adds
  `k4_failure` to K4 (`EP:1600-1616`), and source-scoped Anti-Equilibrium.
* **Public surface**: `limit(context, command)` and `claimPostPunishmentReentry()`. Results are `ALLOW`, `SOFT_BLOCK`,
  `HARD_BLOCK` (`src/DTO/RateLimitResultDTO.php:14-16`). There is no attempt identity.

---

## 4. K1–K5 responsibility reconciliation (A2)

Each key is answered on the eight questions of the brief: (1) reads, (2) writes, (3) penalties it can produce, (4) the
defect, (5) where each security function goes and what is deliberately not moved, (6) preset impact, (7) custom-policy
impact, (8) contracts and decisions touched. Admission scopes named here are defined in §6.

### 4.1 K1 — IP prefix

| # | Answer |
| --- | --- |
| 1 | Active block, skipped for trusted-authentication requests (`EP:509-515`); score (`EP:252`); spray-set anchor `credential_spray:{K1}` (`EP:1554`); dilution-set member (`EP:1230-1237`); device-cap IP anchor (`EP:210-217`); account anchor `K4:K1` (`EP:1447-1454`); IPv6 macro scopes (`EP:242-249`) |
| 2 | Login `k1_spray` +5 per failure, no Login threshold (`EP:2722-2724`); API `access` per request (`EP:2686-2691`); spray block K1 L2 60 s (`EP:1513-1526`), persisted also when it is a trusted advisory candidate (`EP:2221`, `EP:2232`); API K1 score block (600/600/600 ⇒ L3) (`EP:604-616`) |
| 3 | `HARD_BLOCK` for every untrusted requester on the IP (Login/OTP spray, API K1) |
| 4 | Breadth of subjects at one IP is attributed to everyone behind it; a trusted requester persists a block it does not obey. `MEASURED` #108 (S0, D2): 117/121 legitimate denials; K1+K2 both disabled 27/121; K1 alone disabled: SP1n 4 → 200/200. IPv4 exact address ⇒ every CGNAT subscriber shares one scope (`DERIVED`) |
| 5 | *Spray bound* → `U_src(op)`, a per-window count of admitted unproven attempts per source, refused as capacity, never persisted as punishment. *Spray detection* stays an observation that may escalate **U-lane account risk** (K4, §7), not the IP. *Trusted advisory persistence* is removed: P-lane traffic never charges `U_src` and is never refused by it. **Not moved:** a hard block from breadth alone. API: K1 stays the anonymous source parent |
| 6 | Login/OTP: spray `HARD_BLOCK` replaced by `U_src`. API: role unchanged |
| 7 | `CREDENTIAL_SPRAY` changes meaning; a custom policy must opt in through a new typed capability rather than inherit it silently (DEC-008) |
| 8 | `DM` §5.1, `DM` §10 ("IP-only blocking is never final", "no decision based on a single signal"), `KS` §3.1, §4.3.4, DEC-019 → D1, D5 |

### 4.2 K2 — IP prefix + normalized UA

| # | Answer |
| --- | --- |
| 1 | Active block (`EP:509-533`); churn-set anchor (`EP:1174-1181`); IPv6 macro churn anchor (`EP:1197-1205`); API score (`EP:252`) |
| 2 | Churn and LOW-dilution `HARD_BLOCK` L2 60 s (`EP:1207-1222`, `EP:1264-1279`); `k2_missing_fp` (unreachable with the default resolver; no Login/OTP K2 thresholds) (`EP:2693-2701`); API `access` score (120 ⇒ L1 only) and LOW-K3 L2 remapped to a K2 `HARD_BLOCK` (`EP:573-579`, `EP:1832-1835`) |
| 3 | `HARD_BLOCK` for every requester on the IP + UA major, for every policy; API K2 `SOFT_BLOCK` |
| 4 | Observational equivalence becomes a punitive identity. `MEASURED`: #100 ABA_B 118/120 legitimate denied (S0, #116 on `main@72f6dea`); #97 D1 596/600 (S0N, #125 on `main@9e95f2f`); one browser re-authenticating with a trusted session churns itself, SESS 6/8 (F97-03). The protection it gave failed against the best response: X2 6/121 with one UA, 121/121 with UA rotation (#125). Partition by UA major multiplies the cross-account scope: TMSd 2/200 vs TMUA 36/200 (#120, `main@ebeb0a3`) |
| 5 | Per security function: **(a)** fingerprint rotation at one source → subsumed by `U_src`, which counts attempts whatever the fingerprint (F§3 I8); churn stays an observation. **(b)** one passive fingerprint across IPs (LOW dilution) → **not moved**: the inference is unsound (D1). A distributed attacker on one victim is bounded by `U_acct` (per D4, GA-3); across many victims only by `U_glob`. **(c)** cross-account aggregate at one IP + UA for trusted sessions (#113 TMSd 2/200) → **not moved as a source-scoped collective bound by default**: the trusted office and the 12-session attacker are request-for-request identical (#119 M3). Replacement: per-account P bounds + `P_glob`; a `P_src` is not recommended (D5). **(d)** API IP + UA fairness → kept as a capacity sub-limit under the K1 parent |
| 6 | Login/OTP: no K2 enforcement; existing K2 blocks are honoured read-only until they expire (§10). API: K2 rate sub-limit stays, churn/dilution `HARD_BLOCK` goes |
| 7 | `POL` §5 applies correlation to all policies, so the change reaches every custom policy unless it is capability-gated or versioned (DEC-008). A custom policy's own K2 thresholds remain its configured capacity |
| 8 | `DM` §5.3, `POL` §5, `DFP` §7.1–§7.2, `KS` §3.2, §4.3.5, DEC-019 → D1 |

### 4.3 K3 — IP prefix + DeviceFP

| # | Answer |
| --- | --- |
| 1 | Active block; API score. With LOW confidence K3 = IP + HMAC(UA major), the same partition as K2 (`DIR:39`, `EP:2613`) |
| 2 | MEDIUM/HIGH dilution confirmation `HARD_BLOCK` L2 60 s (`EP:1281-1326`); API `access` score (300 ⇒ L1/L2) |
| 3 | `HARD_BLOCK` on IP + fingerprint; API `SOFT`/`HARD` |
| 4 | A client-held value chooses the enforcement path (F97-02: constant id X2 121/121 vs 6/121, #125); LOW K3 is as collective as K2 |
| 5 | Optional per-client sub-limit `U_cid ≤ U_src`, keyed on a bounded continuity hint, with the existing 50-per-IP cardinality cap and an overflow bucket; it never creates capacity. **Not moved:** the MEDIUM dilution block |
| 6 | Login/OTP: no K3 enforcement. API: K3 sub-limit stays |
| 7 | Same as K2 |
| 8 | `DM` §5.3, `DFP` §4.2, §7.3, `KS` §3.3 |

### 4.4 K4 — account

| # | Answer |
| --- | --- |
| 1 | Active block for **every** requester on the account, including trusted sessions (`EP:509-533`); score; DEC-007 lifecycle (`EP:255-270`); budget state keyed on K4 (`EP:377`, `EP:2442-2456`); account Anti-Equilibrium watcher (`EP:403-407`) |
| 2 | Score +3 (Login) / +5 (OTP) per new-device or unproven failure, plus DEC-021 overflow (`EP:1600-1616`); persisted blocks from score levels (`EP:1909-1931`), flood L1 (`EP:360-371`), distributed-account L4 on the third occurrence (`EP:718-728`), Anti-Equilibrium L2 (`EP:408-422`); generation-bound publication (`EP:2057-2108`); budget counter (`EP:1794-1796`) |
| 3 | Account-wide `SOFT`/`HARD` |
| 4 | Evidence from anyone becomes a penalty on everyone, including the owner's proven sessions. `DERIVED` from `CODE`: three unproven Login failures (+3 each = 9 ≥ L2 = 8) persist a K4 `HARD_BLOCK` (60 s) that the owner's trusted session meets (`EP:513` skips only K1); device rotation against the account reaches K4 L4 (1800 s); DEC-007 re-entry evidence is exposed to any requester's pre-check on the account, not only to the owner |
| 5 | Lane-aware enforcement on the existing key (§7.2, M-C). K4 keeps its key, score, budget, DEC-007 lifecycle, account watcher, distributed-account and flood escalation, and binds U requests as today. Account-scoped candidates of a P request are advisory: persisted, so proven failures (including DEC-021 overflow) harden the account against U traffic, but not enforced on the P requester, which is bounded by `P_acct` / `P_proof` caps and K5. Admission counters are separate. **Not moved:** U-lane evidence closing a proven request |
| 6 | Login/OTP materially changed; API unaffected |
| 7 | Every authentication custom policy (DEC-008 capabilities, DEC-007 / DEC-021 markers) is affected; the change must be an explicit, versioned opt-in |
| 8 | DEC-007, DEC-021 §3 and §5, `DM` §1, §2.3–§2.5, §3.3, §5.2, §5.4, §10, §11, `KS` §3.4, §4.5, §4.6, §10.1 → D2 (mechanism LT-1) |

### 4.5 K5 — account + DeviceFP

| # | Answer |
| --- | --- |
| 1 | Active block; score; device Anti-Equilibrium watcher (`EP:2328-2357`); micro-cap (`EP:1771-1790`) |
| 2 | Known-device failure score with DEC-021 thresholds and K5-only persistence (`EP:1933-1940`); flood `HARD` (`EP:347-358`); every member of a distributed-account snapshot L2 (`EP:692-716`); device Anti-Equilibrium L2 |
| 3 | Device-scoped `SOFT`/`HARD` on that account |
| 4 | With LOW confidence the "device" is account + UA major (`DIR:39`, `EP:2615`): the owner's same-major browsers and an attacker who picks that major share one K5, and distributed-account persistence writes attacker-chosen fingerprints (`DERIVED`). A browser update creates a new K5 (`MEASURED` UAC known-device reach 8/12, #125) |
| 5 | K5 becomes the per-device sub-scope of the lane that issued it. P lane: DEC-021 per-proof bound, keyed on the proof's device or session reference rather than the passive UA. U lane: distributed-account and flood evidence escalates K4, which is advisory on P requests. **Not moved:** U-lane device evidence blocking proven devices |
| 6 | Login/OTP |
| 7 | DEC-021 opt-in policies |
| 8 | DEC-021, `DM` §2.2.2, §5.2, §5.4, `KS` §3.5, §4.3.3 |

### 4.6 Before and after

| Security function | Today | Proposed owner | Status |
| --- | --- | --- | --- |
| Single-source spray bound | K1 breadth `HARD_BLOCK` (shared, persisted) | `U_src` capacity (per window, non-persistent) | `PROPOSED` |
| Fingerprint-rotation evasion at one source | K2 churn `HARD_BLOCK` | `U_src` (rotation-invariant) | `PROPOSED` |
| Distributed attack on one victim | K4 score (outcomes only); K2 LOW dilution (one-UA attacker only) | K4 score + `U_acct` | **per D4 (GA-3)** |
| Distributed attack on many victims | none against the best response (X2 121/121) | `U_glob` (a denial lever, F§7 B4-3) | `PROPOSED`, D5 |
| Cross-account bound for proof holders at one source | K2 (one UA major); multiplied by majors (#120) | per-account P bounds + `P_glob`; no `P_src` (recommended) | D5 |
| Per-account harassment protection | none for proven sessions (GA-4) | account penalties advisory on P (M-C) + reserved `P_acct` / `P_proof` | D2, D3 |
| Known-device bound | DEC-021 K5 + allowance → K4 | DEC-021 unchanged; its K4 overflow advisory on P; P day caps | D2 |
| Collective punishment from correlation | K1/K2/K3 correlation `HARD_BLOCK` | none; correlation is observation | D1 |
| API source rate | K1 600 | K1 parent unchanged | unchanged |
| API fairness sub-limits | K2 120, K3 300 + correlation `HARD_BLOCK` | K2/K3 sub-limits under the K1 parent; no correlation block | D1, D3 |

---

## 5. Trust and proof boundary

| Class | Members | May be used for | Must never be used for |
| --- | --- | --- | --- |
| **Observation** | IP, UA, passive fingerprint, churn/dilution/spray sets, IPv6 macro scopes | Capacity scoping (source), risk metrics, U-lane escalation of the *targeted account* | Attributing a penalty to everyone sharing the observation |
| **Continuity hint** | `clientInstanceId` (`PROPOSED`, a field separate from `clientFingerprint`), `clientFingerprint`, untrusted `sessionDeviceId` | Optional sub-limit `≤` parent; K5 continuity inside a lane | Raising confidence or lane; creating capacity; acting alone as punishment |
| **Historical association** | `isDevicePreviouslyVerifiedForAccount`, K5 history | DEC-021 scoring classification (K5 instead of K4) | Admission entitlement |
| **Current proof** (`PROPOSED`) | A Host-validated statement, made now, that the requester holds an account-bound credential for **this** target account and **this** operation | P lane | Any other account or operation |

Rules (`PROPOSED`):

1. Identity is not trust. A client id identifies a continuity record; it proves neither uniqueness of a person nor a right to
   attempts. A server-signed cookie proves issuance, not that one was issued per person (proposal 07 in the #110 comparison).
2. A proof names its account and operation. The library refuses a proof whose account or operation differs from the request
   and puts the request in U. Today's booleans cannot be checked this way (`DERIVED`).
3. Mismatched proof use (session for A, target B) is U for B and is not a penalty on A's session.
4. Losing a proof (cookie loss, expiry) moves the requester to U; history keyed by account stays (`DFP` §3.3 persistence rule).
5. The library cannot verify the Host. Integrity of P rests on Host compliance; a Host that asserts trust for the wrong
   account turns relief into bypass (`MEASURED` #119 SPV 4 → 200/200 as a Host-violation control).

**What qualifies as a proof per preset** (`DERIVED` from §3.2):

| Preset / flow | Candidate proof | Existing input? |
| --- | --- | --- |
| Login, logged-out primary sign-in | Account-bound device credential (e.g. a server-issued remember-device token) validated now | **No** (GA-1). `isDevicePreviouslyVerifiedForAccount` is historical; `sessionDeviceId` is post-authentication |
| Login, re-authentication inside a session for the same account | Trusted session for that account | Yes as data, but "advisory only" by contract (GA-2) |
| OTP after primary authentication | The primary-authenticated session for that account | Yes as data (GA-2). The attacker who has the password is in P too (§8.3) |
| OTP in passwordless or recovery flows | None | No → U |
| API Heavy | Host-validated API principal | No field today (D3) |

---

## 6. U/P admission contract (`PROPOSED`)

### 6.1 Lanes and scopes

| Lane | Entered when | Reserved scopes (all-or-nothing) | Penalties enforced on the request |
| --- | --- | --- | --- |
| **U** (unproven) | no valid proof for the target account and operation | `U_acct(subject, op)` *(per D4)*, `U_src(source, op)`, `U_glob(verifier class)` *(per D5)*, optional `U_cid ≤ U_src` | K4 (account), K5 of the U device |
| **P** (proven) | valid proof for the target account and operation | `P_acct(account, op)` per window and per day, `P_proof(proof ref) ≤ P_acct` per window and per day, `P_glob(verifier class)` *(per D5)* | K5 of the proof's device; **not** account penalties (§7.2, D2) |
| **A** (anonymous API) | API request without a validated principal | K1 source parent, K2/K3 sub-limits, optional `A_glob` | API scores only |
| **R** (API principal) | API request with a validated principal | `R_principal(op)`, optional `R_glob` | principal state |

* `subject` is a Host-supplied opaque target subject that exists **whether or not the account exists**, so a refusal never
  reveals existence (F§2.6).
* `source` is IPv4 exact address or IPv6 `/64`; an IPv6 hierarchy (`/48` parent) is `UNPROVEN` and part of D5.
* `verifier class` groups every policy that calls the same verifier (for example login, re-authentication and password change),
  so a global ceiling cannot be bypassed by splitting traffic across policies (F§7 B4-6). Today state is namespaced per policy
  name (`EP:2607`), so this grouping is new (LT-5).
* No scope is keyed by UA, client id, fingerprint or session. Those may only key sub-limits that cannot raise a parent.

### 6.2 Operation

At `checkOnly`, for a policy that opts in (DEC-008 typed capability): read the lane's active penalties; then, in one atomic
step, check every reserved scope and, if all have headroom, increment all of them; otherwise write nothing. Admission counters
are never decremented. With tier T1 (§6.5) the same step also writes a permit record; outcomes then go through the permit state
machine of F§2.3. With tier T0, `recordFailure` / `recordSuccess` stay unbound outcome commands, scored as they are today.

### 6.3 Results

| Outcome of `checkOnly` | Expressed with today's DTOs (T0) | Rule |
| --- | --- | --- |
| Admitted | `ALLOW` (T1 adds an opaque permit token to the metadata) | The Host calls the verifier once per admission (H1, F§1) |
| Capacity refusal | `SOFT_BLOCK` with Retry-After (the `DM` §0 meaning of `SOFT_BLOCK`) and a capacity value in the existing `RateLimitMetadataDTO::$cause` field (precedent for a non-punitive capacity cause: `fallback_limit_exceeded`, `RLE:318-322`, today returned with `HARD_BLOCK`) | Never persisted as a block, never counted by Anti-Equilibrium, never escalated, never attributed to an identity |
| Penalty active | `HARD_BLOCK` / score `SOFT_BLOCK` as today | Scope is the lane's account, device or source sub-limit, never a correlation group |
| Proof required | **Not proposed** for the official presets | No preset has a proof path; a `PROOF_REQUIRED` constant would only make sense for a Host-declared path and would be a new decision value |

### 6.4 Admission and outcome by mode

| Mode | Admission | Outcome | Guarantee |
| --- | --- | --- | --- |
| `pre` | at `checkOnly` | none | Per-window bounds hold (F§3); no outcome-based escalation (as today) |
| `pre+out`, T0 | at `checkOnly` | unbound `recordFailure`/`recordSuccess` | Per-window bounds + outcome scoring exactly as robust as today (a duplicated outcome scores twice, as today) |
| `pre+out`, T1 | at `checkOnly`, with permit | permit-bound outcome | Per-window bounds + at-most-once scoring per permit (F§2.3) |
| `out` | none before the verifier | unbound, as today | **No pre-verifier bound.** The result may only gate what the Host does after verification. A permit-bearing outcome that fails is never re-sent as an unbound `out` outcome |
| mixed routes on shared state | per route | per route | Bounds count only admissions; verifier calls on `out` routes are not counted against them |

### 6.5 Minimum public contract (F126-04)

| Tier | Public change | What it delivers | What it lacks |
| --- | --- | --- | --- |
| **T0** | None in types. Semantic change of `checkOnly` for policies that opt in through a new typed capability (DEC-008), plus new `cause` values and an optional typed proof input (D3) | Every admission bound of F§3 that the Owner decisions allow: none of them needs an outcome binding, only an irreversible reservation before the verifier. `checkOnly` is already documented as not globally read-only (`docs/guides/USAGE_GUIDE.md:197`) | Outcome-to-admission binding: duplicates and replays of `recordFailure` score again, as today; no per-attempt observability; a lost `checkOnly` reply costs a second admission |
| **T1** | T0 + an optional opaque permit token in `ALLOW` metadata and an optional token on outcome commands (additive) | At-most-once scoring per permit; conflicting and late outcomes detected; per-attempt observability | Exactly-once scoring (not achievable with the current non-transactional pipeline, F§2.3) |
| T2 | New `admit()` / `complete()` API and new decision constants | Clearer naming only | Nothing T1 lacks; larger surface |

Impact on the official presets (`DERIVED`):

* **Usability.** A Host that follows the documented `checkOnly → verify → record` flow keeps working; what changes is that
  `checkOnly` consumes capacity, so a Host that calls `checkOnly` as a probe (for example on page load and again on submit)
  pays twice. That is why the change must be an explicit opt-in capability and documented.
* **`pre+out`.** T0 keeps today's outcome path unchanged. T1 fixes duplicate scoring, which S0 also has.
* **DEC-021.** The known-device allowance is consumed on `recordFailure` (`EP:1600-1616`); T0 leaves that unchanged. With T1 the
  allowance is consumed once per permit-bound failure.

Recommendation (LT-2): **T0 for the bounds; T1 as an additive option for `pre+out` Hosts.** T2 adds nothing.

### 6.6 Bootstrap and the new user

A first-time user, a user on a new device and a user who lost the cookie have no proof, so they are U. They are admitted while
`U_src`, `U_acct` (if adopted) and `U_glob` (if adopted) have headroom. They are refused with a capacity `SOFT_BLOCK` when (a)
a co-located requester exhausted `U_src`, (b) someone exhausted `U_acct` for their account, or (c) `U_glob` is exhausted. A
successful sign-in may let the Host issue a proof for next time — only if D3 creates that contract. Without D3, Login users
never leave U, so an office stays a day-0 office every day.

`MEASURED` context (S0, not this design): on `main@a6c79f4`, 86–88 of 100 office browsers had no successful login on each of
14 days (#118 R8); idealized standing reached the whole office only on day 9 (#118 R9, model).

---

## 7. Owner protection (A3)

**Question.** If an attacker exhausts unproven attempts on account A, how does the owner still use the protected share?

### 7.1 Today

`DERIVED` from `CODE`: the owner does not. Every K4 mechanism is fed by any requester and enforced on every requester:

| K4 mechanism | Fed by unproven attacker? | Closes owner's proven session? |
| --- | --- | --- |
| Score (Login +3, thresholds 5/8/12) | yes (`EP:2718-2720`) | yes, persisted on K4 (`EP:1919-1931`), read for all (`EP:509-533`) |
| Budget (20/24 h, `SOFT` L3, trusted floor L2) | yes (`EP:1764-1766`) | issues a `SOFT` to the owner too, one-shot per cooldown (`EP:2192-2203`) |
| Anti-Equilibrium (3 account `SOFT` in 6 h ⇒ K4 L2) | yes, account watcher (`EP:469-473`) | yes |
| DEC-007 lifecycle | yes | re-entry evidence is exposed to any pre-check (`EP:255-270`) |
| DEC-021 allowance | consumed by any "known" claim the Host makes | overflow adds to the shared K4 (`EP:1613-1615`) |
| Distributed-account occurrence | yes, by device rotation (`EP:718-728`) | K4 L4, 1800 s |
| Flood | yes (`EP:360-371`) | K4 L1 |

A reserved `P_acct` is useless while any of these can close the P lane. Exempting P without another bound is forbidden by the
brief and would remove DEC-021's protection against a stolen proof.

### 7.2 Mechanisms compared (F126-01)

The problem is proven by code; the mechanism is a design choice. Five candidates:

* **M-A — full key split.** `K4U` (legacy K4 key) and `K4P` (new key), each with its own score, budget, watcher and lifecycle;
  each lane obeys only its own key. (This was the `a79c06d` proposal.)
* **M-B — lane-tagged penalties on one key.** One K4 history; every persisted account block records the lane of its evidence;
  a request obeys only blocks of its own lane.
* **M-C — lane-aware enforcement on the existing K4.** One K4 history (score, budget, DEC-007 lifecycle, account watcher), fed
  by evidence from both lanes as today. U requests obey it exactly as today. For a P request, account-scoped candidates are
  **advisory**: persisted, so they bind U traffic on that account, but not enforced on the P requester — the pattern the
  pipeline already uses for trusted-session K1 candidates (`EP:584-588`, `EP:1863-1891`, `EP:2151-2158`). P is bounded
  instead by `P_acct` and `P_proof`, per window and per day, and by DEC-021 K5 on the proof's device.
* **M-D — unproven evidence never punishes the account.** U is bounded by capacity scopes only.
* **M-E — no change.**

| Criterion | M-A | M-B | M-C | M-D | M-E |
| --- | --- | --- | --- | --- | --- |
| 1. Unproven traffic cannot close a proven request | yes | yes | yes | yes | **no** (GA-4) |
| 2. Outcome-based protection on the U lane, where foreign guessing happens | as today (on `K4U`) | as today | as today, plus proven failures also harden U | **lost** | as today |
| 3. Bound on a stolen proof per window | P caps + `K4P` (`pre+out` only) | P caps + P-tagged blocks | P caps (outcome-independent) | P caps | DEC-021 K5 + allowance → shared K4 |
| 4. Long-horizon bound on a stolen proof | `K4P` escalation; none in `pre` (`checkOnly` scores nothing, §3.1) | same as M-A | P day caps; also in `pre` | P day caps | DEC-021 allowance → K4 |
| 5. DEC-007 | lifecycle moves to `K4U`; P outside it | publication primitive must carry the lane tag | unchanged | loses its U-evidence trigger | unchanged |
| 6. DEC-021 | overflow re-pointed to `K4P` | overflow tagged P | allowance and overflow unchanged; overflow binds U only | unchanged | unchanged |
| 7. Anti-Equilibrium | two account watchers | one watcher, lane-tagged escalation | one account watcher, fed only by issued U `SOFT`s (P account candidates are advisory, so never issued); device watcher unchanged | unchanged | unchanged |
| 8. Budget | two budgets | one budget, lane semantics | one budget; its `SOFT` is advisory on P | unchanged | unchanged |
| 9. Contract cost | new key family (`KS`) | `BlockStateDTO` and the block / lifecycle publication operations (Store contract) | pipeline only | pipeline only | none |
| 10. Rollback to S0 | P-lane penalties lost | S0 ignores the tag: S0 semantics | S0 semantics; nothing lost but the caps | S0 resumes with less K4 history | — |

Rejections, each by the table:

* **M-E** fails criterion 1 (`CODE`, §7.1).
* **M-D** loses criterion 2 on the lane where credential guessing against other people's accounts happens, and gives nothing
  on criterion 1 that M-C does not; it is dominated by M-C.
* **M-B** has M-A's properties but changes the Store block schema and DEC-007's atomic publication; it is dominated by M-A on
  cost and by M-C on every criterion.
* **M-A versus M-C.** Both satisfy 1 and 2. M-A's only distinct property is outcome-based, account-wide escalation for proof
  holders (`K4P`); it does not work in `pre`, where nothing is scored. M-C replaces it with outcome-independent day caps that
  also hold in `pre`, keeps DEC-007, DEC-021's allowance path, the single budget and watcher, and the K4 key family unchanged,
  and loses nothing on rollback. M-C dominates M-A **unless** the Owner requires outcome-based rather than count-based escalation
  for proof holders — that requirement would be part of D2.

**Recommendation (LT-1): M-C.** The normative content — proven requests are not denied by account penalties, and are bounded
by caps and K5 instead — is an Owner decision (D2), because it changes the protection `DM` §2.2.2–§2.5 and DEC-021's
consequences give a proven device today ("Real account evidence can still cause account-level enforcement").

### 7.3 What M-C gives and does not give

Gives, with D2 and D3: unproven traffic cannot consume `P_acct` or `P_proof` (disjoint scopes) and cannot close a proven
request (account penalties are advisory on P). One stolen proof is bounded by its `P_proof` caps and K5; an attacker holding
several proofs for A is bounded by `P_acct(A)` caps. `P_acct ≥ k · P_proof` keeps one stolen proof from exhausting the account
share (`k` belongs to D5).

Does not give:

* An owner without a proof is U and shares `U_acct` and K4 with the attacker; F§2.9 shows no rule over the available observables
  can both bound the attacker independently of its sources and protect that owner (D4). For Login without D3, that is every
  owner.
* An attacker holding enough proofs for A exhausts `P_acct(A)` and refuses the owner's other proofs for the window or day — a
  compromise case, bounded but real.
* Under DEC-015 degraded mode the local fallback has no lanes (dimensions ACCOUNT, IP_PREFIX, IP_PREFIX + UA), so owner
  protection is absent during an outage (D6).

---

## 8. Admission truth tables (A1)

Columns follow the brief: **Input → Trust → Lane → Required budgets → Atomic operation → Result → Enforcement scope → State
updates.** Admission happens at `checkOnly` in `pre` and `pre+out`; in `out` nothing is reserved and only the state-update
column applies (§6.4). "Reserve{…}" is one all-or-nothing operation. `CAP(scope)` is the capacity `SOFT_BLOCK` of §6.3;
`PEN(key)` is an active penalty. Rows marked ◇ depend on D3 (proof contract) and do not exist without it; `U_acct` follows
D4 throughout (GA-3).

### 8.1 Login (`login_protection`)

| Case | Input | Trust | Lane | Budgets | Atomic operation | Result | Enforcement scope | State updates (`pre+out`) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| L1 No target account | `accountId = null`, no subject | observation | U | `U_src`, `U_glob` | Reserve{src, glob} | ALLOW / CAP(src or glob) | source, service | none account-scoped. Weaker than L2; the contract should require a Host target subject (§6.1) |
| L2 Target known, requester unproven | subject, no proof | observation | U | `U_acct`, `U_src`, `U_glob` | Reserve{acct, src, glob} | ALLOW / CAP(acct, src or glob) / PEN(K4) | account, source, service | failure: K4 +3, budget, account watcher. success: nothing refunded |
| L3 Client instance id only | L2 + `clientInstanceId` | continuity hint | U | L2 + optional `U_cid ≤ U_src` | Reserve{acct, src, cid, glob} | as L2 | as L2 | as L2; cid record bounded by the 50-per-IP cap, then overflow |
| L4 Historically known, no current proof | L2 + `isDevicePreviouslyVerifiedForAccount` | historical association | **U** | as L2 | as L2 | as L2 / PEN(K5) | account, device | failure: DEC-021 K5 +2 and allowance; overflow → K4 |
| L5 ◇ Current proof for this account and operation | L2 + proof(account, op) | current proof | P | `P_acct`, `P_proof`, `P_glob` | Reserve{acct-P, proof, glob-P} | ALLOW / CAP(P scope) / PEN(K5) | P caps, device | failure: K5 +2 (DEC-021), allowance, overflow → K4 (advisory for this request, binds U), device watcher |
| L6 Session for A, target B | subject B, proof(A) | mismatch | U for B | as L2 for B | as L2 for B | as L2 for B | B's U scopes | as L2 for B; A untouched |
| L7a Cookie or proof lost | was L5, now no proof | — | U | as L2 / L4 | as L2 | as L2 | as L2 | as L2 / L4 |
| L7b UA change | any | unchanged | unchanged | unchanged (no scope uses UA) | unchanged | unchanged | unchanged | new K5 for passive-only devices (§4.5) |
| L7c IP change | any | unchanged | unchanged | U: new `U_src`; `U_acct`, `U_glob` unchanged. P: unchanged | as before | as before | as before | as before |
| L8 Many sessions/devices, one account | several proofs for A | current proof | P | all share `P_acct(A)`; each its `P_proof` | as L5 | as L5 | P caps | as L5; never multiplies `P_acct` |
| L8u Many unproven devices, one account | several requesters, no proof | observation | U | all share `U_acct(A)` | as L2 | as L2 | as L2 | as L2 |

Without D3, rows L5 and L8 do not exist: every Login request is in L1–L4, L6, L7 or L8u.

### 8.2 OTP (`otp_protection`)

Same structure; the differences are:

| Case | Lane | Difference from Login |
| --- | --- | --- |
| O1 Step-up after primary authentication, session for this account ◇ | P | The proof is the primary-authenticated session (GA-2). An attacker who knows the password is **also** in P: `P_acct` / `P_proof` caps and K5 bound the OTP guesses, which is the main OTP threat |
| O2 Passwordless or recovery OTP | U | No proof exists; `U_acct` (per D4) is the per-account bound |
| O3 Session for A, OTP for B | U for B | as L6 |
| O4 Known device, no session | U | as L4; DEC-021 allowance 2 |
| Budget command eligibility | both | `pre` never issues the budget (`precheck_enforcement = false`, `DM` §3.3.2), unchanged |
| Recovery Collision Guard | P when the device is HIGH/known with proof; else U | account-scoped one-shot `SOFT` (`EP:1797-1811`): enforced on U, advisory on P like the budget it guards |

### 8.3 API Heavy (`api_heavy_protection`)

| Case | Input | Trust | Lane | Budgets | Atomic operation | Result | Enforcement scope | State updates |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| A1 Anonymous | ip, ua | observation | A | K1 parent; K2/K3 sub-limits; optional `A_glob` | Reserve{K1, K2, K3, glob} or today's per-key scoring | ALLOW / CAP(scope) | source; IP+UA and IP+FP sub-limits | access counted on admission; no correlation `HARD_BLOCK` |
| A2 Anonymous with client id | A1 + id | continuity hint | A | A1 + optional `A_cid ≤ K1` | as A1 | as A1 | as A1 | as A1 |
| A3 Principal validated by the Host | principal | current proof (principal) | R | `R_principal`, optional `R_glob` | Reserve{principal, glob} | ALLOW / CAP(principal or glob) | principal | principal counter |
| A4 Principal claimed, Host validation failed | — | — | A | as A1 | as A1 | as A1 | as A1 | authentication failures belong to an authentication policy, not API Heavy |
| A5 UA/IP change, anonymous | — | — | A | new K2 sub-bucket under the same K1; new IP ⇒ new K1, `A_glob` only | — | — | — | — |
| A6 Many clients, one principal | — | — | R | all share `R_principal` | — | — | — | — |

The API "account" cases of the brief (target account known without proof) do not exist for API Heavy: it has no target
account; `accountId` reaches no API enforcement key today (`EP:510-511`; no API K4/K5 thresholds).

---

## 9. API Heavy design (A4)

* **Anonymous traffic is a capacity problem, not an attribution problem.** The defensible bound is per source plus an optional
  service ceiling. K1 already is that parent (600, `ApiHeavyProtectionPolicy.php:48`); K2 (IP + UA) and K3 (IP + fingerprint)
  are fairness sub-limits that UA or fingerprint rotation can multiply only up to K1 (`DERIVED`: K1 is charged on every access,
  `EP:2686-2691`).
* **What changes** (`PROPOSED`, D1): churn and dilution stop producing API `HARD_BLOCK`s (`MEASURED` D1API 596/600 → 0/600
  when the cohort was split by id, #125). The LOW-K3 → K2 remap is a sub-limit mapping, not a correlation inference, and can
  stay.
* **What anonymous API cannot get:** per-person fairness inside one source. Two clients behind one IP with the same UA are one
  sub-limit; a client id is a hint and can be rotated, so it can only split the parent, never grow it.
* **Principal traffic** (`PROPOSED`, D3): a new optional Host-validated principal field. Principal budgets do not charge the
  anonymous source parent, so co-tenants on a NAT are not starved by a principal and vice versa. DEC-011 forbids an ACCOUNT
  fallback dimension for `API_OVERUSE` (`RLE:122`); a principal dimension in degraded mode is a separate decision.
* **Failure mode** stays `FAIL_OPEN` with bounded fallback (DEC-011, DEC-015); admission bounds do not hold during outages.
* **Official vs custom:** the API preset is ours to redesign under DEC-008; a custom `API_OVERUSE` policy keeps today's semantics
  until it opts into a new typed capability.

---

## 10. Compatibility and migration impact

| Area | Impact | Owner |
| --- | --- | --- |
| `RateLimitCommand`, `RateLimitResultDTO`, `RateLimiterInterface` | T0: no type change; new `cause` values. T1: optional permit token in metadata and on outcome commands (additive) | LT-2 |
| `RateLimitContextDTO` | optional typed proof `{account, operation, kind}`, optional `clientInstanceId`, target subject, API principal | D3 |
| Store contracts | new optional capability: atomic multi-scope reserve with dual-generation read; T1 adds a permit record with a state machine (F§6) | LT-4 |
| Redis adapter | Lua with caller time (DEC-016); one hash slot per verifier class; the supported topology stays one non-clustered Redis | LT-4 |
| Builder / DEC-008 | new typed capability; fail-fast without the Store capability (DEC-007 precedent); custom policies keep S0 unless they opt in | LT-2 |
| DEC-007 | unchanged lifecycle on K4; P requests neither read K4 blocks nor need re-entry | D2 (consequence only) |
| DEC-021 | K5 thresholds and allowance unchanged; the overflow to K4 no longer binds the proven request; the proven long horizon comes from P day caps | D2 |
| DEC-015 / DEC-011 | admission bounds do not hold in degraded mode; lanes are absent in fallback | D6 |
| DEC-019 | superseded only by a future approved replacement (Decision Governance §9) | D1 |
| Canonical docs | `DM` §0, §1, §2.2.2–§2.5, §3.3, §5.1–§5.4, §10; `POL` §1, §5, §6; `DFP` §3.3, §4.2, §4.3; `KS` §3, §4.3, §4.6; `FS` §4; `docs/guides/USAGE_GUIDE.md` (`checkOnly` semantics) | with each decision |

**Migration** (`PROPOSED`; failure analysis in F§6):

1. Ship the capability dormant; no preset declares it; behaviour is S0.
2. Fence: an operator-set admission epoch in the store enables the new path only after every worker runs the new version; old
   workers do not reserve, so bounds are `UNPROVEN` while mixed.
3. While enabled, existing K1/K2/K3 correlation blocks are honoured read-only until expiry (≤ 60 s for correlation L2) and no
   new ones are written. K4 keeps its key and history (M-C).
4. Rollback: S0 reads K4 and K5 as before and ignores admission counters and permits (they expire by TTL). No penalty history is
   lost; S0's collective blocks resume.
5. Secret rotation: counters are read across the current and previous generation and summed (F§6.4); a second rotation
   within one window re-issues capacity (`DERIVED`), so rotation interval ≥ window is a precondition (`DFP` §5.1.6).

---

## 11. Decision register

### 11.1 Lead technical choices (recommended here; closed by the Lead, not the Owner)

| ID | Choice | Recommendation | Basis |
| --- | --- | --- | --- |
| LT-1 | Owner-safety mechanism | M-C: lane-aware enforcement on the existing K4, P bounded by window and day caps and K5 | §7.2 |
| LT-2 | Public contract tier | T0 for the bounds; T1 (optional permit token) for `pre+out`; no T2; no `PROOF_REQUIRED` for official presets | §6.3, §6.5 |
| LT-3 | Outcome guarantee | At-most-once scoring per permit; no conversion of a permit-bearing outcome into unbound scoring | F§2.3 |
| LT-4 | Storage | One atomic script per verifier class; validate before the first write; caller time; dual-generation read; non-clustered topology; Cluster only with one slot per class | F§6 |
| LT-5 | Verifier class | Required for any global ceiling to be a bound | F§7 B4-6 |
| LT-6 | Migration | Dormant ship, fleet fence, read-only honour of existing correlation blocks, K4 unchanged | §10 |
| LT-7 | API structure | K1 parent, K2/K3 sub-limits, principal lane; correlation `HARD_BLOCK` removed only under D1 | §9 |

### 11.2 Owner security decisions

None is taken or assumed. Each has a recommendation and its trade-off.

| ID | Decision | Recommendation | Trade-off |
| --- | --- | --- | --- |
| **D1** | Replace correlation-derived collective punishment (K1 spray, K2 churn and LOW dilution, K3 MEDIUM dilution; official presets, custom policies only by opt-in) with capacity admission; supersede DEC-019 later through a new ADR | Approve as the Gate C direction | Removes measured collective harm (`MEASURED` D1 596/600, ABA_B 118/120, D2 117/121). The naive one-UA cross-IP attacker S0 stopped (X2 6/121) is bounded only by `U_glob`; the best-response reach is unchanged (121/121) |
| **D2** | Proven requests are not denied by account penalties earned from unproven evidence; they are bounded by `P_acct` / `P_proof` caps (window and day) and DEC-021 K5 instead | Approve, with M-C | A stolen proof is bounded by count caps rather than account-wide K4 escalation; DEC-021's consequence "real account evidence can still cause account-level enforcement" no longer applies to proven requests (new ADR) |
| **D3** | Optional typed Host proof `{account, operation, kind}`; a trusted session counts as proof only for same-account re-authentication and OTP step-up (GA-2); no proof from the historical flag; a Login primary sign-in has P only if the Host issues an account-bound device credential; the API principal input belongs here | Approve the optional typed proof and the two-flow meaning of a trusted session | P integrity rests on the Host (`MEASURED` control SPV 4 → 200/200, #119). Without D3 there is no proven lane and no owner protection anywhere |
| **D4** | Unproven-owner availability (F§2.9): **U-2** σ-independent per-target cap with possible renewable refusal of an unproven owner (S0's K4 already allows this in `pre+out`); **U-3** per-source floor `f` (an owner at an attacker-free source keeps `f`; per-target reach grows by `σ·f`); **U-1** no per-account cap (`pre` stays unbounded per account, as today) | **U-2 together with D3**, and amend the owner-safety clause of `POL` §1 to name proven owners as its protected population | U-2 makes refusal of an unproven owner reachable in `pre` too; U-3 re-creates the source scaling the bound exists to remove; U-1 leaves BRD-type attacks on `pre` Hosts unbounded |
| **D5** | Envelope: existence and role of `U_glob` and `P_glob` (resource guards per verifier class), no `P_src`, parameter ranges for Gate C sweeps, and acceptance of the residuals of F§8 | Keep both globals as resource guards sized above peak legitimate demand; no `P_src` (it re-blocks the trusted office, #119 M3; this is the #120 disposition); approve sweep ranges, not values | A binding global denies the whole lane; reach of a stolen-session attacker grows with sessions held, bounded per account and by `P_glob` |
| **D6** | Outage semantics: accept that bounds and lanes do not hold in DEC-015 degraded mode, or keep `FAIL_CLOSED` throughout for admission-enabled authentication policies | Accept the documented weaker guarantee for Gate C; revisit with measurements | Today's outage allowance (per worker) continues; owner protection is absent during outages |
| **D7** | Authorize an isolated Gate C prototype | After D1–D4 | — |

---

## 12. Final recommendation

**Status of this revision: `CORRECTED PER DIRECT LEAD REVIEW F126-01 … F126-05 — PENDING LEAD RE-REVIEW`.** Gate C is not
recommended before D1–D4.

Why it is not ready for Gate C:

1. Owner protection needs a proof that does not exist for Login primary sign-in (GA-1) and a new contractual meaning for the
   trusted session (GA-2) — D3.
2. The protection of unproven owners is a proven trade-off (F§2.9), not a design defect that engineering can remove — D4.
3. The proven-lane safety changes a normative protection of DEC-021 and `DM` — D2.
4. Global ceilings are resource guards and denial levers, not security bounds — D5.

Why it is not rejected:

* On paper, under stated conditions, it is the only reviewed direction that removes collective punitive attribution **and**
  keeps an outcome-independent, rotation-invariant bound on verifier admissions (F§3). The measured candidates did one or the
  other (#125 CIR, #116 OAAA, #119 AKDR, #115 GNH5: all NO-GO).
* It bounds `checkOnly`-only Hosts and cross-account misuse of a session where S0 does not (`MEASURED` S0: D24K1 `pre`
  1440/1440, #119; SP5, a Host-attested trusted session spraying 200 fresh accounts, 200/200, #108). The improvement itself is `DERIVED` and
  holds only if the proof names its own account (C8).
* Its minimum public contract is small (T0: no type change), and the recommended owner-safety mechanism (M-C) keeps the K4 key,
  DEC-007 and DEC-021's allowance path unchanged.

A prototype must measure what paper cannot settle (F§9), with thresholds swept as parameters, not approved.

---

## 13. Revision history of this proposal

| Revision | Change |
| --- | --- |
| `89ef2d2` | Initial draft |
| `a79c06d` | Gate A/B analysis: GA-1 … GA-5, truth tables, K1–K5 reconciliation, invariants, threat verdicts, storage, falsification |
| this revision | Direct Lead Review: F126-01 owner-safety mechanisms compared, M-C recommended over the key split (§7.2); F126-02 permit state machine with an at-most-once guarantee and no unbound fallback (F§2.3); F126-03 decision class separated from availability effect, trade-off proven (F§2.9), options in D4; F126-04 minimum contract tiers, T0 needs no type change (§6.5); F126-05 storage bound restated per class and window, permit records counted, no absolute "bounded" claim (F§3 I10, F§6.6); decision register split into Lead choices and seven Owner decisions (§11) |

The earlier validation matrix and work-sequence sections of `89ef2d2` are superseded by F§9 and §12; its evidence summary is
folded into §4 and F§5.

---

## 14. Sources and continuation

* Merged studies: [#125 UA client identity](../ua-client-identity-97/REPORT.md), [#116 OAAA](../outcome-aware-atomic-admission-110/REPORT.md)
  and [its contract feasibility](../outcome-aware-atomic-admission-110/CONTRACT_FEASIBILITY.md),
  [#119 AKDR](../account-scoped-known-device-relief-110/REPORT.md), [#118 HMCI](../host-managed-client-identity-110/REPORT.md),
  [#113 trusted multi-session](../trusted-multi-session-s0/REPORT.md), [#108 K1 NAT](../k1-credential-spray-nat/REPORT.md),
  [11-proposal comparison](../nat-architecture-proposals-110/COMPARISON.md) (proposals 06 and 07 are the closest precedents).
* Decisions: DEC-007, DEC-008, DEC-011, DEC-015, DEC-016, DEC-019, DEC-021 in [`docs/decisions/`](../../docs/decisions/DECISIONS_INDEX.md).
* Standards: `AGENTS.md` → pinned [`STANDARDS_MANIFEST.md`](../../docs/php-engineering-standards/STANDARDS_MANIFEST.md).
* Resume from the Draft PR #126 body (current-state snapshot), this file and the companion. Re-verify `main`, the branch HEAD
  and DEC-019 before acting. The issues stay open; this proposal closes nothing.

No runtime code, test, configuration, threshold, ADR status, issue state or released behaviour is changed by this document.
