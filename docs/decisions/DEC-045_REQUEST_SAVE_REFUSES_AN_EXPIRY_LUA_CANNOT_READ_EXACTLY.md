# DEC-045 — Request Save Refuses a Rewritten Key Whose Expiry Lua Cannot Read Exactly

## Decision ID

`DEC-045`

## Status

`PROPOSED` — decided by the Lead in the review of [PR #294](https://github.com/Maatify/php-rate-limiter/pull/294) ("adopt refusal before the first write when the exactness of the comparison in the save cannot be proven"), and recorded in the same Draft. It becomes `ACTIVE` when that PR is accepted; until then it is not implementation authority beyond that PR. Refs [#287](https://github.com/Maatify/php-rate-limiter/issues/287).

## Date

2026-11-23

## Decision Authority / Deciders

**Lead**, in the #294 review. The refusal contract, the placement of the refusal at the save, and the carve-outs below are the Lead's decision.

## Scope / Concern

What the commit of `transactRequest()` on the official Redis store (`RedisRequestOverlay::commitScript()`) does with a key whose **physical expiry** is at or above 2^53 milliseconds, the first integer a Lua number (an IEEE double) can no longer tell from its neighbour. It concerns the save and its conflict check only.

## Context

Lua receives the integer reply of `PEXPIRETIME` as a double. Below 2^53 the value is exact. At or above it the value is rounded, so the overlay's fingerprint of a key (which includes its expiry) is computed from a rounded value, and a save that writes the key back with `PEXPIREAT` writes the rounded value. Measured on Redis 7.0.15 from outside Lua with `DUMP` and `PEXPIRETIME`, on PHP `PHP_INT_SIZE` 4 and 8 (`tests/Integration/Redis/RequestSaveExpiryStabilityRedisTest.php`): an increment saved `9007199254740993` as `…992`, `9007199254740995` as `…996` and `9223372036854775000` as `…774784`; and a rival that changed only such an expiry could not be told apart by the fingerprint.

## Decision

1. **A save that would rewrite a key whose live expiry is at or above 2^53 ms is refused before its first write.** The commit script answers an error reply `request commit: a key to be saved has an expiry beyond the exactly readable range (2^53 ms); nothing was saved`. It is a server reply classified as every other commit refusal (DEC-042's `NOPERM request commit`); it is never retried and nothing is saved.
2. **A changed state is a conflict first (DEC-037).** The refusal is evaluated after the fingerprint comparison of every key of the request. A key whose state moved between the read and the save, including an expiry that moved from below 2^53 to or above it (the fingerprint of an exactly read value never equals that of a rounded one), makes the commit answer "not current", so the request is evaluated again within its bounds. The refusal then applies to the evaluation that reads the value Lua cannot hold.
3. **Reads are unchanged (DEC-040).** The overlay's live read keeps its historical behavior at every expiry, including the legacy lifecycle read inside `transactRequest()` and its refusal on a 32-bit build. The refusal never fires for a request that rewrites no key, or for a key the request only read.
4. **Below 2^53 ms, and for a key without an expiry, nothing changes.** The expiry is saved to the millisecond, or the key stays without one, and a rival that moves it by one millisecond is a conflict.
5. **The ACL check of the write plan is unchanged (DEC-042),** and the new check issues no command beyond the `PEXPIRETIME` the commit already uses.

## Rationale

- Lua offers no decimal text for an integer reply, so an exact comparison above 2^53 is not available; refusing is the only choice that never saves a wrong expiry or overwrites a rival silently.
- Placing the refusal at the save keeps reads and read-only requests exactly as accepted by DEC-040, and keeps the conflict path of DEC-037 first.

## Consequences

- A request that rewrites a key whose expiry is at or above 2^53 ms (about the year 285,000) is refused instead of saved a few hundred milliseconds off. No package-owned key carries such an expiry; a Host-written one is outside the contract.
- The `RequestSaveExpiryStabilityRedisTest` suite (groups `integration`, `php32`, `request-expiry-stability`) is the before/after verification.

## Relationship to Other Decisions

- **DEC-037**: unchanged and applied (conflict first, bounded re-evaluation). **DEC-040**: unchanged (item 3). **DEC-042**: unchanged (item 5). **Not superseded.**

## Canonical Contract / Current Owner

`src/Repository/Redis/RedisRequestOverlay.php` (`COMMIT`) and the `transactRequest()` documentation in `src/Repository/Redis/RedisFullCapabilityStore.php`; `tests/Integration/Redis/RequestSaveExpiryStabilityRedisTest.php`.

## Supersedes

None.

## Superseded By

None.
