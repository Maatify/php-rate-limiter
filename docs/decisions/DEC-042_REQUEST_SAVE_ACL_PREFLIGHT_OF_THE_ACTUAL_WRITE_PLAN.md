# DEC-042 — Request Save: ACL Preflight of the Actual Write Plan

## Decision ID

`DEC-042`

## Status

`ACTIVE` — approved by the **Owner** on [#261](https://github.com/Maatify/php-rate-limiter/issues/261) after the study [PR #269](https://github.com/Maatify/php-rate-limiter/pull/269) (merged at `main@3acf0258087a7bb89057dcf629f9bf8f5b38c246`); the execution assignment is [the Lead's comment on #261](https://github.com/Maatify/php-rate-limiter/issues/261#issuecomment-6999369733).

**Implementation status: approved, under review.** The implementation is carried by the PR that adds this record; until it is accepted and merged, `main` does not enforce it. #261 stays open until acceptance.

## Date

2026-11-19

## Decision Authority / Deciders

**Owner**, approving "a permission check of the save plan inside the `commit` script itself, after the state comparison and the plan validation and before the first write command", as recorded by the Lead on #261.

## Scope / Concern

What the request save (`RedisRequestOverlay::commitScript()`, DEC-037 item 4) does when the Redis account of the Host may not run a command the save needs, and which permissions the Host must grant it.

## Context

The study (`investigations/redis-acl-request-save-261/`, Redis 7.0.15, PHP `PHP_INT_SIZE` 4 and 8, two Host client shapes) measured, from outside Lua with exact `DUMP` and `PEXPIRETIME`:

- Refusals of a read command, of `EVAL` itself and of the key scope happen before any write and leave everything unchanged.
- A refusal of a **write** command inside `commit` (except `DEL`, the first call) left a partial application: keys before the refused one written, the refused key deleted or rewritten without its expiry. The comparison and the plan validation of DEC-037 item 4 finish before the first write but do not cover permissions, and a script error after the first write is not rolled back.
- A prototype that checked the plan with `redis.acl_check_cmd` before the first write left nothing changed in the measured cases. It used representative arguments and copied the save's branches; this record asks for the check to be derived from the actual plan.

## Decision

1. **The save builds its write plan first.** After the comparison (`o` fingerprints) and the plan validation, and before any write, `commit` derives from the validated entries the exact sequence of write commands it will issue: its real keys, arguments, order and batches (`DEL`, then `SET`, or `HSET`/`SADD`/`ZADD` in batches of 200 elements, then `PEXPIREAT` for a future expiry or `DEL` for an expiry already passed, nothing when there is no expiry). The single time snapshot (`__nowMs`) that decides the expiry branch is the one taken at the start of the script.
2. **The whole plan is checked, then executed.** Every command of the plan is checked with `redis.acl_check_cmd` (Redis 7.0, the existing minimum of this path); only if all pass, the same plan is executed. Nothing else changes in the writes: deletion semantics, the physical lifetime of a key, the absent/future/passed expiry branches and the order and batches of type rebuilding are as before.
3. **A refusal is a Redis error reply coded `NOPERM`**, `NOPERM request commit: the account may not run <COMMAND> (plan step <n> of <m>)`, raised before any write, deletion or change of expiry. It names the command and its position, never a key or a value. It reaches the Host through the executor as an error reply, as DEC-018 already provides for server errors: `RateLimiterException` through an executor that raises it, the client's own exception through a native bridge; it is **not** a `BackendFailureException`, so it raises no failure signal, no circuit report and no fallback consumption (DEC-015), and it is not a conflict, so the request is not evaluated again because of it.
4. **A real conflict still comes first.** The comparison runs before the plan is built: a changed key returns `0` and the request is evaluated again within the DEC-037 limits; a plan built from a stale read is never checked. The read-only `verify`, a request without changes (which sends no commit), the classification of read errors, transport failures and a reply lost after the save are unchanged.
5. **What the check does not claim.** It answers the ACL question for the commands of the plan at the moment of the save, atomically with the writes (a script runs without interleaving, so the account cannot change between the check and the writes). It is not a rollback and says nothing about other Redis errors (memory limit, read-only replica, client output limits): DEC-037's all-or-none claim is not extended to them.

## Rationale

- The refusal that the study showed to be dangerous is the only one that can follow a write. Checking the actual plan first turns it into a clean refusal at no extra round trip (the study measured no cost above noise).
- Deriving the check from the plan that is executed removes the drift risk of a hand-copied list and of representative arguments.

## Consequences

- **Host permissions** (documented in `docs/FAILURE_SEMANTICS.md` §2.7): the Host account needs `EVAL`, read/write access to the declared keys, the read commands the overlay uses, and the write commands of the save. The save-command permissions are now enforced as one preflight; the entry and read permissions keep failing earlier and unchanged.
- A Host whose account lacked a write command used to find a partial state; it now finds a `NOPERM` error and an unchanged keyspace.
- `redis.acl_check_cmd` requires Redis 7.0; this path already required 7.0 (`PEXPIRETIME`). No version or Cluster support is added.

## Relationship to Other Decisions

- **DEC-037** (item 4: compare, validate, then apply; item 5: conflicts recalculate): unchanged and extended by this record; **not superseded**. DEC-037 links here from its application notes.
- **DEC-015 / DEC-018**: the refusal is a server reply and keeps the Host-owned classification; it is never a transport failure.
- **DEC-029**, **DEC-036**, **DEC-041**: unchanged in the paths they own.
- Out of scope: the direct (non-overlay) store scripts and their `pcall` of `EXPIRE` (#270), #262, #263.

## Canonical Contract / Current Owner

`src/Repository/Redis/RedisRequestOverlay.php` (`commitScript()`) owns the preflight; `docs/FAILURE_SEMANTICS.md` §2.7 owns the Host permission documentation; `tests/Integration/Redis/RequestSaveAclRedisTest.php` is the regression (group `php32`).

## Supersedes

None.

## Superseded By

None.
