# DRAFT — Identity-Aware Bounded Admission as a Replacement for K2 Collective Enforcement

> **Status:** ARCHITECTURE PROPOSAL / NOT APPROVED / NO PRODUCTION CHANGE  
> **Workstream:** [#97](https://github.com/Maatify/php-rate-limiter/issues/97), with mandatory cross-issue dependencies [#100](https://github.com/Maatify/php-rate-limiter/issues/100), [#106](https://github.com/Maatify/php-rate-limiter/issues/106), [#120](https://github.com/Maatify/php-rate-limiter/issues/120) and architecture ledger [#110](https://github.com/Maatify/php-rate-limiter/issues/110).  
> **Baseline:** `Maatify/php-rate-limiter main@4c6ca618d8206ba502961eaf06ffb82a5d61b50d` (2026-10-16).  
> **Authority:** Owner decides material security policy, public contract, ADR, implementation, merge and release.  
> **Purpose:** Preserve the full context, reasoning, open decisions, and implementation/validation sequence for the next discussion; this proposal is **not** a policy contract, approved ADR, GO verdict, or deployment instruction.

## 1. The corrected architectural premise

The Owner identified a foundational error in the previous comparison criterion: **S0's enforcement decisions are not a security oracle.** S0 can produce a high number of blocked requests by wrongly treating unrelated users as one identity. Reproducing its denial counts is not a legitimate security acceptance criterion.

Use S0 only for **historical comparison and regression attribution**. The candidate must be assessed against independently defined security invariants: sound attribution of enforcement scope, bounded verifier admissions and resource consumption, resistance to identity rotation, protection of account owners from third-party harassment, real client availability, and documented remaining limitations. Report attacker admissions and benign denials side by side; do not relabel every S0-deny → candidate-allow as a security regression or every S0-block as correct defense. Conversely, do not assume removing an unsound correlation block allows unbounded attack traffic.

This correction **does not** supersede ACTIVE [DEC-019](../../docs/decisions/DEC-019_INTERIM_SECURITY_FIRST_CORRELATION_POSTURE.md): the existing shipped S0 behavior stays unchanged **temporarily** until the Owner approves a separately demonstrated replacement and the corresponding canonical changes.

## 2. Actual root cause, not a generic NAT problem

`src/Service/DeviceIdentityResolver.php` currently hashes `v2|normalizedUA|clientFingerprint|sessionDeviceId`; absent optional inputs it still emits an HMAC over passive normalized UA and classifies confidence LOW. A nonempty client fingerprint changes confidence to MEDIUM, without proving a distinct client or trust. `src/Service/EvaluationPipeline.php::buildKeys()` defines:

| Key | Current material | Current significance |
| --- | --- | --- |
| K1 | IP prefix | Network spray/correlation and other risk handling |
| K2 | IP prefix + normalized UA | Shared churn anchor, LOW cross-IP dilution enforcement, API score/blocks |
| K3 | IP prefix + DeviceFP | Device-ish scope, not proof of unique client |
| K4 | AccountID | Account-level protection across networks |
| K5 | AccountID + DeviceFP | Account/device-level protection, including DEC-021 |

The **invalid inference** is the transition from coarse observational equivalence to a shared punitive identity:

1. A and B share IP/UA but are independent. Sequence A/B/A produces distinct-fingerprint churn, then a K2 L2/60s shared block, which can retrigger from longer correlation/WATCH state (#100).
2. Hundreds of independent users on distinct IPs with the same popular UA produce identical passive LOW fingerprints. Cross-IP dilution can issue K2 hard denials without outcome evidence (#97).
3. K1 credential-spray also imposes independent overlapping NAT collateral (#106); removing K2 alone cannot claim end-to-end fairness.
4. K2 partitioning by mutable browser major weakens aggregate protection for an attacker with several genuine account-bound sessions, as shown by #120.
5. `checkOnly` can observe attempts without recording their outcomes; it does not currently reserve atomic verifier capacity. Outcome-only integrations cannot retrospectively prevent the verifier load they already performed.

Selected **measured synthetic evidence, not production rates**:
- #97/#125 D1 benign LOW cross-IP denials: S0 596/600; candidate CIR 0/600. CIR is **NO-GO**, since L1 shared NAT changed 0/134 → 127/134 and check-only attack paths regressed.
- #100 L1 measured 127/134 shared-NAT denials in the identity-assisted candidate; A/B/A and repeated block cycle are source-reproducible.
- #106 D2 benign denials in four controls: S0 117/121, with K2 correlation disabled 108/121, with K1 disabled 117/121, both disabled 27/121. These are **overlapping**, not additive, sources.
- #120 TMSd 2/200 attacker verifier admissions with one UA major vs TMUA 36/200 with 20 rotated majors; proof is for that trace, not a general upper bound.
- #116 OAAA is **NO-GO** due to replay/idempotency, owner-account successes/credit recycling, co-located exposure, compatibility/rotation and outage issues; useful atomic reservation primitives do not imply an approved policy.

Canonical runtime authority remains `docs/DECISION_MATRIX.md`, `docs/POLICIES.md`, `docs/KEY_STRATEGY.md`, `docs/DEVICE_FINGERPRINT.md`, applicable decisions including DEC-007/008/015/019/021, and the pinned repository standards activated by `AGENTS.md`.

## 3. Target properties and non-goals

### Required security and fairness properties

1. **No collective punitive identity from IP, UA, passive LOW fingerprint, or their combination alone.** They are observations; K2 churn/dilution alone cannot repeatedly hard-block unrelated users.
2. **An opaque client instance is an identification/continuity hint, not proof, authentication, or independent quota entitlement.** Rotating UA, ClientInstanceId, cookie or fingerprint cannot manufacture unrestricted admissions.
3. **Every accepted verifier-bound unproven attempt consumes the applicable budgets before verification**, including pre-check-only traffic. One logical attempt must not be admitted/charged/refunded several times due to retries or duplicated outcomes.
4. **Per-target account protection across IP/client rotation**, without letting requests lacking authority over an account drain the reserved lane of its proven owner.
5. **Shared-source spray protection** and a finite **service-wide admission ceiling** for unproven attempts; rotating IP changes source allocation but must not bypass target-account/service limits.
6. **Proven privileges are strictly account- and operation-scoped.** Owning or stealing a session for A cannot confer free attempts against B. Multiple sessions do not multiply A's allowance; many owned accounts remain subject to a finite shared upper bound.
7. **Progressive and bounded enforcement**, with a clear distinction between attribution-based punishment and capacity-based temporary refusal. Do not call a quota exhaustion a proof of malicious identity.
8. **Bounded storage, privacy, concurrent correctness, key rotation and recovery semantics** without silent re-credit/reset.
9. **Official presets must remain usable as package-owned protection**; no mandatory consumer-designed CAPTCHA, external fingerprint engine or fraud system. Custom policies remain opt-in via typed capabilities; do not silently mutate DEC-008 contracts.
10. **No false equivalence of all integration modes:** pre-verifier protection requires a call **before** verification. Record-only integrations cannot claim this guarantee.

### Explicitly not guaranteed

A completely new unproven legitimate request indistinguishable from a malicious request can be delayed when the common unproven pool is exhausted. Different persons cannot be proven from matching client-held tokens. An attacker with valid accounts can consume their bounded proven capacity. System-wide overload, multi-IP botnets, Redis state loss and anonymous API clients have separate residual risks. None is silently called "solved."

## 4. Proposed model: Identity-Aware Bounded Admission

The common engine distinguishes **identity**, **correlation**, **entitlement**, **admission** and **punishment**.

### 4.1 Identity is separate from trust

A proposed bounded `ClientInstanceId` is independent of UA/IP and **must not be smuggled into `clientFingerprint`** to solve #97: today's resolver elevates any nonempty fingerprint to MEDIUM, which the merged #125 research showed can weaken security and worsen #100. It must not be blindly trusted, used for global tracking, or counted as quota minting. Missing or rotating IDs cannot escape parent budgets.

The Host can optionally supply validated facts it already owns, e.g. account-bound current session authority. `isDevicePreviouslyVerifiedForAccount` alone is **historical** and does not prove current possession. A server-created cookie proves issuance, not uniqueness of physical users or abuse resistance. A session for account A never grants standing for account B.

### 4.2 Authentication admissions: U (unproven) and P (proven for this target and operation)

**U / unproven:** On each accepted attempt, reserve simultaneously against:
- `U(targetAccount,operation)`: the account's unproven-attempt admission budget across networks;
- `U(sourceNetwork,operation)`: a source-scope spray budget independent of UA and ClientInstanceId;
- `U(service,operation)` / hierarchical policy-level admission: finite aggregate admission bound across source networks.
- Optional bounded per-client sublimits may improve fairness but **cannot** enlarge these parents; when client cardinality is exhausted, clients must fall into a shared overflow bucket, not create unlimited keys.

**P / proven:** Requires a **current, Host-validated proof for the same account and operation**. Reserves against:
- `P(targetAccount,operation)`: a finite account-owned quota shared across its sessions/devices;
- `P(service,operation)`: a finite aggregate proven lane;
- any explicitly justified operational capacity ceiling.
Proof for A never gives access to P for B. A third party with only an unproven guess about A cannot deplete A's reserved P capacity. The total P+U admission exposure must be reported; splitting lanes does **not** make P admissions free.

**Shared account sanctions:** Existing K4 score/cooldown/anti-harassment interplay must be re-specified so that unproven harassment cannot deplete a supposedly reserved P lane via a different shared K4 block. Do not bypass K4 or rewrite DEC-007/021 implicitly: this is a material design/ADR decision.

**Accounting:** Reserve **before** the verifier. No automatic success refund/replenishment into a common bucket and no unlimited long-term credits based on successful logins. Valid Host outcomes may update risk evidence and tightly scoped standing, but must not retroactively multiply admissions or grant other-account authority.

**Capacity exhaustion:** Distinguish `ALLOW` (capacity reserved), `PROOF_REQUIRED` (where a valid additional-account/operation proof path is actually supported) and `RETRY_LATER` (capacity exhausted; no claim every grouped user is malicious). These are **proposed new contracts**; current `RateLimitResultDTO` supports only ALLOW/SOFT_BLOCK/HARD_BLOCK. A "proof required" decision MUST NEVER be silently mapped to ALLOW. If Host has no proof flow, the applicable result is bounded refusal/wait, not a fictional usable challenge.

### 4.3 API Heavy is not account-based by default

`src/Config/ApiHeavyProtectionPolicy.php` uses K1/K2/K3, default limits 600/120/300, and does not require accountId. The proposed model must therefore independently define:
- Authenticated API principals, when the Host already has a trusted principal: finite principal+operation budget under operational parents;
- Anonymous API requests: source+operation+service admission and controlled resource cost; no independent client-ID-minted quota;
- API-specific fallback/overuse behavior, without silently turning API into an authentication policy.
An anonymous population within an indistinguishable network group cannot be guaranteed perfect per-person fairness. `API_OVERUSE` and custom typed policies must not be silently rewritten because official authentication presets change.

## 5. Structural security invariants (must be made executable)

For a healthy, correctly serialized distributed store, fixed admission window W, and when **every U attempt actually reaching a verifier requires one committed reservation with no refund**:

- `admitted_U(source,W) <= U_source(W)`
- `admitted_U(service,W) <= U_global(W)`
- `admitted_U(targetAccount,W) <= U_account(W)`
- `admitted_P(targetAccount,W) <= P_account(W)`
- `admitted_P(service,W) <= P_global(W)`

These are **conditional, intended bounds**, not yet runtime proofs. They require a durable one-logical-attempt admission identity, atomic all-or-nothing reserve, no partial K4 debit, single-use completion, no double refund, no new quota under identity/UA/key-secret rotation, explicit current/previous generation handling, and a correct backend model.

The combined exposure must include `U+P` and also distinguish:
- `reach_foreign`: requests against accounts for which the attacker has no valid proof;
- `reach_own`: requests involving accounts the attacker actually controls;
- total verifier CPU/storage/resource expenditure;
- benign completed attempts, denied attempts, latency, and usable recovery steps.

Do not assert that `U_global` can be chosen arbitrarily small without hurting new legitimate users. Do not infer universal anti-bot bounds from S0's synthetic denial counts. For distributed attacks with unbounded identities/IPs, a finite service ceiling is a resource safety guard, **not** perfect attacker classification or guaranteed capacity fairness.

## 6. K1–K5 disposition: proposed, NOT a silent key migration

| Current key | Proposed design role | Owner/contract review needed |
| --- | --- | --- |
| K1 | Network risk observation and bounded U source admission; no automatic attribution of one actor to all network members | Existing spray hard-block behavior, trusted-session advisory, IPv6 aggregation |
| K2 | Optional IP+UA analytics/correlation only; **no independent shared HARD_BLOCK** from churn or LOW dilution | Score, block, source semantics, watch, API overuse, existing state |
| K3 | Fingerprint-derived risk history / bounded subordinate throttling, without claiming unique physical identity | Derivation, collision, missing-FP, ephemeral device caps |
| K4 | Account history/abuse defense across sources; explicit separation of U/P capacity from punitive account state | DEC-007 lifecycle, budget safety, distributed attacks |
| K5 | Account/device history and bounded owner-related signal | DEC-021, current/previous fingerprint rotation, session-id changes |
| New state | Versioned, HMAC-scoped, TTL-bounded admission counters/attempt permits (U/P, account/source/service/principal) | Store API, Redis scripting/Cluster, privacy, rollout and rollback |

Retiring enforcement on K2 is a **proposed normative change**, not authorization to delete every K2 read/write. Old workers, active K2 blocks and old fingerprints can recreate punishment during mixed deployment; migration must explicitly fence incompatible writers and define state transition, no blanket flush or independent quota duplication across generations.

## 7. Implementation feasibility and failure semantics

Proposed new capability must be explicit and fail-fast under the package Builder; preserve custom policy isolation and DEC-008 typed capabilities. The current public `limit(context,command)` and `checkOnly/recordFailure/recordSuccess` cannot silently be represented as an atomic admission permit lifecycle without new semantics/contract.

The storage design must establish:
- **one all-or-nothing reservation** covering every applicable U or P scope; Redis Lua atomicity is local to one Redis execution context; Redis Cluster hash-slot, key-layout or equivalent coordination are design constraints, not assumptions;
- **one logical attempt ID and one-use admission permit**, bounded lifetime, outcome tied to that attempt/account/operation, and explicit retry/timeout semantics;
- no success-derived shared refunds, account/operation proof replay, counter underflow or partial consumption on errors;
- hard limits on per-client keys, account subjects, proof standing and observation cardinality (including arbitrary untrusted input lengths);
- single-writer or coordinated migration of current/previous secret generations and mixed code versions, with no quota reset;
- deliberately modeled Redis failover loss, unknown write results, backend outage, DEC-015 fallback. Local fallback does not magically preserve a distributed quota; advertise weaker guarantees or stop the strict admission path safely. Existing policy failure semantics may require separately approved changes;
- IPv4 exact-IP and canonical IPv6 /64 enforcement plus existing detection-only macro hierarchy, with explicit tests for NAT and privacy addressing;
- rollback and historical block migration policies that neither silently erase active sanctions nor keep generating false K2 blocks indefinitely.

This blueprint **does not authorize** implementing a new DTO, API enum, Redis interface, new preset defaults, keys or any runtime mutation.

## 8. Validation matrix and falsification conditions

Reproduce all source-aligned control streams against unchanged S0 to establish comparability, then evaluate the candidate using **independent** security/availability invariants, not S0 block parity:

| Category | Required fixtures / checks |
| --- | --- |
| #97 cross-IP LOW | D1, L3, app UA `other/0`, legitimate independent IPs vs adversarial UA/ID rotation; no passive fingerprint-only shared hard block |
| #100 K2 NAT churn | A/B/A, L1, OFF12, 60s block expiry, 600s correlation window, 1800s WATCH, stable vs rotating IDs and sessionId rebind |
| #106 K1 NAT | D2, Nu4/Nu6/Nu12, MIX; multiple accounts, typos, co-located attacker, independent K1/K2/K4/K5 denial attribution |
| #120 identity evasion | TMSd vs TMUA, 12 owned accounts/sessions, rotating UA and changing client identifiers; account-specific and aggregate exposures |
| Attack models | X1/X2/X7, BRU/BRD, SP1n/SP3/SP6, low-and-slow, cross-IP, foreign-account vs owned-account vs total admitted verifier requests |
| Modes | `pre`, `pre+out`, `out` and **mixed routes sharing state**, explicitly state no pre-verifier guarantee for out-only |
| Independent guarantees | Concurrent atomic one-slot limits, 100 racing requests, idempotent retries, account proof misuse, quota amplification, bounded keys and TTL |
| Operations | Real Redis reference parity and Redis Cluster feasibility, IPv6, rotation, mixed-version deploy, rollback, DEC-015, crash before/after reserve/verifier/outcome, failover |

**Reject** any candidate that lets UA/Client ID rotation mint capacity; promotes unverified client data to proven status; uses A's proof for B; converts a correlation observation alone into renewable group punishment; over-admits under concurrency/retry; refunds success into foreign-account capacity; hides relevant deltas by counting only victim targets or only attacker-owned accounts; claims pre-check security without pre-verifier reservation; or asserts parity during outages/rollback without a proven mechanism.

The absolute numeric caps and acceptable U/P trade-offs **must be specified and approved** based on capacity, attack risk, legitimate bootstrap requirements and scenario evidence. No numeric thresholds are approved in this draft.

## 9. Work sequence and readiness gates

**Stage A — Normative design (discussion / no runtime):** Specify each official preset's eligible signals, proof authority, source of account IDs, precheck/record-only semantics, enforcement vs observation, and U/P quotas. Map potential changes to DEC-007/008/015/019/021, canonical documents and Store capabilities. Identify unresolved Owner material choices; do not disguise these as implementation details.

**Stage B — Formal/accounting feasibility (no runtime):** Derive per-window U/P/combined upper bounds and shared-resource fairness limits, validate independent-signal cases, current-day office bootstrap and attacker multi-account stock. Prove or explicitly reject each desired guarantee. A logically failing design does not progress.

**Stage C — Isolated candidate, only after Owner authorization:** Code prototype separate from package Runtime with deterministically replayable case streams, bounded stores, atomic admission, idempotency and attack accounting.

**Stage D — Direct adversarial and operational acceptance:** Evaluate all matrix scenarios and controls, score source attribution, exact contender tree SHA, real Redis concurrency and failures. Record total benign harm and full attacker exposure. Fix rejected findings and re-review on final state.

**Stage E — Material policy decision:** Owner chooses whether measured remaining bootstrap/shared-pool harm and resource limits are acceptable. If approved, create ACTIVE ADR(s)/decision index and canonical amendments, with an explicit incompatibility/supersession treatment for DEC-019. No proposal here is already ACTIVE.

**Stage F — Separately authorized runtime/migration delivery:** New versioned contracts/state, tests, integration docs, staged rollout, mixed worker/fallback/rollback gates, full applicable checks, fresh independent Lead review, Owner-only merge/tag/release.

## 10. Current state, next work and decisions

**Current state at drafting:** Issue #97 and related #100/#106/#120 remain open; merged studies are evidence-only; proposed CIR and OAAA variants are NO-GO; **no approved replacement design, no thresholds, no phase implementation authority, no new production behavior.** S0 stays temporarily in effect under DEC-019. This draft is the sole proposed direction for discussion and should not be conflated with existing rejected candidates.

**First next work:** Complete **Stage A**, in particular reconcile the login/OTP account-protection design and anonymous API heavy design against existing K4/K5 and fallback semantics; produce the exact U/P/no-proof admission truth table and explain who is denied after a source pool is exhausted. Then do the paper feasibility/bounds in Stage B **before** authorizing candidate code. Avoid reopening generic NAT or S0-parity studies.

**Owner decisions not assumed:** Material policy replacement; acceptance of unavoidable U-lane bootstrap exhaustion; whether optional proof-required outcomes/integration are acceptable for particular presets; actual capacity budgets and acceptable security/availability envelopes; changing public/Store contracts; whether to proceed to prototype and any GitHub merge or release.

## 11. Sources and continuation rules

- [Issue #97](https://github.com/Maatify/php-rate-limiter/issues/97); [#100](https://github.com/Maatify/php-rate-limiter/issues/100); [#106](https://github.com/Maatify/php-rate-limiter/issues/106); [#120](https://github.com/Maatify/php-rate-limiter/issues/120); [#110](https://github.com/Maatify/php-rate-limiter/issues/110).
- [Merged #125 report](../ua-client-identity-97/REPORT.md), [#116 OAAA report](../outcome-aware-atomic-admission-110/REPORT.md), [#119 account-known-device report](../account-scoped-known-device-relief-110/REPORT.md), [architectural comparison of 11 proposals](../nat-architecture-proposals-110/COMPARISON.md).
- `AGENTS.md`, pinned `STANDARDS_MANIFEST.md`, `STANDARDS_ADOPTION_STANDARD_AR.md` §10, pinned AI Collaboration / Phase Stack / Documentation Lifecycle / Decision Governance standards, and existing package reference.
- Resume by confirming latest repository state, open issues, relevant decisions and this Draft PR's current body/HEAD. **PR body is the current execution snapshot**; this file holds the detailed architecture proposal. Update both coherently as real material decisions change. The Issue remains the umbrella tracker; this draft must not auto-close it.

No runtime code, tests, production configuration, policy defaults, ADR status, existing issue state or released behavior is changed by this proposal.
