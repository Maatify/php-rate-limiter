# DEC-051 — Request-Atomic Save with Fail-Closed Incomplete Overlay Read Sets

## Decision ID

`DEC-051`

## Status

`ACTIVE` — Owner-approved for this implementation batch in [#281 comment 7235113819](https://github.com/Maatify/php-rate-limiter/issues/281#issuecomment-7235113819), following [the selected design](https://github.com/Maatify/php-rate-limiter/issues/281#issuecomment-7235081976). Implementation and integration remain subject to Direct Technical Lead Review and separate merge authorization.

## Date

2026-11-28

## Decision Authority

**Project Owner**, approving the scoped F-G4-09 assignment linked above. This record supersedes DEC-037 under Decision Governance §9 for the request-atomic contract, preserving its unaffected guarantees and historical evidence.

## Scope / Concern

Whether an evaluation may be saved when an overlay step failed without delivering a complete, verifiable read set, and which error or verified conflict ends that attempt. No public signature, executor contract, Lua envelope, retry budget, failure-mode policy or admission outcome-claim boundary changes.

## Context

A Lua overlay command can read a key and then return a native error without returning its delta. For example, incrementing an unread score at PHP_INT_MAX raises the real Redis overflow error. A callback can catch that error and continue with a held write to another key. Without a completeness condition, the request can save that write while its read set omits the failing step's reads. The approved assignment requires a real-Redis before/after detector; the source analysis and earlier DEC-037 measurements are not claimed as new measurements here.

## Decision

1. **One request, held writes, repeatable evaluation.** RequestAtomicStoreInterface owns the guarantees: every write during the evaluation is held, later steps read their earlier held results, and a save is conditional on every read needed by the evaluation being provable and unchanged. A custom implementation with a trustworthy read set need not use the bundled adapter's private marker. An incomplete caught step does not grant permission to save. EvaluationPipeline uses this capability when its store implements it and its correlation store is the same object; other stores retain sequential writes. The unchanged production scripts run inside RedisRequestOverlay, whose supported redis.call/redis.pcall operations hold reads and writes, including rotation and expired-record cleanup.
2. **First incomplete overlay error, unchanged provenance.** RedisFullCapabilityStore records the first actual non-backend Throwable from overlay dispatch or reply/delta decoding, validation or import. It keeps the same object and does not infer a server-error classification from text. BackendFailureException has its separate first-backend marker and precedence. Validation before dispatch, semantic PHP validation after a complete delta, PING, independent outcome claims and direct operations do not acquire this marker merely because they throw.
3. **All-or-none delta import.** Decode and validate the existing entry shape into a temporary held-state map before publishing any entry to the request. A valid prefix of a malformed multi-entry delta never becomes trusted state for verification. This is the existing internal response contract, not a new validation scheme for every serialized Lua-state field or a guarantee against a malicious executor fabricating a well-formed response.
4. **Normal return cannot save an incomplete attempt.** After evaluation, first recorded backend failure wins as already fixed by F-G4-07. Otherwise a marked attempt throws the same first incomplete error without verification, retry or commit, even if no held writes remain or a rival changed held state. Following steps may continue to run in the overlay; none may cause this attempt to save.
5. **A thrown callback error and a known conflict.** Explicit backend and overlap exceptions keep their identities and no-retry boundaries. With an observed backend failure, a later Throwable is thrown unchanged without verification/retry, as F-G4-07 already requires. Otherwise verify only the previously trusted held read set: a real verify=0 permits bounded re-evaluation from a fresh attempt; an empty set or verify=1 throws the same error that ended the callback. A partial verify=1 does not prove the failed step's unknown reads current. When verification is reached, only integer/string 0 permits retry and only integer/string 1 means the supplied trusted held set is unchanged. Any other reply throws RateLimiterException('Malformed request verify response.') without retry; a Throwable from the executor propagates unchanged, including native ACL refusal. These verification errors take precedence over the callback error and are not replaced by the incomplete marker. No retry is justified by the marker alone, and no fresh read after the error repairs its provenance.
6. **Lifecycle and composition.** Root, E1 view, legitimate nested transaction and admission overlay share one marker. It is reset on every outer attempt and in its finally cleanup. A root clone never inherits transient request state; a view clone keeps its root relation. The next independent request is unpoisoned. Ownership/context/Host-crossing refusal remains DEC-041, and overlapping calls never join or retry.
7. **Conditional commit and bounded conflict handling.** One commit compares the trusted complete read set, validates the entire write plan and applies the held changes only on success. Known conflicts recalculate within at most 32 evaluations, a random pause growing to 16ms and the existing default 500ms monotonic budget; an attempt always gets its second evaluation after a known conflict. Exhaustion throws RateLimitConcurrencyException with nothing saved (DEC-029). The bundled limits belong to RedisFullCapabilityStore, not to every custom store.
8. **Save validation, ACL and expiry.** Unsupported overlay commands or keys not declared in KEYS are refused before any write. The supported strings, hashes, sets, sorted sets, expiry and DUMP/PEXPIRETIME fingerprint commands keep their existing mechanism; adding a store command still requires overlay support. State comparison precedes write-plan validation and actual-plan ACL preflight (DEC-042). A refused save is a native server error, not backend fallback. Writing requests refuse a read key's live physical expiry at or above 2^53ms before writing (DEC-045); reads and requests without writes retain their existing boundaries. No Lua, direct expiry-provenance (DEC-043), clock (DEC-016/017) or legacy-read exactness boundary (DEC-040) changes.
9. **Recovery, admission and signals.** A whole process() evaluation, open-fence admission reservation and circuit recovery lease/state transition are held in the request when composed on the same store. Backend failure reporting and recovery success reporting follow it. When circuit breaker and pipeline share the store, the recovery probe, guard and evaluation compose in one transactRequest(): competing leases are serialized by the conditional save and the loser recalculates against the changed circuit without probing again. Rejection saves neither lease nor transition; CB_RECOVERED/re-entry signals wait for the save. Only a BackendFailureException in the relevant recovery-only path triggers repeating that step as a single lease-plus-state save; if that repeated step raises BackendFailureException too, the outage path keeps both in process. A lease saved before its reply was lost makes the repeat answer without taking it again. A BackendFailureException in the closing-request failure-mode path announces no recovery success and returns the configured failure-mode answer; the next request can recover. This does not describe every failed closing request: non-backend incomplete-overlay, malformed-verify and native verification errors propagate under their own exception contracts, while RateLimitConcurrencyException has its separate concurrency-block answer. Admission outcome claim plus settlement remains an independent atomic operation (DEC-036 and the #258 application of DEC-037); a rejected or recalculated later evaluation never rolls it back or claims it again. claimPostPunishmentReentry remains its own script. Pending-permit/configuration and closed-fence settlement retain DEC-048/049/050.
10. **Backend precedence and limits of knowledge.** An observed backend failure is not retried; even when caught by a step, normal return throws the first backend failure. A later non-backend callback Throwable keeps its identity (F-G4-07). PING transport failure handled by isHealthy() remains a failed health check not observed by the transaction; recovery renewal and lease may therefore save together as before. A failure before a save reaches Redis saves nothing; a lost reply after a commit was sent can follow a full save, with unknown outcome and at-most-once signals. No rollback guarantee is added for OOM, read-only replicas or other Redis runtime errors after writes begin. Only classified backend failures enter circuit/fallback accounting (DEC-015/018).
11. **Other operations stay available.** The observation/device-cap group commit operations keep their direct contracts and use the same overlay when held. Current/Previous rotation, E1 ownership and clone boundaries (DEC-046/#306), independent Redis callers and bounded retry budgets are unchanged.

## Rationale

No error response without a usable delta proves that all reads of that step were captured. Refusing a normal-return save prevents an omitted-read decision without wrapping native errors, suppressing known-conflict recovery, changing Lua or inventing a second store protocol. Staging the complete delta avoids promoting an invalid prefix to trusted state.

## Consequences and Verification

The scoped change intentionally changes a previously successful normal return after a caught incomplete step into the first native/decoding/validation error. A callback must remain repeatable for proven conflicts. Consumers must not classify such non-backend errors as outages or retry as if a failed commit were known not to have happened.

The execution assignment requires A–E real-Redis regression evidence on PHP32/64, including actual overflow, rivals, exact snapshots, error identity, malformed replies, mixed failures, admission/nesting/clones and existing recovery/ACL/expiry/ownership suites. Historical DEC-037 performance/concurrency evidence remains historical; this record does not re-label it as testing of the new completeness rule.

## Relationship to Other Decisions

DEC-037 is superseded, with its historical rationale/context preserved. Existing historical references to its request contract are read with this successor's completeness boundary. DEC-015/016/017/018/029/036/040/041/042/045/046 and admission decisions remain unchanged; in particular verify ACL failures keep their own error, legacy expiry semantics do not change, and independent claims do not become transactional rollback.

## Canonical Contract / Current Owner

RequestAtomicStoreInterface, RedisFullCapabilityStore::transactRequest()/evalInRequest(), docs/FAILURE_SEMANTICS.md, RATE_LIMITER_PACKAGE_REFERENCE.md and docs/guides/USAGE_GUIDE.md own the current contract. RedisRequestOverlay keeps the unchanged Lua mechanism; EvaluationPipeline keeps its composition boundary.

## Supersedes

DEC-037

## Superseded By

None.
