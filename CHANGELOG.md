# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
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
  (amended) for the full rationale. Package Reference version: `1.17.0` →
  `1.18.0`; Policy contract version: `1.6.0` → `1.7.0`; Failure Semantics
  version: `1.3.0` → `1.4.0`.

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
  client or `ext-redis` runtime dependency. Package Reference version:
  `1.14.0` → `1.15.0`.
- Added WU-S4-02A full-capability storage composition. `FullCapabilityStoreInterface`
  aggregates the budget-seed, bounded snapshot-rotation, circuit-probe, and
  hard-block-cycle contracts without adding methods, and
  `RateLimiterBuilder::fromFullCapabilityStore()` composes one host store across
  those boundaries while keeping the failure-signal emitter separate. The
  existing multi-store constructor remains source-compatible. Package Reference
  version: `1.13.0` → `1.14.0`. No concrete Redis, PDO, Lua, or `ext-redis`
  adapter is included.
- Implemented WU-S3-07 multiple-block-cycle decay pause. Persisted L2+ blocks now
  use the additive `HardBlockCycleStoreInterface` for atomic Current-only block
  persistence, real cycle classification, rolling six-hour K1/K2/K3/K4/K5
  histories, fixed 600-second pauses, rotation adoption, and read-only lazy
  decay accounting. Base-only stores remain compatible for reads and L1 writes
  but fail explicitly before an L2+ block write. `DecayCalculator` preserves
  source compatibility through trailing pause arguments. Version transitions:
  `DECISION_MATRIX.md` `1.9.0` → `1.10.0`, `POLICIES.md` `1.3.0` → `1.4.0`,
  `KEY_STRATEGY.md` `1.8.0` → `1.9.0`, and package reference `1.11.0` → `1.12.0`.
- Implemented WU-S3-05 IPv6 adaptive aggregation. IPv6 enforcement now uses
  canonical `/64` K1 only; bounded `/48`, `/40`, and `/32` correlation scopes
  activate at exact `2/2`, `4/4`, and `8/8` child thresholds within a fixed
  600-second window, without hierarchy WATCH decisions or macro RateLimitStore
  score/block state. Spray and Churn observe active macro scopes while persisting
  only current `/64` K1 or `/64 + UA` K2 enforcement. Hierarchy keys and members
  are policy/environment/version/purpose-separated HMAC references with bounded
  outer-key rotation continuity; Dilution remains canonical `/64`-member based.
  Version transitions: `KEY_STRATEGY.md` `1.7.0` → `1.8.0`,
  `DECISION_MATRIX.md` `1.8.0` → `1.9.0`, and package reference `1.9.0` → `1.10.0`.
- Documented the public operational API contraction from serialized
  `k1,k2,k3,k4,k5,k1_48,k1_40,k1_32` to `k1,k2,k3,k4,k5`. The removed macro
  fields were enforcement-scope projections; `/48`, `/40`, and `/32` remain
  internal correlation-detection state only. No additional specification
  version bump is introduced for this clarification.
- Follow-up hardening for WU-S3-04: New Device Flood now evaluates from the sixth
  admitted/account-scope observation through the rejected overflow path, while only
  admitted devices may persist K5 state. All bounded distinct-store results are validated
  centrally before classification; malformed counts or rejected-before-cap results fail
  explicitly with a package-owned exception.
- Completed WU-S3-04 bounded ephemeral and fingerprint-correlation hardening. The additive
  `BoundedCorrelationStoreInterface` and `BoundedCorrelationRotationStoreInterface` now
  provide atomic fixed-TTL distinct admission with `BoundedDistinctResultDTO`; device-cap,
  churn, dilution, and credential-spray scopes use opaque domain-separated HMAC references
  and explicit caps. Current/previous generation observations use read-only previous state
  and a TTL-capped current bridge. Ephemeral overflow performs one real-key observation,
  never creates a fake K3/K5 identity, preserves active-block checks and K4/flood handling,
  and suppresses per-fingerprint dilution state while retaining bounded churn. Version
  transitions: `DEVICE_FINGERPRINT.md` `1.2.0` → `1.3.0`, `KEY_STRATEGY.md` `1.5.0` → `1.6.0`,
  `DECISION_MATRIX.md` `1.6.0` → `1.7.0`, and package reference `1.7.0` → `1.8.0`.
- Isolated account/policy auxiliary state for repeated missing fingerprints, Anti-Equilibrium,
  and New Device Flood with the locked policy/environment/version HMAC namespace. Current
  generation is writable, previous outer-key state is read-only, raw AccountID no longer
  crosses storage boundaries, and API Heavy does not create a repeated-missing marker.
  Updated Key Strategy `1.4.0` → `1.5.0`, Decision Matrix `1.5.0` → `1.6.0`, and Package
  Reference `1.6.0` → `1.7.0`.

### Added
- Implemented WU-S4-01's default composition surface. Added the immutable
  `RateLimiterConfig` contract and package-wide `RateLimiterBuilder`, which
  require the four Host integration boundaries, compose the existing runtime
  graph with a shared UTC default clock, register the Login/OTP/API Heavy policy
  presets, and preserve independent outer/fingerprint rotation inputs. Package
  Reference version: `1.12.0` → `1.13.0`. No Redis, PDO, or full aggregate store
  implementation is included.
- Implemented WU-S3-06 Circuit Breaker state-machine recovery: OPEN requests
  short-circuit shared-backend work, recovery uses leased read-only health probes
  through HALF_OPEN, and the rolling re-entry guard is authoritative across all
  circuit states with exactly-once transition signals and remaining Retry-After.
  Added the additive `CircuitBreakerProbeStoreInterface`; the existing
  `CircuitBreakerStoreInterface` remains source-compatible. Failure Semantics
  `1.0.0` → `1.1.0` and Package Reference `1.10.0` → `1.11.0`.
- Implemented WU-S3-08 distributed account-attack correlation for Login and OTP pre-checks.
  The new snapshot DTO and additive snapshot store capabilities return the complete bounded
  logical K5 member set with fixed expiry, validate it fail-closed, preserve one previous
  generation through a current-only bridge, and keep previous state read-only. The 600-second
  four-device window blocks every involved K5 at HARD L2; its 30-minute N-1 WATCH prevents
  stable three-device gaming; and exactly the third 24-hour occurrence adds HARD L4 on K4.
  API Heavy, missing identity, and non-pre-check commands do not observe the rule. Flood state
  still advances and a distributed HARD candidate wins a concurrent flood SOFT candidate.
  Version transitions: `KEY_STRATEGY.md` `1.6.0` → `1.7.0`, `DECISION_MATRIX.md` `1.7.0` →
  `1.8.0`, and package reference `1.8.0` → `1.9.0`.
- Rotation-safe credential-spray correlation for Login and OTP pre-checks (Spec Version `1.2.0` → `1.3.0`): the unchanged `CorrelationStoreInterface` remains the no-rotation base contract, while `CorrelationRotationStoreInterface` preserves previous history through current-secret bridge members, fixed TTLs, read-only previous state, and explicit missing-capability/corrupt-state failures. No concrete Redis/Lua/PDO adapter is included.
- Credential-spray correlation for Login and OTP pre-checks using the optional opaque `correlationId` with `accountId` fallback, domain-separated HMAC members, a fixed K1 window, mandatory N-1 WATCH escalation, and trusted-session advisory K1 semantics (Spec Version `1.1.0` → `1.2.0`).
- Standards-compliant read-only operational state API with point-in-time inspection that does not couple consumers to raw storage keys.
- Implemented the locked two-generation runtime integration for persistent K3/K5 active-block
  lookup and score fallback, and for K5 micro-cap current-authoritative atomic migration across
  outer-only, fingerprint-only, and both-rotated generations. `BudgetSeedStoreInterface` is
  required only when a valid Previous micro-cap must be migrated; missing capability continues
  through the existing failure semantics. Correlation/ephemeral fingerprint-secret rotation
  remains pending as a separate design. Budget Owner-Safety orchestration is implemented for
  command eligibility, cooldown, known-device eligibility, Recovery Collision Guard, decision
  aggregation, and fail-fast removal.
- Standalone Composer package foundation for `maatify/php-rate-limiter`
- Initial package metadata and CI quality gate
- Locked Budget Owner-Safety architecture and public-contract decisions (Spec Version `1.0.0` → `1.1.0`):
  - Budget is a decision candidate, never a fail-fast gate; aggregation stays `HARD_BLOCK > SOFT_BLOCK > ALLOW`
  - Budget-issued `SOFT_BLOCK` is decoupled from `RateLimitStoreInterface::block()` (not `BlockState`)
  - Explicit command eligibility: Login `recordFailure`/`checkOnly` only; OTP `recordFailure` only
  - Enforcement-owned budget cooldown (Login 3600s / OTP 7200s); budget `retryAfter` = remaining cooldown
  - Key-rotation survival for all budget state (K4 epoch/count, K5 micro-cap, cooldown marker) — no `max(v1,v2)` merge
  - Recovery Collision Guard keyed off the atomic returned budget count (one-shot L2, concurrency-safe)
- Declared additive capability interface `BudgetSeedStoreInterface::incrementBudgetWithSeed()` (extends `RateLimitStoreInterface`) for atomic budget-epoch hand-off across key rotation; the capability interface is additive and does not change `RateLimitStoreInterface`
- Implemented `BudgetSeedStoreInterface::incrementBudgetWithSeed()` with the locked `KEY_STRATEGY.md` §4.3.2 seeding semantics (existing V2 state wins, seed carries the epoch on first-time initialization, expired seed starts a fresh epoch); the test-support and consumer-verification stores expose the capability while `ThrowingRateLimitStore` remains base-contract only
- Added `BudgetSeedStore` contract and capability tests covering valid/expired/boundary seeds, no double-seeding, fixed-epoch preservation, and additive-capability compatibility
- K4 account budget now preserves its fixed 24h epoch and cumulative count across key rotation: budget reads are V2-first with V1 fallback (never `max(v1,v2)`), and a V1→V2 migration on writes uses the atomic `BudgetSeedStoreInterface::incrementBudgetWithSeed()` hand-off; when a migration is required but the store lacks the capability, the pipeline fails explicitly through the existing failure semantics instead of silently resetting the previous-secret budget. Persistent K3/K5 two-generation continuity and K5 micro-cap migration are now implemented across the coordinated current/previous generation model; correlation/ephemeral fingerprint-secret rotation remains a separate pending design.
- Locked the dual-fingerprint (fingerprint-secret rotation) architecture and the **additive** `previousFingerprintHash` contract (nullable, end-of-DTO, `DeviceIdentityDTO::previousFingerprintHash`) as the **previous-generation fingerprint component**, while `DeviceIdentityResolverInterface::resolve()` stays unchanged; the default resolver hashes the same normalized raw identity once with the current hasher and once with an optional previous hasher. Key continuity is modeled as **one coordinated current generation plus at most one previous generation** — previous outer `??` current outer, previous fingerprint `??` current fingerprint, previous generation present when `previousKeySecret` or `previousFingerprintHash` is non-null — covering outer-only, fingerprint-only, and both-rotated cases. Probing or merging arbitrary Cartesian historical combinations is forbidden, and overlapping generations are forbidden (a second rotation must not begin while the previous generation still needs enforcement continuity, `docs/DEVICE_FINGERPRINT.md` §5.1.6). Write-current / read-previous. K5 micro-cap becomes current/previous with current-authoritative atomic seeding and no `max()` merge. K3/K5 fingerprint-secret rotation and K5 micro-cap fingerprint-secret rotation = implemented. Correlation/ephemeral fingerprint-secret rotation = pending separate design (not claimed). Normative ownership: `docs/DEVICE_FINGERPRINT.md` §5.1, `docs/KEY_STRATEGY.md` §4.3.3 / §4.5.2.
- Implemented the dual-fingerprint **public identity contract** (`docs/DEVICE_FINGERPRINT.md` §5.1). `DeviceIdentityDTO::previousFingerprintHash` is implemented as the nullable, additive last serialized field; the default `DeviceIdentityResolver` accepts an optional `?FingerprintHasher $previousHasher = null` after the current hasher. The default resolver builds the normalized raw identity exactly once (`v2|normalizedUa|normalizedClientFp|sessionDeviceId`), hashes it with both the current and (when configured) the previous hasher, and returns both hashes on the DTO; raw material is never stored, logged, or exposed on the DTO. Current implementation status: dual-fingerprint architecture = locked; `DeviceIdentityDTO.previousFingerprintHash` = implemented; default resolver optional previous hasher = implemented; K3/K5 pipeline generation integration = implemented; K5 micro-cap generation integration = implemented; correlation/ephemeral rotation = pending separate design. The old single-hasher constructor and the consumer integration API remain unchanged; `previousFingerprintHash` never alters `confidence`, `isTrustedSession`, `isDevicePreviouslyVerifiedForAccount`, or `isKnownForAccount` (`docs/DEVICE_FINGERPRINT.md` §5.1.4).
- Implemented host-provided signal `isDevicePreviouslyVerifiedForAccount` (default `false`) on `RateLimitContextDTO` and `DeviceIdentityDTO`, propagated by `DeviceIdentityResolver`, for known-device-for-account semantics without a trusted session; the host remains the sole authority and the RateLimiter performs no inference (no `K5` presence check, no `HIGH` confidence inference, no external storage query)

### Fixed
- Fixed fallback UA double-normalization so local fallback receives raw UA and applies normalization once.
- Fixed local fallback GC so cleanup removes only expired fixed-window buckets and preserves active buckets until natural rollover.

### Changed
- Reconciled the Device Fingerprint contract (`1.1.0` → `1.2.0`), Key Strategy
  contract (`1.3.0` → `1.4.0`), and Package Reference (`1.5.0` → `1.6.0`):
  trusted sessions are `HIGH` without
  client hints, the default resolver uses bounded canonical browser-major UA
  values and no automatic header harvesting, client-fingerprint serialization
  is recursively deterministic with explicit package exceptions on failure, and
  the normalized identity schema is now `v2|...`; local fallback K2 uses the same
  UA source of truth.
- Corrected API Heavy enforcement semantics (Spec Version `1.4.0` → `1.5.0`; Policy Preset contract `1.2.0` → `1.3.0`): K2 is limited to SOFT_BLOCK L1, K3 to HARD_BLOCK L2, and K1 to HARD_BLOCK L3; each API scope persists independently, K4/K5 are not enforced, equal N-1 thresholds are observed once at the highest represented level, and LOW-confidence moderate K3 enforcement targets K2.
- Score-threshold decisions now derive `retryAfter` from the package-owned `DecayCalculator` (Spec Version `1.3.0` → `1.4.0`; Policy Preset contract `1.1.0` → `1.2.0`): active persisted block TTL remains authoritative, L3 hard decisions exit below L2, and `PenaltyLadder` persistence durations remain unchanged. The multiple-block-cycle pause remains deferred to Stage 3.
- Normalized the pre-stable public namespaces and source topology to the canonical
  Single Capability layout (`Command`, `Config`, `Contract`, `DTO`, `Exception`,
  `Repository`, and `Service`) without changing runtime behavior or adding
  compatibility shims.
- Clarified the locked Budget Owner-Safety architecture (Spec Version remains `1.1.0`):
  decision class wins before level/duration, a budget `SOFT_BLOCK` cannot upgrade hard
  properties, budget cooldown is tied to actual budget-soft issuance, Login `checkOnly`
  requires a would-be `ALLOW`, Recovery Collision Guard does not consume budget cooldown,
  and Anti-Equilibrium reads prior soft history before cooldown, then records only the
  final issued `SOFT_BLOCK` for future requests.
- Package licensing established as proprietary by Owner Decision before first release
- Expanded `BudgetConfigDTO` to the full policy-owned contract (threshold, block level, cooldown seconds, trusted-session floor, pre-check enforcement, known-device micro-cap, Recovery Collision Guard toggle) with defaults preserving current runtime semantics (`cooldown_seconds = 0`, `trusted_session_floor_level = 2`, `precheck_enforcement = true`, `known_device_micro_cap = 8`, `recovery_collision_guard_enabled = false`); locked Login/OTP preset values live in the policy objects, with the full contract in `docs/POLICIES.md`. Normative Budget Owner-Safety behavior is implemented in the runtime and covered by regression tests. Normative behavior lives in `docs/DECISION_MATRIX.md`.
