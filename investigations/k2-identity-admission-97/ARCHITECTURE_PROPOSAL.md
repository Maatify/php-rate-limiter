# Identity-Aware Bounded Admission — Architecture Contract (#97)

> **Status:** ARCHITECTURE PROPOSAL, analysed through Gate A (architecture contract) and Gate B (paper security
> feasibility). **NOT APPROVED. NO PRODUCTION CHANGE.** Not an ADR, not a GO, not a prototype authorization.
> **Verdict:** `CORRECTED PER DIRECT LEAD RE-REVIEW (F126-R2-01 … R2-04) — PENDING LEAD RE-REVIEW`. No GO to Gate C: LT-1 is
> conditional on measurement and the Owner decisions are not yet to be requested (§11, §12).
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
mechanism itself requires; the Direct Lead Review of PR #126 (F126-01 … F126-05) and its re-review (F126-R2-01 … R2-04)
corrected that. This revision separates **Lead technical choices** (recommended here, closed by the Lead) from **Owner security
decisions** (§11), and adds GA-6 for the API score penalties.

| ID | Finding | Kind | Consequence |
| --- | --- | --- | --- |
| **GA-1** | No existing input is a *current, account-bound proof* for the primary Login of a logged-out user. `sessionDeviceId` trust is post-authentication only; `isDevicePreviouslyVerifiedForAccount` is a historical Host boolean (§5) | `CONTRACT` gap | Without a proof contract, Login is **U-only** and proven-owner protection cannot be claimed for Login (D3) |
| **GA-2** | The Level-3 session identifier is contractually "Advisory only" and "NOT proof of device ownership" (`DFP` §3.3). That text does not by itself establish a P entitlement for an account and operation | Proposed change of contractual meaning | Counting a trusted session as proof for same-account re-authentication or OTP is part of D3 |
| **GA-3** | `U_acct` refuses with a capacity reason, not a punitive `HARD_BLOCK`, so its **decision class** does not engage the attempt-count `HARD_BLOCK` clause of `POL` §1. Its **availability effect** does engage the owner-safety clause ("MUST NOT enable renewable or scheduled denial-of-owner"): anyone can exhaust it each window and refuse an owner who has no proof. F§2.9 proves that, for unproven traffic, a per-target bound independent of the attacker's number of sources and protection of the unproven owner from a remote attacker exclude each other — S0's account-wide K4 score is already on the first side | `CONTRACT` tension with a proven trade-off | Three concrete options, with a recommendation, in D4 |
| **GA-4** | K4 is one account key, fed by evidence from any requester and enforced on every requester, including trusted sessions (`EP:509-515`). A reserved P capacity is useless while a K4 block can close it | `CODE` | Owner safety needs proven requests not to be denied by account penalties earned from unproven evidence. Mechanisms compared in §7.2–§7.3; none dominates on paper. M-G (allowance-gated proven lane) is the recommended candidate and keeps the K4 and K5 derivations; LT-1 stays conditional on Gate C measurement. The normative change is D2 |
| **GA-5** | An irreversible reservation taken inside `checkOnly` bounds admissions with **no type-signature change** (tier T0, §6.5), but it is a **material behavioural change** of `checkOnly` for the policies that enable it. A permit token is needed only to bind outcomes to admissions replay-safely (tier T1) | Contract scope, corrected | LT-2 (Lead): opt-in variants first |
| **GA-6** | API Heavy denies anonymous requests through persisted, renewable score blocks on shared scopes — K1 L3, LOW K3 remapped to a K2 `HARD_BLOCK`, MEDIUM/HIGH K3 L2 — independently of correlation (§9.1) | `CODE` | Removing correlation alone does not remove API collective penalties; A-cap (§9.2) under D1 |

What survives on paper, under its stated conditions (Gate B):

* Removing collective punishment derived from IP / UA / passive fingerprint correlation, and replacing its *bounding* role
  with non-persistent capacity scopes that rotation of UA, client id, fingerprint or session cannot multiply (F§3 I1–I8).
* Outcome-independent per-window bounds, so `checkOnly`-only Hosts and dropped outcomes are bounded too — something S0 does
  not do (`MEASURED` D24K1 `pre` 1440/1440, #119 on `main@ebeb0a3`).
* Proven requests that unproven traffic can neither drain nor close inside the DEC-021 allowance — **only** where a proof exists
  (GA-1, GA-2), and with LT-1 still to be measured (§7.3).

What does not survive, whatever is decided:

* A co-located attacker and an unproven office are indistinguishable at the source scope; `U_src` trades one against the
  other (F§5 rows 3–4).
* For an unproven owner, a σ-independent per-target bound and protection from a remote attacker exclude each other (F§2.9).
* A service ceiling (`U_glob`, `P_glob`) is a denial lever for the whole lane, and per-policy ceilings are bypassed by
  splitting traffic across policies that share a verifier (F§7 B4-5, B4-6).
* Bounds weaken to "per window, modulo lost acknowledged writes" under Redis failover and to per-worker local limits under
  DEC-015 degraded mode (F§6). Outcome scoring is at most once per permit, not exactly once (F§2.3).
* Record-only (`out`) Hosts get no pre-verifier bound.
* An anonymous heavy requester still exhausts a shared API window for co-located clients; A-cap removes persistence and
  escalation, not that collateral (§9.2).

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
| 5 | *Spray bound* → `U_src(op)`, a per-window count of admitted unproven attempts per source, refused as capacity, never persisted as punishment. *Spray detection* stays an observation that may escalate **U-lane account risk** (K4, §7), not the IP. *Trusted advisory persistence* is removed: P-lane traffic never charges `U_src` and is never refused by it. **Not moved:** a hard block from breadth alone. API: K1 becomes a per-window capacity parent without persisted blocks (A-cap, §9.2) |
| 6 | Login/OTP: spray `HARD_BLOCK` replaced by `U_src`. API: persisted L3 score block replaced by a window counter (A-cap, D1) |
| 7 | `CREDENTIAL_SPRAY` changes meaning; a custom policy must opt in through a new typed capability rather than inherit it silently (DEC-008) |
| 8 | `DM` §5.1, `DM` §10 ("IP-only blocking is never final", "no decision based on a single signal"), `KS` §3.1, §4.3.4, DEC-019 → D1, D5 |

### 4.2 K2 — IP prefix + normalized UA

| # | Answer |
| --- | --- |
| 1 | Active block (`EP:509-533`); churn-set anchor (`EP:1174-1181`); IPv6 macro churn anchor (`EP:1197-1205`); API score (`EP:252`) |
| 2 | Churn and LOW-dilution `HARD_BLOCK` L2 60 s (`EP:1207-1222`, `EP:1264-1279`); `k2_missing_fp` (unreachable with the default resolver; no Login/OTP K2 thresholds) (`EP:2693-2701`); API `access` score (120 ⇒ L1 only) and LOW-K3 L2 remapped to a K2 `HARD_BLOCK` (`EP:573-579`, `EP:1832-1835`) |
| 3 | `HARD_BLOCK` for every requester on the IP + UA major, for every policy; API K2 `SOFT_BLOCK` |
| 4 | Observational equivalence becomes a punitive identity. `MEASURED`: #100 ABA_B 118/120 legitimate denied (S0, #116 on `main@72f6dea`); #97 D1 596/600 (S0N, #125 on `main@9e95f2f`); one browser re-authenticating with a trusted session churns itself, SESS 6/8 (F97-03). The protection it gave failed against the best response: X2 6/121 with one UA, 121/121 with UA rotation (#125). Partition by UA major multiplies the cross-account scope: TMSd 2/200 vs TMUA 36/200 (#120, `main@ebeb0a3`) |
| 5 | Per security function: **(a)** fingerprint rotation at one source → subsumed by `U_src`, which counts attempts whatever the fingerprint (F§3 I8); churn stays an observation. **(b)** one passive fingerprint across IPs (LOW dilution) → **not moved**: the inference is unsound (D1). A distributed attacker on one victim is bounded by `U_acct` (per D4, GA-3); across many victims only by `U_glob`. **(c)** cross-account aggregate at one IP + UA for trusted sessions (#113 TMSd 2/200) → **not moved as a source-scoped collective bound by default**: the trusted office and the 12-session attacker are request-for-request identical (#119 M3). Replacement: per-account P bounds + `P_glob`; a `P_src` is not recommended (D5). **(d)** API IP + UA fairness → a per-window sub-limit under K1 without persistence; the LOW-K3 → K2 remap is removed (§9.2) |
| 6 | Login/OTP: no K2 enforcement; existing K2 blocks are honoured read-only until they expire (§10). API: K2 becomes a non-persistent sub-limit; churn/dilution `HARD_BLOCK` and the LOW-K3 → K2 remap go (§9.2) |
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
| 6 | Login/OTP: no K3 enforcement. API: non-persistent K3 sub-limit for MEDIUM/HIGH only; none for LOW (§9.2) |
| 7 | Same as K2 |
| 8 | `DM` §5.3, `DFP` §4.2, §7.3, `KS` §3.3 |

### 4.4 K4 — account

| # | Answer |
| --- | --- |
| 1 | Active block for **every** requester on the account, including trusted sessions (`EP:509-533`); score; DEC-007 lifecycle (`EP:255-270`); budget state keyed on K4 (`EP:377`, `EP:2442-2456`); account Anti-Equilibrium watcher (`EP:403-407`) |
| 2 | Score +3 (Login) / +5 (OTP) per new-device or unproven failure, plus DEC-021 overflow (`EP:1600-1616`); persisted blocks from score levels (`EP:1909-1931`), flood L1 (`EP:360-371`), distributed-account L4 on the third occurrence (`EP:718-728`), Anti-Equilibrium L2 (`EP:408-422`); generation-bound publication (`EP:2057-2108`); budget counter (`EP:1794-1796`) |
| 3 | Account-wide `SOFT`/`HARD` |
| 4 | Evidence from anyone becomes a penalty on everyone, including the owner's proven sessions. `DERIVED` from `CODE`: three unproven Login failures (+3 each = 9 ≥ L2 = 8) persist a K4 `HARD_BLOCK` (60 s) that the owner's trusted session meets (`EP:513` skips only K1); device rotation against the account reaches K4 L4 (1800 s); DEC-007 re-entry evidence is exposed to any requester's pre-check on the account, not only to the owner |
| 5 | K4 keeps its key, derivation, score, budget, DEC-007 lifecycle, account watcher, distributed-account and flood escalation, and binds U requests as today. Recommended candidate M-G (§7.2, LT-1 conditional): inside the DEC-021 allowance a P request is not denied by account-scoped penalties and is bounded by P caps and K5; once the allowance is exhausted the account's P requests are demoted to U and meet K4 as today. Required pipeline changes: §7.4. Admission counters are separate. **Not moved:** others' evidence closing a proven request inside the allowance |
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
| 5 | **K5 derivation unchanged** (`AccountID + fingerprintHash`, `EP:2615`; the hash includes UA, client fingerprint and `sessionDeviceId`, `DIR:37-40`), so DEC-021 thresholds, K5 history, the device watcher, the micro-cap and rotation coexistence keep their keys. Consequence, stated rather than hidden: a proven device that changes UA gets a new K5, and is bounded across UA changes by the fingerprint-independent allowance and then by demotion (M-G, §7.3 S1′). `P_proof` is a new admission counter keyed on the proof reference; it is not a substitute for DEC-021's outcome rules. **A proof-keyed K5 is not proposed for Gate C:** it would change K5 key material, need a two-derivation read during migration that must not coincide with a secret rotation (`DFP` §5.1.6), and lose proven-lane K5 history on rollback. Distributed-account and flood evidence escalates K4, which a non-demoted P request does not meet |
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
| Per-account harassment protection | none for proven sessions (GA-4) | inside the allowance, account penalties not enforced on P (M-G) + reserved `P_acct` / `P_proof` | D2, D3, LT-1 conditional |
| Known-device bound | DEC-021 K5 + allowance → K4 | DEC-021 unchanged (K5 derivation, thresholds, allowance); exhausting the allowance demotes P to U | D2 |
| Collective punishment from correlation | K1/K2/K3 correlation `HARD_BLOCK` | none; correlation is observation | D1 |
| API source rate | K1 600, persisted L3, renewable | K1 window counter, no persistence (A-cap) | D1, LT-7 |
| API fairness sub-limits | K2 120 (`SOFT`), K3 300 (`HARD`; LOW remapped to K2) + correlation `HARD_BLOCK` | non-persistent K2 / MEDIUM-HIGH K3 sub-limits under K1; no remap, no correlation block | D1, LT-7 |

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
| **P** (proven) | valid proof for the target account and operation | `P_acct(account, op)` per window and per day, `P_proof(proof ref) ≤ P_acct` per window and per day, `P_glob(verifier class)` *(per D5)* | K5 (derivation unchanged); inside the DEC-021 allowance no account penalties, after it demoted to U (M-G, §7.2; LT-1 conditional) |
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

### 6.5 Minimum public contract (F126-04, F126-R2-04)

| Tier | Type signatures | Behavioural contract | What it delivers | What it lacks |
| --- | --- | --- | --- | --- |
| **T0** | unchanged; new values in the existing `RateLimitMetadataDTO::$cause` string; optional typed proof input (D3) | **Changes materially when enabled:** `checkOnly` consumes admission capacity. Today it observes correlation and may issue the Login budget `SOFT`, but reserves nothing (`docs/guides/USAGE_GUIDE.md:197`, §3.1) | Every admission bound of F§3 that the Owner decisions allow; none needs an outcome binding | Outcome binding: duplicated `recordFailure` scores again, as today; no per-attempt observability |
| **T1** | T0 + optional opaque permit token in `ALLOW` metadata and on outcome commands (additive) | T0 + permit-bound outcomes | At-most-once scoring per permit | Exactly-once scoring (F§2.3) |
| T2 | new `admit()` / `complete()` and decision constants | — | naming only | nothing T1 lacks |

T0 is therefore **no type-signature change, but a material behavioural and usage change** for every Host of a policy that
enables it. It is not a contract-free change.

**Activation and compatibility** (`PROPOSED`):

* **Opt-in per policy** through a new typed capability (DEC-008). A policy without it, and every custom policy, keeps today's
  `checkOnly`. Enabling it on an existing policy name changes behaviour for every Host of that name, so the official presets
  are **not** switched in place: the admission-enabled behaviour ships first as separately named official variants (or an
  explicit constructor switch on the preset), so the default `login_protection`, `otp_protection` and `api_heavy_protection` stay
  as they are. Making admission the default of the official names is a later, separately approved step with its own release
  classification (part of D1); the release-versioning rule belongs to the release decision, not to this design.
* **Ready to use without a permit concept:** with T0 the variants keep the documented `checkOnly → verify → record` flow; Hosts
  learn no new type. T1 stays optional; a Host that never sends the token gets T0 outcome semantics.
* **Documentation:** `docs/guides/USAGE_GUIDE.md`, the README examples and the canonical `DM` / `POL` text must state that, for
  the variants, one `checkOnly` = one admission.

**Integration semantics that sizing must include** (`DERIVED`):

| Host behaviour | Effect under T0 | Consequence |
| --- | --- | --- |
| `checkOnly` as a probe (page render, field validation) before the real attempt | one extra admission per probe | legitimate headroom divided by the probe factor `ρ ≥ 1`; the attacker uses `ρ = 1`. Capacities must be sized for `ρ` of real Hosts |
| `checkOnly` then no verifier call (CSRF failure, validation error, user abandons) | admission spent, nothing to cancel (no refunds, F§2.5) | Hosts should run cheap local validation before `checkOnly` |
| Lost `checkOnly` reply, Host retries | second admission | counted in `ρ` |
| Double submit | two admissions, possibly two verifier calls | both bounded; H1 still holds |
| `out`-only Host | no change: nothing reserved | no pre-verifier bound (§6.4) |

Impact on `pre+out` and DEC-021: T0 leaves the outcome path unchanged; the DEC-021 allowance is still consumed on
`recordFailure` (`EP:1600-1616`). Under M-G (§7.2) the allowance is additionally **read** at `checkOnly` to decide demotion.

Recommendation (LT-2): T0 in separately named official variants first; T1 optional for `pre+out` Hosts; no T2.

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
| DEC-021 allowance | consumed only by known-device failures, i.e. a Host trust or "previously verified" claim (`EP:1600-1616`) | overflow adds to the shared K4 (`EP:1613-1615`) |
| Distributed-account occurrence | yes, by device rotation (`EP:718-728`) | K4 L4, 1800 s |
| Flood | yes (`EP:360-371`) | K4 L1 |

A reserved `P_acct` is useless while any of these can close the P lane. Exempting P without another bound is forbidden by the
brief and would remove DEC-021's protection against a stolen proof.

### 7.2 Mechanisms

The problem is proven by code; the mechanism is a design choice. M-B (lane-tagged blocks on one key: needs a Store block-schema
and DEC-007 publication change for M-A's properties), M-D (unproven evidence never punishes the account: loses outcome-based
protection on the U lane) and M-E (no change: fails GA-4) were rejected in the previous revision for the reasons stated there;
they are not revisited. Three remain:

* **M-A — full key split.** `K4U` (legacy K4 key) and `K4P` (new key), each with its own score, budget, watcher and lifecycle.
  Proven failures beyond the DEC-021 allowance score `K4P`, which binds every proven request on the account.
* **M-C — proven requests ignore account penalties.** One K4 fed by both lanes; a P request never meets account penalties and
  is bounded by `P_acct` / `P_proof` caps and K5.
* **M-G — allowance-gated proven lane** (`PROPOSED`, new in this revision). While the account's DEC-021 known-device allowance
  epoch is not exhausted, a P request is not denied by account-scoped penalties (active K4 blocks, K4 score candidates, budget,
  account Anti-Equilibrium, distributed-account and flood candidates) and is bounded by P caps and K5. Once the allowance is
  exhausted, P requests on that account are **demoted to U** for the rest of the 24-hour allowance epoch and meet K4 exactly
  as today. This is DEC-021's own rule — beyond the allowance "a known device is scored on K4 exactly like an unknown device"
  (DEC-021 Rationale) — applied to enforcement as well as scoring.

Inside the allowance, DEC-021 already keeps a known device's own failures off K4 (`EP:2714-2717`, `EP:1600-1616`), so under M-G
every K4 penalty a P request could meet inside the allowance comes from someone else's evidence. Only known-device failures
consume the allowance, so an unproven attacker cannot trigger the demotion without a Host trust claim for that account
(`DFP` §4.3; the same Host-compliance condition as C8).

### 7.3 Scenario comparison (no cap values assumed)

`A_kd` = DEC-021 allowance (Login 8, OTP 2 per 24-hour epoch, `LoginProtectionPolicy.php:108`, `OtpProtectionPolicy.php:106`).
`P_day` = an unapproved per-account daily P cap. All rows `DERIVED`; the long-horizon rows assume that adding evidence to K4
never lowers its later score (monotonicity, `UNPROVEN` for the DEC-003 pause interplay).

| Scenario | M-A | M-C | M-G |
| --- | --- | --- | --- |
| S1 Stolen proof, one device, repeated failures over days (`pre+out`) | `A_kd` failures, then `K4P` escalation; long-run pace set by K4 decay — DEC-021 measured 44–45 per 24 h for one known Login device under K4 (`MEASURED`, DEC-021) | K5 per device, then only `P_day`; K5 alone does not bound pacing below its decay (DEC-021 "K5 thresholds alone: Rejected") | `A_kd` failures, then demoted: K4 with all evidence plus U caps |
| S1′ S1 with UA rotation (new passive K5 per UA, §4.5) | allowance is fingerprint-independent, then `K4P` | only `P_day` | allowance, then demoted |
| S2 `pre` only (no outcomes) | P caps only (`checkOnly` scores nothing, §3.1) | P caps only | P caps only (no failure consumes the allowance) |
| S3 OTP step-up, attacker knows the password | `A_kd = 2`, then `K4P` (4/7/10, +5) | K5 then `P_day` | `A_kd = 2`, then OTP K4 as an unknown device |
| S4 Several proofs for one account | allowance and `K4P` are account-wide | per-proof K5; `P_acct` | allowance account-wide; demotion account-wide |
| S5 Owner successes mixed with stolen-proof failures | owner meets `K4P` after the attacker's `A_kd` + ~3 failures | owner refused only when the attacker exhausts P caps | owner demoted to U after the attacker's `A_kd` failures |
| S6 Owner's own typos | beyond `A_kd`: `K4P` blocks every proven device | never account-wide on P | beyond `A_kd`: K4 as today (DEC-021) |
| `reach_foreign` (U lane) | U evidence only on `K4U` | K4 gets P evidence too | K4 gets P evidence beyond `A_kd` (as today) |
| `reach_proof` bound per epoch | `A_kd` + `K4P`-paced failures | `min(P_day, K5-paced)` | `A_kd` + U-bounded failures |
| Proven owner vs remote unproven attacker | protected | protected | protected (allowance untouchable without a Host trust claim) |
| Contract / state cost | new key family; second budget and watcher; DEC-007 split | pipeline enforcement changes | pipeline enforcement changes; the allowance counter is read at `checkOnly` (today it is read and written on `recordFailure`) |
| Rollback to S0 | `K4P` history lost | nothing lost | nothing lost |

Inequalities that hold without cap values (`DERIVED`):

* `reach_foreign(M-C) ≤ reach_foreign(M-A)` and `reach_foreign(M-G) ≤ reach_foreign(M-A)`: K4 receives a superset of `K4U`'s
  evidence (under the monotonicity assumption).
* Per epoch, proven-lane failures under M-G and M-A are at most `A_kd` before account-level consequences start; under M-C they
  are bounded only by `P_day` and K5 pacing. Hence M-C's stolen-proof bound is no better than M-G's unless `P_day ≤ A_kd`, and
  a `P_day` that small also caps the legitimate owner's successes. **Counterexample to "M-C dominates M-A":** any legitimate
  daily proven demand `L_d > A_kd` forces `P_day ≥ L_d`, so M-C admits up to `L_d` stolen-proof failures where M-A and M-G admit
  `A_kd` before escalation.
* M-C protects the proven owner from their own typos better than both (S6), and from a stolen-proof holder until P caps run
  out (S5). After a stolen proof exhausts `A_kd`, M-A keeps the owner on the proven lane, where only proven failures count
  (`K4P`); M-G returns the owner to U, where unproven traffic counts too (S5).

No mechanism dominates. **LT-1 is conditional:** M-G is the recommended candidate because it keeps DEC-021's normative rule,
adds no key family, loses nothing on rollback, and is not weaker than M-A on `reach_proof` or `reach_foreign` (`DERIVED`); its
cost is S5. Gate C must measure M-G against M-A, with M-C as a control, on S1–S6 before LT-1 can be closed.

### 7.4 Pipeline changes M-G needs (not "pipeline-only and trivial")

The `trusted_advisory:` pattern is not a ready-made fit: today a trusted K1 `score_update` advisory carries **no** persistence
(`EP:1871-1882`), and only the credential-spray advisory persists its block (`EP:1513-1518`, persisted by
`EP:2221`, `EP:2232`). M-G does not need advisory persistence, because inside the allowance a P request's own failures do not
score K4. It needs:

1. Lane resolution at `checkOnly`, including a read of the allowance epoch (`EP:1609-1612` key family) to decide P or demoted.
2. Skip active K4 blocks for a non-demoted P request (`EP:509-533` gains a lane condition, like the trusted K1 skip at `EP:513`).
3. Drop account-scoped candidates for a non-demoted P request: K4 threshold candidates (`EP:274-281`), distributed-account
   (`EP:286-297`), flood (`EP:328-373`) and account Anti-Equilibrium escalation (`EP:403-423`).
4. Never acquire the budget cooldown for a non-demoted P request (`EP:2180-2204`): acquiring it without issuing would spend the
   one-shot cooldown and suppress the U-lane issuance.
5. DEC-007: P requests do not consume or expose re-entry evidence (`EP:255-270`, `EP:476-489`); evidence semantics for U unchanged.
6. Device-scoped K5 candidates and the device watcher stay enforced on P (DEC-021 §5).

### 7.5 What remains unprotected

* An owner without a proof is U and shares `U_acct` and K4 with the attacker (F§2.9; D4). For Login without D3 that is every owner.
* Under M-G, a stolen-proof holder who exhausts `A_kd` demotes the owner's proven devices to U for the rest of the epoch (S5).
* Under DEC-015 degraded mode the local fallback has no lanes, so owner protection is absent during an outage (D6).

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
| L5 ◇ Current proof for this account and operation | L2 + proof(account, op) | current proof | P | `P_acct`, `P_proof`, `P_glob` | Reserve{acct-P, proof, glob-P} | ALLOW / CAP(P scope) / PEN(K5); after demotion as L2 | P caps, device | failure: K5 +2 (DEC-021), allowance, device watcher; once the allowance is exhausted the account's P requests are demoted to U (M-G) |
| L6 Session for A, target B | subject B, proof(A) | mismatch | U for B | as L2 for B | as L2 for B | as L2 for B | B's U scopes | as L2 for B; A untouched |
| L7a Cookie or proof lost | was L5, now no proof | — | U | as L2 / L4 | as L2 | as L2 | as L2 | as L2 / L4 |
| L7b UA change | any | unchanged | unchanged | unchanged (no scope uses UA) | unchanged | unchanged | unchanged | new K5 for every device whose fingerprint material includes the UA (§4.5); the fingerprint-independent allowance still counts |
| L7c IP change | any | unchanged | unchanged | U: new `U_src`; `U_acct`, `U_glob` unchanged. P: unchanged | as before | as before | as before | as before |
| L8 Many sessions/devices, one account | several proofs for A | current proof | P | all share `P_acct(A)`; each its `P_proof` | as L5 | as L5 | P caps | as L5; never multiplies `P_acct` |
| L8u Many unproven devices, one account | several requesters, no proof | observation | U | all share `U_acct(A)` | as L2 | as L2 | as L2 | as L2 |

Without D3, rows L5 and L8 do not exist: every Login request is in L1–L4, L6, L7 or L8u.

### 8.2 OTP (`otp_protection`)

Same structure; the differences are:

| Case | Lane | Difference from Login |
| --- | --- | --- |
| O1 Step-up after primary authentication, session for this account ◇ | P | The proof is the primary-authenticated session (GA-2). An attacker who knows the password is **also** in P: the OTP allowance (2), K5 and P caps bound the guesses, then demotion to U puts it on OTP K4 (§7.3 S3) |
| O2 Passwordless or recovery OTP | U | No proof exists; `U_acct` (per D4) is the per-account bound |
| O3 Session for A, OTP for B | U for B | as L6 |
| O4 Known device, no session | U | as L4; DEC-021 allowance 2 |
| Budget command eligibility | both | `pre` never issues the budget (`precheck_enforcement = false`, `DM` §3.3.2), unchanged |
| Recovery Collision Guard | P when the device is HIGH/known with proof; else U | account-scoped one-shot `SOFT` (`EP:1797-1811`): enforced on U and on demoted P; not issued to a non-demoted P request |

### 8.3 API Heavy (`api_heavy_protection`)

| Case | Input | Trust | Lane | Budgets | Atomic operation | Result | Enforcement scope | State updates |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| A1 Anonymous | ip, ua | observation | A | K1 parent; K2/K3 sub-limits; optional `A_glob` | Reserve{K1, K2, K3 (MEDIUM/HIGH only), glob} (A-cap) | ALLOW / CAP(scope) | source; IP+UA and IP+FP sub-limits | access counted on admission; no persisted block, no remap, no correlation `HARD_BLOCK` (§9.2) |
| A2 Anonymous with client id | A1 + id | continuity hint | A | A1 + optional `A_cid ≤ K1` | as A1 | as A1 | as A1 | as A1 |
| A3 Principal validated by the Host | principal | current proof (principal) | R | `R_principal`, optional `R_glob` | Reserve{principal, glob} | ALLOW / CAP(principal or glob) | principal | principal counter |
| A4 Principal claimed, Host validation failed | — | — | A | as A1 | as A1 | as A1 | as A1 | authentication failures belong to an authentication policy, not API Heavy |
| A5 UA/IP change, anonymous | — | — | A | new K2 sub-bucket under the same K1; new IP ⇒ new K1, `A_glob` only | — | — | — | — |
| A6 Many clients, one principal | — | — | R | all share `R_principal` | — | — | — | — |

The API "account" cases of the brief (target account known without proof) do not exist for API Heavy: it has no target
account; `accountId` reaches no API enforcement key today (`EP:510-511`; no API K4/K5 thresholds).

---

## 9. API Heavy design (A4)

### 9.1 Every path that denies an anonymous API request today (`CODE`)

| Path | Trigger | Persisted on | Duration / renewal |
| --- | --- | --- | --- |
| K1 score | K1 score ≥ 600 (L1 = L2 = L3 = 600 ⇒ level 3; `ApiHeavyProtectionPolicy.php:48`, `EP:2768-2770`) | K1 = every requester on the IP (`EP:604-616`, `EP:1841-1851`) | L3 300 s (`PenaltyLadder`); afterwards the decayed score is still above the threshold, so the next request yields a new candidate and a new block (`EP:274-281` runs on every request); K1 decays 1 point per 180 s, halved at L2+ (`DecayCalculator`) |
| K3 score, MEDIUM/HIGH | K3 ≥ 300 ⇒ L2 | K3 = IP + client fingerprint | L2 60 s, renewed the same way |
| K3 score, LOW | K3 ≥ 300 ⇒ L2, **remapped to K2** (`EP:573-579`, `EP:1832-1835`) | K2 = every requester on the IP + UA major | L2 60 s, renewed |
| K2 score | K2 ≥ 120 ⇒ L1 only (L2/L3 disabled) | written as a level-1 block, not enforced by the fail-fast (which needs level ≥ 2, `EP:520`) | `SOFT_BLOCK` while the score is ≥ 120 |
| Churn / dilution | correlation (`EP:300-309`) | K2 or K3 | L2 60 s, renewable through WATCH |
| Cycle tracking | every L2+ API block goes through DEC-003 cycle tracking (`EP:2110-2127`) | — | two cycles in 6 h add a 600 s decay pause, lengthening renewal |

For API, `persistWinningCandidates` writes **every** candidate's persistence, not only the winning class (`EP:2226-2231`).
With LOW confidence K3 and K2 partition requesters identically (K3 = IP + HMAC of the UA material, `DIR:39`, `EP:2613`), so the
LOW remap is a K2 penalty under another name. All of these are persisted, renewable `HARD_BLOCK`s on shared scopes: penalties,
not capacity refusals. Removing the correlation paths alone (the previous revision) leaves four of the six rows in place.

### 9.2 Decision per key: capacity guard or penalty

| Option | K1 | K2 | K3 |
| --- | --- | --- | --- |
| **A-pen** (today) | persisted L3, renewable | L1 `SOFT` + LOW-K3 remap `HARD` | persisted L2, renewable |
| **A-cap** (`PROPOSED`) | per-window admission counter; refusal is a capacity `SOFT_BLOCK`; nothing persisted, no ladder, no DEC-003 cycle, no renewal beyond the window | per-window sub-limit under K1; same refusal semantics | MEDIUM/HIGH only: per-window sub-limit under K1 keyed on a client-chosen value (it can only refuse; key count capped by the existing 50-per-IP device cap, `EphemeralBucket.php:21`, overflow routed ephemeral, `EP:235-238`). LOW: **no separate K3 limit**, because it is the K2 partition; the remap disappears |

Recommendation (LT-7, conditional on D1): **A-cap for K1, K2 and K3.**

What A-cap does **not** remove: a heavy requester still exhausts the K1 window for everyone behind the same IP, and a heavy
requester with a common UA still exhausts the K2 sub-limit for others with that UA on that IP. Those are co-located capacity
residuals (F§8 item 1), now non-persistent and non-escalating; they are not eliminated. K2 and K3 sub-limits protect only
against a benign heavy client; an adversary rotates UA or fingerprint and meets K1 alone.

### 9.3 Remaining API design

* **Anonymous traffic cannot get** per-person fairness inside one source: a client id is a hint and can be rotated, so it can
  only split the parent, never grow it.
* **Principal traffic** (`PROPOSED`, D3): a new optional Host-validated principal field. Principal budgets do not charge the
  anonymous source parent. DEC-011 forbids an ACCOUNT fallback dimension for `API_OVERUSE` (`RLE:122`); a principal dimension
  in degraded mode is a separate decision.
* **Failure mode** stays `FAIL_OPEN` with bounded fallback (DEC-011, DEC-015); the local fallback (IP 120/60 s, IP + UA 60/60 s)
  is already a non-persistent window counter.
* **Official vs custom:** A-cap changes the meaning of `API_OVERUSE` thresholds (score with decay → window counter) and of
  `retryAfter`; a custom `API_OVERUSE` policy keeps A-pen until it opts into a new typed capability (DEC-008).
* `MEASURED` context: D1API 596/600 → 0/600 once the cohort is no longer one fingerprint (#125) — that harm came from
  correlation; the rate paths of §9.1 were not exercised there and their collateral on shared NATs is `UNPROVEN` in size.

---

## 10. Compatibility and migration impact

| Area | Impact | Owner |
| --- | --- | --- |
| `RateLimitCommand`, `RateLimitResultDTO`, `RateLimiterInterface` | T0: no type change; new `cause` values. T1: optional permit token in metadata and on outcome commands (additive) | LT-2 |
| `RateLimitContextDTO` | optional typed proof `{account, operation, kind}`, optional `clientInstanceId`, target subject, API principal | D3 |
| Store contracts | new optional capability: atomic multi-scope reserve with dual-generation read; T1 adds a permit record with a state machine (F§6) | LT-4 |
| Redis adapter | Lua with caller time (DEC-016); one hash slot per verifier class; the supported topology stays one non-clustered Redis | LT-4 |
| Builder / DEC-008 | new typed capability; fail-fast without the Store capability (DEC-007 precedent); official behaviour ships as separately named variants, default preset names unchanged; custom policies keep S0 unless they opt in | LT-2 |
| `API_OVERUSE` semantics | score with decay and persisted blocks → window counters (A-cap); `retryAfter` meaning changes | D1, LT-7 |
| DEC-007 | unchanged lifecycle on K4; non-demoted P requests neither read K4 blocks nor consume or expose re-entry evidence | D2 (consequence only) |
| DEC-021 | K5 derivation, thresholds and allowance unchanged; the allowance is additionally read at `checkOnly` to decide demotion (M-G) | D2 |
| DEC-015 / DEC-011 | admission bounds do not hold in degraded mode; lanes are absent in fallback | D6 |
| DEC-019 | superseded only by a future approved replacement (Decision Governance §9) | D1 |
| Canonical docs | `DM` §0, §1, §2.2.2–§2.5, §3.3, §5.1–§5.4, §10; `POL` §1, §5, §6; `DFP` §3.3, §4.2, §4.3; `KS` §3, §4.3, §4.6; `FS` §4; `docs/guides/USAGE_GUIDE.md` (`checkOnly` semantics) | with each decision |

**Migration** (`PROPOSED`; failure analysis in F§6):

1. Ship the capability dormant; no preset declares it; behaviour is S0.
2. Fence: an operator-set admission epoch in the store enables the new path only after every worker runs the new version; old
   workers do not reserve, so bounds are `UNPROVEN` while mixed.
3. While enabled, existing K1/K2/K3 correlation blocks are honoured read-only until expiry (≤ 60 s for correlation L2) and no
   new ones are written. K4 and K5 keep their keys, derivations and history (M-G).
4. Rollback: S0 reads K4 and K5 as before and ignores admission counters and permits (they expire by TTL). No penalty history is
   lost, because no penalty key or derivation changes; a later proof-keyed K5 would break this (§4.5). S0's collective blocks
   resume.
5. Secret rotation: counters are read across the current and previous generation and summed (F§6.4); a second rotation
   within one window re-issues capacity (`DERIVED`), so rotation interval ≥ window is a precondition (`DFP` §5.1.6).

---

## 11. Decision register

Status: **nothing here is ready to be closed or put to the Owner** until the Lead accepts this revision. Gate C needs, in
order: Lead acceptance of F126-R2-01 … R2-04; Lead closure of LT-2 … LT-7; the Gate C measurement that LT-1 needs; then the
Owner decisions below, as one security trade-off each.

### 11.1 Lead technical choices

| ID | Choice | Recommendation | State |
| --- | --- | --- | --- |
| LT-1 | Proven-lane mechanism | M-G (allowance-gated proven lane); M-A and M-C measured alongside | **Conditional**: no dominance proven; closes only after Gate C measures S1–S6 (§7.3) |
| LT-2 | Public contract tier and activation | T0 in separately named official variants; T1 optional; no T2 | open, §6.5 |
| LT-3 | Outcome guarantee | At-most-once per permit; no conversion into unbound scoring | open, F§2.3 |
| LT-4 | Storage | One atomic script per verifier class; validate before the first write; caller time; dual-generation read; non-clustered topology | open, F§6 |
| LT-5 | Verifier class | Required for any global ceiling to be a bound | open, F§7 B4-6 |
| LT-6 | Migration | Dormant ship, fleet fence, read-only honour of existing correlation blocks; K4 and K5 derivations unchanged | open, §10 |
| LT-7 | API anonymous keys | A-cap for K1, K2, K3; LOW K3 limit dropped (same partition as K2) | open, conditional on D1, §9.2 |

### 11.2 Owner security decisions (not to be requested before §11 status allows)

| ID | Decision | Recommendation | Trade-off |
| --- | --- | --- | --- |
| **D1** | Replace collective penalties on shared scopes — correlation (K1 spray, K2 churn/LOW dilution, K3 MEDIUM dilution) **and** the API score penalties of §9.1 — with capacity admission, in opt-in variants first; supersede DEC-019 later through a new ADR | Approve as the Gate C direction | Removes measured correlation harm (D1 596/600, ABA_B 118/120, D2 117/121). The naive one-UA cross-IP attacker S0 stopped (X2 6/121) is bounded only by `U_glob`; best-response reach unchanged (121/121). API heavy users still exhaust shared windows, without persistence or escalation |
| **D2** | Inside the DEC-021 allowance, proven requests are not denied by account penalties earned from others' evidence; beyond it they are treated as unproven (DEC-021's existing rule, applied to enforcement) | Approve, with LT-1 still subject to measurement | S5 of §7.3: a stolen proof that exhausts the allowance returns the owner to U for the epoch |
| **D3** | Optional typed Host proof `{account, operation, kind}`; a trusted session counts only for same-account re-authentication and OTP step-up; no proof from the historical flag; API principal input | Approve | P integrity rests on the Host (`MEASURED` control SPV 4 → 200/200, #119); without D3 there is no proven lane |
| **D4** | Unproven-owner availability (F§2.9): U-1, U-2 or U-3 | U-2 with D3, and amend the owner-safety clause of `POL` §1 to name proven owners | U-2 makes refusal of an unproven owner reachable in `pre` too; U-3 re-creates source scaling; U-1 leaves `pre` unbounded per account |
| **D5** | Envelope: global ceilings as resource guards per verifier class, no `P_src` (#120 disposition), sweep ranges not values, acceptance of the residuals of F§8 | As stated | A binding global denies the whole lane; stolen-session reach grows with sessions held |
| **D6** | Outage semantics under DEC-015 | Accept the documented weaker guarantee for Gate C | Owner protection and bounds absent during outages |
| **D7** | Isolated Gate C prototype | Only after everything above | — |

---

## 12. Final recommendation

**Status of this revision: `CORRECTED PER DIRECT LEAD RE-REVIEW F126-R2-01 … R2-04 — PENDING LEAD RE-REVIEW`.**
**No GO to Gate C:** that requires the Lead's acceptance of this revision, the Gate C measurement for LT-1, and separate Owner
decisions.

Why it is not ready:

1. LT-1 is conditional: none of M-A, M-C, M-G dominates on paper (§7.3).
2. The API redesign (A-cap) changes the meaning of `API_OVERUSE` thresholds and needs D1 (§9).
3. Owner protection needs D3 (GA-1, GA-2); unproven-owner protection is a proven trade-off (D4, F§2.9).
4. T0 is a material behavioural change for enabled policies, so it ships as opt-in variants (§6.5).

Why it is not rejected:

* On paper, under stated conditions, it is the only reviewed direction that removes collective penalties on shared scopes
  **and** keeps an outcome-independent, rotation-invariant bound on verifier admissions (F§3). The measured candidates did one or
  the other (#125 CIR, #116 OAAA, #119 AKDR, #115 GNH5: all NO-GO).
* It bounds `checkOnly`-only Hosts and cross-account misuse of a session where S0 does not (`MEASURED` S0: D24K1 `pre`
  1440/1440, #119; SP5, a Host-attested trusted session spraying 200 fresh accounts, 200/200, #108). The improvement itself is
  `DERIVED` and holds only if the proof names its own account (C8).
* The recommended proven-lane mechanism (M-G) keeps the K4 and K5 key derivations, DEC-007 and DEC-021's allowance unchanged,
  and loses no penalty history on rollback.

---

## 13. Revision history of this proposal

| Revision | Change |
| --- | --- |
| `89ef2d2` | Initial draft |
| `a79c06d` | Gate A/B analysis: GA-1 … GA-5, truth tables, K1–K5 reconciliation, invariants, threat verdicts, storage, falsification |
| `6c78fb8` | Direct Lead Review: F126-01 owner-safety mechanisms compared, M-C recommended over the key split (§7.2); F126-02 permit state machine with an at-most-once guarantee and no unbound fallback (F§2.3); F126-03 decision class separated from availability effect, trade-off proven (F§2.9), options in D4; F126-04 minimum contract tiers, T0 needs no type change (§6.5); F126-05 storage bound restated per class and window, permit records counted, no absolute "bounded" claim (F§3 I10, F§6.6); decision register split into Lead choices and seven Owner decisions (§11) |
| this revision | Direct Lead Re-review: R2-01 every API penalty path traced and A-cap proposed per key, GA-6 (§9); R2-02 K5 derivation kept unchanged, proof-keyed K5 withdrawn from Gate C with its migration and rollback cost stated (§4.5); R2-03 M-G added, scenario matrix against M-A and M-C, dominance claim withdrawn, LT-1 conditional, required pipeline changes listed and the advisory-pattern claim corrected (§7.2–§7.4); R2-04 T0 restated as a material behavioural change with opt-in variants and probe/retry/cancel sizing (§6.5); register and status updated (§11, §12) |

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
