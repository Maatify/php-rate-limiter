# DEC-045 — A Writing Request Is Refused When a Key It Read Has an Expiry Lua Cannot Read Exactly

## Decision ID

`DEC-045`

## Status

`ACTIVE` — approved by the Lead in [PR #294](https://github.com/Maatify/php-rate-limiter/pull/294) on implementation commit [`d622791d`](https://github.com/Maatify/php-rate-limiter/commit/d622791d5353323f5f589fc7eed239c0482d1a0b). Refs [#287](https://github.com/Maatify/php-rate-limiter/issues/287).

## Date

2026-11-23

## Decision Authority / Deciders

**Lead**, in the #294 review. The refusal contract, the placement of the refusal at the save, and the carve-outs below are the Lead's decision.

## Scope / Concern

What the commit of `transactRequest()` on the official Redis store (`RedisRequestOverlay::commitScript()`) does with a request that **writes** when any key it read has a **physical expiry** at or above 2^53 milliseconds, the first integer a Lua number (an IEEE double) can no longer tell from its neighbour. It concerns the save and its conflict check only.

## Context

Lua receives the integer reply of `PEXPIRETIME` as a double. Below 2^53 the value is exact. At or above it the value is rounded, so the overlay's fingerprint of a key (which includes its expiry) is computed from a rounded value, and a save that writes the key back with `PEXPIREAT` writes the rounded value. Measured on Redis 7.0.15 from outside Lua with `DUMP` and `PEXPIRETIME`, on PHP `PHP_INT_SIZE` 4 and 8 (`tests/Integration/Redis/RequestSaveExpiryStabilityRedisTest.php`): an increment saved `9007199254740993` as `…992`, `9007199254740995` as `…996` and `9223372036854775000` as `…774784`; and a rival that changed only such an expiry could not be told apart by the fingerprint.

## Decision

1. **A request that writes is refused before its first write when any key it read has a live expiry at or above 2^53 ms.** The commit script answers an error reply `request commit: a key of the request has an expiry beyond the exactly readable range (2^53 ms); nothing was saved`, which never names a key. It is a server reply classified as every other commit refusal (DEC-042's `NOPERM request commit`); it is never retried and nothing is saved. The guard covers every key the request read, not only those it rewrites: a key it only read cannot be proven unchanged either (a rival that moves such an expiry to a neighbouring value is invisible to Lua), and a save over an unproven read would break the all-or-nothing contract.
2. **A changed state is a conflict first (DEC-037).** The refusal is evaluated after the fingerprint comparison of every key of the request. A key whose state moved between the read and the save, including an expiry that moved from below 2^53 to or above it (the fingerprint of an exactly read value never equals that of a rounded one), makes the commit answer "not current", so the request is evaluated again within its bounds. The refusal then applies to the evaluation that reads the value Lua cannot hold.
3. **The guard comes before the validation of the write plan.** A plan derived from a rounded expiry (for example the Redis maximum, whose rounded value exceeds the maximum) must be refused by this guard with its own message, not judged `malformed plan`.
4. **Reads are unchanged (DEC-040).** The overlay's live read keeps its historical behavior at every expiry, including the legacy lifecycle read inside `transactRequest()` and its refusal on a 32-bit build. A request that writes nothing is never refused by this decision, whatever the expiries it read.
5. **Below 2^53 ms, and for a key without an expiry, nothing changes.** The expiry is saved to the millisecond, or the key stays without one, and a rival that moves it by one millisecond is a conflict.
6. **The ACL check of the write plan is unchanged (DEC-042),** and the guard issues no command beyond the `PEXPIRETIME` the commit's read already uses.

## Rationale

- Lua offers no decimal text for an integer reply, so an exact comparison above 2^53 is not available; refusing is the only choice that never saves a wrong expiry or overwrites a rival silently.
- Placing the refusal at the save keeps reads and read-only requests exactly as accepted by DEC-040, and keeps the conflict path of DEC-037 first.

## Consequences

- **Policy durations versus direct calls.** The default policies and the package's own state use durations whose expiry is far below 2^53 ms (about the year 285,000), so no package-owned key reaches this range through them. A **direct** store call is not bound by the policies: on a 64-bit build `incrementAt()` accepts a TTL such as 9,007,199,254,741 seconds and creates an expiry above 2^53 ms (measured in `RequestSaveExpiryStabilityRedisTest`), and a Host-written key can carry one as well. A request that writes while it holds such a key is refused as above; the same key read or written directly keeps working. A 32-bit build cannot pass such a TTL, but can meet the range through a key written by another client.
- A request that wrote nothing keeps its answer; only a writing request pays the refusal.
- The `RequestSaveExpiryStabilityRedisTest` suite (groups `integration`, `php32`, `request-expiry-stability`) is the before/after verification.

## Relationship to Other Decisions

- **DEC-037**: unchanged and applied (conflict first, bounded re-evaluation). **DEC-040**: unchanged (item 3). **DEC-042**: unchanged (item 5). **Not superseded.**

## Canonical Contract / Current Owner

`src/Repository/Redis/RedisRequestOverlay.php` (`COMMIT`) and the `transactRequest()` documentation in `src/Repository/Redis/RedisFullCapabilityStore.php`; `tests/Integration/Redis/RequestSaveExpiryStabilityRedisTest.php`.

## Supersedes

None.

## Superseded By

None.
