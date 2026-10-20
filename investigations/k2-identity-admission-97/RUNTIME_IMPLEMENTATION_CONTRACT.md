# Runtime implementation contract — step 5, stage 5-a (#97)

> **Status:** CONTRACT ON PAPER, reviewed by the Lead (TECHNICAL PASS, [PR #143 comment 6264644406](https://github.com/Maatify/php-rate-limiter/pull/143#issuecomment-6264644406)); the two Owner decisions OD-1 and OD-2 are decided ([comment 6264699340](https://github.com/Maatify/php-rate-limiter/pull/143#issuecomment-6264699340), 2026-10-19). Documentation only: no change to `src/`, `tests/`, `composer.json`, `composer.lock`, any preset, threshold, canonical contract, `DECISIONS_INDEX.md`, or the text or status of DEC-015, DEC-019, DEC-021, DEC-022, DEC-023, DEC-024 or DEC-025.
> It is not Runtime authority, not an API approval, and not an Architecture GO. Stage 5-b (code) starts only after this document is accepted and merged. Stage 5-c (first activation) is a separate atomic PR.
> **Binding inputs (all `ACTIVE`):** DEC-023 (mode eligibility, declaration A-2), DEC-024 (opt-in T0 reservation, conditions C-c1 … C-c7, gates NM-5 / NM-6 / NM-7 before merge or ship, DEC-021 full supersession at first activation), DEC-025 (declared study-value budgets, decision 8 as rule 8.2: [PR #141 comment 6264207884](https://github.com/Maatify/php-rate-limiter/pull/141#issuecomment-6264207884)), DEC-008 (typed capabilities, Backward Compatibility 1–4), DEC-015, DEC-003, DEC-007.
> **Evidence classes:** `CODE` (read from `main@21065b2`), `MEASURED` (a merged archive), `DERIVED` (reasoning from a cited contract), `PROPOSED` (this document, for review). Every number is an archive value quoted at that archive's baseline.
> **Citation keys:** `AP` = [`ARCHITECTURE_PROPOSAL.md`](ARCHITECTURE_PROPOSAL.md); `SF` = [`SECURITY_FEASIBILITY.md`](SECURITY_FEASIBILITY.md); `ILD` = [`IDENTITY_LAYER_DESIGN.md`](IDENTITY_LAYER_DESIGN.md); `HMC` = [`HOST_MODE_CONTRACT_DECISION.md`](HOST_MODE_CONTRACT_DECISION.md); `G4v2`, `G5v2`, `G6`, `G7` = the merged reports of the same names in this folder; `DFP`, `KS`, `DM`, `POL` = `docs/DEVICE_FINGERPRINT.md`, `docs/KEY_STRATEGY.md`, `docs/DECISION_MATRIX.md`, `docs/POLICIES.md`.

## 0. What 5-a settles, and the limits it keeps

**Settles, before any line of `src/` is written:** the additive public shapes (§1); where each study mechanism lands in today's code (§2); the outcome-to-admission question NM-10 as an Owner decision (§3); DEC-015 / DEC-003 / DEC-007 behaviour of every new component (§4); the DEC-021 replacement plan as a draft record (§5, Appendix A); migration and rollback (§6); the canonical contracts that move with the code (§7); the acceptance gates of 5-b (§8); and the split of 5-b into PRs (§10).

**Keeps (prohibited in every stage, from the assignment and DEC-024):** any change to a default preset or to the `checkOnly` behaviour of a policy that did not opt in; any value other than DEC-025's; any touch of DEC-019 or DEC-021 before 5-c; merging with a confirmed finding open.

### 0.1 The values this contract may use

Only DEC-025's. They are package constants in 5-b, not Host-configurable (the DEC-015 precedent: the 4096-subject fallback bound is "package-owned and not Host-configurable"); a different value is a new decision.

| Item | Value | DEC-025 |
| --- | --- | --- |
| Window `W` | 600 s | D1, D8 (G7 `PX4` window) |
| `U_src` / net failure cap `F` (U door) | 24 / 4 | 1.4 |
| Account bucket `T` / `C` / refund | 30 min (1800 s) / 4 / refund on success | 2.2 |
| `P_acct` / `P_proof` / `N_P` | 10 per window and 60 per day / 6 and 30 / 10 per day | 3.1 (set R) |
| `K4P` rule | A: account-wide, not cleared | 4.A |
| Known device on U | account bucket only | 5.1 |
| Re-issue at a password change | the changing device only | 6.1 |
| Global ceiling | none (`U_glob`, and no `P_glob`) | 7.1 |
| Cross-account P failure capacity | `F` 4 per source and window 600 s, failures only, no refund, no persistence, no escalation | 8.2 as a rule |
| API | today's enforcement | 8.4 |
| K5 / allowance / micro-cap | DEC-021 values unchanged (Login: K5 8 / 12 / 16, allowance 8, micro-cap 8; OTP: allowance 2) | 9.1 |

### 0.2 Value gaps this contract found (nothing here is invented; each needs the Owner or is Host-side)

| Gap | Where it appears | Treatment in this contract |
| --- | --- | --- |
| Credential lifetime (30 d sliding, 90 d absolute) and the taint rule (3 other accounts in 600 s) are archive values of G4 v2 / G5 v2, **not in DEC-025** | credential issuance (ILD §4.1) | The library ships the **verifier and a codec**, not an issuance policy. Lifetimes are claims the Host writes into its own credentials; taint is a Host issuance rule. The library holds neither as a constant. A library-side issuer would be a new decision (DEC-025 amendment) |
| Permit deadline `D` and grace `G` | only if NM-10 option (b) is chosen (§3) | Not set here. `D ≤ W` is the only bound in SF §2.3. The Owner states both with the NM-10 decision |
| Global ceilings | the G4 v2 / G5 v2 arms carried `U_glob` and `P_glob` as in G1; DEC-025 7.1 says none | The runtime has none. Archive rows where a global binds (`X2`, `X2API` in G1V §7) are the only rows allowed to differ in the reproduction gate (§8.2) and the difference must be shown, not assumed |
| Binding-key rotation interval | ILD §4.5 | Same rule as the other secrets (current + one previous; interval at least the longest window it protects). No new value |

## 1. Additive public shapes (DEC-008 Backward Compatibility 1–4)

Every shape is additive: no existing signature, default or meaning changes. Names are `PROPOSED` for Lead review; the reason and the `ACTIVE` decision that requires each shape are the binding part.

| # | Shape | Why it exists | Rests on |
| --- | --- | --- | --- |
| **S1** | New case `PolicyCapabilityEnum::ADMISSION_RESERVATION` (`'admission_reservation'`) | The typed opt-in of C-c1. **One capability for the whole measured assembly** (source ceiling, failure cap, account bucket, P caps, `N_P`, the D8 rule, the K5 material and family split). FULL2 and G7 were measured as one assembly; sub-capabilities would be unmeasured combinations. It is authentication-related (`isAuthRelated()` includes it, so every authentication prerequisite of `registerPolicy()` applies, `RateLimiterEngine.php:68`), and it cannot be combined with `API_OVERUSE` (8.4: API unchanged) | DEC-024 C-c1; DEC-008; DEC-025 8.4 |
| **S2** | `Config\AdmissionReservationPolicyInterface` (additive, **not** added to `BlockPolicyInterface`) with `getAdmissionHostMode(): AdmissionHostModeEnum` and `getAdmissionBudgetGroup(): string`; `Enum\AdmissionHostModeEnum` with the single case `PRE_AND_OUT` | The A-2 declaration: the library refuses a policy that does not declare `pre+out`. `pre` and `out` deliberately have **no case** (not eligible). The budget group names the verifier class shared by login, re-authentication and password change, so admission counters are keyed by group and not by policy name (AP LT-5). The interface carries no value | DEC-023 point 3; DEC-025 §0.1 |
| **S3** | `Repository\AdmissionReservationStoreInterface extends RateLimitStoreInterface` (operations in §2.4) and `Repository\AdmissionCapableFullStoreInterface extends FullCapabilityStoreInterface, AdmissionReservationStoreInterface` | The Store capability for the atomic reservation. `FullCapabilityStoreInterface` is **not** edited (a custom implementer would break); a new combined interface is added and `RedisFullCapabilityStore` implements it. Registration fails fast when a policy opts in and the store lacks it | DEC-024 C-c4, C-c5; DEC-007 precedent (`assertPunishmentLifecycleCapability`, `RateLimiterBuilder.php:293`) |
| **S4** | `RateLimitContextDTO`: two optional **trailing** parameters `?string $clientInstanceId = null`, `?string $admissionCredential = null`; `jsonSerialize()` emits them only when non-null (the `postPunishmentReentry` pattern of `RateLimitMetadataDTO`) | The instance id is a continuity hint, **separate** from `sessionDeviceId` (trust) and `clientFingerprint` (AP §5); the credential is the opaque proof that the library verifies. The studies carried the id in `sessionDeviceId` as a study carrier only (ILD §1) | DEC-025 3.1 / 6.1; AP §5, §10 |
| **S5** | `DeviceIdentityDTO`: two optional trailing components `?string $deviceIdHash = null`, `?string $previousDeviceIdHash = null` | The resolver owns device material (DFP §5.1.1); the id component must come out of it with a current and a previous generation like `fingerprintHash`. Selected by the pipeline for opted policies only | ILD §2.1; DFP §5.1.1–§5.1.2 |
| **S6** | `Service\AdmissionCredentialCodec` (final) and `DTO\AdmissionCredentialDTO` (claims: subject, budget groups, instance, `jti`, `exp`, `bindAt`, `epoch`, key id); `RateLimiterConfig`: optional trailing `bindingKeySecret` and `previousBindingKeySecret` | The credential format is public because the Host mints it. The library verifies (integrity, subject, budget group, instance, expiry, epoch; ILD §4.2) and provides the primitive to encode claims; it never decides when to issue (§0.2). A binding key rotates independently of the key and fingerprint secrets | DEC-025 6.1 (re-issue), 3.1; ILD §4.1–§4.5 |
| **S7** | New values in the existing `RateLimitMetadataDTO::$cause`: `source_capacity`, `source_failure_cap`, `account_bucket`, `proof_capacity`, `new_device_cap`, `source_proof_failure_cap` (study codes in §2.3). A capacity refusal is `SOFT_BLOCK` with a retry-after to the end of the window, never persisted, never counted by Anti-Equilibrium, budgets or cycles | The result shape needs no new field (AP §6.3). SC-1 (a source refusal is distinct from every penalty cause) | DEC-024 Consequences (Public API); AP §6.3, §6.7 |
| **S8** | `Service\AdmissionBindingControlInterface` with `revokeAccountBinding(string $accountId): void` (bumps the per-account epoch), implemented by the engine and reached through the composite runtime by `instanceof`; **not** added to `RateLimiterRuntimeInterface` | The password-change / recovery event that voids every older credential is a Host proof; the library cannot learn it from the client (ILD §4.3). A new interface keeps existing implementers of the runtime interfaces valid | DEC-025 6.1; ILD §4.3 |
| **S9** | (Approved shape: OD-1 decided option (b), §3) optional `?string $permit = null` on `RateLimitCommand` and an optional `permit` element in `RateLimitMetadataDTO` on `ALLOW`; `Repository\AdmissionPermitStoreInterface extends AdmissionReservationStoreInterface` | Binds one outcome to one admission (T1). Shape in §3 | DEC-023 point 4 (NM-10); AP §6.5 |

**What no shape does.** No `BlockPolicyInterface` method, no `RateLimiterInterface` / `RateLimiterRuntimeInterface` method, no change to a result constant or `RateLimitCommand::checkOnly|recordFailure|recordSuccess` signature, no official preset declaring S1, no value on any public surface.

**Two consequences to state, not hide.** (1) A Host `match` that is exhaustive over `PolicyCapabilityEnum` sees a new case; this is the same class of change as every earlier case and is stated in `CHANGELOG.md` (§7). (2) `clientInstanceId` is **ignored** by every policy that did not opt in, so a Host can start sending it before activation with no behavioural effect; it is the only part of the identity layer a Host can pre-position.

### 1.1 Dormancy until 5-c (DEC-024 point 4)

No policy may opt in before DEC-026 is `ACTIVE`. In 5-b this is enforced in code, not by convention:

- `RateLimiterEngine` receives an optional `AdmissionRuntime` (internal class, `@internal`, constructor argument defaulting to `null`). A policy that declares S1 when it is `null` fails registration with a typed `RateLimiterException`.
- `RateLimiterBuilder::build()` does **not** construct an `AdmissionRuntime` in 5-b. Opting in through the builder is therefore impossible; the verification harness (§8) builds the engine directly.
- 5-c adds the one builder line, removes nothing else, and is the PR in which DEC-026 becomes `ACTIVE` (§5).

Residual, stated: a Host that hand-builds `RateLimiterEngine` with an `AdmissionRuntime` can opt in. The class is `@internal` and the Host would have to wire the store and the codec itself; it is outside the supported composition (`USAGE_GUIDE.md` "Default Composition").

## 2. Implementation map on the current code

All line numbers are `main@21065b2`. "Study" names the archived patch or library the production code must reproduce in behaviour; the reproduction gate (§8.2) is the proof, not the reading.

### 2.1 Order of work inside one request

```text
RateLimiterEngine::limit()                       src/Service/RateLimiterEngine.php:255
  preflight (guard, circuit, degraded)           :262-:286    unchanged; degraded = no admission (§4)
  device = resolver->resolve(context)            :290         + id component (S5)
  [new] lane = AdmissionRuntime->resolveLane()               integrity, subject, group, instance, expiry, epoch (ILD §4.2)
  result = pipeline->process(policy, ctx, req, device, lane)  :291  lane = optional new parameter, null = today
  [new] on ALLOW and isPreCheck and opted:
        AdmissionRuntime->reserve()  -> refusal = SOFT_BLOCK(cause), nothing written
  [new] on isFailure | isSuccess and opted:
        AdmissionRuntime->applyOutcome()
  circuitBreaker->reportSuccess()                :293
  catch BackendFailureException -> failure path  :308   reserve / outcome / epoch failures arrive here (S3, C-c5)
```

The reservation is taken **after** the pipeline has read the lane's active penalties and decided `ALLOW` (AP §6.2: read penalties, then reserve) and **only** for `isPreCheck`. A request refused by a penalty reserves nothing. The pipeline keeps writing the observation state `checkOnly` already writes (DEC-024 C-c2); the reservation is the only new write set and is all-or-nothing.

**Lane is a call parameter, never object state.** The study pipeline held the lane in a mutable property set before each call (`g1SetLane`, `g1-candidate.diff`). That is acceptable in a single-request PHP process and wrong in a long-running worker or any re-entrant use. Production passes the lane explicitly to `process()` and to `claimPostPunishmentReentry()` (`EvaluationPipeline.php:176`, `:149`) as a new optional trailing parameter whose `null` default is today's behaviour.

### 2.2 Where each mechanism lands

| Mechanism (DEC-025 value) | Study source | Production location | Notes |
| --- | --- | --- | --- |
| Source ceiling `U_src` 24, per source and window | G3 §2; `G1Admission` counters | `AdmissionRuntime::reserve()` U-lane scope, store `reserveAdmission` counter gate | Source = IPv4 exact or IPv6 `/64` (the K1 prefix function `EvaluationPipeline::getIpPrefix`, `:2786`). Cause `source_capacity` |
| Net failure cap `F` 4 (U door) | G3 v2 §2 (`cap:fail_src`) | store set-size gate at reserve; `applyOutcome()` adds the failed account on `isFailure` and removes it on a later `isSuccess` of the same account from the same source (net) | Inert in `pre` (no outcome): HMC §3 |
| Account bucket `T` 1800 s, `C` 4, refund on success | G3 v2 §4.2 (`cap:acct_tb`) | store bucket gate at reserve; `applyOutcome()` refunds on `isSuccess` | The bucket is the only known-device bound on the U door (5.1) |
| `P_acct` 10 / 60 per day, `P_proof` 6 / 30 keyed by `jti`, refund on success to the bucket the reservation came from | G4 v2 §3 (`FULL2`), G1 set R | store counter gates at reserve; refund in `applyOutcome()`; failures never refunded | `P_proof` ≤ `P_acct`. Cause `proof_capacity` |
| `N_P` 10 new devices per account per day, **member = `fingerprintHash`** | G5 v2 V2-1 (`N_P` on the fingerprint) | store device-member gate at reserve (member set per account, current + previous generation, previous read-only) | The member is the UA-inclusive fingerprint, **not** the id: the id member was G5's NO-GO (F-G5-01, F-G5-02). Production passes `device->fingerprintHash` / `previousFingerprintHash`. Gate NP-IN is reproduced (§8.2). Cause `new_device_cap` |
| Cross-account P failure capacity (D8) | G7 §1 (`PX4`, `cap:pfail_src`) | store set-size gate on the P lane at reserve; `applyOutcome()` adds the account on a P-admitted `isFailure`; success neither counts nor refunds; set expires with the window | A request for an account already in the set is not refused by this rule. Separate set from the U door's (door separation). Cause `source_proof_failure_cap`. `pre+out` only; inert in `pre` |
| Lane selection | G4 v2 binding; ILD §4.2 | `AdmissionRuntime::resolveLane()` using `AdmissionCredentialCodec`, the epoch read (S3) and the policy's budget group | Any failed check means **U, never a refusal by itself** |
| K1 / K2 / K3 collective penalties off, U and P | `g1-candidate.diff` | `EvaluationPipeline::process()` for an opted policy: steps 7, 8 and the spray step (`:283-:325`) are skipped; on P also the ephemeral device-cap observation (`:209-:230`), flood (`:327`) and K1/K2/K3 thresholds (`checkThresholds`, `:558`) | Study guards: `if (false && …)` on `checkCorrelationRules` and `checkCredentialSpray`; `$g1Lane !== 'P'` on the rest. Production uses the explicit lane/opt-in flag, never a dead branch. Correlation observations the study kept stay (K2 as observation, DEC-022 Decision 2) |
| U lane: no account-scoped refusal or persistence, device-scoped (K5) parts kept, 24 h budget never adds a candidate | `g3-ablation-no-k4u-persist.diff` (`g3DeviceScopedOnly`, applied in `finalizeDecision`, `:2139`) | same place, same rule, for opted policies on U | Scores still written and read (observable). This is what makes the account bucket the only known-device bound on U (5.1) |
| K4P: new account key family on P, rule A (account-wide, **not** cleared at a password change) | `g1-candidate.diff` (`k4Type`), `g5v2-k4p-scope.diff` (rule A = scope `null`) | `buildKeys()` (`:2603`): `k4` slot hashed from `…:k4p:…` on P. The slot stays `k4`, so the DEC-007 branch in `processUpdates` (`:1669-:1690`, keyed on the slot literal `'k4'`) applies to K4P with no change | Rules B and C (`k4p~scope`) are **not** implemented (DEC-025 4.A). The study patch's scope parameter is not carried |
| P-family device state: `K5p`, micro-cap `k5p`, device watcher `anti_equilibrium_device_p`, allowance `known_device_allowance_p`, account watcher `anti_equilibrium_p`, budget cooldown `budget_cooldown_p` | `g4-k5-separation.diff` (`g4K5`), `g1Purpose()` | `buildKeys`, `processUpdates` allowance (`:1608-:1616`), micro-cap (`:1771-:1790`), `deviceAntiEquilibriumKeys` (`:2328`), the account watcher reads/writes (`:398-:436`, `:469`), `budgetCooldownKey` (`:2302`) | The same string transforms as the study (`_p` suffix on the purpose; `k5p` on the key type). A KS amendment names them (§7) |
| K5 material = instance id when present, else today's fingerprint (A0 path); no UA, no `clientFingerprint` in the id material | `g5-k5-id-material.diff` (`g5K5Material`, `g5PreviousK5Material`) | `DeviceIdentityResolver::resolve()` (`:28`) adds `"id1|" . clientInstanceId` hashed by the current and (if present) the previous `FingerprintHasher` (S5); the pipeline **selects** between components and never re-hashes (DFP §5.1.1). Consumers keyed on the id: K5 score and level, K5 micro-cap, device watcher. Consumers that stay on `fingerprintHash`: K3, K2, churn, dilution, `device_cap_ip`, `device_cap_account`, distributed-account snapshots, `N_P` | `FingerprintHasher` (`src/Service/FingerprintHasher.php`) is **unchanged**: two generations come from the existing current/previous pair. `RateLimitOperationalReader` rebuilds K5 keys on its own (`:332-:387`) and must follow the same material and family selection |
| Known device on U: account bucket only; allowance counter state on U is unsuffixed and enforces nothing | DEC-025 5.1; G5 v2 F-G5-04 | no new code beyond the U-lane rule above | Parity first: the unsuffixed counter keeps being written on U as in the study. Removing that write is an optimisation that needs its own decision |
| Re-issue at a password change: the changing device only | DEC-025 6.1 | Host side (it mints the credential) plus `revokeAccountBinding()` (S8) | The library never re-issues. F-G5v2-05 (second holder locked out after the bump) is a declared cost |

### 2.3 Cause strings

| Public `cause` (S7) | Study code | Scope that refused |
| --- | --- | --- |
| `source_capacity` | `cap:src` | `U_src` |
| `source_failure_cap` | `cap:fail_src` | net `F` on the U door |
| `account_bucket` | `cap:acct_tb` | account bucket |
| `proof_capacity` | `cap:p_acct`, `cap:p_proof` | `P_acct` / `P_proof` |
| `new_device_cap` | `cap:p_device` | `N_P` |
| `source_proof_failure_cap` | `cap:pfail_src` | D8 rule on P |

`RateLimitContextMetadataDTO::$reason` carries the same string; `$scope` names `source`, `account`, `proof` or `device`. The study's `pen:K4P`, `pen:K5` and similar penalty causes are not new: they are the existing score and block paths on the new keys. A capacity refusal has `blockLevel` 1 (the existing `SOFT_BLOCK` level) and a `retryAfter` to the end of the refusing window or bucket refill. **Lead to confirm** both (§9.2, LT-2).

### 2.4 The Store operations (S3)

One Lua script per operation, caller time, relative TTLs (DEC-016: the script never calls `TIME`), replies per DEC-018, all keys of one verifier class in one hash slot, single non-clustered Redis (the topology `RedisFullCapabilityStore` supports today). The contract is the G1 `G1Store` contract, which already passed P2 as the **fixed** study store; the original study store failed T1-N4b / N5 (`G1_FINAL_DISPOSITION.md` §3).

| Operation | Effect | Failure |
| --- | --- | --- |
| `reserveAdmission(request, now)` | Validate **every** key's type and every field **before the first write** (Redis does not roll back earlier script writes: SF §6.2, the #116 F116-02 lesson). Then, all-or-nothing across the request's scopes: counter gates (window or day, with an optional previous-generation key summed and never written), a device-member gate (current and previous sets; a member of either is existing; new members go to the current set only; **expired members are not pruned on a refusal and the previous set is never written**: T1-N4b, T1-N5), set-size gates (U `F` and D8: refuse a request for a further account when the set holds `F` and the account is not a member), and the bucket gate. A refusal writes nothing and names the first refusing scope | Typed `BackendFailureException` for availability, transport, command execution; malformed stored state is a `RateLimiterException`, **never** a backend failure (DEC-015 provenance), and leaves no partial write |
| `applyAdmissionOutcome(outcome, now)` | On `isFailure`: add the account to the U net set (U) or the D8 set (P). On `isSuccess`: remove it from the U net set (U only; the D8 set neither adds nor removes), refund the bucket (U) or the counters the reservation came from (P). Failures are never refunded. Idempotency is **not** claimed for T0 (a duplicated outcome applies twice, as today) | Same |
| **Settlement of expired permits** (5-b2.1, Owner decision on LT-15, option 3; **optional per scope and off by default since 5-b2.2**, Owner decision on #147 of 2026-10-20) | **Optional, per set-size scope (`AdmissionScopeDTO::$settlesOnExpiry`, default false). Without the flag the store behaves exactly as merged at `d6aea4d`: the tombstone semantics (SF §2.3, I9): nothing is recorded for an admission whose outcome never arrives, the `p` key is neither read nor written, `successKeepsSettled` has no effect and the permit-deadline guard does not apply. NM-10 is NO-GO as a store mechanism (it feeds `F` and D8 only and does not compensate lost outcome scores; it harms benign traffic under outcome drops and probes: #147, F-5b3b-NM10 / NM5); A-2 stays an open residual with a Host duty.** The text that follows describes the behaviour **with the flag**. Not a separate operation: it is lazy and happens inside the two operations above. A reservation that carries a permit adds, for every set-size scope, a **pending** member scored by the permit `deadline` (family `p`; an earlier deadline wins; nothing is added when the member is already a settled failure; a reservation without a permit adds nothing). A set's size at a gate is its settled members plus the pending members whose `deadline ≤ now` (counted once). Therefore an admission with no claimed outcome counts as one failure from its `deadline`, never before, never twice. An explicit failure settles the member at once; a success cancels a pending member that is **not yet due** and never removes a due one; a scope flagged `successKeepsSettled` (the D8 rule) also keeps a settled failure on success, any other set-size scope applies the net rule (success removes a settled failure). Counters and buckets are never touched by settlement. A permit whose `deadline - now` exceeds the set scope lifetime is a `RateLimiterException` before any write. The `p` key lives for the scope `ttlSeconds`, set at creation and never renewed | As above |
| `readBindingEpoch(key, previousKey)` / `bumpBindingEpoch(key, previousKey)` | One integer per account (an account auxiliary key). Read: current, else previous, else 0. Bump: write current = max(read) + 1. Everything issued before the bump is void (not "previous still readable") | Same |

The per-account epoch is a **new locked purpose** in `KS` §4.6 (LOCKED): the amendment ships with the code (§7). It is deliberately not a `KS` §10.1 generation-bound namespace (ILD §4.3).

## 3. NM-10: how an outcome is tied to its admission — an Owner decision

**The problem (DEC-023 point 3 and 4; HMC §5).** A declaration is not evidence that the Host records every outcome. A Host that declares `pre+out` and drops outcomes runs, for those attempts, as `pre`: the failure cap is inert, nothing refunds, gate P fails. No activation may happen before NM-10 is registered and passes.

**The two designs the record names, with their cost.**

| | **(a) deadline: an admission with no outcome by `D` counts as a failure** | **(b) permit token T1** (optional) |
| --- | --- | --- |
| What it needs | A pending record per admission, resolved by the first outcome of the same tuple (account, source, lane, instance). **Without a token the only binding available is the tuple.** That is the OAAA design (#116) | A permit record per admission (`ISSUED → CLAIMED \| EXPIRED \| UNKNOWN`), an opaque token on `ALLOW` and on the outcome commands (S9), one compare-and-set per outcome |
| Measured status | #116 measured the tuple binding: **not idempotent per attempt (gate X1 FAIL)**, **a pre-only route on a credited scope (gate X3 FAIL)**, a missing outcome leaves the unit spent; verdict NO-GO | G1 measured T1: original form NO-GO; with the fixed study store `UNPROVEN` for production (`G1_FINAL_DISPOSITION.md` §1). The #116 replay class is addressed by the per-permit binding; its effectiveness is `UNPROVEN` until tested |
| Effect on legitimate users | An admission with no verifier call (probe, abandoned form, lost reply) is **counted as a failure** after `D`; for a Host with probe factor `ρ` > 1 this is a new failure on the net set per probe. NM-5 would measure it | The same expiry rule applies **per permit** **as an opt-in of the store** (5-b2.1, §2.4 "Settlement of expired permits"; **off by default since 5-b2.2**: the default is the tombstone semantics, and NM-10 is NO-GO as a store mechanism, #147): when a scope settles, a permit with no claimed outcome at its `deadline` counts as one failure in the failure sets it reserved; a probe that never sends an outcome expires its own permit. A Host that sends no token gets T0 (no change) |
| Guarantee | None beyond the tuple: a replayed outcome resolves another pending admission (X1) | At most one scoring per permit, modulo claims lost in a failover; **not** exactly-once (SF §2.3: scoring writes several keys through separate operations) |
| Extra surface | none public; internal pending state | S9 (command field, metadata element, permit store interface) |
| Extra state | `A_j` pending records | `A_j` permit records, lifetime `D + G` |
| **New values needed** | `D` (not in DEC-025) | `D` and `G` (not in DEC-025); `D ≤ W` (SF §2.3) |
| What it leaves open | Everything the tuple cannot bind | A Host that never sends the token stays at T0; the A-2 residual remains for it unless the capability **requires** the token |

**Gate (HMC §5, registrable form, shares left to the plan).** Replay `FULL2` in `pre+out` with a Host that drops a share of its outcomes. PASS: A1, A3 and B1 of `g4/integrated_v2/PLAN.md` §6.3 hold with the chosen mechanism; gate P is reported only. Detector: at the highest registered drop share the same arm without the mechanism fails A3, and at a drop share of 1 it reproduces `FULL2` `pre` (SP6 144, SP3 48, SP1n 24, ABA_A 48 against the A3 bounds 72, 24, 12, 24; gate P reported as L1 72, D2 61). The drop shares are **values the Owner must state** with the decision; the contract sets none.

**Recommendation (executor's, for the Owner): option (b), with the permit required for policies that opt in.**

- (a), as a rule on unbound outcomes, can only bind by tuple (`DERIVED`: without a token nothing else names the admission), which is the binding #116 measured as NO-GO (X1, X3); building it re-opens a failed design under another name.
- (b) is the only mechanism in the archive that binds one outcome to one admission, and its failure modes are named (`late`, `duplicate`, `conflict`, `unknown`, SF §2.3).
- **Required, not optional, for the new capability only.** S1 is a new opt-in, so a stricter contract breaks nobody. An optional token leaves the declared-but-dropping Host at T0 and the NM-10 gate would pass only in the token arm. Requiring it makes the A-2 declaration enforceable: an outcome without a valid permit is rejected for an opted policy (it is never re-sent as an unbound outcome: AP §6.4, `out` row).
- Cost the Owner accepts with (b): S9 public surface; a permit record per admission; a Host migration for the activating Host (thread one opaque token from `checkOnly` to the outcome); two new values `D`, `G`; and the guarantee is "at most once, modulo failover".
- If the Owner prefers a lenient Host contract: (b) with the token optional, and the A-2 residual stated as open for token-less Hosts.

**Decided (OD-1, 2026-10-19, [comment 6264699340](https://github.com/Maatify/php-rate-limiter/pull/143#issuecomment-6264699340)): option (b), a T1 permit that is required for policies that opt into the admission capability only.** An outcome without a valid permit is rejected for an opted policy and is never re-sent as an unbound outcome. Policies that did not opt in are not affected (T0 as today). The values `D` (the deadline, `D ≤ W`), `G` (the grace) and the outcome-drop shares for the NM-10 gate are **study values**: they are proposed and registered in the 5-b3 plan before it runs, not in this decision. S9 (an optional `permit` field on `RateLimitCommand`, a `permit` element in the `ALLOW` metadata, the permit store interface) is an approved additive shape. Options (a) and (b′) were not chosen. The text above is the analysis as presented to the Owner.

## 4. DEC-015 behaviour, DEC-003 cycles, DEC-007 lifecycles

### 4.1 DEC-015: no new fallback bound

| Component | Behaviour |
| --- | --- |
| Reserve, outcome, epoch read / bump (S3) | A typed outage raises `BackendFailureException` and takes the existing path (`RateLimiterEngine.php:308`): `reportFailure`, mode from `FailureModeResolver`. Opted policies are authentication-class and must be `FAIL_CLOSED` (registration rule), so the observed sequence is the one DEC-021 describes: `FAIL_CLOSED` `HARD_BLOCK`, then the open circuit's `DEGRADED_MODE` bounded fallback, then `fallback_limit_exceeded` |
| Degraded and `FAIL_OPEN` paths | **No admission, no lane, no epoch read.** `degradedResult()` (`:418`) and the `DEGRADED_MODE` branch return before the pipeline; the admission bounds are absent (DEC-024 Consequences, DEC-023 DEC-015 row). Nothing in `LocalFallbackLimiter` changes: no new dimension, no new bound, no new tracked subject (the 4096 cap is untouched) |
| Per-mode fallback cost | Unchanged and still per call: a `pre+out` attempt uses two fallback units, a `pre` attempt one (HMC C-7). A permit claim (if (b)) is an outcome call and counts as such |
| Partial failure | A failure of the reservation is a typed failure for the **whole request**; never a partial allow (the #116 O6 lesson, SF §6.10). The script validates before it writes (§2.4) |
| Malformed state | `RateLimiterException`, never a backend failure, never fallback allowance (DEC-015 provenance) |
| Credential verification | Pure computation. A malformed, expired, wrong-subject or wrong-epoch credential is **U**, not an exception. A missing binding key at build time is a registration failure (fail fast), not a runtime fallback |

### 4.2 DEC-003 (multiple-block cycles and decay pause)

A cycle is scoped to `policy + canonical enforcement key type + logical enforcement identity`. This contract states the key-type identity of every new key:

| New key / state | DEC-003 treatment |
| --- | --- |
| `K4P` (account on P) | A **distinct logical identity** from K4 (the key string differs), independent cycle and pause history. `blockWithCycleTracking` is called with the K4P key exactly as for K4 (`persistWinningCandidates`, `:2028`, `:2066`, `:2116`); no code path reads K4 cycles for a K4P request or the reverse. Gate: two requests on the two lanes never share a cycle count (§8.3) |
| `K5p` (device on P) | Same: a distinct identity from K5 |
| K5 on the id material vs K5 on the fingerprint material | Different key strings, so different histories; this is the one-time K5 restart (§6), not a merge. DEC-003 point 7 (Current writes, Previous read-only, relevant Previous cycles adopted on first Current mutation) applies only across **secret** generations, not across material generations (§6.2) |
| Admission counters, sets, bucket, permit records | **Not blocks.** A capacity refusal is never persisted, never a cycle, never a pause, never escalated, never counted by Anti-Equilibrium or the budget (SC-3) |

### 4.3 DEC-007 (generation-bound K4 lifecycle and re-entry)

| Point | Treatment |
| --- | --- |
| K4U | The legacy K4 key and derivation. Its lifecycle is unchanged. On U for an opted policy no account-scoped candidate persists (§2.2), so K4U lifecycle writes happen only on the policy's non-opted behaviour |
| K4P | The DEC-007 primitives are keyed by the `k4` **slot**, so K4P gets the generation-bound mutation, atomic hard-block with cycle accounting, and re-entry evidence with no change to `PunishmentLifecycleStoreInterface` (the study reproduced exactly this; AP §7.2 "the same key-parameterised primitives") |
| Re-entry exposure | Evidence on K4P is exposed only to P requests (AP §7.2 DEC-007 extension). `claimPostPunishmentReentry` (public) keeps its signature; the pipeline entry takes the lane (§2.1) and reads K4U or K4P accordingly. **Lead to confirm** that no new public surface is needed (LT-3) |
| `recordSuccess()` | Never resets evidence (DEC-007), unchanged |
| Opted policy validation | DEC-007 validation still binds (K4 thresholds positive and monotonic, not `FAIL_OPEN`, `BudgetConfig`, lifecycle store present). An opted policy that is not a re-entry policy has no K4P re-entry, which is a declared difference, not a regression |

## 5. Replacing DEC-021 entirely — the plan and the draft record

DEC-024 point 4 binds the first activation to a **new record that supersedes DEC-021 entirely** (Decision Governance §9), in the same PR, with the canonical contracts and the code references to DEC-021 updated in that change. No policy may opt in before it is `ACTIVE`. DEC-021 is not edited in text or status before then.

**Plan.**

1. Draft **DEC-026** (Appendix A), `PROPOSED` in 5-a. It is a section of this document and **not** a file under `docs/decisions/`, and it is not indexed: a `PROPOSED` record is not implementation authority (Decision Governance §5), and 5-b needs none of it. It moves to `docs/decisions/` with its index line in 5-c, or earlier if the Owner asks.
2. DEC-026 carries **every** decision, rationale, consequence and alternative of DEC-021 as they are, for every policy; the delta is the four items of Appendix A, "Delta".
3. In 5-c, one atomic PR: DEC-026 `ACTIVE`, DEC-021 `SUPERSEDED` (`Superseded By: DEC-026`; DEC-026 `Supersedes: DEC-021`), the index, the canonical contracts (§7), the code references to DEC-021, and the first opting policy.
4. DEC-021's historical text, rationale and measurements are never rewritten (Decision Governance §9).

**Decided (OD-2, 2026-10-19, [comment 6264699340](https://github.com/Maatify/php-rate-limiter/pull/143#issuecomment-6264699340)):** the draft of Appendix A is accepted as written, in number and form: a full replacement record that carries DEC-021 unchanged for every policy that did not opt in. It stays a draft inside this document until 5-c.

## 6. Migration and rollback

Source: AP §10; ILD §6; SF §6.7–§6.9; DEC-024 C-c3, C-c6. Everything here is `DERIVED` until NM-7 measures it.

| Step | What happens | Safe because / limit |
| --- | --- | --- |
| **M1. Ship dormant** (5-b, C-c6) | The code exists; no preset declares S1; the builder does not construct the admission runtime (§1.1). Behaviour is S0 byte for byte for every existing policy | Gate: the existing suite and the S0 baseline fixtures (`tests/Fixtures/S0Baseline`) pass unchanged; the DEC-021 mixed-version tests still pass |
| **M2. Fleet fence** (5-c, C-c6) | An operator-set admission epoch in the store enables the new path only after every worker runs the new version. Old workers do not reserve, so the bound is `c_x + calls through old workers` and is `UNPROVEN` while the fleet is mixed | The fence value is an operator action, not a package value. It is checked in `reserve` and by the engine before a lane is resolved. Mixed-fleet behaviour is NM-7 |
| **M3. While enabled** | Existing K1 / K2 / K3 correlation blocks are honoured read-only until expiry (correlation L2 ≤ 60 s) and none is written for opted policies. K4U is the legacy K4 key, so its history continues; K4P starts empty | Reads of old correlation blocks need no new code: `checkActiveBlocks` (`:498`) already reads them |
| **M4. One-time K5 restart** | A device that starts sending `clientInstanceId` moves from fingerprint-derived to id-derived K5. Its K5 score, micro-cap count and device-watcher events are no longer on its key: a **one-time K5 restart** for exactly those devices, bounded by the K5 family lifetimes (24 h score TTL, fixed micro-cap epoch, persisted level durations). A device with no id is unaffected (A0 path) | The restart is a laundering opportunity only for a device the Host marks known, and is bounded by the fingerprint-independent allowance (ILD §6.1). The K5 material migration reads the old material as the "previous" derivation during a **transition window ≥ the longest K5-family lifetime** and writes only the new key; never `max(v1, v2)` (DFP §5.1.6) |
| **M5. No overlap with secret rotation** (DFP §5.1.6) | The architecture holds one previous generation. A K5-material migration **must not start while a fingerprint-secret or outer-secret rotation still needs its previous generation, and a rotation must not start during the migration window.** Operationally the migration is scheduled as a rotation event. The binding key rotates independently and may overlap | Without this the pipeline would read (old material × old secret), (new material × old secret) and (new material × new secret): the Cartesian combination DFP §5.1.6 forbids. Admission counters across a rotation sum current and previous and write current only; **a second rotation inside one window re-issues capacity**, so rotation interval ≥ window (C-c3) |
| **M6. Rollback** | S0 reads K4 and K5 as before and **ignores** admission counters, sets, permits and epochs (they expire by TTL). K4U (= K4) survives. | **What is lost:** K4P (P-lane account penalties, at most their TTL), and the P-family device state (`K5p`, `k5p` micro-cap, `anti_equilibrium_device_p`, `known_device_allowance_p`, `anti_equilibrium_p`, `budget_cooldown_p`). K5 written under the id material is unreadable by the old build, so the **device restarts K5 again** (ILD §6.4). The fingerprint-independent allowance counter survives and is shared again across lanes. Credentials are ignored. S0's collective blocks resume and apply to everyone. Rolling forward within the same window finds the old counters; traffic S0 admitted meanwhile was not counted, so that window's bound is `c_x + S0-phase admissions` |

Failover and lost writes are `UNPROVEN` in size: the bound becomes "per window, modulo `L` at each failover" with `L` the reservations acknowledged within the replication lag (SF §6.9). NM-7 reports what is measured; it does not claim linearizability.

## 7. Canonical contracts that move with the code (references only)

Decision Governance §3: a canonical contract moves in the same change as the behaviour it describes. 5-a edits none of them.

| Contract | Sections | What moves | PR |
| --- | --- | --- | --- |
| `docs/POLICIES.md` | §0, §1, §5, §6, new section for the opted variants | S1 and S2; the per-mode eligibility statement (DEC-023); custom-policy validation for S1; the owner-safety clause of §1 for proven owners (AP D4) | 5-b3 (text) / 5-c (official variant) |
| `docs/guides/USAGE_GUIDE.md` | "Inputs and Outputs", "Primary Public Calls", "Integration Boundaries", walkthroughs | C-c7 (**for every enabled policy, one `checkOnly` = one admission**); `checkOnly` is not read-only for opted policies; `clientInstanceId`, `admissionCredential`, the lane; Host duties (outcomes, permit if (b), the password-change event, the opaque subject that exists whether or not the account exists, F§2.6); the existing "not globally read-only" sentence | 5-b3 |
| `docs/DECISION_MATRIX.md` | §0, §1, §2.1, §2.2, §2.2.2, §2.5, §3.1.2, §5.1–§5.4, §10 | Evaluation order with the admission stage; K2 as observation for opted policies; the allowance and device watcher by family; the known device on U (bucket only); `DM` §10 and `KS` §2 source-capacity amendment (AP §6.7: IP-prefix keys stay advisory for identity, a source capacity ceiling for unproven traffic only) | 5-b3 |
| `docs/KEY_STRATEGY.md` | §3.4, §3.5, §4.3.3, §4.5.2, §4.6 (**LOCKED**), §5.1, §5.2, new admission-key section | `K4P`, `K5p`, the `_p` auxiliary purposes, the `binding_epoch` purpose (needs the §4.6 amendment), K5 = account + device material (id, else fingerprint), the §2 amendment for the source capacity class | 5-b2 (keys) / 5-b3 |
| `docs/DEVICE_FINGERPRINT.md` | §3.3, §4.2, §4.3, §5.1 (incl. §5.1.1, §5.1.6) | The instance id as a component that never raises confidence; the resolver producing the id component with current and previous generation; the K5-material migration as a generation event that never overlaps a secret rotation | 5-b3 |
| `docs/FAILURE_SEMANTICS.md` | §4 | Admission absent in degraded mode; typed failure of the reserve operation | 5-b3 |
| `CHANGELOG.md`, `RATE_LIMITER_PACKAGE_REFERENCE.md`, `llms.txt` | | The additive surfaces S1–S9; "dormant, not selectable through the builder" until 5-c | each PR touching public surface |
| `docs/decisions/DECISIONS_INDEX.md`, DEC-019, DEC-021, DEC-022, DEC-025 (statuses and relations) | | DEC-026, supersession, DEC-022 `ACTIVE`, DEC-019 exit | **5-c only** |

## 8. Acceptance gates for 5-b

Each gate is registered **before** the code it measures is written (a plan committed ahead of the harness, as in every study of this folder), has a detector that must fire, and reports its result with the exact HEAD. A gate with no detector firing is "not evidenced", not a pass. No gate may be weakened after a run.

### 8.1 Harness

The Consumer Verification Harness (`consumer-verification/`, `verify.php`, `FixedClock`, `RespRedisCommandExecutor`) runs the real `src/` through the public composition with a fixed clock and a real Redis (`docker/redis-integration/compose.yaml`, redis 7.0.15). 5-b extends it with: the stream and fixture replays of the archive (read from the archive's own scenario definitions, not re-typed), a Host model with the password-change binding, sliding renewal and the credential codec, and an outcome-drop Host. The CVH today only verifies the published artifact (`docs/RELEASE_VERIFICATION.md` Stage C); the new scenarios are an addition that does not change its existing checks.

### 8.2 Reproduction of the archive numbers on the real code

| Gate | Source | PASS | Detector |
| --- | --- | --- | --- |
| **RP-G4** | G4 v2 Part N: 13 gates and 6 detectors (A1, A2, A3, B1, B2, C1, C2, C3, C4v2, C5, C6, E, K5, and the P `pre` result) | every `FULL2` value in `g4/integrated_v2/expected/summary.md` reproduced by `decision` strings and counts (L1 0, ABA_B 0, D2 24, KD 9, UAC 6; MIX 54; SP1n 4, SP3 8, SP6 24, ABA_A 8; BRU24 51 against ceiling 52; STEAL thief ≤ 2 P / window, 9 in 24 h, 0 after the change, U after the change 27 ≤ 28; gate P in `pre` L1 72, D2 61) | the archived leave-one-out arms rebuilt as production switches off: no P refund (L1 72), no renewal (E2 diverges), no password-change binding (owner never admitted, 719 `account_bucket` refusals), no K5 separation (K5U diverges) |
| **RP-G5** | G5 v2 gates G1 UAX, G2 IDM, G3 KDP, G4 MECH, G5 ABS, R:STEAL, R:STREAMS and the NP-IN audit | `UAC12` equals `KDL` on both lanes; `UAX-NP` refuses 2 of 12; `STABLE-600/300/60` 45 / 44 / 45 on P; BRU24 admissions 51; FORGE 0 P admissions; MINT 51; STEAL thief 9, owner wait 0; no-id rows equal the reference; NP-IN: every audited P admission has the **fingerprint** member and none has the id member | `N_P` on the id (G5's `D-NPID`: thief 20, wait 1,260 s, `UAX-NP` 0), an id-keyed budget (`D-IDMINT` 5,760), no allowance (128 / 83 / 76), no K5 (all admitted) |
| **RP-G6** | G6 arm 2 (the multi-session cells on `FULL2` without the D8 rule) | `TMS` 61/61 and `TMSd` 120/200 on the arm without the rule; the UK lane (D5 5.4) is **not** implemented, so G6's UKD NO-GO is not reproduced and is not a gate | the S0 values 7/61 and 2/200 on the unpatched `src/` |
| **RP-G7** | G7 gates X1, X3, X4 and the declared costs | `N12T` 0/61 and the 22 legitimate streams not above the reference; `TMX` 26/200 and 34/200 equal to the reference; every attacker cell equal to the reference; `TMS` 29/61 and `TMSd` 40/200 (`pre+out`), 61/61 and 120/200 in `pre` (inert); `TPAIRT` office 21 / 61 and attacker 120 → 72 | `D-PXALL` (every admitted outcome counts) refuses 32/61 of `N12T` and 24/61 of `N12`; the reference returns 61/61 and 120/200 |
| **RP-GLOB** | the one place the runtime differs from the archive arms | the only rows allowed to differ are those where the archive's `U_glob` / `P_glob` binds (`X2`, `X2API`); each difference is listed and explained, and DEC-025 7.1 (no global) is the reason | a row that differs without a global binding is a **finding** |

Parity means the same decision string, the same count and the same cause, not "close". The baseline of each archive is the one its own report states in §1; the production gate cites it.

### 8.3 Contract gates

| Gate | PASS | Detector |
| --- | --- | --- |
| **S0-PARITY** (C-c1, DEC-008 BC 1–4) | the existing suite is green unchanged; the three official presets and every custom policy without S1 return today's results on the S0 baseline fixtures (`tests/Fixtures/S0Baseline`) and the DEC-021 mixed-version tests; a Host sending `clientInstanceId` / `admissionCredential` to a non-opted policy changes no decision | a deliberate switch that applies the id material to non-opted policies breaks the DEC-021 K5 parity tests |
| **REG** (C-c4) | an S1 policy with a store lacking S3, a missing binding key, a missing mode declaration, `API_OVERUSE` combined, `FAIL_OPEN`, no fallback `ACCOUNT` + `IP_PREFIX` dimensions, no `BudgetConfig`, an `AdmissionRuntime` of `null` (dormancy, §1.1) — each fails at registration with a typed exception | one test per rule; a policy valid except one rule is rejected |
| **DORMANT** | `RateLimiterBuilder::build()` cannot produce a runtime on which a policy opts in (5-b); an engine without `AdmissionRuntime` rejects S1 | build a builder with an S1 policy: must throw |
| **LANE** | U never writes P state; P is entered only through a verified credential: a wrong account, wrong budget group, wrong instance, expired, old epoch, tampered MAC, or a credential for another group each give U (`G1` C9 / `g1PStateLeaks` equivalents); no flag, header, id or `isKnownForAccount` value selects P | a build in which `isKnownForAccount` selects P fails LANE |
| **CYCLES** (DEC-003) | K4 and K4P, K5 and K5p keep independent cycle histories; a capacity refusal creates no cycle, no pause, no Anti-Equilibrium event, no budget increment (SC-3) | a build that shares the cycle key across lanes fails |
| **LIFECYCLE** (DEC-007) | K4P gets generation-bound mutation, atomic hard-block with cycle accounting and re-entry evidence readable only on P; `recordSuccess` never resets evidence; non-re-entry opted policies are rejected for a re-entry claim as today | — |
| **FAIL** (DEC-015, C-c5) | a typed outage in reserve / outcome / epoch gives the existing typed path with **no partial write**; malformed stored state is a `RateLimiterException`, not fallback; degraded and `FAIL_OPEN` paths admit nothing and read no lane; `LocalFallbackLimiter` is byte-identical | a store that raises a non-typed exception must not enter the circuit or the fallback |
| **ROT** (C-c3, DFP §5.1.6) | counters sum current and previous and write current only; the previous generation is never written on admit or refuse; the id material read as "previous" during the K5 migration never writes; a second rotation inside one window is documented as capacity re-issue, and the gate shows its size | a write to the previous key (T1-N5) |
| **SC-1…SC-6** (AP §6.7) | a source refusal carries a distinct cause; no state after the window; no score, budget, watcher or cycle change; P and principal requests from an exhausted source are admitted while their own scopes have headroom; UA, id or fingerprint rotation gains nothing beyond the ceiling; co-tenant harm reported per stream | — |

### 8.4 NM-6 — production store integrity (real Redis)

The T1-N4b and T1-N5 equivalents and the R-1 / R-2 races on `RedisFullCapabilityStore`. PASS: no reservation-state write on a refused reservation; the previous generation never written; expired members present at a refusal are not pruned; R-1 admits exactly the cap under a real race (G1: 3 / 3 / 3), R-2 exactly 1 / 1 / 1. Detector: the **original** study Redis store, which failed T1-N4b / N5 (`G1_DA_T1.md`), fails the same fixtures; and the deliberately non-atomic control (check, then write) over-admits under the race.

### 8.5 NM-7 — operational envelope (real Redis)

Concurrency (admissions never exceed the reserved scope's ceiling under the registered concurrency), failover (the size of `L` is measured and reported, not bounded), a mixed fleet behind the fence (an old worker's calls are uncounted: the bound is reported as `c_x + old-worker calls`), and rollback (M6: S0 ignores counters and permits; K4U survives; the listed state is lost; roll-forward finds the old counters). PASS conditions are the ceiling statements above; the **concurrency level, the failover lag and the mixed-fleet shares are values the plan registers before the run**, none set here. Detector: a non-atomic reservation exceeds the ceiling under the same concurrency.

### 8.6 NM-5 — Host integration under a reservation

Replay the G4 v2 benign streams at probe factors `ρ` and abandonment shares the plan registers (none set here). PASS: the A1 condition of `g4/integrated_v2/PLAN.md` §6.3 holds at every registered rate. Harness validity (not a detector): at `ρ` = 1 with no abandonment the archived `FULL2` values are reproduced (RP-G4). Detector: at the highest registered `ρ`, at least one benign stream is refused more often than at `ρ` = 1; if not, the harness does not exercise `ρ` and the gate is not evidenced.

### 8.7 NM-10

As §3, after the Owner's decision OD-1.

### 8.8 What does not gate 5-b

DEC-022 `ACTIVE` and DEC-019's exit are 5-c conditions (§9.3). The permit and the UK lane (5.4), `P4`, and `N-6` are open items of DEC-025 and are not part of any gate here.

## 9. Decisions and checks requested

### 9.1 Owner decisions (OD) — both decided ([comment 6264699340](https://github.com/Maatify/php-rate-limiter/pull/143#issuecomment-6264699340))

| ID | Decision | Options | Executor's recommendation |
| --- | --- | --- | --- |
| **OD-1** | **NM-10:** how an outcome is bound to its admission | (a) deadline, counted as a failure; (b) T1 permit, required for opted policies; (b′) T1 permit, optional | **Decided: (b), required for opted policies only.** `D`, `G` (`D ≤ W`) and the drop shares are study values registered in the 5-b3 plan before it runs |
| **OD-2** | **Draft DEC-026:** that Appendix A is the whole delta from DEC-021, and its number and form | accept / amend | **Decided: accepted as written.** It restates DEC-021 unchanged for non-opted policies and adds the four items of the Appendix A delta |

### 9.2 Lead technical choices (LT) — accepted by the Lead (comment 6264644406): LT-1 to LT-8 as written, with `blockLevel` 1 (LT-2) and LT-4 (`correlationId ?? accountId`; a new `subject` input is rejected for now) confirmed

| ID | Choice | Proposed |
| --- | --- | --- |
| LT-1 | One capability (S1) for the whole assembly, no sub-capabilities | yes (§1, S1) |
| LT-2 | Capacity refusal shape: `SOFT_BLOCK`, `blockLevel` 1, `retryAfter` to the end of the refusing window or refill, the six causes of §2.3 | as written; confirm `blockLevel` |
| LT-3 | No new public surface for K4P re-entry: `claimPostPunishmentReentry` keeps its signature and reads K4U or K4P by the lane the credential selects | yes |
| LT-4 | Admission subject for the account bucket and the sets = `correlationId ?? accountId` (the existing correlation-subject rule, `DM` §0), so a refusal never reveals whether an account exists; K4 / K5 stay on `accountId` | proposed; the alternative is a new `subject` input. Without a subject, `U_acct` and the sets do not apply to that request |
| LT-5 | Admission keys namespaced by the Budget Group (S2), not the policy name | yes (AP LT-5) |
| LT-6 | The binding key is mandatory when any S1 policy is registered | yes (fail fast) |
| LT-7 | Lane as an explicit parameter, never object state (§2.1) | yes |
| LT-8 | `FingerprintHasher` unchanged; the id component is a second hash of `"id1|…"` by the existing current / previous pair | yes |

### 9.3 What is deferred to 5-c (named so it is not forgotten)

DEC-022 `ACTIVE` needs its four conditions: (1) DEC-023 / DEC-024 (decided), (2) UA-free K5 measured with DEC-021 parity (G5 v2, `SUPPORTED — LIMITED SCOPE`), (3) DEC-025 (decided), (4) **Runtime proven and reviewed**: measured on the DEC-019 Decision 5 list (the `checkOnly`-only and record-only guarantees, K1 + K2 overlap, IPv6 macro scopes, Current / Previous rotation, trusted / nontrusted collateral, block renewal, cap / N−1) and on the best-response and paid-identifier gates of DEC-022 Decision 4, with independent Lead review on the exact HEAD. DEC-019 Decision 6: the exit needs a replacement material decision, its ADR and canonical contract, and proven Runtime; Decision Governance §9 has no partial supersession, so 5-c gives the rest of DEC-019's scope (K1 credential spray for non-opted policies, API anti-evasion, `checkOnly` limitations, custom policies under DEC-008) an explicit `ACTIVE` owner by an explicit record. **LOCKED contracts (Lead ruling, comment 6264644406; no third Owner decision):** `KS` §4.6 (the `binding_epoch` purpose and the `_p` purposes) and `KS` §3.4 / §3.5 belong to DEC-026 as its canonical contract and are amended in the atomic 5-c PR, under Decision Governance §3. `KS` §2 (the source-capacity class) belongs to DEC-022 Level 1 and is amended when DEC-022 becomes `ACTIVE`, also in 5-c. DEC-026's "Canonical Contract / Current Owner" names these sections. The Maatify-dedicated policy that first selects T0 is a 5-c item; the free mode stays on today's defaults until a separate Owner decision (assignment).

### 9.4 Findings of this document the Lead should check first

1. §0.2: credential lifetimes and the taint rule are archive values outside DEC-025; the contract ships no issuer.
2. §2.2: the study's `if (false && …)` branches and mutable lane are not transcribed; the rule is stated and gated by RP-G4 / RP-G5.
3. §8.2 RP-GLOB: the archive arms carried global ceilings that DEC-025 7.1 does not.
4. §1.1: dormancy is a code property (null `AdmissionRuntime`), not a convention.
5. Appendix A states DEC-026 is a draft inside this document, not a file or an index line.
6. **LOCKED contracts — ruled by the Lead, see §9.3 (no third Owner decision).** §7 lists amendments to `KS` §2 (the source-capacity class, `AP` §6.7 / AP D7, `PROPOSED` there), `KS` §4.6 (the `binding_epoch` purpose and the `_p` purposes) and `KS` §3.4 / §3.5. DEC-025 decides the budgets; none of DEC-023 / 024 / 025 names these LOCKED-contract amendments as decided. The Lead should say whether DEC-025 1.4 and DEC-026 cover them or whether a separate Owner decision is needed before 5-b2 / 5-b3 edit those sections. This does not block 5-a.

## 10. Stage 5-b: the PRs

Each PR is dormant, has a Lead review before merge, and carries the registered gates that apply to it. A confirmed finding blocks the merge. DEC-024 point 3 requires NM-5, NM-6 and NM-7 **before any change that implements the reservation is merged**: the reserving store operation is therefore not merged before NM-6 passes on it, and the engine integration is not merged before NM-5 and the engine part of NM-7 pass.

| PR | Content | Gates | Reserves anything? |
| --- | --- | --- | --- |
| **5-b1** Shapes and validation | S1 enum case, S2, S4, S5 (resolver id component), S6 codec and config, S3 interfaces (no implementation), registration rules, dormancy guard, S8 interface | S0-PARITY, REG, DORMANT, LANE (codec part) | No: no store operation exists, no policy can opt in |
| **5-b2** Store | `RedisFullCapabilityStore` and the reference store implement S3 (reserve, outcome, epoch; permit operations if OD-1 = (b)); `AdmissionCapableFullStoreInterface`; `KS` key sections | NM-6, NM-7 (concurrency and failover, store level), ROT (store part), FAIL (store part) | The store can, nothing calls it |
| **5-b3** Engine and pipeline | `AdmissionRuntime`, lane resolution, the pipeline family split and the study guards as explicit rules, the operational reader, outcome hooks, permit handling, canonical documents | RP-G4, RP-G5, RP-G6, RP-G7, RP-GLOB, CYCLES, LIFECYCLE, FAIL, SC-1…6, NM-5, NM-7 (mixed fleet, rollback), NM-10 | Yes, but unreachable through the builder (§1.1) |

5-b3 is **split in advance** (Lead ruling): **5-b3a** the family split in the pipeline and the K5 material (identity family selection, the study guards as explicit rules; RP-G5, CYCLES, LIFECYCLE parts), and **5-b3b** `AdmissionRuntime`, lanes, the permit, outcome hooks and the canonical documents (RP-G4, RP-G6, RP-G7, RP-GLOB, FAIL, SC-1…6, NM-5, NM-7 mixed fleet and rollback, NM-10). The row above is the union of the two.

## 11. Limits

This document decides nothing that DEC-023, DEC-024 or DEC-025 did not, and invents no value. Every number is quoted from a merged archive. The mapping of §2 is read from `main@21065b2` and is verified only by the gates of §8, not by this reading. The reservation, the lane and the identity layer are `EXPERIMENTALLY SUPPORTED — LIMITED SCOPE` in the study (reference stores, a controlled clock, study-code tokens, a Host model); real Redis, concurrency, failover and a mixed fleet are `UNPROVEN` until NM-6 and NM-7 run. The security of the Host's issuance, password-change and recovery flows is outside the library.

---

## Appendix A — Draft record DEC-026 (`PROPOSED`, not a file, not indexed)

> **Status: accepted as draft by the Owner (OD-2, [comment 6264699340](https://github.com/Maatify/php-rate-limiter/pull/143#issuecomment-6264699340)); the text is unchanged and the record stays `PROPOSED` until 5-c.** The text below is a **draft inside this document**. It is not implementation authority (Decision Governance §5) and it does not change the status or text of DEC-021. In 5-c it becomes `docs/decisions/DEC-026_<title>.md` with its index line, in the same PR in which it becomes `ACTIVE` and DEC-021 becomes `SUPERSEDED`.

### DEC-026 — Known-Device Verifier Bound, restated for the admission layer (replaces DEC-021)

**Decision ID** `DEC-026` · **Status** `PROPOSED` · **Date** to be set at activation · **Supersedes** `DEC-021` · **Superseded By** None

**Decision Authority.** Owner. DEC-024 point 4 (2026-10-19) binds the first activation of the opt-in reservation to a record that supersedes DEC-021 entirely. The Owner's approval of this record is OD-2 of the 5-a contract. DEC-021's authority, history and technical acceptance are quoted from DEC-021 and are not rewritten.

**Scope / Concern.** Verifier reach of known devices on Login, OTP and custom authentication policies, and the scope of Anti-Equilibrium escalation, **for every policy**. For policies that do not opt in under DEC-024 the behaviour is DEC-021's, unchanged. For policies that opt in, the delta below applies. Out of scope: as DEC-021 (shared-NAT correlation governed by DEC-019 until DEC-022 is `ACTIVE`, `API_OVERUSE`, budget semantics, the OTP unknown-device path).

**Carried unchanged from DEC-021 (the final record reproduces these sections verbatim).** Context; Decision 1 (K5 thresholds `8 / 12 / 16` on the official presets, K5 decay); Decision 2 (a K5-derived level persists on the K5 key only); Decision 3 (the known-device account allowance, Login 8 and OTP 2, per-failure K4 scoring rule and not an absolute cap) **except the pre-check clause below**; Decision 4 (the opt-in is an authentication capability; every prerequisite and the active-allowance prerequisites); Decision 5 (source-scoped Anti-Equilibrium, one watcher per issued `SOFT_BLOCK`); Decision 6 (what stays unchanged: budget, cooldown, micro-cap, OTP `precheck_enforcement = false`, K1/K2/K3 correlation and DEC-019, the Store and Redis contracts, DEC-003, DEC-007, DEC-008, DEC-015); Rationale; **Consequences including the mixed-version and rollback items M1–M5**; Alternatives Not Adopted.

**Delta.**

1. **Pre-check statements narrowed.** DEC-021 Decision 3, "read-only pre-checks reserve nothing", and its Consequences, "Pre-checks are read-only, so concurrent in-flight attempts on one device are not serialized", apply to **policies that have not opted into DEC-024**. For an opted policy `checkOnly` takes the all-or-nothing reservation of DEC-024: concurrent admissions on a scope are serialized by it, one `checkOnly` is one admission, and the allowance is still consumed on `recordFailure` (the outcome path is unchanged). DEC-021's statement that check-only Hosts report no failures and so its bound cannot learn from them stays true, and extends to the admission failure sets (DEC-023 Consequences).
2. **The allowance is tied to the P family for opted policies.** The known-device allowance counter, its K4 overflow, the K5 device state, the micro-cap and the device Anti-Equilibrium watcher of an opted policy are kept **per lane**: `known_device_allowance_p`, `K5p`, `k5p`, `anti_equilibrium_device_p` for requests admitted by a verified binding (the P family), and the existing unsuffixed state for every other request (the U family). The K4 overflow lands on the failing request's lane key (K4P or K4U). The values are DEC-021's (Login: K5 8 / 12 / 16, allowance 8, micro-cap 8; OTP: allowance 2) and are not changed (DEC-025 9.1).
3. **The known device on the strangers' door is bounded by the account bucket only** (DEC-025 5.1). On the U family of an opted policy the DEC-021 allowance and K4 overflow enforce nothing (no account-scoped candidate persists on U): the measured bound for a known device that lost its credential is 51 verifier reaches in 24 h against DEC-021's 45 / 44 / 45 (G5 v2 F-G5-04, `MEASURED`). This is a declared cost of this record for opted policies and of no other.
4. **K5 material for opted policies.** K5 is `account + device material`, where the material is the client instance id (`"id1|" + id`, hashed by the resolver, no UA and no `clientFingerprint`) when the request carries one, and today's fingerprint material otherwise. For policies that do not opt in, the material is DEC-021's.

**Consequences added to DEC-021's.** The declared costs of DEC-025 that touch this scope (the bucket bound of item 3, F-G5v2-05, the `UAX-NP-K` curve) are restated here by reference; a browser update is the same device for K5 on the id material (`UAC12` equals `KDL`: the owner's `UAC` denials 6 → 9, G5 v2). A device that starts sending an id has a one-time K5 restart (§6, M4). Every admission bound is absent in degraded mode.

**Alternatives Not Adopted (added).** Splitting the allowance per lane for non-opted policies (would redefine DEC-021 for them); an in-place edit of DEC-021 (Decision Governance §9); superseding DEC-021 before a policy can opt in (DEC-024 "Not adopted").

**Relationship to Other Decisions.** DEC-024 (this record is its point 4); DEC-023, DEC-025 (decided inputs); DEC-022 and DEC-019 (their activation and exit are separate 5-c conditions, §9.3); DEC-003, DEC-007, DEC-008, DEC-015 (unchanged; effects in §4).

**Canonical Contract / Current Owner.** `docs/DECISION_MATRIX.md` §2.2.2, §2.5 including §2.5.3, §3.1.2; `docs/POLICIES.md` (Login and OTP known-device bound); `docs/KEY_STRATEGY.md` §4.6; `KnownDeviceAccountAllowancePolicyInterface`, `LoginProtectionPolicy`, `OtpProtectionPolicy`, `RateLimiterEngine` (registration validation), `EvaluationPipeline` (enforcement), `AdmissionRuntime` (admission), and the §7 contracts.

---

## Appendix B — Source map

| Topic | Read from |
| --- | --- |
| Binding decisions | `docs/decisions/DEC-008`, `DEC-015`, `DEC-019`, `DEC-021`, `DEC-022` (Conditions), `DEC-023`, `DEC-024`, `DEC-025`; Decision Governance §3, §5, §9 |
| Mechanisms | `AP` §5–§7, §10, §11; `SF` §2.3–§2.5, §6; `ILD` §2–§6 and v2 amendments; `D8_CROSS_ACCOUNT_P_FAILURE_DESIGN.md` |
| Study code the production code must reproduce in behaviour | `g1/patches/g1-candidate.diff`, `g3/admission_budgets/patches/g3-ablation-no-k4u-persist.diff`, `g4/integrated/patches/g4-k5-separation.diff`, `g5/identity_layer/patches/g5-k5-id-material.diff`, `g5/identity_layer_v2/patches/g5v2-k4p-scope.diff` (scope `null` only), `g1/lib/admission.php`, `g7/cross_account_p/lib/g7.php` |
| Gates | `HMC` §5 (NM-1 … NM-10), `G1_FINAL_DISPOSITION.md` §3–§4, `G4v2` §4, `G5v2` §2–§3, `G7` §2–§3 |
| Code (`main@21065b2`) | `src/Service/RateLimiterEngine.php`, `EvaluationPipeline.php`, `DeviceIdentityResolver.php`, `FingerprintHasher.php`, `RateLimitOperationalReader.php`; `src/Builder/RateLimiterBuilder.php`; `src/Repository/*`, `Repository/Redis/RedisFullCapabilityStore.php`; `src/DTO/RateLimitContextDTO.php`, `DeviceIdentityDTO.php`, `RateLimitMetadataDTO.php`; `src/Enum/PolicyCapabilityEnum.php`; `src/Config/RateLimiterConfig.php`, `PolicyCapabilityProviderInterface.php`; `src/Command/RateLimitCommand.php` |
