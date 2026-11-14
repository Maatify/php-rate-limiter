# DEC-039 — Decimal-Millisecond Caller-Time Store Capabilities

## Decision ID

`DEC-039`

## Status

`ACTIVE` — approved by the Owner on 2026-11-14 on [#215](https://github.com/Maatify/php-rate-limiter/issues/215) (design proposal: [comment 6878825775](https://github.com/Maatify/php-rate-limiter/issues/215#issuecomment-6878825775); approval and executor assignment: [comment 6879049039](https://github.com/Maatify/php-rate-limiter/issues/215#issuecomment-6879049039)). The implementing pull request is a Draft until the Lead reviews its exact head.

## Date

2026-11-14

## Decision Authority / Deciders

**Owner**, explicit approval of the optional canonical decimal epoch-millisecond interfaces scoped by store capability and of the classified `RateLimiterException` for a native-integer-only store, recorded in the assignment on #215. Context: the Owner's decision to keep 32-bit PHP support ([#213](https://github.com/Maatify/php-rate-limiter/issues/213)) under Train 9 ([#185](https://github.com/Maatify/php-rate-limiter/issues/185)).

## Scope / Concern

How the caller's time, in epoch milliseconds, reaches a store that derives a millisecond lifetime or a millisecond-sensitive decision (K4 and K4P generation-bound score read, mutation and re-entry claim; the bridge lifetime of a correlation rotation), on a PHP build whose native integer cannot hold it (`PHP_INT_SIZE === 4`, where `PHP_INT_MAX` is 2147483647 and a current epoch millisecond value is about 1.8 × 10^12).

## Context

The six caller-time operations that take `int $nowMilliseconds` (`addDistinctAcrossRotationAt`, `addDistinctBoundedAcrossRotationAt`, `addDistinctBoundedWithSnapshotAcrossRotationAt`, `readGenerationBoundScoreStateAt`, `mutateGenerationBoundScoreAt`, `claimPostPunishmentReentryAt`) were fed by `(int) $now->format('Uv')`. On 32-bit PHP that cast saturates at `PHP_INT_MAX` without an error, so the engine judged K4, K4P and the rotation bridge on a clock frozen at 2147483647 ms, about 24.9 days after 1970-01-01: the measured effect was `ALLOW, ALLOW, ALLOW, ALLOW` where 64-bit gives `ALLOW, SOFT_BLOCK, HARD_BLOCK, HARD_BLOCK`. The Redis Lua scripts already take the clock as a decimal string and validate it (`^%d+$`, no leading zero, at most 9007199254740991), so only the PHP `int` hop loses the value. An existing public signature may not be widened or replaced in place (DEC-016 rule 1: caller-time capabilities are additive).

## Decision

1. **Additive optional interfaces, separately named methods.** Four interfaces, each extending the existing caller-time interface it mirrors and adding the same operation with the suffix `Milliseconds` and `string $nowMilliseconds`: `CallerTimeDecimalMillisecondsCorrelationRotationStoreInterface`, `CallerTimeDecimalMillisecondsBoundedCorrelationRotationStoreInterface`, `CallerTimeDecimalMillisecondsBoundedCorrelationSnapshotRotationStoreInterface`, `CallerTimeDecimalMillisecondsPunishmentLifecycleStoreInterface`. No existing interface, method or signature changes.
2. **Canonical value.** The decimal is `0` or digits with no leading zero, from 0 to 9007199254740991 (2^53 − 1, the exact range of Redis Lua, the same bound its scripts enforce). A negative value is rejected under DEC-017 with `RateLimiterException('Caller time must be a non-negative Unix timestamp.')`; a non-canonical value (`''`, a leading zero, a sign, a fraction, an exponent, a space) with `Caller time in milliseconds must be a canonical decimal integer.`; a value above the range with `Caller time in milliseconds is not representable.` All are raised before any backend command.
3. **Dispatch by capability, per call (`CallerTimeStore`).** (a) A store that implements the decimal interface receives the exact decimal of the instant and never the native-integer method. (b) A store that implements only the existing caller-time interface receives the native integer when the value fits a native integer, exactly as before; when it does not fit, `RateLimiterException` ("Caller time in milliseconds is not representable as a native integer on this PHP build; the store must implement the decimal-millisecond capability.") is raised **before** the store is called, and the clock is never changed silently. (c) A store with neither keeps its own clock, unchanged. On 64-bit PHP every value fits, so (b) is unchanged there.
4. **Official Redis store.** `RedisFullCapabilityStore` implements all four interfaces. Its existing native-integer methods delegate to the decimal ones (`(string) $int`), and the methods without a time parameter use the store's own clock as an exact decimal (`fallbackNowMilliseconds()` returns the canonical decimal, not `(int)`). The second of the rotation is `substr` of the decimal, not `intdiv`; the generation expiry representability check is exact without a native millisecond integer. Unit conversion (a generated expiry is ⌈(now ms + TTL) / 1 s⌉), the expiry boundary and every result are unchanged.
5. **Seconds-based operations are out of scope.** They take `$now` in seconds, which a native integer holds until 2038-01-19 03:14:07 UTC; they are not changed.

## Rationale

- **Why a decimal string:** it is exact on every build, it is what the Lua scripts already accept and validate, and it matches DEC-038's decimal contract for values a native integer cannot carry. A clock value object would be a larger new public type for the same guarantee.
- **Why a separate method name rather than a wider parameter:** PHP forbids widening an interface parameter in place for implementers, and a Host's existing class would stop loading. A separately named method keeps every existing store source- and behaviour-compatible.
- **Why reject rather than fall back:** switching a legacy store to its own clock, or to a truncated value, would silently change which time the decision uses (the situation DEC-016 exists to prevent).

## Consequences

- **Migration for a custom store.** Nothing is required on 64-bit PHP. A Host that supports 32-bit PHP implements the decimal interface that mirrors each caller-time interface it already implements, converting the decimal to its own representation without a native integer: the second is the decimal without its last three digits (`0` when it has three digits or fewer). A Host that does not will see `RateLimiterException` on 32-bit for these six operations instead of a wrong decision.
- **On 32-bit PHP the supported execution path** is the official Redis store, or a custom store that implements the decimal capability.
- **Behaviour change for a time above 2^53 − 1** passed directly to a native-integer method of the official store: it is now rejected as `Caller time in milliseconds is not representable.` (previously the Lua script or the expiry check rejected it); no real clock reaches it.

## Evidence

| Source | Used for | Class |
| --- | --- | --- |
| `tests/Unit/Repository/CallerTimeMillisecondsTest.php` | canonical decimal of an instant, validation and the typed rejection of each malformed or unrepresentable value, the native-integer hop on both widths | `MEASURED` |
| `tests/Unit/Service/CallerTimeStoreDecimalMillisecondsDispatchTest.php` | the six dispatch sites: exact decimal to a decimal store, native integer to a legacy store when it fits, rejection before the call when it does not (32-bit), own clock for a store without the capability | `MEASURED` |
| `tests/Integration/Store/CallerTimeDecimalMillisecondsRedisTest.php` | public Builder and runtime K4 and K4P decisions, key-rotation continuity, exact forwarding, expiry boundary and rejections on isolated Redis, 64-bit reference reproduced on actual 32-bit PHP | `MEASURED` |

## Relationship to Other Decisions

- **DEC-016:** additive caller-time capabilities (rule 1); this record adds a representation, not a clock authority.
- **DEC-017:** the non-negative semantic timestamp domain applies to the decimal; a negative value is rejected.
- **DEC-007:** the K4 generation-bound lifecycle and post-punishment re-entry whose time this carries; semantics unchanged.
- **DEC-038:** the decimal-string precedent for values a native integer cannot hold.

## Canonical Contract / Current Owner

`CallerTimeMilliseconds`, the four `CallerTimeDecimalMilliseconds*StoreInterface` interfaces, `CallerTimeStore`, `RedisFullCapabilityStore`, `docs/guides/USAGE_GUIDE.md`.

## Supersedes

None

## Superseded By

None
