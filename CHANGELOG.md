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
- Implemented host-provided signal `isDevicePreviouslyVerifiedForAccount` (default `false`) on `RateLimitContextDTO` and `DeviceIdentityDTO`, propagated by `DeviceIdentityResolver`, for known-device-for-account semantics without a trusted session; the host remains the sole authority and the RateLimiter performs no inference (no `K5` presence check, no `HIGH` confidence inference, no external storage query)

### Fixed
- Fixed fallback UA double-normalization so local fallback receives raw UA and applies normalization once.
- Fixed local fallback GC so cleanup removes only expired fixed-window buckets and preserves active buckets until natural rollover.

### Changed
- Package licensing established as proprietary by Owner Decision before first release
- Expanded `BudgetConfigDTO` to the full policy-owned contract (threshold, block level, cooldown seconds, trusted-session floor, pre-check enforcement, known-device micro-cap, Recovery Collision Guard toggle) with defaults preserving current runtime semantics (`cooldown_seconds = 0`, `trusted_session_floor_level = 2`, `precheck_enforcement = true`, `known_device_micro_cap = 8`, `recovery_collision_guard_enabled = false`); locked Login/OTP preset values live in the policy objects, with the full contract in `docs/POLICIES.md`. Budget orchestration is NOT implemented yet: `cooldown_seconds`, `trusted_session_floor_level`, `precheck_enforcement`, `known_device_micro_cap`, and `recovery_collision_guard_enabled` are public contract values that `EvaluationPipeline` does not consume yet. Normative behavior lives in `docs/DECISION_MATRIX.md`.
