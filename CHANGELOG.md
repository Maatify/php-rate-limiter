# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed (DEC-021)
- Login and OTP bound known devices (trusted session or previously verified device):
  K5 thresholds `8 / 12 / 16`, and a fingerprint-independent known-device account
  allowance (Login 8, OTP 2 per fixed 24h epoch); each known-device failure beyond it also
  adds the K4 new-device delta. This is a per-failure scoring rule, not an absolute cap.
  A trusted session failing on its own account now reaches the verifier 4 times in a 72 s
  Login burst instead of 25; a stable known device failing every 300 s for 24 h reaches it
  44 times instead of 268 (an unknown device: 37).
- A score level derived from K5 is persisted on the K5 key only and no longer on the account
  key K4. This also applies to custom policies that define K5 thresholds.
- Anti-Equilibrium is source-scoped with one event per issued `SOFT_BLOCK`: a
  `SOFT_BLOCK` that comes from K5 alone counts on a device watcher and escalates to a K5
  `HARD_BLOCK` L2 on that device only; every `SOFT_BLOCK` with an account source (mixed
  included) keeps the account-wide escalation on K4.

### Added
- The admission runtime and the engine integration (DEC-023, DEC-024, DEC-025), **dormant, not selectable through
  the builder**: lane resolution from a verified credential (any failed check is the unproven lane), the
  all-or-nothing reservation at an allowed `checkOnly`, the capacity refusal (`SOFT_BLOCK`, `blockLevel` 1, six
  causes), a permit on every admission (opaque, at most 512 characters) that the outcome commands must carry (each
  outcome is scored once and only with its permit; an admission whose outcome never arrives changes no bound, a Host
  duty of DEC-023 A-2; a success on the proven door never removes a cross-account failure), the
  fleet fence, `revokeAccountBinding()` and the new read side `AdmissionBindingEpochInterface::bindingEpoch()` on the
  engine and the composite runtime. New internal shapes: `AdmissionBudgetsDTO`, `AdmissionLaneDTO`,
  `SourcePrefix`; two additive methods on `AdmissionCredentialCodec` (`signPermitToken`, `verifyPermitToken`).
  `EvaluationPipeline` delegates its source derivation to `SourcePrefix` with no behaviour change.
- Settlement of expired admission permits in the admission store is **optional per set-size scope and off by default** (optional trailing `AdmissionScopeDTO::$settlesOnExpiry`, default `false`; without it the store keeps the tombstone semantics of 5-b2 and the pending key family `p` is neither written nor read). When enabled (dormant, no policy can opt in): a reservation that carries a permit records a pending member in each failure set it names, and the member counts as one failure from the permit `deadline` (never before, never twice); an explicit failure counts at once, a success cancels a not-yet-due member, counters and buckets are untouched. New optional trailing `AdmissionScopeDTO::$successKeepsSettled` (default `false`, set-size scopes only) and a reserved `p` key family. No interface method was added or changed.
- `KnownDeviceAccountAllowancePolicyInterface` (opt-in, authentication capability) for
  custom authentication policies. An active allowance requires positive K5 failure delta
  and K5 thresholds; `API_OVERUSE` cannot be combined with it; `null` disables it.
- Admission-reservation shapes (DEC-023, DEC-024, DEC-025), all **dormant, not selectable
  through the builder**: no official preset declares them, `RateLimiterBuilder::build()` never
  composes an admission runtime, and registering a policy that declares the capability fails.
  Existing behavior is unchanged byte for byte. Each shape is additive:
  - `PolicyCapabilityEnum::ADMISSION_RESERVATION` (authentication-related; cannot be combined
    with `API_OVERUSE`).
  - `AdmissionReservationPolicyInterface` (`getAdmissionHostMode()`, `getAdmissionBudgetGroup()`)
    and `AdmissionHostModeEnum` with the single case `PRE_AND_OUT`. `BlockPolicyInterface` is
    unchanged.
  - Store interfaces `AdmissionReservationStoreInterface`, `AdmissionPermitStoreInterface` and
    `AdmissionCapableFullStoreInterface`, with their DTOs and enums. `RedisFullCapabilityStore`
    implements them (below); nothing in the engine or the pipeline calls them yet.
    `FullCapabilityStoreInterface` is unchanged.
  - `RateLimitContextDTO`: optional trailing `clientInstanceId` and `admissionCredential`,
    serialized only when present and ignored by every policy that did not opt in.
  - `DeviceIdentityDTO`: optional trailing `deviceIdHash` and `previousDeviceIdHash`, produced by
    `DeviceIdentityResolver` when an instance id is supplied; consumed only by the evaluation
    pipeline for a call that carries an admission lane (below), which nothing passes yet.
  - `AdmissionCredentialCodec` and `AdmissionCredentialDTO` (encode and verify only; the Host
    decides when to issue), with `RateLimiterConfig` optional trailing `bindingKeySecret` and
    `previousBindingKeySecret`.
  - `AdmissionBindingControlInterface` (`revokeAccountBinding()`), interface only.
  - `RateLimitCommand` optional trailing `permit` (outcome commands only) and
    `RateLimitMetadataDTO` optional trailing `permit`, shapes only.
- `EvaluationPipeline::process()` and `claimPostPunishmentReentry()` take an optional last
  parameter, the admission lane (`Enum\AdmissionLaneEnum`, internal: `U`, `P`), and
  `RateLimitOperationalReader::read()` (the concrete class only; its interface and the composite
  are unchanged) takes the same optional lane. `null`, the default, is today's behaviour byte for
  byte and ignores any instance id; **no production code passes a lane yet** (the engine and the
  builder are untouched), and a lane is refused for a policy that did not declare the admission
  capability. With a lane the pipeline applies, as explicit rules: no collective churn,
  dilution or credential-spray rule on either lane; on `U` no account-scoped refusal or
  persistence and no 24 h budget candidate (device-scoped K5 parts kept); on `P` its own account
  key `k4p` (rule A, not cleared) and device key `k5p`, the `_p` purposes of the known-device
  allowance, device and account Anti-Equilibrium watchers and budget cooldown, the `k5p`
  micro-cap, and no device-cap observation, flood or K1/K2/K3 block, threshold or level. K5
  material is the instance-id component when present, else the fingerprint component, selected
  without re-hashing; `FingerprintHasher` is unchanged. See `docs/KEY_STRATEGY.md` §3.4 and §3.5.
- `RedisFullCapabilityStore` implements `AdmissionCapableFullStoreInterface` and
  `AdmissionPermitStoreInterface` (DEC-024), still **dormant**: nothing calls the new operations,
  the builder composes no admission runtime, and no existing Lua script, store method or test
  changed. One Lua script per operation, the caller's `now` as a parameter, relative TTLs, no
  `TIME` call:
  - `reserveAdmission`: validates the type and fields of every key before the first write, then
    admits or refuses all scopes together (counter gates with an optional previous generation that
    is summed and never written, a device-member gate over a current and a previous set, set-size
    gates, a credit bucket, and the permit record). A refusal writes and prunes nothing and names
    the first refusing scope in request order.
  - `applyAdmissionOutcome`: a failure adds the account to the failure set; a success removes it
    and refunds the bucket or the counters (never below zero). No idempotency is claimed.
  - `readBindingEpoch` / `bumpBindingEpoch`: one integer per account, current else previous else 0;
    a bump writes the current key as `max(current, previous) + 1`.
  - `claimAdmissionPermit`: one compare-and-set per claim, answering `accepted`, `duplicate`,
    `conflict`, `rejected`, `late` or `unknown`.
  - Malformed stored state is a `RateLimiterException`; an outage reaches the caller as the
    executor's own `BackendFailureException`; other throwables propagate unchanged; no fallback.
  - `docs/KEY_STRATEGY.md` §4.6 (the `binding_epoch` purpose and the reserved `_p` purposes) and
    the new §4.8 (admission keys) describe the key layout.

### Fixed
- Bounded local-fallback denials (`fallback_limit_exceeded`) no longer return a
  constant `retryAfter` of 60. The value is now the largest remaining
  fixed-window time among the dimensions that denied the evaluation, on both the
  typed backend-failure path and the open-circuit degraded path. Quotas,
  denial decisions, failure modes, and public contracts are unchanged.

## [1.0.0-rc.3] - 2026-10-07

### Added
- Maintained Release Artifact Verification tooling (`composer release:verify-artifact`)
  that verifies a candidate commit against the release contract and produces
  fail-closed qualification evidence.
- Maintained Published Artifact Verification tooling
  (`composer release:verify-published-artifact`) that verifies the artifact
  actually delivered for a published version against its qualified evidence.
- Release verification documentation in `docs/RELEASE_VERIFICATION.md`.

### Changed
- Upgraded the selective pinned engineering standards adoption to the current
  pinned upstream commit.
- Release qualification and publication-integrity verification now fail closed:
  inspection-only and caller-supplied values never produce a qualifying result.

The public runtime API and runtime behavior of the package are unchanged from
`1.0.0-rc.2`.

## [1.0.0-rc.2] - 2026-10-06

### Fixed
- The official Redis adapter now interprets native `ext-redis`/phpredis
  `rawCommand()` replies without Host normalization (Issue #91, DEC-018): a
  healthy `PING` is exactly `'PONG'` or exactly `true`, which restores
  circuit-breaker recovery and `RateLimitOperationalSnapshotDTO::$backendHealthy`;
  circuit state is read through an internal atomic tagged Redis read, so the
  first request on an empty Redis works through direct phpredis while a Redis
  error such as `WRONGTYPE` stays an explicit failure instead of being treated
  as absent state. Any other reply shape is still unhealthy/malformed, failure
  provenance is unchanged, and generic storage contracts are unchanged.

## [1.0.0-rc.1] - 2026-09-30

### Added
- Added Builder-coordinated Production Default Operational Read (DEC-012):
  `RateLimiterBuilder::buildOperationalReader()` returns the typed
  `CompositeRateLimitOperationalReaderInterface`, including simple fixed-window
  operational inspection with Current-before-Previous, read-only resolution and
  no migration or mutation during reads. The existing
  `RateLimitOperationalReaderInterface` remains the Advanced Path.
- Added DEC-013 weighted simple fixed-window consumption. `consume()` now
  accepts a positive caller-supplied `$cost = 1`; existing two-argument calls
  remain compatible, while third-party interface implementers must accept the
  evolved optional parameter. Weighted costs accumulate in the same fixed
  window, including over-limit costs, and preserve the existing rotation,
  FAIL_CLOSED, and operational-read semantics.
- Added a first-class generic/simple fixed-window throttling capability
  (DEC-009): `Config\SimpleThrottlePolicyInterface`,
  `Config\FixedWindowThrottlePolicy`, `DTO\SimpleRateLimitResultDTO`,
  `Service\SimpleRateLimiterInterface`, and its production implementation
  `Service\FixedWindowSimpleRateLimiter`. The initial simple-throttling
  capability was fixed-window, unit cost, one policy-defined limit and interval,
  one atomic `consume()`, and
  FAIL_CLOSED only; it reuses the existing atomic budget-epoch persistence
  primitives (`RateLimitStoreInterface::getBudget()`/`incrementBudget()`,
  `BudgetSeedStoreInterface::incrementBudgetWithSeed()` for rotation
  continuity) under a distinct package-owned key namespace, so no new storage
  backend family is introduced and simple-throttle state cannot collide with
  score/budget state.
- Evolved the default composition surface (DEC-010, supersedes DEC-004):
  `RateLimiterBuilder::withSimpleThrottlePolicy(SimpleThrottlePolicyInterface)`
  registers simple policies with the same same-name-replace/new-name-append
  semantics as `withPolicy()`; the registry starts empty. `build()` now
  returns the new `Service\CompositeRateLimiterRuntimeInterface`
  (implemented by `Service\CompositeRateLimiterRuntime`), which extends both
  the existing `RateLimiterRuntimeInterface` and the new
  `SimpleRateLimiterInterface` and remains assignable to
  `RateLimiterRuntimeInterface` for every existing consumer.
  `RateLimiterEngine` and its constructor are unchanged. See the new
  `docs/SIMPLE_THROTTLING.md` for the complete contract,
  `examples/simple-fixed-window.php` for a runnable example, and
  `docs/KEY_STRATEGY.md` §4.7 for the key contract.

### Changed
- Enforced exact PHP-integer representability for simple fixed-window reset
  boundaries (DEC-014): unrepresentable boundaries and backend-impossible
  expiries are rejected before enforcement state changes, and Operational Read
  applies the same checked arithmetic. Public DTO and storage signatures remain
  unchanged.
- Reconciled semantic clock authority (DEC-016): capabilities receiving
  caller-supplied semantic `now` use it as the time authority and Redis Lua does
  not substitute Redis `TIME`; primitives without caller semantic time may
  remain Redis-time authoritative. Public interfaces and custom/fixed Clock
  behavior remain coherent.
- Enforced the non-negative semantic Unix timestamp domain (DEC-017): negative
  caller timestamps fail before backend mutation or use, persisted semantic
  timestamps are validated as canonical non-negative integers, epoch `0`
  remains valid where applicable, and malformed persisted temporal state fails
  explicitly without silent repair or overwrite.
- Clarified the failure contract (DEC-015): public
  `BackendFailureException` is the explicit provenance for operational backend
  failures that may enter score-runtime circuit/fallback; configuration,
  unknown, corrupt-state, and programming failures retain explicit exception
  propagation. The package retains a bounded process-local emergency circuit
  when persistent circuit state is unavailable, caps local fallback subjects,
  and preserves corrected circuit re-entry accounting and rolling failure-count
  semantics. Simple fixed-window failures remain governed separately by
  DEC-013.
- Hardened official Redis budget persistence to use exact signed-integer arithmetic
  instead of Lua floating-point count arithmetic, covering weighted Current
  increments, the rotation seed path, exact large-count readback, overflow handling,
  and existing seed branch precedence. Public API: unchanged by this Redis
  hardening. Storage interfaces: unchanged.
- Added the typed `PolicyCapabilityEnum` extension contract for reusable custom
  policies, and replaced the initial closed-enum `FailureFallbackProfileEnum`
  fallback contract with a generic typed
  `FailureFallbackConfigurationProviderInterface` returning a
  `FailureFallbackConfigurationDTO` of `FailureFallbackRuleDTO` values
  (`FailureFallbackDimensionEnum::ACCOUNT`, `IP_PREFIX`, or
  `IP_PREFIX_NORMALIZED_USER_AGENT`, each with a positive limit and window).
  `LoginProtectionPolicy`, `OtpProtectionPolicy`, and `ApiHeavyProtectionPolicy`
  remain zero-configuration: they resolve their official locked caps
  internally from the retained `FailureFallbackProfileEnum` factory, now an
  internal preset resolver rather than the public contract. A direct custom
  reusable policy may compose its own bounded fallback configuration with
  values independent of every official preset, validated and evaluated
  through the exact same `LocalFallbackLimiter` runtime — there is no
  separate official/custom fallback code path, and `LocalFallbackLimiter` no
  longer holds a duplicate hard-coded copy of the official numeric values.
  Fallback state remains namespaced by policy identity only. See DEC-011
  (amended) for the full rationale.

### Added
- Added DEC-007 generation-bound authentication K4 lifecycle for opt-in Login
  and OTP policies, including typed lifecycle storage, bounded public claims,
  early Builder capability validation, and the composite runtime surface.
- Custom `FullCapabilityStoreInterface` implementations must add the
  `PunishmentLifecycleStoreInterface` methods. Pre-DEC-007 writers must not
  concurrently write the same logical namespace during migration.

### Changed
- Added the official optional built-in Redis full-capability store. It uses a
  Host-supplied command executor, supports one logical non-clustered Redis
  server, preserves the backend-agnostic aggregate contract, and adds no Redis
  client or `ext-redis` runtime dependency.
- Added full-capability storage composition. `FullCapabilityStoreInterface`
  aggregates the budget-seed, bounded snapshot-rotation, circuit-probe, and
  hard-block-cycle contracts without adding methods, and
  `RateLimiterBuilder::fromFullCapabilityStore()` composes one host store across
  those boundaries while keeping the failure-signal emitter separate. The
  existing multi-store constructor remains source-compatible, and the aggregate
  storage contract remains backend-agnostic.
- Implemented multiple-block-cycle decay pause. Persisted L2+ blocks now
  use the additive `HardBlockCycleStoreInterface` for atomic Current-only block
  persistence, real cycle classification, rolling six-hour K1/K2/K3/K4/K5
  histories, fixed 600-second pauses, rotation adoption, and read-only lazy
  decay accounting. Base-only stores remain compatible for reads and L1 writes
  but fail explicitly before an L2+ block write. `DecayCalculator` preserves
  source compatibility through trailing pause arguments.
- Implemented IPv6 adaptive aggregation. IPv6 enforcement now uses
  canonical `/64` K1 only; bounded `/48`, `/40`, and `/32` correlation scopes
  activate at exact `2/2`, `4/4`, and `8/8` child thresholds within a fixed
  600-second window, without hierarchy WATCH decisions or macro RateLimitStore
  score/block state. Spray and Churn observe active macro scopes while persisting
  only current `/64` K1 or `/64 + UA` K2 enforcement. Hierarchy keys and members
  are policy/environment/version/purpose-separated HMAC references with bounded
  outer-key rotation continuity; Dilution remains canonical `/64`-member based.
- Documented the public operational API contraction from serialized
  `k1,k2,k3,k4,k5,k1_48,k1_40,k1_32` to `k1,k2,k3,k4,k5`. The removed macro
  fields were enforcement-scope projections; `/48`, `/40`, and `/32` remain
  internal correlation-detection state only.
- Follow-up hardening: New Device Flood now evaluates from the sixth
  admitted/account-scope observation through the rejected overflow path, while only
  admitted devices may persist K5 state. All bounded distinct-store results are validated
  centrally before classification; malformed counts or rejected-before-cap results fail
  explicitly with a package-owned exception.
- Completed bounded ephemeral and fingerprint-correlation hardening. The additive
  `BoundedCorrelationStoreInterface` and `BoundedCorrelationRotationStoreInterface` now
  provide atomic fixed-TTL distinct admission with `BoundedDistinctResultDTO`; device-cap,
  churn, dilution, and credential-spray scopes use opaque domain-separated HMAC references
  and explicit caps. Current/previous generation observations use read-only previous state
  and a TTL-capped current bridge. Ephemeral overflow performs one real-key observation,
  never creates a fake K3/K5 identity, preserves active-block checks and K4/flood handling,
  and suppresses per-fingerprint dilution state while retaining bounded churn.
- Isolated account/policy auxiliary state for repeated missing fingerprints, Anti-Equilibrium,
  and New Device Flood with the locked policy/environment/version HMAC namespace. Current
  generation is writable, previous outer-key state is read-only, raw AccountID no longer
  crosses storage boundaries, and API Heavy does not create a repeated-missing marker.

### Added
- Implemented the default composition surface. Added the immutable
  `RateLimiterConfig` contract and package-wide `RateLimiterBuilder`, which
  require the four Host integration boundaries, compose the existing runtime
  graph with a shared UTC default clock, register the Login/OTP/API Heavy policy
  presets, and preserve independent outer/fingerprint rotation inputs.
- Implemented Circuit Breaker state-machine recovery: OPEN requests
  short-circuit shared-backend work, recovery uses leased read-only health probes
  through HALF_OPEN, and the rolling re-entry guard is authoritative across all
  circuit states with exactly-once transition signals and remaining Retry-After.
  Added the additive `CircuitBreakerProbeStoreInterface`; the existing
  `CircuitBreakerStoreInterface` remains source-compatible.
- Implemented distributed account-attack correlation for Login and OTP pre-checks.
  The new snapshot DTO and additive snapshot store capabilities return the complete bounded
  logical K5 member set with fixed expiry, validate it fail-closed, preserve one previous
  generation through a current-only bridge, and keep previous state read-only. The 600-second
  four-device window blocks every involved K5 at HARD L2; its 30-minute N-1 WATCH prevents
  stable three-device gaming; and exactly the third 24-hour occurrence adds HARD L4 on K4.
  API Heavy, missing identity, and non-pre-check commands do not observe the rule. Flood state
  still advances and a distributed HARD candidate wins a concurrent flood SOFT candidate.
- Rotation-safe credential-spray correlation for Login and OTP pre-checks: the unchanged
  `CorrelationStoreInterface` remains the no-rotation base contract, while
  `CorrelationRotationStoreInterface` preserves previous history through current-secret bridge
  members, fixed TTLs, read-only previous state, and explicit
  missing-capability/corrupt-state failures.
- Credential-spray correlation for Login and OTP pre-checks using the optional opaque
  `correlationId` with `accountId` fallback, domain-separated HMAC members, a fixed K1 window,
  mandatory N-1 WATCH escalation, and trusted-session advisory K1 semantics.
- Standards-compliant read-only operational state API with point-in-time inspection that does not couple consumers to raw storage keys.
- Implemented the locked two-generation runtime integration for persistent K3/K5 active-block
  lookup and score fallback, and for K5 micro-cap current-authoritative atomic migration across
  outer-only, fingerprint-only, and both-rotated generations. `BudgetSeedStoreInterface` is
  required only when a valid Previous micro-cap must be migrated; missing capability continues
  through the existing failure semantics. Correlation/ephemeral fingerprint-secret rotation
  is implemented. Budget Owner-Safety orchestration is implemented for
  command eligibility, cooldown, known-device eligibility, Recovery Collision Guard, decision
  aggregation, and fail-fast removal.
- Standalone Composer package foundation for `maatify/php-rate-limiter`
- Initial package metadata and CI quality gate
- Locked Budget Owner-Safety architecture and public-contract decisions:
  - Budget is a decision candidate, never a fail-fast gate; aggregation stays `HARD_BLOCK > SOFT_BLOCK > ALLOW`
  - Budget-issued `SOFT_BLOCK` is decoupled from `RateLimitStoreInterface::block()` (not `BlockState`)
  - Explicit command eligibility: Login `recordFailure`/`checkOnly` only; OTP `recordFailure` only
  - Enforcement-owned budget cooldown (Login 3600s / OTP 7200s); budget `retryAfter` = remaining cooldown
  - Key-rotation survival for all budget state (K4 epoch/count, K5 micro-cap, cooldown marker) — no `max(v1,v2)` merge
  - Recovery Collision Guard keyed off the atomic returned budget count (one-shot L2, concurrency-safe)
- Declared additive capability interface `BudgetSeedStoreInterface::incrementBudgetWithSeed()` (extends `RateLimitStoreInterface`) for atomic budget-epoch hand-off across key rotation; the capability interface is additive and does not change `RateLimitStoreInterface`
- Implemented `BudgetSeedStoreInterface::incrementBudgetWithSeed()` with the locked `KEY_STRATEGY.md` §4.3.2 seeding semantics (existing V2 state wins, seed carries the epoch on first-time initialization, expired seed starts a fresh epoch); the test-support and consumer-verification stores expose the capability while `ThrowingRateLimitStore` remains base-contract only
- Added `BudgetSeedStore` contract and capability tests covering valid/expired/boundary seeds, no double-seeding, fixed-epoch preservation, and additive-capability compatibility
- K4 account budget now preserves its fixed 24h epoch and cumulative count across key rotation: budget reads are V2-first with V1 fallback (never `max(v1,v2)`), and a V1→V2 migration on writes uses the atomic `BudgetSeedStoreInterface::incrementBudgetWithSeed()` hand-off; when a migration is required but the store lacks the capability, the pipeline fails explicitly through the existing failure semantics instead of silently resetting the previous-secret budget. Persistent K3/K5 two-generation continuity and K5 micro-cap migration are now implemented across the coordinated current/previous generation model; correlation/ephemeral fingerprint-secret rotation is implemented.
- Locked the dual-fingerprint (fingerprint-secret rotation) architecture and the **additive** `previousFingerprintHash` contract (nullable, end-of-DTO, `DeviceIdentityDTO::previousFingerprintHash`) as the **previous-generation fingerprint component**, while `DeviceIdentityResolverInterface::resolve()` stays unchanged; the default resolver hashes the same normalized raw identity once with the current hasher and once with an optional previous hasher. Key continuity is modeled as **one coordinated current generation plus at most one previous generation** — previous outer `??` current outer, previous fingerprint `??` current fingerprint, previous generation present when `previousKeySecret` or `previousFingerprintHash` is non-null — covering outer-only, fingerprint-only, and both-rotated cases. Probing or merging arbitrary Cartesian historical combinations is forbidden, and overlapping generations are forbidden (a second rotation must not begin while the previous generation still needs enforcement continuity, `docs/DEVICE_FINGERPRINT.md` §5.1.6). Write-current / read-previous. K5 micro-cap becomes current/previous with current-authoritative atomic seeding and no `max()` merge. K3/K5 fingerprint-secret rotation and K5 micro-cap fingerprint-secret rotation = implemented. Correlation/ephemeral fingerprint-secret rotation = implemented. Normative ownership: `docs/DEVICE_FINGERPRINT.md` §5.1, `docs/KEY_STRATEGY.md` §4.3.3 / §4.5.2.
- Implemented the dual-fingerprint **public identity contract** (`docs/DEVICE_FINGERPRINT.md` §5.1). `DeviceIdentityDTO::previousFingerprintHash` is implemented as the nullable, additive last serialized field; the default `DeviceIdentityResolver` accepts an optional `?FingerprintHasher $previousHasher = null` after the current hasher. The default resolver builds the normalized raw identity exactly once (`v2|normalizedUa|normalizedClientFp|sessionDeviceId`), hashes it with both the current and (when configured) the previous hasher, and returns both hashes on the DTO; raw material is never stored, logged, or exposed on the DTO. Current implementation status: dual-fingerprint architecture = locked; `DeviceIdentityDTO.previousFingerprintHash` = implemented; default resolver optional previous hasher = implemented; K3/K5 pipeline generation integration = implemented; K5 micro-cap generation integration = implemented; correlation/ephemeral rotation = implemented. The old single-hasher constructor and the consumer integration API remain unchanged; `previousFingerprintHash` never alters `confidence`, `isTrustedSession`, `isDevicePreviouslyVerifiedForAccount`, or `isKnownForAccount` (`docs/DEVICE_FINGERPRINT.md` §5.1.4).
- Implemented host-provided signal `isDevicePreviouslyVerifiedForAccount` (default `false`) on `RateLimitContextDTO` and `DeviceIdentityDTO`, propagated by `DeviceIdentityResolver`, for known-device-for-account semantics without a trusted session; the host remains the sole authority and the RateLimiter performs no inference (no `K5` presence check, no `HIGH` confidence inference, no external storage query)

### Fixed
- Fixed fallback UA double-normalization so local fallback receives raw UA and applies normalization once.
- Fixed local fallback GC so cleanup removes only expired fixed-window buckets and preserves active buckets until natural rollover.

### Changed
- Reconciled the Device Fingerprint, Key Strategy, and Package Reference contracts:
  trusted sessions are `HIGH` without
  client hints, the default resolver uses bounded canonical browser-major UA
  values and no automatic header harvesting, client-fingerprint serialization
  is recursively deterministic with explicit package exceptions on failure, and
  the normalized identity schema is now `v2|...`; local fallback K2 uses the same
  UA source of truth.
- Corrected API Heavy enforcement semantics: K2 is limited to SOFT_BLOCK L1, K3 to HARD_BLOCK L2, and K1 to HARD_BLOCK L3; each API scope persists independently, K4/K5 are not enforced, equal N-1 thresholds are observed once at the highest represented level, and LOW-confidence moderate K3 enforcement targets K2.
- Score-threshold decisions now derive `retryAfter` from the package-owned `DecayCalculator`: active persisted block TTL remains authoritative, L3 hard decisions exit below L2, and `PenaltyLadder` persistence durations remain unchanged. The multiple-block-cycle pause is implemented.
- Normalized the pre-stable public namespaces and source topology to the canonical
  Single Capability layout (`Builder`, `Command`, `Config`, `Contract`, `DTO`, `Enum`,
  `Exception`, `Repository`, and `Service`) without changing runtime behavior or adding
  compatibility shims.
- Clarified the locked Budget Owner-Safety architecture:
  decision class wins before level/duration, a budget `SOFT_BLOCK` cannot upgrade hard
  properties, budget cooldown is tied to actual budget-soft issuance, Login `checkOnly`
  requires a would-be `ALLOW`, Recovery Collision Guard does not consume budget cooldown,
  and Anti-Equilibrium reads prior soft history before cooldown, then records only the
  final issued `SOFT_BLOCK` for future requests.
- Package licensing established as proprietary by Owner Decision before first release
- Expanded `BudgetConfigDTO` to the full policy-owned contract (threshold, block level, cooldown seconds, trusted-session floor, pre-check enforcement, known-device micro-cap, Recovery Collision Guard toggle) with defaults preserving current runtime semantics (`cooldown_seconds = 0`, `trusted_session_floor_level = 2`, `precheck_enforcement = true`, `known_device_micro_cap = 8`, `recovery_collision_guard_enabled = false`); locked Login/OTP preset values live in the policy objects, with the full contract in `docs/POLICIES.md`. Normative Budget Owner-Safety behavior is implemented in the runtime and covered by regression tests. Normative behavior lives in `docs/DECISION_MATRIX.md`.

[Unreleased]: https://github.com/Maatify/php-rate-limiter/compare/v1.0.0-rc.3...HEAD
[1.0.0-rc.3]: https://github.com/Maatify/php-rate-limiter/releases/tag/v1.0.0-rc.3
[1.0.0-rc.2]: https://github.com/Maatify/php-rate-limiter/compare/v1.0.0-rc.1...v1.0.0-rc.2
[1.0.0-rc.1]: https://github.com/Maatify/php-rate-limiter/releases/tag/v1.0.0-rc.1
