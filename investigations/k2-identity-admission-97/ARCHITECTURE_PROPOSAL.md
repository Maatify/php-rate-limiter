# Identity-Aware Bounded Admission — Architecture Contract (#97)

> **Status:** ARCHITECTURE PROPOSAL, analysed through Gate A (architecture contract) and Gate B (paper security
> feasibility). **NOT APPROVED. NO PRODUCTION CHANGE.** Not an ADR, not a GO, not a prototype authorization.
> **Verdict:** `CORRECTED PER G0 REVIEW R4 (F126-R4-01 … R4-03) — PENDING LEAD G0`. One recommended architecture
> (§12); no research, prototype, policy or merge is authorized; gate order in §11.
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
mechanism itself requires; four Direct Lead Reviews of PR #126 (F126-01 … 05, R2-01 … 04, R3-01 … 05, R4-01 … 03) corrected
that. This revision gives one recommended architecture (§12), keeps rejected variants as short evidence, separates **Lead
technical choices** from **Owner security decisions**, and orders them in non-circular gates (§11).

| ID | Finding | Kind | Consequence |
| --- | --- | --- | --- |
| **GA-1** | No existing input is a *current, account-bound proof* for the primary Login of a logged-out user. `sessionDeviceId` trust is post-authentication only; `isDevicePreviouslyVerifiedForAccount` is a historical Host boolean (§5) | `CONTRACT` gap | Without a proof contract, Login is **U-only** and proven-owner protection cannot be claimed for Login (D3) |
| **GA-2** | The Level-3 session identifier is contractually "Advisory only" and "NOT proof of device ownership" (`DFP` §3.3). That text does not by itself establish a P entitlement for an account and operation | Proposed change of contractual meaning | Counting a trusted session as proof for same-account re-authentication or OTP is part of D3 |
| **GA-3** | `U_acct` refuses with a capacity reason, not a punitive `HARD_BLOCK`, so its **decision class** does not engage the attempt-count `HARD_BLOCK` clause of `POL` §1. Its **availability effect** does engage the owner-safety clause ("MUST NOT enable renewable or scheduled denial-of-owner"): anyone can exhaust it each window and refuse an owner who has no proof. F§2.9 proves that, for unproven traffic, a per-target bound independent of the attacker's number of sources and protection of the unproven owner from a remote attacker exclude each other — S0's account-wide K4 score is already on the first side | `CONTRACT` tension with a proven trade-off | Three concrete options, with a recommendation, in D4 |
| **GA-4** | K4 is one account key, fed by evidence from any requester and enforced on every requester, including trusted sessions (`EP:509-515`). A reserved P capacity is useless while a K4 block can close it | `CODE` | Recommended: lane-scoped account penalties (M-A: K4U = legacy K4, K4P new), shared DEC-021 allowance with a stated coupling, no demotion (§7.2). M-G and M-C are NO-GO (§7.5). Device-level isolation needs rules P-1 … P-3 (§7.3). The normative change is D2 |
| **GA-5** | An irreversible reservation taken inside `checkOnly` bounds admissions with **no type-signature change** (tier T0, §6.5), but it is a **material behavioural change** of `checkOnly` for the policies that enable it. A permit token is needed only to bind outcomes to admissions replay-safely (tier T1) | Contract scope, corrected | LT-2 (Lead): opt-in variants first |
| **GA-6** | API Heavy denies anonymous requests through persisted, renewable score blocks on shared scopes — K1 L3, LOW K3 remapped to a K2 `HARD_BLOCK`, MEDIUM/HIGH K3 L2 — independently of correlation (§9.1) | `CODE` | Removing correlation alone does not remove API collective penalties. Recommended: K1 window parent only, no K2 / LOW-K3 enforcement (§9.2), under D1 |
| **GA-7** | `U_src` and the anonymous-API K1 ceiling refuse on an IP-prefix scope alone; the LOCKED `KS` §2 says IP-only keys are advisory, never final (§6.7). S0's spray `HARD_BLOCK (IP)` and API K1 block are existing inconsistencies, not authorization | `CONTRACT` conflict | Source capacity ceiling needs an explicit Owner amendment (D7) with acceptance tests SC-1 … SC-6; otherwise both ceilings are withdrawn |

What survives on paper, under its stated conditions (Gate B):

* Removing collective punishment derived from IP / UA / passive fingerprint correlation, and replacing its *bounding* role
  with non-persistent capacity scopes that rotation of UA, client id, fingerprint or session cannot multiply (F§3 I1–I8).
* Outcome-independent per-window bounds, so `checkOnly`-only Hosts and dropped outcomes are bounded too — something S0 does
  not do (`MEASURED` D24K1 `pre` 1440/1440, #119 on `main@ebeb0a3`).
* A proven lane that unproven traffic can neither drain nor penalize — lane-scoped K4U / K4P (§7.2) **and** device-level rules
  P-1 … P-3 (§7.3) — **only** where a proof and a device secret exist (GA-1, GA-2); the shared DEC-021 allowance and the
  own-device K5 remain stated couplings.

What does not survive, whatever is decided:

* A co-located attacker and an unproven office are indistinguishable at the source scope; `U_src` trades one against the
  other (F§5 rows 3–4).
* For an unproven owner, a σ-independent per-target bound and protection from a remote attacker exclude each other (F§2.9).
* A service ceiling (`U_glob`, `P_glob`) is a denial lever for the whole lane, and per-policy ceilings are bypassed by
  splitting traffic across policies that share a verifier (F§7 B4-5, B4-6).
* Bounds weaken to "per window, modulo lost acknowledged writes" under Redis failover and to per-worker local limits under
  DEC-015 degraded mode (F§6). Outcome scoring is at most once per permit, not exactly once (F§2.3).
* Record-only (`out`) Hosts get no pre-verifier bound.
* An anonymous heavy requester still exhausts the shared K1 window for co-located clients; the recommended API design removes
  persistence, escalation and the K2 false sharing, not that collateral (§9.2).
* A historical-known U requester can exhaust the shared DEC-021 allowance before the owner's own proven failures (§7.2 item 3).

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
| 5 | *Spray bound* → `U_src(op)`, a per-window count of admitted unproven attempts per source, refused as capacity, never persisted as punishment. *Spray detection* stays an observation that may escalate **U-lane account risk** (K4U, §7), not the IP. *Trusted advisory persistence* is removed: P-lane traffic never charges `U_src` and is never refused by it. **Not moved:** a hard block from breadth alone. API: K1 becomes a per-window capacity parent without persisted blocks (§9.2) |
| 6 | Login/OTP: spray `HARD_BLOCK` replaced by `U_src`. API: persisted L3 score block replaced by a window counter (§9.2, D1) |
| 7 | `CREDENTIAL_SPRAY` changes meaning; a custom policy must opt in through a new typed capability rather than inherit it silently (DEC-008) |
| 8 | `DM` §5.1, `DM` §10 ("IP-only blocking is never final", "no decision based on a single signal"), `KS` §3.1, §4.3.4, DEC-019 → D1, D5 |

### 4.2 K2 — IP prefix + normalized UA

| # | Answer |
| --- | --- |
| 1 | Active block (`EP:509-533`); churn-set anchor (`EP:1174-1181`); IPv6 macro churn anchor (`EP:1197-1205`); API score (`EP:252`) |
| 2 | Churn and LOW-dilution `HARD_BLOCK` L2 60 s (`EP:1207-1222`, `EP:1264-1279`); `k2_missing_fp` (unreachable with the default resolver; no Login/OTP K2 thresholds) (`EP:2693-2701`); API `access` score (120 ⇒ L1 only) and LOW-K3 L2 remapped to a K2 `HARD_BLOCK` (`EP:573-579`, `EP:1832-1835`) |
| 3 | `HARD_BLOCK` for every requester on the IP + UA major, for every policy; API K2 `SOFT_BLOCK` |
| 4 | Observational equivalence becomes a punitive identity. `MEASURED`: #100 ABA_B 118/120 legitimate denied (S0, #116 on `main@72f6dea`); #97 D1 596/600 (S0N, #125 on `main@9e95f2f`); one browser re-authenticating with a trusted session churns itself, SESS 6/8 (F97-03). The protection it gave failed against the best response: X2 6/121 with one UA, 121/121 with UA rotation (#125). Partition by UA major multiplies the cross-account scope: TMSd 2/200 vs TMUA 36/200 (#120, `main@ebeb0a3`) |
| 5 | Per security function: **(a)** fingerprint rotation at one source → subsumed by `U_src`, which counts attempts whatever the fingerprint (F§3 I8); churn stays an observation. **(b)** one passive fingerprint across IPs (LOW dilution) → **not moved**: the inference is unsound (D1). A distributed attacker on one victim is bounded by `U_acct` (per D4, GA-3); across many victims only by `U_glob`. **(c)** cross-account aggregate at one IP + UA for trusted sessions (#113 TMSd 2/200) → **not moved as a source-scoped collective bound by default**: the trusted office and the 12-session attacker are request-for-request identical (#119 M3). Replacement: per-account P bounds + `P_glob`; a `P_src` is not recommended (D5). **(d)** API IP + UA fairness → **not moved**: K2 refuses benign same-IP-and-UA clients and gives no adversarial bound; anonymous API keeps only the K1 parent, with optional identified-client sub-limits (§9.2) |
| 6 | Login/OTP: no K2 enforcement; existing K2 blocks are honoured read-only until they expire (§10). API: no K2 enforcement; churn/dilution `HARD_BLOCK` and the LOW-K3 → K2 remap go (§9.2) |
| 7 | `POL` §5 applies correlation to all policies, so the change reaches every custom policy unless it is capability-gated or versioned (DEC-008). A custom policy's own K2 thresholds remain its configured capacity |
| 8 | `DM` §5.3, `POL` §5, `DFP` §7.1–§7.2, `KS` §3.2, §4.3.5, DEC-019 → D1 |

### 4.3 K3 — IP prefix + DeviceFP

| # | Answer |
| --- | --- |
| 1 | Active block; API score. With LOW confidence K3 = IP + HMAC(UA major), the same partition as K2 (`DIR:39`, `EP:2613`) |
| 2 | MEDIUM/HIGH dilution confirmation `HARD_BLOCK` L2 60 s (`EP:1281-1326`); API `access` score (300 ⇒ L1/L2) |
| 3 | `HARD_BLOCK` on IP + fingerprint; API `SOFT`/`HARD` |
| 4 | A client-held value chooses the enforcement path (F97-02: constant id X2 121/121 vs 6/121, #125); LOW K3 is as collective as K2 |
| 5 | Optional per-client sub-limit `U_cid ≤ U_src` for clients with an identifier only; the existing 50-per-IP cap stops creating new client sub-limits, after which clients use the parent alone (no shared overflow, §9.2); it never creates capacity. **Not moved:** the MEDIUM dilution block |
| 6 | Login/OTP: no K3 enforcement. API: no K3 enforcement for LOW; for MEDIUM/HIGH, optionally an identified-client sub-limit under K1 (§9.2) |
| 7 | Same as K2 |
| 8 | `DM` §5.3, `DFP` §4.2, §7.3, `KS` §3.3 |

### 4.4 K4 — account

| # | Answer |
| --- | --- |
| 1 | Active block for **every** requester on the account, including trusted sessions (`EP:509-533`); score; DEC-007 lifecycle (`EP:255-270`); budget state keyed on K4 (`EP:377`, `EP:2442-2456`); account Anti-Equilibrium watcher (`EP:403-407`) |
| 2 | Score +3 (Login) / +5 (OTP) per new-device or unproven failure, plus DEC-021 overflow (`EP:1600-1616`); persisted blocks from score levels (`EP:1909-1931`), flood L1 (`EP:360-371`), distributed-account L4 on the third occurrence (`EP:718-728`), Anti-Equilibrium L2 (`EP:408-422`); generation-bound publication (`EP:2057-2108`); budget counter (`EP:1794-1796`) |
| 3 | Account-wide `SOFT`/`HARD` |
| 4 | Evidence from anyone becomes a penalty on everyone, including the owner's proven sessions. `DERIVED` from `CODE`: three unproven Login failures (+3 each = 9 ≥ L2 = 8) persist a K4 `HARD_BLOCK` (60 s) that the owner's trusted session meets (`EP:513` skips only K1); device rotation against the account reaches K4 L4 (1800 s); DEC-007 re-entry evidence is exposed to any requester's pre-check on the account, not only to the owner |
| 5 | Lane-scoped account penalties (§7.2, M-A). **K4U** = the legacy K4 key and derivation keeps every U role — score, budget, DEC-007 lifecycle, account watcher, distributed-account and flood escalation, DEC-021 overflow of U requests — and binds U requests only. **K4P** (new key family) receives P-lane failures and DEC-021 overflow of P requests and binds P requests only. The DEC-021 allowance stays one shared counter; its coupling is stated in §7.2. Admission counters are separate. **Not moved:** U evidence penalizing the proven lane |
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
| 5 | **K5 derivation unchanged** (`AccountID + fingerprintHash`, `EP:2615`; the hash includes UA, client fingerprint and `sessionDeviceId`, `DIR:37-40`), so DEC-021 thresholds, K5 history, the device watcher, the micro-cap and rotation coexistence keep their keys. Consequence, stated rather than hidden: a known device that changes UA gets a new K5; across UA changes it stays bounded by the fingerprint-independent allowance and then by K4U or K4P scoring of its lane. `P_proof` is a new admission counter keyed on the proof reference; it is not a substitute for DEC-021's outcome rules. **A proof-keyed K5 is not proposed:** it would change K5 key material, need a two-derivation read during migration that must not coincide with a secret rotation (`DFP` §5.1.6), and lose proven-lane K5 history on rollback. Lane isolation at device level is obtained instead by rules P-1 … P-3 (§7.3) |
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
| Per-account harassment protection | none for proven sessions (GA-4) | lane-scoped K4U / K4P (M-A) + reserved `P_acct` / `P_proof` | D2, D3 |
| Known-device bound | DEC-021 K5 + allowance → K4 | DEC-021 K5 and shared allowance unchanged; overflow → the lane's key (K4U or K4P) | D2 |
| Collective punishment from correlation | K1/K2/K3 correlation `HARD_BLOCK` | none; correlation is observation | D1 |
| API source rate | K1 600, persisted L3, renewable | K1 window counter, no persistence | D1, LT-7 |
| API fairness sub-limits | K2 120 (`SOFT`), K3 300 (`HARD`; LOW remapped to K2) + correlation `HARD_BLOCK` | none by IP + UA; optional identified-client sub-limits under K1; no remap, no correlation block | D1, LT-7 |

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

### 5.1 Proof and known-device classification (F126-R3-04)

The lane (proof) and the DEC-021 classification (known device) are **independent**. `calculateDeltas()` classifies on
`isKnownForAccount` only (`EP:2714-2720`); a typed proof is not an input to it, and must not become one implicitly.

| Proof for this account and operation | `isKnownForAccount` | Lane | Failure scoring (`pre+out`) |
| --- | --- | --- | --- |
| yes | yes (trusted session, or previously verified) | P | K5 + shared allowance; overflow → K4P |
| yes | no (e.g. OTP step-up from a new device; a freshly issued device credential the Host has not yet marked verified) | P | new-device delta on **K4P** directly; no K5 |
| no | yes, historical only (`isDevicePreviouslyVerifiedForAccount`) | U | K5 + shared allowance; overflow → K4U |
| no | no | U | new-device delta on K4U |

Host/DTO boundary (`PROPOSED`, D3):

* A request is P only with **both** a typed proof and a non-empty server-issued `sessionDeviceId` (rule P-1, §7.3).
* The proof is a new optional typed field `{account, operation, kind}`, validated by the Host now. It does **not** set
  `isSessionTrusted` or `isDevicePreviouslyVerifiedForAccount`, and those flags never create a proof.
* A trusted session (`sessionDeviceId` + `isSessionTrusted`) may be presented **also** as a proof only for same-account
  re-authentication and OTP step-up (GA-2). It is then P and known (row 1).
* OTP step-up after primary authentication: the primary-authenticated session is the proof; the device is known only if the
  Host's existing flags say so (row 1 or row 2).
* Not every P request is known. A proof-bearing new device scores the new-device delta on K4P from its first failure; nothing
  in this design claims that a P request meets only others' evidence.

---

## 6. U/P admission contract (`PROPOSED`)

### 6.1 Lanes and scopes

| Lane | Entered when | Reserved scopes (all-or-nothing) | Penalties enforced on the request |
| --- | --- | --- | --- |
| **U** (unproven) | no valid proof for the target account and operation | `U_acct(subject, op)` *(per D4)*, `U_src(source, op)`, `U_glob(verifier class)` *(per D5)*, optional `U_cid ≤ U_src` | K4U (account), K5 of the U device |
| **P** (proven) | valid proof for the target account and operation | `P_acct(account, op)` per window and per day, `P_proof(proof ref) ≤ P_acct` per window and per day, `P_glob(verifier class)` *(per D5)* | K4P (account, P evidence only) and K5 (derivation unchanged); never K4U (§7.2) |
| **A** (anonymous API) | API request without a validated principal | K1 source ceiling (per D7), optional identified-client sub-limit `≤ K1`, optional `A_glob`; no K2 / K3 (§9.2) | none persisted (window counters only) |
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

**Activation, compatibility and end state** (`PROPOSED`, F126-R3-05):

* **Pilot (compatibility step, not the resolution).** The behaviour first ships behind a new typed capability (DEC-008) in
  separately named official variants (or an explicit preset switch). The default names `login_protection`, `otp_protection`
  and `api_heavy_protection` and every custom policy keep today's behaviour during the pilot. A pilot alone does **not** resolve
  #97 for consumers of the default presets.
* **Required end state.** If the Owner approves the policy after the research gate (§11), the package-owned default presets
  themselves must adopt it: the existing names get the new behaviour in a versioned, behaviour-changing release with a migration
  guide, canonical document updates (`DM`, `POL`, `KS`, `DFP`, `docs/guides/USAGE_GUIDE.md`) and the DEC-019 supersession.
  Whether the old semantics remain reachable for a deprecation period (for example as an explicitly named legacy variant), and
  the release classification, are part of that decision. Nothing switches the defaults implicitly.
* **Ready to use without a permit concept:** with T0 the documented `checkOnly → verify → record` flow is unchanged in shape;
  Hosts learn no new type. T1 stays optional; a Host that never sends the token gets T0 outcome semantics.
* **Documentation:** for every enabled policy, one `checkOnly` = one admission, stated in the usage guide and README examples.

**Integration semantics that sizing must include** (`DERIVED`):

| Host behaviour | Effect under T0 | Consequence |
| --- | --- | --- |
| `checkOnly` as a probe (page render, field validation) before the real attempt | one extra admission per probe | legitimate headroom divided by the probe factor `ρ ≥ 1`; the attacker uses `ρ = 1`. Capacities must be sized for `ρ` of real Hosts |
| `checkOnly` then no verifier call (CSRF failure, validation error, user abandons) | admission spent, nothing to cancel (no refunds, F§2.5) | Hosts should run cheap local validation before `checkOnly` |
| Lost `checkOnly` reply, Host retries | second admission | counted in `ρ` |
| Double submit | two admissions, possibly two verifier calls | both bounded; H1 still holds |
| `out`-only Host | no change: nothing reserved | no pre-verifier bound (§6.4) |

Impact on `pre+out` and DEC-021: T0 leaves the outcome path unchanged; the DEC-021 allowance is still consumed on
`recordFailure` (`EP:1600-1616`); its overflow goes to the failing request's lane key (K4U or K4P, §7.2).

Recommendation (LT-2): T0 in separately named official variants as a pilot, then the required default-preset adoption by Owner
decision; T1 optional for `pre+out` Hosts; no T2.

### 6.6 Bootstrap and the new user

A first-time user, a user on a new device and a user who lost the cookie have no proof, so they are U. They are admitted while
`U_src`, `U_acct` (if adopted) and `U_glob` (if adopted) have headroom. They are refused with a capacity `SOFT_BLOCK` when (a)
a co-located requester exhausted `U_src`, (b) someone exhausted `U_acct` for their account, or (c) `U_glob` is exhausted. A
successful sign-in may let the Host issue a proof for next time — only if D3 creates that contract. Without D3, Login users
never leave U, so an office stays a day-0 office every day.

`MEASURED` context (S0, not this design): on `main@a6c79f4`, 86–88 of 100 office browsers had no successful login on each of
14 days (#118 R8); idealized standing reached the whole office only on day 9 (#118 R9, model).

### 6.7 Source-only refusals versus the LOCKED Key Strategy (F126-R4-02)

`KS` §2 is `LOCKED` and Non-Negotiable: "No single-signal key may determine a final decision" and "IP-only keys are advisory,
never final". `POL` §1 requires multi-signal decisions and `DM` §10 states "IP-only blocking is never final".
`RateLimitResultDTO::isBlocked()` treats every non-`ALLOW` as a refusal (`src/DTO/RateLimitResultDTO.php:43-46`), so a capacity
`SOFT_BLOCK` is final for that request whatever its persistence.

Two refusals in this design are decided by an IP-prefix scope alone and **conflict with that contract as written**:

* `U_src` exhaustion for unproven Login / OTP traffic (§6.1);
* the K1 window for anonymous API traffic (§9.2).

S0 already contains IP-only final decisions — the credential-spray `HARD_BLOCK (IP)` for untrusted requests (`DM` §5.1,
`EP:1513-1526`) and the API K1 L3 score block (§9.1). That is an existing canonical inconsistency (`CONTRACT`); it does not
authorize new ones.

**Why a second signal cannot replace the source scope** (`DERIVED`): against a single-source attacker spraying fresh accounts,
any co-signal is either attacker-chosen (UA, client id, fingerprint — rotated so the combined predicate stays false, I3) or fresh
per target (account), so a rule "source exhausted AND second signal" never fires against that attacker. Without a source-only
refusal the spray bound falls back to `U_acct` per target and `U_glob` in aggregate (and, for anonymous API, to `A_glob` only:
one IP could take the whole global window).

**Required Owner amendment (D7, `PROPOSED` delta):** add to `KS` §2, with matching text in `POL` §1 and `DM` §10:

> IP-prefix keys remain advisory for identity, attribution and penalties. An IP-prefix scope MAY additionally act as a
> **source capacity ceiling** for unproven or anonymous traffic only: a per-window admission counter whose refusal carries the
> cause `source_capacity`, is never persisted as a block, never scored, never escalated, never counted by Anti-Equilibrium,
> budgets or block cycles, never attributed to an identity, and never applied to proven-lane or principal traffic.

The amendment must also state the effect on innocent NAT co-tenants: when the ceiling is exhausted, every unproven or anonymous
request from that source is refused for the rest of the window, whoever exhausted it.

**Acceptance tests for the future variant** (to run in the G1 study, not now):

| ID | Test | Pass condition |
| --- | --- | --- |
| SC-1 | Cause | a source refusal carries `source_capacity`, distinct from every penalty cause |
| SC-2 | No persistence | the request after the window boundary is admitted with no carried state; no block key is written |
| SC-3 | No escalation | source refusals change no score, budget, Anti-Equilibrium watcher or DEC-003 cycle |
| SC-4 | Lane scope | P-lane and principal requests from the exhausted source are admitted while their own scopes have headroom |
| SC-5 | Rotation invariance | UA, client id or fingerprint rotation from one source obtains no admissions beyond the ceiling |
| SC-6 | Co-tenant harm | legitimate refusals with cause `source_capacity` are reported per NAT stream |

If D7 is declined, `U_src` and the anonymous-API K1 ceiling are withdrawn and the residual of the previous paragraph applies.

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
| DEC-021 allowance | consumed by every known-device failure: `isKnownForAccount = isTrustedSession OR isDevicePreviouslyVerifiedForAccount` (`EP:2388-2391`, `EP:1600-1616`) — including historical-known requests that carry no current proof | overflow adds to the shared K4 (`EP:1613-1615`) |
| Distributed-account occurrence | yes, by device rotation (`EP:718-728`) | K4 L4, 1800 s |
| Flood | yes (`EP:360-371`) | K4 L1 |

### 7.2 Recommended architecture: lane-scoped account penalties (M-A)

Two orthogonal axes decide how a request is treated (`PROPOSED`):

* **Lane** — P if and only if the request carries a current typed proof for this account and operation (§5, D3); otherwise U.
  Penalty state never changes the lane: a proof stays a proof until the Host stops presenting it.
* **DEC-021 classification** — known or new device, from the existing flags only (`isKnownForAccount`, `EP:2388-2391`). A proof
  never promotes a device to known, and the known flags never create a proof (§5.1).

Account-level penalty state is kept **per lane**:

| Element | U lane | P lane |
| --- | --- | --- |
| Account penalty key | **K4U** = the legacy K4 key and derivation | **K4P** = new key family (`KS` change) |
| Fed by | failures of U requests: new-device delta, DEC-021 overflow of U requests, distributed-account, flood, U budget, U account watcher | failures of P requests: new-device delta for a proof-bearing new device, DEC-021 overflow of P requests, P budget, P account watcher |
| Enforced on | U requests for the account | P requests for the account |
| DEC-007 lifecycle | unchanged, on K4U | the same key-parameterised primitives (`PunishmentLifecycleStoreInterface.php:52-148`) on K4P; re-entry evidence exposed only to P requests (DEC-007 extension) |
| DEC-021 allowance | **unchanged, one counter per account, shared** | same counter |
| DEC-021 overflow goes to | K4U | K4P |
| K5 and device watcher | unchanged (DEC-021 §5) | unchanged |
| Admission | `U_acct` (per D4), `U_src`, `U_glob` | `P_acct`, `P_proof`, `P_glob` |

No demotion exists: exhausting the allowance changes **scoring** (new-device delta on the lane key), exactly as DEC-021 already
specifies for K4, and never the lane.

**Owner-safety statement** (`DERIVED`, corrected after F126-R4-01):

1. U requests never write K4P, a P budget, a P watcher or a P admission counter, so U traffic cannot create a P-lane
   **account** penalty or consume P capacity (F§3 I4a).
2. U requests cannot create a **device-level** penalty (K5, device watcher, micro-cap, distributed or flood candidate, ephemeral
   routing) on a P request unless they present that device's server-issued `sessionDeviceId` (§7.3, F§3 I4b). Without rules
   P-1 … P-3 this item is false: K5 alone carries no lane.
3. **Explicit residual coupling.** The DEC-021 allowance is shared and is consumed by every known-device failure, including U
   requests with `isDevicePreviouslyVerifiedForAccount = true` (row L4 of §8.1). Such requests can exhaust it; afterwards the
   *owner's own* known-device P failures score K4P immediately instead of after `A_kd` free failures. They cannot by themselves
   create K4P state. A remote U requester without a historical association to A cannot consume the allowance (it needs the
   Host's flag for that account, `DFP` §4.3).
4. P-lane penalties come only from P-lane failures — the owner's own, or those of another holder of a proof and device secret
   for A (stolen or shared). Such a holder can close the P lane for the owner's other proven devices: an owner-safety cost of
   any account-level P penalty, stated in D2.

Splitting the allowance per lane would remove coupling 3 but redefine DEC-021 and double the free known-device failures per
account (`A_kd` per lane) for an attacker who holds both a historical association and a proof; it is not recommended.

### 7.3 Device-level isolation: K5 and device-population signals (F126-R4-01)

Splitting K4 alone does not isolate the lanes. K5 is `HMAC(account + fingerprintHash)` with no lane (`EP:2615`), and the
fingerprint material is `v2|UA|clientFingerprint|sessionDeviceId` (`DIR:35-39`). Every path by which U activity can reach a P
request below the account level:

| # | Path | Code | Why U reaches P today | Treatment |
| --- | --- | --- | --- | --- |
| 1 | K5 score, threshold candidates, active K5 block | `EP:274-281`, `EP:509-533`, `EP:1933-1940` | same fingerprint material ⇒ same K5; with passive material only, K5 = account + UA major | key identity (P-1) |
| 2 | Device Anti-Equilibrium watcher | `EP:2328-2357` | keyed on account + fingerprint | key identity (P-1) |
| 3 | Known-device micro-cap | `EP:1771-1790` | keyed on account + fingerprint | key identity (P-1) |
| 4 | Distributed-account member persistence | `EP:692-716` | writes L2 on the K5 of every member fingerprint | key identity (P-1) |
| 5 | Distributed-account candidate on the current request | `EP:286-297`, `EP:640-738` | a P pre-check joins the account device set that U devices pushed toward 4 | lane gating (P-2) |
| 6 | Flood stage and flood `HARD` on the current request's K5 | `EP:328-373` | the flood stage is account-level and is set by U device churn | lane gating (P-2) |
| 7 | Account device cap → ephemeral routing | `EP:209-238`, `EphemeralBucket.php:20-21` | U fills the 10-per-account set; a P request becomes ephemeral, gets no K5 and is scored on the account path (`EP:2708-2713`) | P-lane cap (P-3) |
| 8 | Previous fingerprint generation | `EP:186-195` | same material under the previous secret | follows P-1 per generation |
| 9 | DEC-021 allowance | `EP:1600-1616` | one counter per account | stated coupling (§7.2 item 3) |

Rules (`PROPOSED`, part of LT-1):

* **P-1 — a proven request carries a device secret.** A request enters P only if, besides the typed proof, it carries a
  non-empty `sessionDeviceId`: server-issued, random, HttpOnly, bound to the account (`DFP` §3.3). The resolver puts
  `sessionDeviceId` into the fingerprint material whether or not the session is trusted (`DIR:35-39`), so every P request's K5,
  device watcher and micro-cap are keyed on a value the U side does not have. Presence is library-checked; secrecy and
  per-device uniqueness are Host-assured (D3). The K5 derivation does not change.
* **P-2 — device-population signals are U-lane signals.** P requests are not added to the account device sets of the
  distributed-account and flood rules, and those candidates are not evaluated for P requests.
* **P-3 — separate P device cap.** P requests are not routed by the U account device cap; a per-account P device cap, bounded by
  the device secrets the Host issues, protects storage.

Result (`DERIVED`): a U request can change a P request's device-level state only by presenting that device's `sessionDeviceId` —
the same physical device without a current proof (own-device evidence), or a stolen device secret (compromise). Conditions:
the official resolver, or a custom resolver that keeps `sessionDeviceId` in the material (`RateLimiterBuilder.php:127-133` lets
a Host replace it); the Host keeps the identifier secret and per device.

Compatibility: no K5 derivation change, so DEC-021 thresholds, K5 history, rotation coexistence and rollback are unaffected. A
device that starts sending `sessionDeviceId` gets a new K5 once (as with a UA update). Paths 1–4 and 8 are isolated on old
workers too, because isolation comes from the material; paths 5–7 are isolated only on new workers, so during a mixed rollout
they stay cross-lane until the fleet fence (LT-6).

Costs: a request with a proof but no device secret is U; distributed-account and flood no longer observe proven devices, whose
rotation is bounded instead by P caps and by the number of secrets the Host issues; own-device coupling remains.

Alternative **K5-L** (a lane marker in the K5 derivation): isolates even the same device across lanes, but changes K5 key
material (a new K5P family), lets an actor holding both an association and a proof fail `A_kd`-independently up to the K5
thresholds once per lane, needs the fence for paths 1–4 too, and loses K5P on rollback. Not recommended; it is the option if
the Owner requires same-device isolation.

### 7.4 Concurrency (F126-R3-02)

Outcome-based state (allowance, K5, K4U/K4P) is updated by `recordFailure`, after the verifier; admissions are decided at
`checkOnly`. As today (DEC-021 Consequences: "pre-checks are read-only, so concurrent in-flight attempts on one device are not
serialized"), no outcome-based rule bounds concurrent attempts. Trace: with `r` units left in `P_acct` for the window, `r`
concurrent P pre-checks are all admitted atomically; all fail; the allowance and K4P advance only as their outcomes arrive. The
overshoot beyond `A_kd` is therefore at most the P admissions in flight, which the atomic `P_acct` / `P_proof` caps bound per
window (F§3 I1, I5). **The concurrency bound is the admission cap, not the allowance;** no claim of "`A_kd` failures, then
escalation" is made for concurrent traffic.

### 7.5 Rejected alternatives (short evidence)

| Mechanism | Verdict | Evidence |
| --- | --- | --- |
| **M-G** allowance-gated demotion of P to U (`04d6d0f`) | **NO-GO** | (i) The allowance is consumed by U requests with a valid historical flag (`EP:2388-2391`, `EP:1600-1616`), so U traffic can demote genuinely proven requests — contradicting its own isolation claim (F126-R3-01). (ii) DEC-021 overflow changes scoring, not proof validity; demotion is a new policy that punishes the proven owner for another requester's history (R3-02). (iii) The allowance is read at `checkOnly` but written at `recordFailure`, so "`A_kd` then demotion" is not a bound under concurrency (R3-02) |
| **M-C** proven requests ignore account penalties, bounded by P caps only | **NO-GO** | A stolen proof is bounded only by `P_day` and K5; K5 resets with every UA change (§4.5) and K5 alone does not bound pacing (DEC-021 "K5 thresholds alone: Rejected"). Any legitimate daily proven demand `L_d > A_kd` forces `P_day ≥ L_d`, so M-C admits up to `L_d` stolen-proof failures where DEC-021 today escalates after `A_kd` (counterexample of the `04d6d0f` revision) |
| M-B lane-tagged blocks on one key | dominated by M-A | needs a Store block-schema and DEC-007 publication change for the same properties |
| M-D unproven evidence never punishes the account | NO-GO | loses outcome-based protection on the lane where foreign guessing happens |
| M-E no change | NO-GO | fails GA-4 (§7.1) |

### 7.6 Costs of M-A, stated

* New K4P key family, a second budget and account watcher, and a DEC-007 extension (Store primitives reused; no new Store
  operation).
* `reach_foreign` loses P evidence: a stolen-proof holder's failures do not harden K4U. If it drops the proof it starts as any
  unproven attacker, bounded by the U caps.
* Rollback to S0 loses K4P state (≤ its TTL); K4U (= legacy K4) and K5 survive and S0 applies them to everyone.
* Pre-only Hosts get no outcome-based escalation on either lane (as today); only the admission caps bound them.

### 7.7 What remains unprotected

* An owner without a proof is U and shares `U_acct` and K4U with the attacker (F§2.9, D4). For Login without D3, every owner.
* Own-device coupling (§7.3), the allowance coupling (item 3) and the shared-proof cost (item 4) of §7.2.
* Under DEC-015 degraded mode the local fallback has no lanes; owner protection is absent during an outage (D6).

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
| L3 Client instance id only | L2 + `clientInstanceId` | continuity hint | U | L2 + optional `U_cid ≤ U_src` | Reserve{acct, src, cid, glob} | as L2 | as L2 | as L2; cid sub-limits capped at 50 per IP, later clients use the parent only |
| L4 Historically known, no current proof | L2 + `isDevicePreviouslyVerifiedForAccount` | historical association | **U** | as L2 | as L2 | as L2 / PEN(K5) | account, device | failure: DEC-021 K5 +2 and the **shared** allowance (consuming it also for the account's P lane, §7.2 item 3); overflow → K4U |
| L5 ◇ Current proof for this account and operation | L2 + proof(account, op) + `sessionDeviceId` (P-1) | current proof | P | `P_acct`, `P_proof`, `P_glob` | Reserve{acct-P, proof, glob-P} | ALLOW / CAP(P scope) / PEN(K4P or K5) | P caps, account-P, device | known device: K5 +2 (DEC-021), shared allowance, overflow → K4P; new device with proof: K4P + delta (§5.1); P budget and watchers |
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
| O1 Step-up after primary authentication, session for this account ◇ | P | The proof is the primary-authenticated session (GA-2); the device is known only if the Host's flags say so (§5.1). An attacker who knows the password is **also** in P: OTP allowance (2), K5, P caps and K4P (4/7/10, +5) bound the guesses |
| O2 Passwordless or recovery OTP | U | No proof exists; `U_acct` (per D4) is the per-account bound |
| O3 Session for A, OTP for B | U for B | as L6 |
| O4 Known device, no session | U | as L4; DEC-021 allowance 2 |
| Budget command eligibility | both | `pre` never issues the budget (`precheck_enforcement = false`, `DM` §3.3.2), unchanged |
| Recovery Collision Guard | P when the device is HIGH/known with proof; else U | account-scoped one-shot `SOFT` (`EP:1797-1811`) on the lane's budget (K4U or K4P) |

### 8.3 API Heavy (`api_heavy_protection`)

| Case | Input | Trust | Lane | Budgets | Atomic operation | Result | Enforcement scope | State updates |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| A1 Anonymous | ip, ua | observation | A | K1 parent; optional `A_glob` | Reserve{K1, glob} | ALLOW / CAP(scope) | source | access counted on admission; no persisted block, no K2/K3 limit, no correlation `HARD_BLOCK` (§9.2) |
| A2 Anonymous with client id | A1 + id | continuity hint | A | A1 + optional identified-client sub-limit `≤ K1` | Reserve{K1, client, glob} | as A1 | as A1 | as A1; no shared overflow bucket (§9.2) |
| A3 Principal validated by the Host | principal | current proof (principal) | R | `R_principal`, optional `R_glob` | Reserve{principal, glob} | ALLOW / CAP(principal or glob) | principal | principal counter |
| A4 Principal claimed, Host validation failed | — | — | A | as A1 | as A1 | as A1 | as A1 | authentication failures belong to an authentication policy, not API Heavy |
| A5 UA/IP change, anonymous | — | — | A | UA change: nothing (no scope uses UA); new IP ⇒ new K1, `A_glob` only | — | — | — | — |
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

### 9.2 Recommended: K1 parent only, per-client limits only for identified clients (F126-R3-03)

Three options for anonymous API, all with window counters and no persistence (the A-cap semantics of the previous revision):

| Option | Enforcement scopes | Benign refusal not caused by the parent | Adversarial value |
| --- | --- | --- | --- |
| A-cap+K2 (`04d6d0f`) | K1 parent, K2 (IP + UA) sub-limit, K3 sub-limit for MEDIUM/HIGH | **yes**: two independent clients on one IP and UA needing 80 calls each (160 < K1 600) lose 40 calls to K2 = 120, only because they share IP + UA (F126-R3-03). In a NAT the popular UA major is shared by most clients, so K2 is effectively a smaller K1 for them | none: an attacker rotates UA and meets K1 alone |
| **A-parent** (recommended) | K1 parent only; optional `A_glob` | none beyond the parent | K1 is the only bound in every option |
| A-parent+client | A-parent + an optional sub-limit per **identified** client (a Host-issued `clientInstanceId`, or a MEDIUM/HIGH client fingerprint); clients without an identifier get no sub-limit and no shared overflow bucket | none: distinct clients never share a sub-limit; the cardinality cap (50 per IP, `EphemeralBucket.php:21`) only stops creating new client sub-limits — later clients fall back to the parent alone | none (rotation only splits the parent); benign value: one buggy identified client cannot take the whole parent |

The only benefit K2 offered is benign: limiting one heavy client so others on the same IP keep capacity. It delivers that only to
co-tenants with a different UA major, and refuses co-tenants with the same major — in a NAT, usually the majority. The
identified-client sub-limit delivers the same benefit without the false sharing. **Verdict: K2 as an anonymous API enforcement
scope is NO-GO; LOW K3 goes with it (same partition, §9.1).** Recommendation (LT-7, conditional on D1 and D7): **A-parent**, with
**A-parent+client** as an option, and principal budgets (§9.3) when the Host has a validated principal.

What none of the options removes: a heavy co-located client still exhausts the K1 window for everyone behind the IP. That is the
co-located residual (F§8 item 1), non-persistent and non-escalating; it is not eliminated.

### 9.3 Remaining API design

* **Anonymous traffic cannot get** per-person fairness inside one source without an identifier; an identifier can be rotated,
  so it can only split the parent, never grow it.
* **Principal traffic** (`PROPOSED`, D3): a new optional Host-validated principal field. Principal budgets do not charge the
  anonymous source parent. DEC-011 forbids an ACCOUNT fallback dimension for `API_OVERUSE` (`RLE:122`); a principal dimension
  in degraded mode is a separate decision.
* **Failure mode** stays `FAIL_OPEN` with bounded fallback (DEC-011, DEC-015). Its IP + UA fallback dimension (60/60 s,
  `FailureFallbackProfileEnum.php:45-46`) has the same false-sharing property as K2; changing it is part of D6, not done here.
* **Official vs custom:** the change replaces the meaning of `API_OVERUSE` thresholds (score with decay → window counter) and of
  `retryAfter`; a custom `API_OVERUSE` policy keeps today's semantics until it opts into a new typed capability (DEC-008). The
  engine today requires K1, K2 and K3 thresholds for `API_OVERUSE` (`RLE:150-161`); the new capability needs its own validation.
* `MEASURED` context: D1API 596/600 → 0/600 once the cohort is no longer one fingerprint (#125) — that harm came from
  correlation; the rate paths' collateral on shared NATs is `UNPROVEN` in size.

---

## 10. Compatibility and migration impact

| Area | Impact | Owner |
| --- | --- | --- |
| `RateLimitCommand`, `RateLimitResultDTO`, `RateLimiterInterface` | T0: no type change; new `cause` values. T1: optional permit token in metadata and on outcome commands (additive) | LT-2 |
| `RateLimitContextDTO` | optional typed proof `{account, operation, kind}`, optional `clientInstanceId`, target subject, API principal | D3 |
| Store contracts | new optional capability: atomic multi-scope reserve with dual-generation read; T1 adds a permit record with a state machine (F§6) | LT-4 |
| Redis adapter | Lua with caller time (DEC-016); one hash slot per verifier class; the supported topology stays one non-clustered Redis | LT-4 |
| Builder / DEC-008 | new typed capability; fail-fast without the Store capability (DEC-007 precedent); official behaviour ships as separately named variants, default preset names unchanged; custom policies keep S0 unless they opt in | LT-2 |
| `API_OVERUSE` semantics | score with decay and persisted blocks → K1 window counter; K2 / K3 thresholds no longer enforced for the new capability (today's validation requires them, `RLE:150-161`); `retryAfter` meaning changes | D1, LT-7 |
| DEC-007 | unchanged lifecycle on K4U (legacy key); the same key-parameterised primitives applied to K4P, with re-entry exposed only to P requests (extension) | D2 |
| DEC-021 | K5 derivation, thresholds and the shared allowance unchanged; overflow goes to the failing request's lane key (K4U or K4P) | D2 |
| DEC-015 / DEC-011 | admission bounds do not hold in degraded mode; lanes are absent in fallback | D6 |
| DEC-019 | superseded only by a future approved replacement (Decision Governance §9) | D1 |
| Canonical docs | `DM` §0, §1, §2.2.2–§2.5, §3.3, §5.1–§5.4, §10; `POL` §1, §5, §6; `DFP` §3.3, §4.2, §4.3; `KS` §3, §4.3, §4.6; `FS` §4; `docs/guides/USAGE_GUIDE.md` (`checkOnly` semantics) | with each decision |

**Migration** (`PROPOSED`; failure analysis in F§6):

1. Ship the capability dormant; no preset declares it; behaviour is S0.
2. Fence: an operator-set admission epoch in the store enables the new path only after every worker runs the new version; old
   workers do not reserve, so bounds are `UNPROVEN` while mixed.
3. While enabled, existing K1/K2/K3 correlation blocks are honoured read-only until expiry (≤ 60 s for correlation L2) and no
   new ones are written. K4U is the legacy K4 key and K5 keeps its derivation, so their history continues; K4P starts empty.
4. Rollback: S0 reads K4 and K5 as before and ignores admission counters and permits (they expire by TTL). No penalty history is
   lost except K4P (P-lane account penalties, at most their TTL); K4U (= K4) and K5 survive and S0 applies them to everyone.
   S0's collective blocks resume.
5. Secret rotation: counters are read across the current and previous generation and summed (F§6.4); a second rotation
   within one window re-issues capacity (`DERIVED`), so rotation interval ≥ window is a precondition (`DFP` §5.1.6).

---

## 11. Decision register and gate order (F126-R3-05)

### 11.1 Gate order (non-circular)

| Gate | Who | Decides | Does not decide |
| --- | --- | --- | --- |
| **G0 — scope acceptance** | Lead | that this Gate A/B is coherent and falsifiable (one recommended architecture, rejected variants with evidence, measurable claims) | any policy |
| **G1 — research authorization (R-1)** | Owner | whether an **isolated** comparative study of the recommended architecture against named controls may be built outside the package Runtime | the security policy, thresholds, defaults, ADR, merge or release |
| **G2 — selection** | Lead | LT-1 … LT-7 from the G1 evidence | material policy |
| **G3 — policy and rollout** | Owner | D1–D7 as security trade-offs, then default-preset adoption and DEC-019 supersession (§6.5 end state) | — |

No research starts before G1; no policy is implied by G1. Today the PR is **before G0**.

### 11.2 Lead technical choices (closed at G2)

| ID | Choice | Recommendation | Evidence needed at G1 |
| --- | --- | --- | --- |
| LT-1 | Account penalty structure | **M-A** lane-scoped K4U / K4P, shared DEC-021 allowance, no demotion (§7.2), device-level rules P-1 … P-3 (§7.3); M-G and M-C are NO-GO (§7.5) | M-A against M-E (S0) as a control on S1–S6 of F§9; M-C optional as a further control |
| LT-2 | Contract tier | T0 for the bounds, T1 optional, no T2 (§6.5) | probe factor `ρ`, abandoned admissions, retries |
| LT-3 | Outcome guarantee | at-most-once per permit, no unbound fallback (F§2.3) | replay, conflict, expiry, failover traces |
| LT-4 | Storage | one atomic script per verifier class, caller time, dual-generation read (F§6) | concurrency with mutation, rotation, failover |
| LT-5 | Verifier class | required for any global ceiling (F§7 B4-6) | — |
| LT-6 | Migration | dormant ship, fleet fence, K4U = legacy K4, K5 unchanged, K4P lost on rollback (§10) | mixed fleet, rollback, roll-forward |
| LT-7 | API anonymous | **A-parent** (K1 only), optional identified-client sub-limit; K2/LOW-K3 enforcement NO-GO (§9.2) | A-parent vs A-cap+K2 on shared-NAT and heavy-client streams |

### 11.3 Owner decisions

| ID | Gate | Decision | Recommendation | Trade-off |
| --- | --- | --- | --- | --- |
| **R-1** | G1 | Authorize an isolated comparative study (no Runtime, no policy) of the architecture in this document | Authorize, after G0 | Cost of the study only |
| **D1** | G3 | Replace collective penalties on shared scopes (correlation, and the API score penalties of §9.1) with capacity admission; adopt in the default presets per §6.5; supersede DEC-019 by a new ADR | Decide on G1 evidence | Removes measured correlation harm (D1 596/600, ABA_B 118/120, D2 117/121); the naive one-UA cross-IP attacker S0 stopped (X2 6/121) is bounded only by `U_glob`; best-response reach unchanged (121/121) |
| **D2** | G3 | Lane-scoped account penalties (M-A): U evidence never penalizes the proven lane; a proven holder's failures penalize the proven lane of that account; the DEC-021 allowance stays shared with the coupling of §7.2 item 3 | Decide on G1 evidence | Another holder of a proof for A can close A's proven lane (§7.2 item 4); historical-known U requests can exhaust the shared allowance |
| **D3** | G3 | Optional typed Host proof, independent of the known-device flags (§5.1); trusted session as proof only for same-account re-authentication and OTP step-up; API principal input | Approve | P integrity rests on the Host (`MEASURED` control SPV 4 → 200/200, #119); without D3 there is no proven lane |
| **D4** | G3 | Unproven-owner availability (F§2.9): U-1, U-2 or U-3 | U-2 with D3, and amend the owner-safety clause of `POL` §1 to name proven owners | U-2 makes refusal of an unproven owner reachable in `pre` too; U-3 re-creates source scaling; U-1 leaves `pre` unbounded per account |
| **D5** | G3 | Envelope: global ceilings as resource guards per verifier class, no `P_src` (#120 disposition), approved values, residuals of F§8 | Decide on G1 evidence | A binding global denies the whole lane; stolen-session reach grows with sessions held |
| **D6** | G3 | Outage semantics under DEC-015 (including the API IP + UA fallback dimension) | Accept the documented weaker guarantee | Owner protection and bounds absent during outages |
| **D7** | G3 | Amend the LOCKED `KS` §2 (and `POL` §1, `DM` §10) with a non-punitive **source capacity ceiling** class for unproven and anonymous traffic (exact delta in §6.7) | Approve only with SC-1 … SC-6 passing in G1 | Every unproven or anonymous request from an exhausted source is refused for the window, innocent NAT co-tenants included; declining it leaves single-source spray bounded only by `U_acct` and `U_glob`, and anonymous API by `A_glob` |

---

## 12. Final recommendation

**Status of this revision: `CORRECTED PER G0 REVIEW R4 (F126-R4-01 … R4-03) — PENDING LEAD G0`.** No research,
prototype, policy or merge is authorized.

**One recommended architecture:**

| Layer | Recommendation |
| --- | --- |
| Lanes | U / P by a current typed proof (D3), independent of DEC-021 classification (§5.1) |
| Admission | atomic, irreversible, at `checkOnly` (T0; T1 optional): `U_src` per D7, `U_acct` per D4, `U_glob` optional; `P_acct`, `P_proof`, `P_glob` optional (§6) |
| Account and device penalties | M-A: K4U (legacy key) and K4P per lane; shared DEC-021 allowance; K5 derivation unchanged with device-level rules P-1 … P-3 (P requires a device secret; device-population signals U-only; separate P device cap); no demotion (§7.2, §7.3) |
| Correlation | observation only; no collective penalty (D1) |
| Anonymous API | K1 window ceiling only (per D7); optional identified-client sub-limit; principal budgets (§9.2) |
| Rollout | pilot variants, then required default-preset adoption by Owner decision (§6.5) |

**Engineering NO-GO, kept as evidence:** M-G and M-C (§7.5), K2 / LOW-K3 as anonymous API enforcement scopes (§9.2),
proof-keyed K5 (§4.5), and the first revision's claims of M-C dominance and of T0 as contract-free.

Why it is still not a GO: G0 is pending; the comparative evidence of G1 does not exist; owner protection needs D3; the
unproven-owner trade-off needs D4; every source-only refusal needs D7 (§6.7).

Why it is not rejected: on paper, under stated conditions, it is the only reviewed direction that removes collective penalties
on shared scopes **and** keeps an outcome-independent, rotation-invariant bound on verifier admissions (F§3). The measured
candidates did one or the other (#125 CIR, #116 OAAA, #119 AKDR, #115 GNH5: all NO-GO). It bounds `checkOnly`-only Hosts and
cross-account misuse of a session where S0 does not (`MEASURED` S0: D24K1 `pre` 1440/1440, #119; SP5 200/200, #108); that
improvement is `DERIVED` and holds only if the proof names its own account (C8).

---

## 13. Revision history of this proposal

| Revision | Change |
| --- | --- |
| `89ef2d2` | Initial draft |
| `a79c06d` | Gate A/B analysis: GA-1 … GA-5, truth tables, K1–K5 reconciliation, invariants, threat verdicts, storage, falsification |
| `6c78fb8` | Direct Lead Review: F126-01 owner-safety mechanisms compared, M-C recommended over the key split (§7.2); F126-02 permit state machine with an at-most-once guarantee and no unbound fallback (F§2.3); F126-03 decision class separated from availability effect, trade-off proven (F§2.9), options in D4; F126-04 minimum contract tiers, T0 needs no type change (§6.5); F126-05 storage bound restated per class and window, permit records counted, no absolute "bounded" claim (F§3 I10, F§6.6); decision register split into Lead choices and seven Owner decisions (§11) |
| `04d6d0f` | Direct Lead Re-review: R2-01 every API penalty path traced and A-cap proposed per key, GA-6 (§9); R2-02 K5 derivation kept unchanged, proof-keyed K5 withdrawn from Gate C with its migration and rollback cost stated (§4.5); R2-03 M-G added, scenario matrix against M-A and M-C, dominance claim withdrawn, LT-1 conditional, required pipeline changes listed and the advisory-pattern claim corrected (§7.2–§7.4); R2-04 T0 restated as a material behavioural change with opt-in variants and probe/retry/cancel sizing (§6.5); register and status updated (§11, §12) |
| `bee26cf` | Direct Lead Re-review R3: R3-01/02 M-G declared NO-GO (U requests with a historical flag consume the shared allowance; demotion is a new policy; TOCTOU), M-C NO-GO, **M-A** recommended with a stated allowance coupling and a concurrency trace (§7); R3-03 K2 / LOW-K3 as anonymous API enforcement NO-GO, K1 parent only (§9.2); R3-04 proof and known-device classification made independent (§5.1); R3-05 required default-preset end state (§6.5) and non-circular gate order G0–G3 (§11); one recommended architecture (§12) |
| this revision | G0 review R4: R4-01 every K5 and device-population path traced; owner safety split into account-level (I4a) and device-level (I4b) statements; rules P-1 (proven request carries a server-issued device secret), P-2 (device-population signals U-only), P-3 (separate P device cap); K5 derivation unchanged; K5-L kept as the alternative (§7.3); R4-02 source-only refusals (`U_src`, anonymous-API K1) recorded as a conflict with the LOCKED `KS` §2, Owner amendment D7 with exact delta and acceptance tests SC-1 … SC-6 (§6.7, GA-7); R4-03 API rows of §6.1, §8.3 and F§2.7 aligned with §9.2 |

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
