# Decision Index

This index is the current discovery surface for durable engineering decisions in this repository.

## Current decisions

| Decision ID | Title | Status | Scope / Concern | Decision Record | Canonical Contract / Current Owner | Supersedes | Superseded By |
| --- | --- | --- | --- | --- | --- | --- | --- |
| [DEC-001](DEC-001_PER_CODING_STYLE_VERIFICATION.md) | Repository PER Coding Style 3.1 Verification Mechanism | SUPERSEDED | Repository-wide PHP coding-style verification | [DEC-001](DEC-001_PER_CODING_STYLE_VERIFICATION.md) | [PHP Coding Style Standard](../php-engineering-standards/standards/php/PHP_CODING_STYLE_STANDARD.md) | None | DEC-002 |
| [DEC-002](DEC-002_PER_CODING_STYLE_VERIFICATION_CORRECTION.md) | Repository PER Coding Style 3.1 Verification Correction | ACTIVE | Repository-wide PHP coding-style verification | [DEC-002](DEC-002_PER_CODING_STYLE_VERIFICATION_CORRECTION.md) | [PHP Coding Style Standard](../php-engineering-standards/standards/php/PHP_CODING_STYLE_STANDARD.md) | DEC-001 | None |
| [DEC-003](DEC-003_MULTIPLE_BLOCK_CYCLE_DECAY_PAUSE.md) | Multiple-block-cycle Decay Pause | ACTIVE | Persisted L2+ hard-block cycle history, rotation continuity, and score-decay pause | [DEC-003](DEC-003_MULTIPLE_BLOCK_CYCLE_DECAY_PAUSE.md) | `HardBlockCycleStoreInterface`, `EvaluationPipeline`, `DecayCalculator`, and versioned rate-limiter contracts | None | None |
| [DEC-004](DEC-004_DEFAULT_COMPOSITION_SURFACE.md) | Default Composition Surface | ACTIVE | Production default configuration and composition for the standalone rate limiter | [DEC-004](DEC-004_DEFAULT_COMPOSITION_SURFACE.md) | `RateLimiterBuilder`, `RateLimiterConfig`, and the root Package Reference | None | None |
| [DEC-005](DEC-005_FULL_CAPABILITY_STORAGE_BOUNDARY.md) | Full-Capability Storage Boundary | SUPERSEDED | Aggregate storage contract and one-store Builder composition convenience | [DEC-005](DEC-005_FULL_CAPABILITY_STORAGE_BOUNDARY.md) | `FullCapabilityStoreInterface`, `RateLimiterBuilder::fromFullCapabilityStore()`, and the root Package Reference | None | DEC-006 |
| [DEC-006](DEC-006_BUILT_IN_REDIS_FULL_CAPABILITY_STORE.md) | Built-in Redis Full-Capability Store | ACTIVE | Official Redis placement, command boundary, and backend dependency strategy | [DEC-006](DEC-006_BUILT_IN_REDIS_FULL_CAPABILITY_STORE.md) | `src/Repository/Redis/`, `FullCapabilityStoreInterface`, and `RateLimiterBuilder::fromFullCapabilityStore()` | DEC-005 | None |
| [DEC-007](DEC-007_GENERATION_BOUND_AUTHENTICATION_K4_POST_PUNISHMENT_REENTRY.md) | Generation-Bound Authentication K4 Post-Punishment Re-entry | ACTIVE | Login/OTP K4 punishment lifecycle, generation fencing, and public claim | [DEC-007](DEC-007_GENERATION_BOUND_AUTHENTICATION_K4_POST_PUNISHMENT_REENTRY.md) | `PunishmentLifecycleStoreInterface`, opt-in policies, and `RateLimiterRuntimeInterface` | None | None |
