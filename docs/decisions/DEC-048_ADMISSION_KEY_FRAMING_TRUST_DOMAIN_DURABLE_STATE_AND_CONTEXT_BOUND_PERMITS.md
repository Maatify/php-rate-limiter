# DEC-048 — Admission Canonical Key Framing (`a1`), Trust-Domain Authority, Durable Revocation State, Cohort-Bound Credentials, and Context-Bound Permits (G3)

## Decision ID

`DEC-048`

## Status

`ACTIVE` — Pre-Stable breaking boundary and G3 contractual deltas (`D1`–`D7`) approved by the Project Owner on 2026-11-26 in [#284 comment 7185799202](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7185799202) following the comparative design and constrained execution assignment in [#284 comment 7185756131](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7185756131) and the closure plan in [#281 comment 7157227625](https://github.com/Maatify/php-rate-limiter/issues/281#issuecomment-7157227625). Implementation remains subject to Direct Technical Lead Independent Implementation Review; this record is not implementation, merge, or release acceptance.

## Date

2026-11-26

## Decision Authority / Deciders

Project Owner (pre-Stable breaking-state authority, public library usability mandate, and explicit approval of G3 contractual deltas `D1`, `D4`, and `D7` in [#284 comment 7185799202](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7185799202)); Technical Lead (bounded `D1`–`D7` specification and acceptance criteria in [#284 comment 7185756131](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7185756131)).

## Scope / Concern

Closes the three Admission-layer release blockers (`A1` NUL-delimited Admission framing, `A2` physical fence/epoch namespace collision, and `A3` `binding_epoch` contract-vs-source discrepancy) and hardens Admission authority, revocation continuity across outer key-secret rotations, credential cohort/account binding, permit context commitment, and direct-store physical key framing (`D1`–`D6`).

Accompanied atomically by:
- [DEC-049](DEC-049_DURABLE_SHARED_ADMISSION_CONFIGURATION_AND_PROVEN_PENDING_PERMIT_LIMIT.md) (supersedes `DEC-038` in full so `pcfg` uses one durable `admissionStateSecret`-keyed address rather than outer current/previous lookup).
- [DEC-050](DEC-050_RETAINED_CORRELATION_POSTURE_AND_CLOSED_FENCE_PERMIT_SETTLEMENT.md) (supersedes `DEC-027` in full to record the narrow `D7` boundary for explicit permit-bearing outcomes of a healthy opted policy when the fleet fence is closed).

This record does **not** alter `E1` account keys (`DEC-046`), non-account `f1` keys (`DEC-047`), simple fixed-window keys (`DEC-013`), `LocalFallbackLimiter` (`DEC-011`/`DEC-015`), `DeviceIdentityResolver` / `FingerprintHasher`, admission budget constants (`DEC-034`/`DEC-035`), known-device bounds (`DEC-026`), or atomic claim/request-overlay/ownership/ACL/expiry guarantees (`DEC-036`, `DEC-037`, `DEC-041`, `DEC-042`, `DEC-043`, `DEC-045`).

## Context

Direct source inspection at `main@1d87036c87773baf2432a0e01f3202a99bb4930b` ([#284 comment 7185575377](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7185575377), [#284 comment 7185756131](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7185756131)) established five structural defects in the Admission layer:

1. **`A1` (NUL-delimited framing).** `AdmissionRuntime` joined scope, member, config, fence, and epoch preimages with `\0`, and `RedisAdmissionOperations` joined `verifierClass . "\0" . key` for physical scope, pending, and config keys without framing opaque inputs.
2. **`A2` (Shared fence/epoch namespace).** Both `admission_fence` and `binding_epoch` used the same physical `:adm:epoch:` family and the same `readBindingEpoch` / `bumpBindingEpoch` store methods.
3. **`A3` and revocation revival across outer rotation.** `binding_epoch` was keyed by the rotating outer `keySecret` (current else previous else `0`). After two outer key-secret rotations without a bump for an idle revoked account, both current and previous epoch keys were absent and `readBindingEpoch` returned `0`, reviving revoked epoch-0 credentials whose independent `bindingKeySecret` remained valid. Simultaneously, historical `KEY_STRATEGY.md` §4.6 mentioned `{budget group}` in the epoch preimage while the canonical identity design (`IDENTITY_LAYER_DESIGN.md` §4.3), `RUNTIME_IMPLEMENTATION_CONTRACT.md`, `DEC-034`, `USAGE_GUIDE.md`, and `AdmissionRuntime` define one account-wide epoch across budget groups.
4. **Unscoped credentials and missing account claim.** `AdmissionCredentialCodec` v1 did not bind credentials or permits to an explicit environment, trust domain, or state cohort, and verified only `subject` (which may be `correlationId`) while `AdmissionRuntime` read `bindingEpoch($accountId)`.
5. **Unbound permit settlement context and sentinel collision.** Permit tokens v1 carried only `(v, id, lane, t0, j)` and stored raw `subject` (or sentinel `'-'` when absent), allowing a valid permit issued for one source/context to be claimed under another source or colliding absent subject with literal `'-'`.

## Decision

### D1 — Explicit Trust-Domain Authority, Stable State Secret, and Unified Public Setup

1. `RateLimiterConfig` reuses `environmentScope` and gains two optional trailing parameters required **only** when an admission policy is registered:
   - `?string $admissionTrustDomain = null`: opaque non-blank identifier of the state/issuance authority shared solely by workers and the Host credential issuer for the same state (ASCII-whitespace-only rejected; opaque bytes preserved verbatim without trimming or normalization).
   - `#[\SensitiveParameter] ?string $admissionStateSecret = null`: stable high-entropy secret generated randomly by the Host, minimum **32 bytes** (`RateLimiterConfig::MIN_ADMISSION_STATE_SECRET_BYTES = 32`; checked by byte length `strlen`, not character count; length alone does not prove entropy).
2. `RateLimiterConfig` rejects blank values, an `admissionStateSecret` shorter than 32 bytes, or supplying only one of `$admissionTrustDomain` / `$admissionStateSecret` without the other. There is **no** default and **no** fallback to `keySecret`, `fingerprintSecret`, `bindingKeySecret`, or Redis namespace.
3. Non-opted consumers require neither parameter and experience zero change in configuration, keys, or runtime behavior.
4. `RateLimiterBuilder::build()` rejects any registered policy declaring `PolicyCapabilityEnum::ADMISSION_RESERVATION` when `bindingKeySecret`, `admissionTrustDomain`, or `admissionStateSecret` is absent, raising `RateLimiterException` **before** any backend command.
5. **Unified public issuer/runtime path:** `AdmissionCredentialCodec::fromConfig(RateLimiterConfig $config): self` constructs the Host issuer codec from the exact same `RateLimiterConfig` trust context used by `RateLimiterBuilder`. Hosts whose issuer does not hold outer/fingerprint secrets may also construct `new AdmissionCredentialCodec($bindingKey, $environmentScope, $admissionTrustDomain, $admissionStateSecret, $previousBindingKey)` explicitly. No alternate codec injection, generic factory, or registry is added.
6. **Secret roles and loss-of-continuity boundary:**
   - `admissionStateSecret` is stable for the lifetime of the revocation/config state and does **not** participate in routine key rotation.
   - `bindingKeySecret` / `previousBindingKeySecret` rotate independently for signing/verifying credentials and permits.
   - `keySecret` / `previousKeySecret` and `fingerprintSecret` / `previousFingerprintSecret` rotate independently as before.
   - Changing `admissionStateSecret` or `admissionTrustDomain`, or losing persistent store continuity, requires establishing a **fresh isolated scope/cohort** under operator authorization. Live migration, dual-read/dual-write, live rollback, mixed workers, Redis deletion, and reusing a state secret or cohort after state loss are unsupported. The library does not claim detection of partial state loss or split configuration between deployments that intentionally share full authority, nor protection against a malicious authorized Host issuer.

### D2 — Canonical Admission Framing (`a1`) and Closed Purpose Registry

All Admission logical preimages use `KeyPreimageFraming::encodeTuple()` (`u32be` checked byte lengths and field counts in `0..4294967295` before `pack()` / HMAC / store, zero trimming or normalization) with Admission-`a1`-exclusive magic headers and HMAC subkey domains (`AdmissionKeyDeriver`):

| Derivation | Secret | Key Domain | Magic (`\x00`-terminated) | Tuple (`f_1 .. f_N`) | Logical Form |
|---|---|---|---|---|---|
| Cohort | `admissionStateSecret` | `maatify.rate-limiter.key-domain.admission.cohort.a1` | `maatify.rate-limiter.admission.cohort.a1\0` | `('a1', env, authority)` | `<64hex>` |
| Scope / Member | outer `keySecret` (current or previous) | `maatify.rate-limiter.key-domain.admission.scope.a1` | `maatify.rate-limiter.admission.scope.a1\0` | `(cohort, env, authority, group, purpose, value, label)` | `a1.scope.{purpose}.<64hex>` |
| Fleet Fence | outer `keySecret` (current or previous) | `maatify.rate-limiter.key-domain.admission.fence.a1` | `maatify.rate-limiter.admission.fence.a1\0` | `(cohort, env, authority, group)` | `a1.fence.<64hex>` |
| Binding Epoch | `admissionStateSecret` only | `maatify.rate-limiter.key-domain.admission.epoch.a1` | `maatify.rate-limiter.admission.epoch.a1\0` | `(cohort, env, authority, accountId)` | `a1.epoch.<64hex>` |
| Shared Config (`pcfg`) | `admissionStateSecret` only | `maatify.rate-limiter.key-domain.admission.config.a1` | `maatify.rate-limiter.admission.config.a1\0` | `(cohort, env, authority, group, 'pcfg')` | `a1.pcfg.<64hex>` |
| Permit Context Commitment (`ctx`) | `admissionStateSecret` only | `maatify.rate-limiter.key-domain.admission.permit-ctx.a1` | `maatify.rate-limiter.admission.permit-ctx.a1\0` | `(cohort, authority, env, group, operation, canonicalSource, hasAcc, acc, hasSub, sub, hasIns, ins, hasCred, credSha256)` | `<64hex>` |
| Permit Record / Member ID | `admissionStateSecret` only | `maatify.rate-limiter.key-domain.admission.permit-record.a1` | `maatify.rate-limiter.admission.permit-record.a1\0` | `(cohort, rawPermitId, ctx)` | `a1.permit.<64hex>` |

The closed 12-purpose scope/member registry (`AdmissionScopePurpose`) permits only:
`src`, `acct`, `ufail`, `pacct`, `pacctd`, `pproof`, `pproofd`, `pfail`, `ppend`, `pdev` (label `'set'`), `member`, `dev` (all other 11 have label `''`). Unknown purposes or label mismatches throw `RateLimiterException` before framing or HMAC. `proofRef(jti)` remains `substr(hash('sha256', $jti), 0, 32)`.

### D3 — Explicit Physical Layout (`adm:a1`), Checked Physical Wrappers, and Separated Fence vs Epoch Store Operations

1. Official Redis Admission keys move to the explicit layout `maatify:rate-limiter:v1:{sha256(namespace)}:adm:a1:...` inside the existing namespace hash tag (preserving single Redis hash slot, request overlay, direct atomic claim, ownership, ACL preflight, and expiry semantics). Legacy `:adm:` keys are never read.
2. Physical two-field wrappers in `RedisAdmissionOperations` frame `(verifierClass, key)` via `KeyPreimageFraming::encodeTuple()` with a fixed family-specific magic header:
   - Scope (`c`, `d`, `s`, `b`): `maatify.rate-limiter.redis.adm.a1.scope.{code}\0` → `:adm:a1:{code}:{sha256(framed)}`
   - Pending (`p`): `maatify.rate-limiter.redis.adm.a1.pending\0` → `:adm:a1:p:{sha256(framed)}`
   - Shared config (`cfg`): `maatify.rate-limiter.redis.adm.a1.config\0` → `:adm:a1:cfg:{sha256(framed)}`
3. Single-field physical keys hash independently under dedicated families:
   - Permit: `:adm:a1:permit:{sha256(permitId)}`
   - Fleet fence: `:adm:a1:fence:{sha256(key)}`
   - Binding epoch: `:adm:a1:epoch:{sha256(key)}`
   No physical family is ever selected by regex over opaque input.
4. `AdmissionReservationStoreInterface` separates fleet fence operations from durable binding epoch operations:
   - `readAdmissionFence(string $key, ?string $previousKey): int`
   - `bumpAdmissionFence(string $key, ?string $previousKey): int`
   - `readBindingEpoch(string $key): int`
   - `bumpBindingEpoch(string $key): int`
   Both reuse one internal Lua monotonic-counter primitive in `RedisAdmissionOperations` (no duplicated counter logic). Before the first write, the Lua primitive checks both the Lua exact limit (`2^53 - 1`) and the caller's native integer maximum (`PHP_INT_MAX` on 32-bit PHP), returning a typed failure without writing or wrapping on overflow.

### D4 — Durable Account-Wide Binding Epoch and Durable Shared Config; Outer-Secret Fleet Fence Retained

1. `binding_epoch` is **account-wide** across all budget groups within `(cohort, env, authority)`, keyed by `admissionStateSecret`, with `absent = 0` inside continuous healthy state, atomic monotonic bump, no TTL, no write on read, and no lazy seeding. Reconciles `A3` with `IDENTITY_LAYER_DESIGN.md` §4.3, `RUNTIME_IMPLEMENTATION_CONTRACT.md`, `DEC-034`, and `USAGE_GUIDE.md`. Public `AdmissionBindingEpochInterface::bindingEpoch(string $accountId)` and `AdmissionBindingControlInterface::revokeAccountBinding(string $accountId)` remain unchanged; `K4P` is not reset on password change.
2. `pcfg` is a single durable non-expiring key under `admissionStateSecret` with no `previousKey`; governed formally by [DEC-049](DEC-049_DURABLE_SHARED_ADMISSION_CONFIGURATION_AND_PROVEN_PENDING_PERMIT_LIMIT.md) (superseding `DEC-038`).
3. Fleet fence stays operator-controlled and outer-secret `current`-else-`previous`, requiring re-opening before dropping `previousKeySecret`.

### D5 — Cohort-Scoped Credentials (`v=2`) with Explicit Account + Subject Binding

1. `AdmissionCredentialCodec` emits and accepts **only** `v = 2` credentials with exact JSON keys `['v', 'acc', 'sub', 'grp', 'ins', 'jti', 'exp', 'bat', 'epo', 'kid']`. `v = 1` credentials are unconditionally rejected even with a valid v1 signature and active/previous binding key.
2. Credential MACs authenticate `KeyPreimageFraming::encodeTuple(MAGIC_CREDENTIAL_MAC, [cohort, env, authority, 'credential', payloadPart])` under subkey `HMAC(KEY_DOMAIN_CREDENTIAL_MAC, bindingKey)`; permit MACs use `MAGIC_PERMIT_MAC`, purpose `'permit'`, and `KEY_DOMAIN_PERMIT_MAC`. Key IDs (`kid`) are derived from `KeyPreimageFraming::encodeTuple(MAGIC_KEY_ID, [cohort, env, authority])` under `bindingKey`.
3. `AdmissionCredentialDTO` carries both explicit `account` (`acc`) and `subject` (`sub`), and `AdmissionCredentialCodec::verify()` verifies both (`WRONG_ACCOUNT` and `WRONG_SUBJECT` verdicts). Any failed check (`MALFORMED`, `INVALID_SIGNATURE`, `WRONG_ACCOUNT`, `WRONG_SUBJECT`, `WRONG_BUDGET_GROUP`, `WRONG_INSTANCE`, `EXPIRED`, `STALE_EPOCH`) yields unproven lane `U`, never an exception.

### D6 — Context-Bound Permits (`v=2`)

1. Signed permit payload is exact JSON keys `['v', 'id', 'lane', 't0', 'j', 'ctx']` with `v = 2`, `id` = 32-hex random bytes, `j` = bounded `proofRef` or `null`, `ctx` = 64-hex `permitContextCommitment` under `admissionStateSecret`. Total encoded permit length is bounded to **512 characters** (`AdmissionCredentialCodec::PERMIT_MAX_LENGTH = 512`), enforced on both signing and verification.
2. Optional identity presence (`account`, effective `subject`, `clientInstanceId`, `admissionCredential`) is encoded explicitly with `'1'`/`'0'` presence flags; no sentinel `'-'` is shared with a real identity, and `admissionCredential` enters `ctx` solely as `sha256(credential)` (never raw).
3. Both `reserve()` and `applyOutcome()` derive the store permit record id and counter member id via `permitRecordId(rawId, ctx)` (`a1.permit.<64hex>`), and store `$ctx` in the permit record's `subject` field so the store's atomic claim check binds the full settlement context without any raw identity or token entering Redis keys or fields.
4. `applyOutcome()` verifies token signature, `v = 2`, payload shape, and `hash_equals($payload['ctx'], $expectedCtx)` **before** calling `claimAdmissionPermitWithOutcome()` or scoring. An invalid/foreign/v1 token returns `permit_unknown`; a valid token with a mismatched context returns `permit_rejected` without consuming the real permit record, writing/refunding budgets, or scoring.
5. `D = 30 s`, `G = 600 s`, atomic claim outcomes, deduplication, refunds, pending-permit release, and failure semantics are unchanged; `binding_epoch` is not re-verified on settlement (a permit settles an already-admitted attempt).

### Independent Synthetic Reference Vectors (`a1` / `v2`)

Computed independently from the framing specification above with synthetic inputs (`env = "prod"`, `authority = "auth.example.test"`, `admissionStateSecret = "state-secret-vector-0123456789abcdef"`, `outerSecret = "outer-secret-vector"`, `bindingKeySecret = "binding-secret-vector"`, `budgetGroup = "login"`, `operation = "login_protection"`, `ip = "198.51.100.10"`, `account = "acct-42"`, `correlationId = "corr-42"`, `clientInstanceId = "inst-42"`, `admissionCredential = "cred-sample"`, `rawPermitId = "0123456789abcdef0123456789abcdef"`, `payloadPart = "payload-part"`):

| Derivation | Expected Output |
|---|---|
| `cohort` | `fca526f950d23c289a2b1aafde35bed1da3b86727fa6055d840bcd50ec1b58e0` |
| `fence("login")` | `a1.fence.988d60a1692fbcd94001cc6d5a8688bfacfb569886511c468bb24b77409132b8` |
| `epoch("acct-42")` | `a1.epoch.beaef2f189b290a8366c3e9d1ecb64fb15301cb2397f1aa18db89b4734bd5fc6` |
| `pcfg("login")` | `a1.pcfg.9c3bb9e31ad86dd88e8e08fce17f0ab3654bc2528d679e3fb9196b28a13286e9` |
| `scope.src("198.51.100.10", "")` | `a1.scope.src.690e990f4b80cff0eae2ec81fb7a412cef991082e18f0e9de86a23eea6482870` |
| `scope.acct("acct-42", "")` | `a1.scope.acct.9492185e98f35bb2ddac074425a7796343930dae346904a0ac387786f9a937f9` |
| `scope.ufail("198.51.100.10", "")` | `a1.scope.ufail.76ca6f5160fff1f4feb9cc881a18966f03eeb046fe62f400e5d408162e18ff65` |
| `scope.pacct("acct-42", "")` | `a1.scope.pacct.3b8d419be8fd6528e49bb6a9d7cff7f48a59220dc603fc4101fa15f3eb3ec721` |
| `scope.pacctd("acct-42", "")` | `a1.scope.pacctd.b61e064a14f0584b766dd413a51b5a0e92a278ce430554184828cc2007938539` |
| `scope.pproof("proof-ref-1", "")` | `a1.scope.pproof.7a0d71e7a7fe96fc654dc3cf81dde69ea7f76f27db4ad573e825ee2a1ba11cc0` |
| `scope.pproofd("proof-ref-1", "")` | `a1.scope.pproofd.e5d7eaf946614b2debf7e8aa5b196b4c11d251f94f3a0311924510ad91cf056c` |
| `scope.pfail("198.51.100.10", "")` | `a1.scope.pfail.1350418c2427331d8a239779463da31c7fcc35494e8312e63e425317294af6c8` |
| `scope.ppend("198.51.100.10", "")` | `a1.scope.ppend.a11a85a65178a66df37aabcb1c4a7a0d92901945f17a6219a078f218ee3a8a4f` |
| `scope.pdev("acct-42", "set")` | `a1.scope.pdev.2bfd07ca989fe93905a7e6317b96d5821e3bfc4dd95a16775c1a4f1b5403e520` |
| `scope.member("acct-42", "")` | `a1.scope.member.99b91aa598f462c239ae5cd3f414584ff0db5df4e1f5db8cdfe0e4f7a8991cc4` |
| `scope.dev("fp-hash-1", "")` | `a1.scope.dev.8500fa836f408900035fb1c67efb41152fb37d685946b2af3fe1b8b6bdad0e4d` |
| `permitContextCommitment` | `2f969c88612631042796908c6a216f6e08eb7f82fa8706078f9841461c085fdb` |
| `permitRecordId` | `a1.permit.dde2a67975699c6fe577645b8cbc2e01b832ed28396cd986f13b58d95e59b081` |
| `bindingKeyId` | `9e621a9c10ab6154` |
| `credentialMac` (hex) | `19818dafb9c3750b597cb305f6d52bd2a37a1033ea0740e2fe4414079a52c784` |
| `permitMac` (hex) | `253b52e8e267b4fded3635a1498df0f85ffea19a498de7e1d40d86a6a61635ff` |

## Rationale

Separating stable state authority (`admissionTrustDomain` + `admissionStateSecret`) from rotating operational secrets (`keySecret`, `fingerprintSecret`, `bindingKeySecret`) eliminates revocation revival and shared-config drift across routine rotations without introducing lazy read-writes, unbounded secret history, unkeyed account hashes, or mass holder invalidation. Canonical `KeyPreimageFraming::encodeTuple()` across logical keys, token MACs, permit context commitments, and physical Redis wrappers closes `A1`–`A3` and direct-store framing ambiguity unconditionally within `0..2^32 - 1`. Providing `AdmissionCredentialCodec::fromConfig(RateLimiterConfig)` satisfies the public library usability mandate with one explicit configuration object and zero hidden defaults or extra abstractions.

## Consequences

1. **Pre-Stable breaking Admission storage and token boundary.** All Admission keys move to `:adm:a1:*`, all credentials and permits require `v = 2` with cohort/trust-domain/context binding, `AdmissionReservationStoreInterface` separates fence and epoch methods, `AdmissionSharedConfigDTO` drops `previousKey`, and `RateLimiterConfig` / `AdmissionCredentialCodec` require explicit `admissionTrustDomain` and `admissionStateSecret` (≥ 32 bytes) for Admission.
2. **Non-opted consumers unaffected.** Policies that do not declare `PolicyCapabilityEnum::ADMISSION_RESERVATION`, E1 account state (`e1:`), non-account `f1` state (`v1:`), simple fixed-window state (`v1:budget`), and `LocalFallbackLimiter` retain identical configuration, keys, and behavior.
3. **Unsupported operations.** Mixed pre-G3 / post-G3 workers, live rollback, cross-version token acceptance, live migration, dual-read/dual-write, Redis deletion, reissuing credentials from legacy claims, and reusing a cohort/state secret after loss of persistence continuity are unsupported.
4. **Release status.** This record closes G3 design/implementation scope only; rc.4 / Stable qualification remains gated by G4, `#185`, and `#180`.

## Canonical Contract / Current Owner

`docs/KEY_STRATEGY.md` (§4.6, §4.8, §10), `docs/guides/USAGE_GUIDE.md`, `RATE_LIMITER_PACKAGE_REFERENCE.md`, `RateLimiterConfig`, `RateLimiterBuilder`, `AdmissionScopePurpose`, `AdmissionKeyDeriver`, `AdmissionCredentialDTO`, `AdmissionCredentialCodec`, `AdmissionRuntime`, `AdmissionReservationStoreInterface`, `RedisAdmissionOperations`, `RedisFullCapabilityStore`.

## Supersedes

None. (Paired atomically with [DEC-049](DEC-049_DURABLE_SHARED_ADMISSION_CONFIGURATION_AND_PROVEN_PENDING_PERMIT_LIMIT.md) superseding `DEC-038` and [DEC-050](DEC-050_RETAINED_CORRELATION_POSTURE_AND_CLOSED_FENCE_PERMIT_SETTLEMENT.md) superseding `DEC-027`.)

## Superseded By

None.
