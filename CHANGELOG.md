# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
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
- K4 account budget now preserves its fixed 24h epoch and cumulative count across key rotation: budget reads are V2-first with V1 fallback (never `max(v1,v2)`), and a V1→V2 migration on writes uses the atomic `BudgetSeedStoreInterface::incrementBudgetWithSeed()` hand-off; when a migration is required but the store lacks the capability, the pipeline fails explicitly through the existing failure semantics instead of silently resetting the previous-secret budget. K5/device-derived rotation continuity remains pending because `DeviceFP` itself is keyed and changes across fingerprint-secret rotation.
- Locked the dual-fingerprint (fingerprint-secret rotation) architecture — architecture gate only, no runtime implementation. Locked the **target additive** `previousFingerprintHash` contract (nullable, end-of-DTO, `DeviceIdentityDTO::previousFingerprintHash`) as the **previous-generation fingerprint component**, while `DeviceIdentityResolverInterface::resolve()` stays unchanged; the default resolver hashes the same normalized raw identity once with the current hasher and once with an optional previous hasher. Key continuity is modeled as **one coordinated current generation plus at most one previous generation** — previous outer `??` current outer, previous fingerprint `??` current fingerprint, previous generation present when `previousKeySecret` or `previousFingerprintHash` is non-null — covering outer-only, fingerprint-only, and both-rotated cases. Probing or merging arbitrary Cartesian historical combinations is forbidden, and overlapping generations are forbidden (a second rotation must not begin while the previous generation still needs enforcement continuity, `docs/DEVICE_FINGERPRINT.md` §5.1.6). Write-current / read-previous. K5 micro-cap becomes current/previous with current-authoritative atomic seeding and no `max()` merge. Dual-fingerprint public contract = architecture locked, runtime pending. K3/K5 true fingerprint-secret rotation and K5 micro-cap true fingerprint-secret rotation = pending. Correlation/ephemeral fingerprint-secret rotation = pending separate design (not claimed). Normative ownership: `docs/DEVICE_FINGERPRINT.md` §5.1, `docs/KEY_STRATEGY.md` §4.3.3 / §4.5.2.
- Implemented host-provided signal `isDevicePreviouslyVerifiedForAccount` (default `false`) on `RateLimitContextDTO` and `DeviceIdentityDTO`, propagated by `DeviceIdentityResolver`, for known-device-for-account semantics without a trusted session; the host remains the sole authority and the RateLimiter performs no inference (no `K5` presence check, no `HIGH` confidence inference, no external storage query)

### Fixed
- Fixed fallback UA double-normalization so local fallback receives raw UA and applies normalization once.
- Fixed local fallback GC so cleanup removes only expired fixed-window buckets and preserves active buckets until natural rollover.

### Changed
- Package licensing established as proprietary by Owner Decision before first release
- Expanded `BudgetConfigDTO` to the full policy-owned contract (threshold, block level, cooldown seconds, trusted-session floor, pre-check enforcement, known-device micro-cap, Recovery Collision Guard toggle) with defaults preserving current runtime semantics (`cooldown_seconds = 0`, `trusted_session_floor_level = 2`, `precheck_enforcement = true`, `known_device_micro_cap = 8`, `recovery_collision_guard_enabled = false`); locked Login/OTP preset values live in the policy objects, with the full contract in `docs/POLICIES.md`. Remaining Budget Owner-Safety orchestration is not yet implemented: cooldown enforcement, policy-owned command eligibility, known-device eligibility, Recovery Collision Guard, decision aggregation. Normative behavior lives in `docs/DECISION_MATRIX.md`.
