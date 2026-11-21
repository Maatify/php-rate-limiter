# DEC-043 — Direct Calls: Provenance of a Refused Expiry (`NOPERM` against a Range Refusal)

## Decision ID

`DEC-043`

## Status

`ACTIVE` — approved by the **Owner** on [#270](https://github.com/Maatify/php-rate-limiter/issues/270) after the study [PR #276](https://github.com/Maatify/php-rate-limiter/pull/276) (merged at `main@971abb58ef542aa72758f2fea406eff1110085f1`), as recorded by the Lead in the executor assignment on #270.

**Implementation status: approved, under review.** The implementation is carried by the PR that adds this record; until it is accepted and merged, `main` does not enforce it. #270 stays open until acceptance.

## Date

2026-11-21

## Decision Authority / Deciders

**Owner**, approving the contract for the classification of expiry failures in direct calls to the official Redis store.

## Scope / Concern

What a **direct** call to `RedisFullCapabilityStore` (a store call made outside `transactRequest()`) reports when Redis refuses one of the expiry commands (`EXPIRE`, `PEXPIRE`, `PEXPIREAT`) its script runs under `redis.pcall` (LT-P2-12).

## Context

The study (`investigations/expiry-provenance-270/`, Redis 7.0.15, PHP `PHP_INT_SIZE` 4 and 8, RESP and phpredis) measured, from outside Lua with exact `DUMP` and `PEXPIRETIME`, that two different refusals reached the caller with **the same text**, `expiry not representable`:

- **An ACL denial** of the expiry command (the account may not run `EXPIRE`): the `pcall` reply is `ERR The user executing the script can't run this command or subcommand`; Redis counts `rejected_calls=1` and writes an `ACL LOG` entry.
- **A genuine range refusal** (a lifetime Redis cannot represent): the `pcall` reply is `ERR invalid expire time in 'expire' command`; Redis counts `failed_calls=1` and writes no `ACL LOG` entry.

Only Redis-side evidence told them apart. The caller saw a range problem when the real problem was a missing permission. In both cases the script had already deleted what it created, so the keyspace was unchanged.

## Decision

1. **A refusal of an expiry command that the account may not run is `NOPERM`.** When a direct script's expiry command fails, the script checks **the same command with the same arguments** with `redis.acl_check_cmd` (Redis 7.0) inside the same script. The ACL result is the basis of the classification: a command the account may not run answers a Redis error reply coded `NOPERM`, `NOPERM this user has no permissions to run the '<command>' command`, which names the command (lower case) and **never the key or a value**.
2. **A genuine range refusal keeps its text.** Any other failure of the expiry command, including `acl_check_cmd` answering "allowed", answers `expiry not representable` exactly as before. The existing range preflight, the order of operations and the cleanup of the keys the call created are unchanged. The cleanup deletes the keys **this call created**; it does not restore a key the branch had already deleted or rewritten before the refusal (a stale pair removed before its fresh write, the content of an existing key rewritten before its expiry). An unchanged keyspace after a refusal is evidenced only for the measured states (a new key, an existing key and the prerequisite states of the study); this record claims no state invariance beyond them.
3. **A missing or failing check never changes the old answer.** If `redis.acl_check_cmd` is absent, or raises (it raises for a command or arity Redis does not know), the answer stays `expiry not representable`.
4. **The classification is the same for the three expiry commands and for every direct branch that applies one.** The 38 call sites of the shared helper (`EXPIRE`, `PEXPIRE` and `PEXPIREAT`; first applications, refreshes of an existing key and re-created keys) are inventoried, with their coverage, in `investigations/expiry-provenance-270/README.md`.
5. **The caller-side classes do not change.** A refusal is a server reply and keeps the Host-owned classification (DEC-018): it is a `RateLimiterException` on the RESP client and the client's own exception on phpredis, never a `BackendFailureException`. A proven connection failure keeps `BackendFailureException` (DEC-015 / DEC-018), with the phpredis reconnect behaviour measured by the study. The public executor contract and the public exception classes are unchanged.
6. **Request overlay and request save are unchanged (DEC-042).** Every store script also runs unchanged against the request overlay of `transactRequest()` (DEC-037), where no real expiry command is issued. The overlay hides `acl_check_cmd` (`false`) from the script, so an overlaid evaluation answers exactly as before, and the DEC-042 preflight of the actual write plan remains the only ACL judge of a held request. No request-save behaviour changes.

## Rationale

- Redis itself answers the permission question atomically inside the script: no extra round trip, no race between the failure and the check, and the arguments are the ones the script used (no representative copy to drift).
- Keeping `expiry not representable` for everything else preserves the measured contract for a real range refusal and for every client that already matches on it.
- Naming the command without the key matches DEC-042 and avoids leaking key material into a message.

## Consequences

- A Host whose account lacks `EXPIRE` / `PEXPIRE` / `PEXPIREAT` now finds `NOPERM … '<command>' …` instead of a message that read as a range problem. A Host that matched the old text for an ACL denial must match the `NOPERM` prefix.
- Redis older than 7.0 has no `acl_check_cmd`: such a server keeps the old answer for both refusals (the library's other paths already require 7.0 for `PEXPIRETIME`).
- **Coverage:** the Docker evidence reaches all 38 modified sites with a denial. 31 are reached by denying the command; the other 7 are the second expiry of a pair of keys (a data key, then its metadata key), reached with Redis ACL selectors that let the command run on the first key and refuse it on the second (`investigations/expiry-provenance-270/fix-coverage.md`).
- A refusal of the expiry command on **some keys only** (an ACL selector or a key scope that lets the command run on one key of a pair and not on the next) is classified `NOPERM` as well, because `redis.acl_check_cmd` checks the command on its arguments including the keys; the message still names the command only. This is measured for the second expiry of every key pair (see coverage below).
- Admission scripts that call Redis with an unguarded `redis.call` are not changed and are not part of this decision.

## Relationship to Other Decisions

- **DEC-015 / DEC-018**: unchanged and applied (operational failure bound; the Host/adapter interprets replies; `BackendFailureException` only for transport).
- **DEC-037 / DEC-042**: unchanged; the overlay is explicitly separated (item 6). **Not superseded.** DEC-042 lists this concern as out of scope; this record owns it.
- **DEC-039**: the `…AtMilliseconds` decimal-string variants are unchanged; the decimal `EVAL` control for a lifetime outside native `int` on 32-bit is how the study reached a genuine range refusal.
- Out of scope: #261, `transactRequest()` behaviour, #267, #268.

## Canonical Contract / Current Owner

`src/Repository/Redis/RedisFullCapabilityStore.php` (`LUA_EXPIRY_JUDGE`, used by every direct script) owns the classification; `src/Repository/Redis/RedisRequestOverlay.php` owns the explicit separation (`acl_check_cmd = false`); `docs/FAILURE_SEMANTICS.md` (LT-P2-12) owns the documented contract; `tests/Integration/Redis/ExpiryProvenanceDirectStudy270Test.php` is the before/after verification (groups `redis-expiry-provenance-270`, `php32`) and `tests/Integration/Redis/RequestSaveAclRedisTest.php` the direct-call regression.

## Supersedes

None.

## Superseded By

None.
