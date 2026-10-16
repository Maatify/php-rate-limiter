# Identity-Aware Bounded Admission — Architecture Contract (#97)

> **Status:** ARCHITECTURE PROPOSAL, analysed through Gate A (architecture contract) and Gate B (paper security
> feasibility). **NOT APPROVED. NO PRODUCTION CHANGE.** Not an ADR, not a GO, not a prototype authorization.
> **Verdict:** `CORRECTION REQUIRED — BLOCKED ON OWNER POLICY DECISIONS` (§12).
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

Gate A found four contradictions that only the Owner can resolve, and one required public-contract change. Gate B analysed
the mechanism **conditionally on those decisions**; it does not claim that the decisions will be taken.

| ID | Finding | Kind | Consequence |
| --- | --- | --- | --- |
| **GA-1** | No existing input is a *current, account-bound proof* for the primary Login of a logged-out user. `sessionDeviceId` trust is post-authentication only; `isDevicePreviouslyVerifiedForAccount` is a historical Host boolean (§5) | `CONTRACT` gap | Without a new proof contract, Login is **U-only** and the owner-protection property (A3) cannot be claimed for Login |
| **GA-2** | The Level-3 session identifier is contractually "Advisory only" and "NOT proof of device ownership" (`DFP` §3.3) | `CONTRACT` conflict | Using a trusted session as P-lane entitlement (re-auth, OTP) is a contract change |
| **GA-3** | A per-account pre-verifier attempt cap (`U_acct`) is an account-wide refusal based solely on attempt counts and, for any owner without a proof, a renewable denial-of-owner. `POL` §1 forbids account-wide hard blocks on attempt counts alone and budgets that enable renewable denial-of-owner; `DM` §2.4 exists to stop abuse "without enabling remote permanent lockout". DEC-021 itself chose a per-failure scoring rule, "not an absolute cap" (Decision 3) | `CONTRACT` conflict | Every claim that depends on `U_acct` (per-target bound across IP/identity rotation) is **conditional on OD-2**. Gate B states it as such |
| **GA-4** | K4 is one account key, fed by evidence from any requester and enforced on every requester, including trusted sessions (`EP:509-515`). A reserved P lane is closed by any K4 block | `CODE` + `CONTRACT` | Owner protection needs a lane split of K4 (§7), which changes DEC-007 and DEC-021 semantics (OD-5) |
| **GA-5** | Atomic pre-verifier admission needs an attempt identity carried from admission to outcome; `RateLimitCommand` has none (`src/Command/RateLimitCommand.php:27-33`); #116 measured why a tuple counter is not enough (X1 FAIL) | Public contract change | OD-3 |

What survives on paper, under its stated conditions (Gate B):

* Removing collective punishment derived from IP / UA / passive fingerprint correlation, and replacing its *bounding* role
  with non-persistent capacity scopes that rotation of UA, client id, fingerprint or session cannot multiply (F§3 I1–I8).
* Outcome-independent per-window bounds, so `checkOnly`-only Hosts and dropped outcomes are bounded too — something S0 does
  not do (`MEASURED` D24K1 `pre` 1440/1440, #119 on `main@ebeb0a3`).
* A P lane that U traffic cannot drain — **only** where a proof exists (GA-1/GA-2).

What does not survive, whatever the Owner decides:

* A co-located attacker and an unproven office are indistinguishable at the source scope; `U_src` trades one against the
  other (F§5 rows 3–4).
* A service ceiling (`U_glob`, `P_glob`) is a denial lever for the whole lane, and per-policy ceilings are bypassed by
  splitting traffic across policies that share a verifier (F§7 B4-5, B4-6).
* Bounds weaken to "per window, modulo lost acknowledged writes" under Redis failover and to per-worker local limits under
  DEC-015 degraded mode (F§6).
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
| 5 | *Spray bound* → `U_src(op)`, a per-window count of admitted unproven attempts per source, refused as capacity, never persisted as punishment. *Spray detection* stays an observation that may escalate **U-lane account risk** (K4U, §7), not the IP. *Trusted advisory persistence* is removed: P-lane traffic never charges `U_src` and is never refused by it. **Not moved:** a hard block from breadth alone. API: K1 stays the anonymous source parent |
| 6 | Login/OTP: spray `HARD_BLOCK` replaced by `U_src`. API: role unchanged |
| 7 | `CREDENTIAL_SPRAY` changes meaning; a custom policy must opt in through a new typed capability rather than inherit it silently (DEC-008) |
| 8 | `DM` §5.1, `DM` §10 ("IP-only blocking is never final", "no decision based on a single signal"), `KS` §3.1, §4.3.4, DEC-019 → OD-1, OD-6 |

### 4.2 K2 — IP prefix + normalized UA

| # | Answer |
| --- | --- |
| 1 | Active block (`EP:509-533`); churn-set anchor (`EP:1174-1181`); IPv6 macro churn anchor (`EP:1197-1205`); API score (`EP:252`) |
| 2 | Churn and LOW-dilution `HARD_BLOCK` L2 60 s (`EP:1207-1222`, `EP:1264-1279`); `k2_missing_fp` (unreachable with the default resolver; no Login/OTP K2 thresholds) (`EP:2693-2701`); API `access` score (120 ⇒ L1 only) and LOW-K3 L2 remapped to a K2 `HARD_BLOCK` (`EP:573-579`, `EP:1832-1835`) |
| 3 | `HARD_BLOCK` for every requester on the IP + UA major, for every policy; API K2 `SOFT_BLOCK` |
| 4 | Observational equivalence becomes a punitive identity. `MEASURED`: #100 ABA_B 118/120 legitimate denied (S0, #116 on `main@72f6dea`); #97 D1 596/600 (S0N, #125 on `main@9e95f2f`); one browser re-authenticating with a trusted session churns itself, SESS 6/8 (F97-03). The protection it gave failed against the best response: X2 6/121 with one UA, 121/121 with UA rotation (#125). Partition by UA major multiplies the cross-account scope: TMSd 2/200 vs TMUA 36/200 (#120, `main@ebeb0a3`) |
| 5 | Per security function: **(a)** fingerprint rotation at one source → subsumed by `U_src`, which counts attempts whatever the fingerprint (F§3 I8); churn stays an observation. **(b)** one passive fingerprint across IPs (LOW dilution) → **not moved**: the inference is unsound (D1). A distributed attacker on one victim is bounded by `U_acct` (conditional, GA-3); across many victims only by `U_glob`. **(c)** cross-account aggregate at one IP + UA for trusted sessions (#113 TMSd 2/200) → **not moved as a source-scoped collective bound by default**: the trusted office and the 12-session attacker are request-for-request identical (#119 M3). Replacement: per-account P bounds + `P_glob`; an optional `P_src` is OD-7. **(d)** API IP + UA fairness → kept as a capacity sub-limit under the K1 parent |
| 6 | Login/OTP: no K2 enforcement; existing K2 blocks are honoured read-only until they expire (§10). API: K2 rate sub-limit stays, churn/dilution `HARD_BLOCK` goes |
| 7 | `POL` §5 applies correlation to all policies, so the change reaches every custom policy unless it is capability-gated or versioned (DEC-008). A custom policy's own K2 thresholds remain its configured capacity |
| 8 | `DM` §5.3, `POL` §5, `DFP` §7.1–§7.2, `KS` §3.2, §4.3.5, DEC-019 → OD-1 |

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
| 5 | Lane split (§7): **K4U** (the legacy K4 key) keeps every existing U-lane role — score, budget, DEC-007 lifecycle, account watcher, distributed-account and flood escalation — and is enforced on U-lane requests only. **K4P** (new) receives P-lane evidence — DEC-021 overflow from proven devices and the P-lane account watcher — and is enforced on P-lane requests only. Admission counters are separate from both. **Not moved:** U-lane evidence closing the P lane |
| 6 | Login/OTP materially changed; API unaffected |
| 7 | Every authentication custom policy (DEC-008 capabilities, DEC-007 / DEC-021 markers) is affected; the change must be an explicit, versioned opt-in |
| 8 | DEC-007, DEC-021 §3 and §5, `DM` §1, §2.3–§2.5, §3.3, §5.2, §5.4, §10, §11, `KS` §3.4, §4.5, §4.6, §10.1 → OD-5 |

### 4.5 K5 — account + DeviceFP

| # | Answer |
| --- | --- |
| 1 | Active block; score; device Anti-Equilibrium watcher (`EP:2328-2357`); micro-cap (`EP:1771-1790`) |
| 2 | Known-device failure score with DEC-021 thresholds and K5-only persistence (`EP:1933-1940`); flood `HARD` (`EP:347-358`); every member of a distributed-account snapshot L2 (`EP:692-716`); device Anti-Equilibrium L2 |
| 3 | Device-scoped `SOFT`/`HARD` on that account |
| 4 | With LOW confidence the "device" is account + UA major (`DIR:39`, `EP:2615`): the owner's same-major browsers and an attacker who picks that major share one K5, and distributed-account persistence writes attacker-chosen fingerprints (`DERIVED`). A browser update creates a new K5 (`MEASURED` UAC known-device reach 8/12, #125) |
| 5 | K5 becomes the per-device sub-scope of the lane that issued it. P lane: DEC-021 per-proof bound, keyed on the proof's device or session reference rather than the passive UA. U lane: distributed-account and flood evidence escalates K4U, never a P-lane key. **Not moved:** U-lane device evidence blocking proven devices |
| 6 | Login/OTP |
| 7 | DEC-021 opt-in policies |
| 8 | DEC-021, `DM` §2.2.2, §5.2, §5.4, `KS` §3.5, §4.3.3 |

### 4.6 Before and after

| Security function | Today | Proposed owner | Status |
| --- | --- | --- | --- |
| Single-source spray bound | K1 breadth `HARD_BLOCK` (shared, persisted) | `U_src` capacity (per window, non-persistent) | `PROPOSED` |
| Fingerprint-rotation evasion at one source | K2 churn `HARD_BLOCK` | `U_src` (rotation-invariant) | `PROPOSED` |
| Distributed attack on one victim | K4 score (outcomes only); K2 LOW dilution (one-UA attacker only) | K4U score + `U_acct` | **conditional on OD-2 (GA-3)** |
| Distributed attack on many victims | none against the best response (X2 121/121) | `U_glob` (a denial lever, F§7 B4-3) | `PROPOSED`, OD-6 |
| Cross-account bound for proof holders at one source | K2 (one UA major); multiplied by majors (#120) | per-account P bounds + `P_glob`; `P_src` optional | OD-7 |
| Per-account harassment protection | none for proven sessions (GA-4) | K4 lane split + reserved `P_acct` | OD-2, OD-5 |
| Known-device bound | DEC-021 K5 + allowance → K4 | DEC-021 inside each lane; overflow → K4P | OD-5 |
| Collective punishment from correlation | K1/K2/K3 correlation `HARD_BLOCK` | none; correlation is observation | OD-1 |
| API source rate | K1 600 | K1 parent unchanged | unchanged |
| API fairness sub-limits | K2 120, K3 300 + correlation `HARD_BLOCK` | K2/K3 sub-limits under the K1 parent; no correlation block | OD-1, OD-9 |

---

## 5. Trust and proof boundary

| Class | Members | May be used for | Must never be used for |
| --- | --- | --- | --- |
| **Observation** | IP, UA, passive fingerprint, churn/dilution/spray sets, IPv6 macro scopes | Capacity scoping (source), risk metrics, U-lane escalation of the *targeted account* | Attributing a penalty to everyone sharing the observation |
| **Continuity hint** | `clientInstanceId` (`PROPOSED`, a field separate from `clientFingerprint`), `clientFingerprint`, untrusted `sessionDeviceId` | Optional sub-limit `≤` parent; K5 continuity inside a lane | Raising confidence or lane; creating capacity; acting alone as punishment |
| **Historical association** | `isDevicePreviouslyVerifiedForAccount`, K5 history | DEC-021 scoring classification (K5 instead of K4U) | Admission entitlement |
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
| API Heavy | Host-validated API principal | No field today (OD-9) |

---

## 6. U/P admission contract (`PROPOSED`)

### 6.1 Lanes and scopes

| Lane | Entered when | Reserved scopes (all-or-nothing) | Punishment read |
| --- | --- | --- | --- |
| **U** (unproven) | no valid proof for the target account and operation | `U_acct(subject, op)` *(conditional, GA-3)*, `U_src(source, op)`, `U_glob(verifier class)` *(optional, OD-6)*, optional `U_cid ≤ U_src` | K4U, K5 of the U device |
| **P** (proven) | valid proof for the target account and operation | `P_acct(account, op)`, `P_proof(proof ref) ≤ P_acct`, `P_glob(verifier class)` *(optional, OD-6)*, optional `P_src` (OD-7) | K4P, K5 of the proof |
| **A** (anonymous API) | API request without a validated principal | K1 source parent, K2/K3 sub-limits, optional `A_glob` | API scores only |
| **R** (API principal) | API request with a validated principal | `R_principal(op)`, optional `R_glob` | principal state |

* `subject` is a Host-supplied opaque target subject that exists **whether or not the account exists**, so a refusal never
  reveals existence (F§2.6).
* `source` is IPv4 exact address or IPv6 `/64`; an IPv6 hierarchy (`/48` parent) is `UNPROVEN` and part of OD-6.
* `verifier class` groups every policy that calls the same verifier (for example login, re-authentication and password change),
  so a global ceiling cannot be bypassed by splitting traffic across policies (F§7 B4-6). Today state is namespaced per policy
  name (`EP:2607`), so this grouping is new.
* No scope is keyed by UA, client id, fingerprint or session. Those may only key sub-limits that cannot raise a parent.

### 6.2 Operation and results

`admit()` (or `checkOnly` extended — OD-3) performs, in one atomic step: read the lane's active punishments; check every
reserved scope; if all have headroom, increment all of them and create a permit `{permitId, lane, subject, op, window,
generation, state = ISSUED}`; otherwise write nothing. `complete(permitId, outcome)` moves the permit to `DONE` once and then
applies scoring to the lane's punishment keys. Admission counters are never decremented.

| Result | Meaning | Today's nearest | Rule |
| --- | --- | --- | --- |
| `ALLOW` + permit | One verifier call is authorized | `ALLOW` | Host calls the verifier once per permit |
| `RETRY_LATER(scope, retryAfter)` | Capacity of `scope` is exhausted in this window; no attribution | `SOFT_BLOCK` with Retry-After (`DM` §0) | Must carry a capacity reason; must **not** count as an Anti-Equilibrium soft event, must not persist, must not escalate |
| `BLOCKED(scope, level, retryAfter)` | An outcome-based punishment of this lane is active | `HARD_BLOCK` / score `SOFT_BLOCK` | Scope is the lane's account, device or source sub-limit, never a correlation group |
| `PROOF_REQUIRED` | The Host declared a proof path for this preset and the requester has none | none | Never mapped to `ALLOW`; never returned by a preset whose Host declared no proof path |

### 6.3 Permit lifecycle by mode

| Mode | Admission | Outcome | Guarantee |
| --- | --- | --- | --- |
| `pre` | at `checkOnly` | none; permit expires as consumed | Per-window bounds hold (F§3); no outcome-based escalation (as today) |
| `pre+out` | at `checkOnly` | `complete(permitId, outcome)` once | Per-window bounds + outcome-based K4U/K4P/K5 escalation |
| `out` | none before the verifier | outcome scored without a permit | **No pre-verifier bound.** The result may only gate what the Host does after verification (for example, refuse to issue a session) |
| mixed routes on shared state | per route | per route | Bounds count only permits; verifier calls made on an `out` route are not counted against them |

### 6.4 Bootstrap and the new user

A first-time user, a user on a new device and a user who lost the cookie have no proof, so they are U. They are admitted while
`U_src`, `U_acct` (if adopted) and `U_glob` (if adopted) have headroom. They are refused with `RETRY_LATER` when (a) a
co-located requester exhausted `U_src`, (b) someone exhausted `U_acct` for their account, or (c) `U_glob` is exhausted. A
successful sign-in may let the Host issue a proof for next time — only if OD-2 creates that contract. Without OD-2, Login
users never leave U, so an office stays a day-0 office every day.

`MEASURED` context (S0, not this design): on `main@a6c79f4`, 86–88 of 100 office browsers had no successful login on each of
14 days (#118 R8); idealized standing reached the whole office only on day 9 (#118 R9, model).

---

## 7. Owner protection (A3)

**Question.** If an attacker exhausts unproven attempts on account A, how does the owner still use the protected share?

**Today** (`DERIVED` from `CODE`): the owner does not. Every K4 mechanism is fed by any requester and enforced on every
requester:

| K4 mechanism | Fed by unproven attacker? | Closes owner's proven session? |
| --- | --- | --- |
| Score (Login +3, thresholds 5/8/12) | yes (`EP:2718-2720`) | yes, persisted on K4 (`EP:1919-1931`), read for all (`EP:509-533`) |
| Budget (20/24 h, `SOFT` L3, trusted floor L2) | yes (`EP:1764-1766`) | issues a `SOFT` to the owner too, one-shot per cooldown (`EP:2192-2203`) |
| Anti-Equilibrium (3 account `SOFT` in 6 h ⇒ K4 L2) | yes, account watcher (`EP:469-473`) | yes |
| DEC-007 lifecycle | yes | re-entry evidence is served to any pre-check (`EP:255-270`) |
| DEC-021 allowance | consumed by any "known" claim the Host makes | overflow adds to the shared K4 (`EP:1613-1615`) |
| Distributed-account occurrence | yes, by device rotation (`EP:718-728`) | K4 L4, 1800 s |
| Flood | yes (`EP:360-371`) | K4 L1 |

A reserved `P_acct` is useless while any of these can close the P lane. Exempting P from K4 is forbidden by the brief and
would remove DEC-021's protection against a stolen proof.

**Proposed lane split** (`PROPOSED`, OD-5):

| Element | U lane | P lane |
| --- | --- | --- |
| Account punishment key | **K4U** = legacy K4 key (rollback keeps it, §10) | **K4P** = new key |
| Fed by | unproven failures, distributed-account, flood, U watcher, U budget | DEC-021 overflow from proven devices, P watcher |
| Enforced on | U-lane requests for the account | P-lane requests for the account |
| Anti-Equilibrium | account watcher U ⇒ K4U L2 | account watcher P ⇒ K4P L2; device watcher ⇒ K5 L2 (DEC-021 §5) |
| Budget | U budget (existing semantics) | P budget (existing semantics) |
| DEC-007 lifecycle | on K4U | not needed: P has its own entitlement |
| Admission | `U_acct` (conditional) | `P_acct`, per-proof `P_proof ≤ P_acct` |

What this gives, under OD-2 and OD-5: U-lane traffic cannot consume `P_acct` (disjoint scopes) and cannot close the P lane
(K4U is not read on P). A stolen proof is bounded per proof by `P_proof` and DEC-021 K5, and per account by `P_acct` and K4P.

What it does not give:

* An owner without a proof is U and shares `U_acct` and K4U with the attacker; renewable denial of that owner remains
  (GA-3). For Login without OD-2, that is every owner.
* An attacker holding one stolen proof can exhaust its own `P_proof`; holding enough proofs for A it can exhaust `P_acct(A)`
  and trigger K4P, closing the P lane for the owner's other devices. `P_acct ≥ k · P_proof` keeps one stolen proof from doing
  that; `k` is OD-6.
* Under DEC-015 degraded mode the local fallback has no lanes (`LocalFallbackLimiter.php` dimensions are ACCOUNT, IP_PREFIX,
  IP_PREFIX + UA), so owner protection is absent during an outage.

---

## 8. Admission truth tables (A1)

Columns follow the brief: **Input → Trust → Lane → Required budgets → Atomic operation → Result → Enforcement scope → State
updates.** Admission happens at `checkOnly` in `pre` and `pre+out`; in `out` nothing is reserved and only the state-update
column applies (§6.3). "Reserve{…}" is one all-or-nothing operation. Rows marked ◇ depend on OD-2 (proof contract) and do not
exist without it; `U_acct` is conditional throughout (GA-3).

### 8.1 Login (`login_protection`)

| Case | Input | Trust | Lane | Budgets | Atomic operation | Result | Enforcement scope | State updates (`pre+out`) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| L1 No target account | `accountId = null`, no subject | observation | U | `U_src`, `U_glob` | Reserve{src, glob} | ALLOW / RETRY_LATER(src or glob) | source, service | none account-scoped. Weaker than L2; the contract should require a Host target subject (§6.1) |
| L2 Target known, requester unproven | subject, no proof | observation | U | `U_acct`, `U_src`, `U_glob` | Reserve{acct, src, glob} | ALLOW / RETRY_LATER(acct, src or glob) / BLOCKED(K4U) | account-U, source, service | failure: K4U +3, U budget, U watcher. success: nothing refunded |
| L3 Client instance id only | L2 + `clientInstanceId` | continuity hint | U | L2 + optional `U_cid ≤ U_src` | Reserve{acct, src, cid, glob} | as L2 | as L2 | as L2; cid record bounded by the 50-per-IP cap, then overflow |
| L4 Historically known, no current proof | L2 + `isDevicePreviouslyVerifiedForAccount` | historical association | **U** | as L2 | as L2 | as L2 / BLOCKED(K5) | account-U, device-U | failure: DEC-021 K5 +2 and allowance; overflow → K4U |
| L5 ◇ Current proof for this account and operation | L2 + proof(account, op) | current proof | P | `P_acct`, `P_proof`, `P_glob` | Reserve{acct-P, proof, glob-P} | ALLOW / RETRY_LATER(P scope) / BLOCKED(K4P or K5) | account-P, device-P | failure: K5 +2 (DEC-021), allowance, overflow → K4P, P watchers |
| L6 Session for A, target B | subject B, proof(A) | mismatch | U for B | as L2 for B | as L2 for B | as L2 for B | B's U scopes | as L2 for B; A untouched |
| L7a Cookie or proof lost | was L5, now no proof | — | U | as L2 / L4 | as L2 | as L2 | as L2 | as L2 / L4 |
| L7b UA change | any | unchanged | unchanged | unchanged (no scope uses UA) | unchanged | unchanged | unchanged | new K5 for passive-only devices (§4.5) |
| L7c IP change | any | unchanged | unchanged | U: new `U_src`; `U_acct`, `U_glob` unchanged. P: unchanged | as before | as before | as before | as before |
| L8 Many sessions/devices, one account | several proofs for A | current proof | P | all share `P_acct(A)`; each its `P_proof` | as L5 | as L5 | account-P | as L5; never multiplies `P_acct` |
| L8u Many unproven devices, one account | several requesters, no proof | observation | U | all share `U_acct(A)` | as L2 | as L2 | as L2 | as L2 |

Without OD-2, rows L5 and L8 do not exist: every Login request is in L1–L4, L6, L7 or L8u.

### 8.2 OTP (`otp_protection`)

Same structure; the differences are:

| Case | Lane | Difference from Login |
| --- | --- | --- |
| O1 Step-up after primary authentication, session for this account ◇ | P | The proof is the primary-authenticated session (GA-2). An attacker who knows the password is **also** in P: `P_acct` and K5/K4P bound the OTP guesses, which is the main OTP threat |
| O2 Passwordless or recovery OTP | U | No proof exists; `U_acct` (conditional) is the per-account bound |
| O3 Session for A, OTP for B | U for B | as L6 |
| O4 Known device, no session | U | as L4; DEC-021 allowance 2 |
| Budget command eligibility | both | `pre` never issues the budget (`precheck_enforcement = false`, `DM` §3.3.2), unchanged |
| Recovery Collision Guard | P when the device is HIGH/known with proof; else U | its one-shot `SOFT` is a punishment-lane candidate of that lane (`EP:1797-1811`) |

### 8.3 API Heavy (`api_heavy_protection`)

| Case | Input | Trust | Lane | Budgets | Atomic operation | Result | Enforcement scope | State updates |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| A1 Anonymous | ip, ua | observation | A | K1 parent; K2/K3 sub-limits; optional `A_glob` | Reserve{K1, K2, K3, glob} or today's per-key scoring | ALLOW / RETRY_LATER(scope) | source; IP+UA and IP+FP sub-limits | access counted on admission; no correlation `HARD_BLOCK` |
| A2 Anonymous with client id | A1 + id | continuity hint | A | A1 + optional `A_cid ≤ K1` | as A1 | as A1 | as A1 | as A1 |
| A3 Principal validated by the Host | principal | current proof (principal) | R | `R_principal`, optional `R_glob` | Reserve{principal, glob} | ALLOW / RETRY_LATER(principal or glob) | principal | principal counter |
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
* **What changes** (`PROPOSED`, OD-1): churn and dilution stop producing API `HARD_BLOCK`s (`MEASURED` D1API 596/600 → 0/600
  when the cohort was split by id, #125). The LOW-K3 → K2 remap is a sub-limit mapping, not a correlation inference, and can
  stay.
* **What anonymous API cannot get:** per-person fairness inside one source. Two clients behind one IP with the same UA are one
  sub-limit; a client id is a hint and can be rotated, so it can only split the parent, never grow it.
* **Principal traffic** (`PROPOSED`, OD-9): a new optional Host-validated principal field. Principal budgets do not charge the
  anonymous source parent, so co-tenants on a NAT are not starved by a principal and vice versa. DEC-011 forbids an ACCOUNT
  fallback dimension for `API_OVERUSE` (`RLE:122`); a principal dimension in degraded mode is a separate decision.
* **Failure mode** stays `FAIL_OPEN` with bounded fallback (DEC-011, DEC-015); admission bounds do not hold during outages.
* **Official vs custom:** the API preset is ours to redesign under DEC-008; a custom `API_OVERUSE` policy keeps today's semantics
  until it opts into a new typed capability.

---

## 10. Compatibility and migration impact

| Area | Impact | Decision |
| --- | --- | --- |
| `RateLimitCommand`, `RateLimitResultDTO`, `RateLimiterInterface` | permit id in results and outcomes; capacity vs punishment reasons; optional `PROOF_REQUIRED` | OD-3 |
| `RateLimitContextDTO` | optional typed proof `{account, operation, kind}`, optional `clientInstanceId`, target subject, API principal | OD-2, OD-9 |
| Store contracts | new optional capability: atomic multi-scope reserve + permit state + dual-generation read (F§6) | OD-4 |
| Redis adapter | Lua with caller time (DEC-016); one hash slot per verifier class; non-clustered topology stays the supported one (DEC-006 history, DEC-016) | OD-4 |
| Builder / DEC-008 | new typed capability, fail-fast without the Store capability (DEC-007 precedent); customs keep S0 unless they opt in | OD-3 |
| DEC-007 | lifecycle bound to K4U; re-entry semantics unchanged inside U | OD-5 |
| DEC-015 / DEC-011 | admission bounds do not hold in degraded mode; lanes absent in fallback | OD-8 |
| DEC-019 | superseded only by a future approved replacement (Decision Governance §9) | OD-1 |
| DEC-021 | allowance and K5 per lane; overflow → K4P | OD-5 |
| Canonical docs | `DM` §0, §1, §2.3–§2.5, §3.3, §5.1–§5.4, §10, §11; `POL` §1, §5, §6; `DFP` §3.3, §4.2, §4.3; `KS` §3, §4.3–§4.6, §10.1; `FS` §4 | with each decision |

**Migration** (`PROPOSED`; detail and failure analysis in F§6):

1. Ship the capability dormant; no preset declares it; behaviour is S0.
2. Fence: an operator-set admission epoch in the store enables the new path only after every worker runs the new version; old
   workers do not reserve, so bounds are `UNPROVEN` while mixed.
3. While enabled, existing K1/K2/K3 correlation blocks are honoured read-only until expiry (≤ 60 s for correlation L2) and no
   new ones are written; K4U is the legacy K4 key, so existing K4 history continues.
4. Rollback: S0 reads K4 (= K4U) and K5 and ignores K4P, admission counters and permits (they expire by TTL). P-lane
   punishments are lost on rollback; U-lane history is kept. S0's collective blocks resume.
5. Secret rotation: counters are read across the current and previous generation and summed (F§6.4); a second rotation
   within one window re-issues capacity (`DERIVED`), so rotation interval ≥ window is a precondition (`DFP` §5.1.6).

---

## 11. Owner decision register

Only decisions the executor cannot take. None is taken or assumed.

| ID | Decision | Why it is the Owner's | Effect if declined |
| --- | --- | --- | --- |
| **OD-1** | Replace correlation-derived collective punishment (K1 spray, K2 churn/LOW dilution, K3 MEDIUM dilution) with capacity admission, eventually superseding DEC-019 | Material security policy; DEC-019 §5–§6 | S0 stays; no further work on this design |
| **OD-2** | (a) Amend `POL` §1 to allow a lane-scoped, non-persistent, per-window **attempt-count** cap per account (`U_acct`), accepting renewable denial of an **unproven** owner; or (b) keep §1 and drop `U_acct` | GA-3: conflicts with a Non-Negotiable principle (`POL` §1) and the anti-harassment contract (`DM` §2.4) | (b): per-account bound only from outcomes (K4U), `pre`-only Hosts unbounded per account as today; rows depending on `U_acct` are void |
| **OD-3** | Public contract: permit id from admission to outcome; capacity vs punishment reasons; whether `RETRY_LATER` is a new decision constant or a `SOFT_BLOCK` reason; `PROOF_REQUIRED` only with a Host-declared proof path | Public contract (GA-5) | No atomic, replay-safe admission (#116 X1) |
| **OD-4** | Optional Store capability for atomic multi-scope reservation and permit state; one hash slot per verifier class; Cluster stance | Store contract | No admission mechanism |
| **OD-5** | K4 lane split (K4U legacy, K4P new); DEC-007 lifecycle on K4U; DEC-021 overflow to K4P; per-lane watchers and budgets | Changes ACTIVE DEC-007 and DEC-021 semantics (GA-4) | No owner protection; P lane closed by any K4 block |
| **OD-6** | Capacity envelope: `W`, daily tiers, `U_src`, `U_acct`, `U_glob`, `P_acct`, `P_proof`, `P_glob`; whether the global ceilings exist; verifier-class grouping; IPv6 hierarchy; acceptance of the residual harms in F§8 | Security/availability trade-off; no threshold is approved anywhere | Nothing can be sized or prototyped with meaning |
| **OD-7** | `P_src` (cross-account bound for proof holders at one source) — keeps a TMSd-type attacker low and re-blocks a trusted office (#119 M3) — or accept linear reach in proofs held (#120 disposition) | Material trade-off already open in #120 | Linear-in-proofs reach, bounded only by `P_glob` |
| **OD-8** | Accept that bounds and lanes do not hold in DEC-015 degraded mode, or change DEC-015/DEC-011 | ACTIVE decisions | Outage semantics as today |
| **OD-9** | API: Host-validated principal field; removal of correlation `HARD_BLOCK` for `API_OVERUSE`; principal dimension in fallback (DEC-011) | Public contract and ACTIVE decision | Anonymous API design only |
| **OD-10** | Whether to authorize an isolated prototype of the corrected design (§12) | Owner-only | Stop at paper |

OD-2 also has a proof-contract half: **(c)** create an optional Host proof input (account, operation, kind) and decide whether a
trusted session may serve as proof for re-authentication and OTP (GA-1, GA-2). Without (c), there is no P lane for Login primary
sign-in and §7 does not apply to Login.

---

## 12. Final recommendation

**`CORRECTION REQUIRED — BLOCKED ON OWNER POLICY DECISIONS`.** Not ready for a prototype as written; not rejected.

Reasons it is not prototype-ready:

1. Its per-account guarantee (`U_acct`) contradicts `POL` §1 and the anti-harassment contract of `DM` §2.4 (GA-3). The
   executor cannot amend a Non-Negotiable principle.
2. Its owner-protection guarantee needs a proof that does not exist for Login primary sign-in (GA-1) and reinterprets the
   trusted session contract (GA-2).
3. Its service ceilings, as drafted, are per policy and therefore bypassable, and when they bind they deny every user of the
   lane (F§7 B4-3, B4-5, B4-6).
4. Owner protection needs the K4 lane split, which changes ACTIVE DEC-007 and DEC-021 (GA-4).

Reasons it is not rejected:

* On paper, under stated conditions, it is the only reviewed direction that removes collective punitive attribution **and**
  keeps an outcome-independent, rotation-invariant bound on verifier admissions (F§3). The measured candidates did one or the
  other (#125 CIR, #116 OAAA, #119 AKDR, #115 GNH5: all NO-GO).
* It bounds `checkOnly`-only Hosts and cross-account misuse of a session where S0 does not (`MEASURED` S0: D24K1 `pre`
  1440/1440, #119; SP5, a Host-attested trusted session spraying 200 fresh accounts, 200/200, #108). The improvement itself is `DERIVED` and
  holds only if the proof names its own account (C8).

**Corrected design for a prototype**, once the Owner rules on OD-1 to OD-5:

| Element | Corrected form |
| --- | --- |
| Account bound | per OD-2: `U_acct` lane-scoped and non-persistent, or K4U outcome scoring only |
| Proof | typed `{account, operation, kind}` from the Host; the library rejects mismatches; trusted session counts only if OD-2(c) says so |
| Global ceilings | optional, per verifier class, documented as resource guards and denial levers, not as security bounds |
| K4 | split per §7 |
| Results | capacity reasons distinct from punishment; capacity never feeds Anti-Equilibrium |
| Storage | one atomic script per verifier class; caller time; dual-generation counters; permit state |
| Modes | `out` documented as having no pre-verifier bound |

A prototype must measure what paper cannot settle (F§9), with thresholds swept as parameters, not approved.

---

## 13. What changed from the initial draft (`89ef2d2`)

| Initial draft | Now |
| --- | --- |
| `U(targetAccount)` presented as a required property | Conditional on OD-2: conflicts with `POL` §1 (GA-3) |
| P lane assumed available | No proof for Login primary sign-in (GA-1); trusted session contract conflict (GA-2) |
| `U(service)` / `P(service)` as finite safety bounds | Optional resource guards per verifier class; denial levers; per-policy form bypassable |
| "Re-specify K4" | Concrete lane split with keys, feeds, enforcement and rollback (§7) |
| Results ALLOW / PROOF_REQUIRED / RETRY_LATER | Plus BLOCKED; capacity excluded from Anti-Equilibrium; `PROOF_REQUIRED` only with a declared proof path |
| Invariants listed | Stated with conditions, proof sketches and break cases (F§3) |

The earlier validation matrix and work-sequence sections are superseded by F§9 and §12; the earlier evidence summary is
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
