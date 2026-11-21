# DEC-041 — Exclusive Request Ownership and Explicit Refusal of an Overlapping Call

## Decision ID

`DEC-041`

## Status

`ACTIVE` — **principle B** (approved by the Owner on 2026-11-18, Africa/Cairo, on [#260](https://github.com/Maatify/php-rate-limiter/issues/260), [record](https://github.com/Maatify/php-rate-limiter/issues/260#issuecomment-6964289182)) **and the design contract M1–M7** (approved by the Owner on 2026-11-18: «اعتمد», [record](https://github.com/Maatify/php-rate-limiter/issues/260#issuecomment-6967037636)), as specified in "Approved design contract" below. The accepted design is [PR #266](https://github.com/Maatify/php-rate-limiter/pull/266) at `f9045bd5ef0b4959e146f78edc2fca404aadfd9d` (tree `f46f789ab1252868a907055537310c15d061a551`, `investigations/fiber-request-ownership-260/EXCLUSIVITY_DESIGN.md` §3–9).

**Implementation status: accepted and merged.** The implementation was carried by the same [PR #266](https://github.com/Maatify/php-rate-limiter/pull/266) (accepted head `5a68b6cd6e5922c684f6400fae4a34e373a4a836`), [merged at `main@cacdb3a6e2571b68cdae6fe2a73639ea4d4f112e`](https://github.com/Maatify/php-rate-limiter/issues/260#issuecomment-6992722348); #260 was closed with that evidence. The description of `main@316b1de99e784fec95b40f849d83a04dec772e2b` under Context and Consequences is the historical state before that merge: at that commit the code did not enforce this decision. The approval does not supersede or amend any other decision except for the one sentence of DEC-037 named under Consequences.

## Date

2026-11-18

## Decision Authority / Deciders

**Owner**, who chose direction B over direction A on #260 and authorized design documents only: "approve direction B; prepare the executor assignment for #260 alone: the design of exclusivity and explicit refusal, covering the shared store and preserving nested calls, with no change to runtime code, merge, or release" (recorded by the Lead in the comment linked above, which is the source of this record).

The Owner later approved the design contract M1–M7 (see Status and "Approved design contract").

## Scope / Concern

Who may use the runtime graph and the store objects it is built on while one call is in flight: the exclusivity of a call over the in-memory state that belongs to one request (the held request state of the store, the Host clock reading, the held recovery signals, the failure flag, the sequential-recovery flag), and what happens to an independent call that begins meanwhile. It concerns object ownership in process memory, not Redis state shared between processes and not the correctness of independent graphs over the same Redis.

## Context

The investigation of #260 ([PR #266](https://github.com/Maatify/php-rate-limiter/pull/266), accepted at head `515b09f02396ddf41a137d54eba147d5f3ed6ca5`, tree `0941de150b8866a46b031cbfb46a0e1e0961b9db`) reproduced on actual 32-bit and 64-bit PHP, with Fibers as a deterministic scheduler, that when one runtime graph or one store object serves two calls whose execution overlaps:

- an independent call is answered before anything of it is saved, because the store runs any call made while one of its requests is open inside that request (`RequestAtomicStoreInterface`, the nested-call guarantee), and its effects are lost when the call holding the request is rejected;
- the Host clock reading of the call holding the request is used by a call that starts later on the same runtime;
- a backend failure met by one call is reported for the other, and a held recovery signal is emitted by the other call before its own save.

Two runtimes that share one store object share the request even though each has its own clock and signal buffer; runtimes with their own store and clock do not interact. The documentation at the time said nothing about overlapping calls, instance sharing or schedulers, so neither support nor prohibition was documented.

## Decision

1. **Exclusive ownership.** A runtime graph, together with every store object it is built on, serves **one independent call at a time**. A call is in flight from the moment it begins until it has returned or thrown and everything it held has been released.
2. **Explicit refusal.** An independent call that begins while another call is in flight on the same graph, or on any store object that graph shares with another graph, is **refused explicitly**. The refusal is made before the refused call can alter, consume or observe any state that belongs to the call in flight, and it is never converted into an answer of the rate limiter (no allow, no block, no fallback consumption).
3. **Nesting is preserved.** An operation that the call in flight performs by itself, within its own logical request, is not an independent call and is not refused. The supported nested behaviour of `RequestAtomicStoreInterface` and of the Host clock reading (one reading per call, kept by nested operations) stays.
4. **Documentation is not enforcement.** Where the principle cannot be enforced for a hosting pattern, the limit and the capability needed to enforce it must be stated; such a pattern is not counted as supported and is not left as undefined behaviour.
5. **Independent graphs stay supported.** Graphs that own their store object, clock and signal buffer remain independent callers of the same Redis; conflicts between valid requests of separate graphs or processes remain governed by DEC-037. The principle does not create a lock across workers or on a Redis namespace.

## Approved design contract (M1–M7)

These are the approved terms. The implementation may not substitute another mechanism; where this text is silent the accepted design document is the reference. The status of each term as implemented is the status of the implementation above.

1. **M1 — Mechanism.** Ownership is held per **resource object** (identity) in a package-owned internal coordinator over deduplicated resource sets. An entry acquires its whole set **all-or-none**, in a fixed order, in two phases: a **provider-free reservation first**, then the **execution-context identity lookup** under the reservation. Every reservation, lookup marker and cleanup belongs to the record or token the call itself created, and slots are released **last**, after signals, the clock reading and the lookup bookkeeping. A call is nested only when it comes from **the owner's execution context while no Host crossing of the owner is open**; Host crossing markers are a supplementary guard, never the sole proof of nesting.
2. **M2 — Context provider.** The default context is the current Fiber, held weakly. An **optional** `ExecutionContextProviderInterface` (synchronous, no re-entry, no yielding, no throwing, stable and unique keys inside one domain), `RateLimiterBuilder::withExecutionContextProvider()` and **one** optional trailing constructor parameter of `RedisFullCapabilityStore` are the only configuration paths. Each resource is bound to **one** context domain by a set-wide, failure-atomic `bindAll` with a ledger and rollback of only what that call bound; the binding is immutable while its provider lives, and mixed domains over one resource are refused. A configured provider is retained by the store and by the entry classes that use it, and **every active ownership scope** (an entry call, a direct store call that inherited its provider from a graph, a nested lookup) holds a **strong provider lease in its own frame** until its last release; the registry and tokens stay weak. A vacated binding is replaceable only when no owner, reservation or pending lookup exists. No ownership interface is required of Host stores.
3. **M3 — Refusal.** `Maatify\RateLimiter\Exception\OverlappingRequestException extends RateLimiterException`: an explicit refusal with no result DTO, no retry, and never converted into a backend failure, a concurrency block or fallback consumption.
4. **M4 — Nesting.** A nested call from the owner's context with no Host crossing joins the open request. The corresponding sentence of `RequestAtomicStoreInterface` and DEC-037 is reconciled to it; the atomic save, the re-evaluation and the independent outcome claim are unchanged.
5. **M5 — Admission.** Every independent public entry and every direct capability call is guarded, including the outcome claim and the health check; every shared resource is protected; delegating wrappers do not acquire twice; only pure local getters are exempt, with a reason. A graph over separate or partially shared stores acquires every distinct resource it uses.
6. **M6 — Readers.** Overlapping operational readers are **refused**, with no pass-through read; concurrent monitoring uses its own graph, store object and connection. The readers' current clock is unchanged.
7. **M7 — Contracts and limits.** The supported scheduler and callback contract, and its declared limits, are those of the accepted design; policy callbacks are guarded from the first implementation (the policy getters evaluated inside a call are marked as Host crossings, #260 review F266-10). An owner that is never unwound stays an owner (no abandon or timeout release); decorated aliasing of a store is outside the support; a non-cooperating custom store is protected at graph entries only. Open items O-1..O-5 are resolved: the proposed names, no alternative result metadata, no decorator discovery, no abandon or timeout-release API, custom policy guarding required.

## Rationale

Direction B repairs the observed failures by refusing the misuse instead of making overlapping calls safe: per-call ownership of every piece of request state (direction A) was judged a larger change across the store, clock, circuit breaker and engine, while exclusivity keeps the existing per-object state valid by guaranteeing that only one call uses it at a time. Silence about overlap had already produced silent loss and cross-call effects, which is why the refusal has to be explicit and has to be enforced where the shared state lives.

## Consequences

- At `main@316b1de99e784fec95b40f849d83a04dec772e2b`, when this record was written, the behaviour did not conform to the principle. That gap is closed: the implementation was accepted and merged with [PR #266](https://github.com/Maatify/php-rate-limiter/pull/266) at `main@cacdb3a6e2571b68cdae6fe2a73639ea4d4f112e`, and #260 is closed.
- The mechanism, the refusal shape, the scheduler contract, the compatibility effects and the verification plan are the approved design contract above; the detailed design is `investigations/fiber-request-ownership-260/EXCLUSIVITY_DESIGN.md`.
- **One sentence of an ACTIVE contract is reconciled, and no other:** the nested-call guarantee of `RequestAtomicStoreInterface` (and the matching application note of DEC-037): a nested call joins the open request only from the owner's execution context while no Host crossing is open (M4). The rest of DEC-037 is unchanged.
- The decision does not move the outcome claim (#258, DEC-036), the fallback accounting (#259), the retry budget, the signals contract or the clock rules.

## Relationship to Other Decisions

- **DEC-037:** unchanged. DEC-037 keeps owning the request overlay, its conditional save and its retry on Redis conflicts; this decision concerns who may use the in-memory request state, not how it is saved.
- **DEC-036:** unchanged. The outcome claim stays an independent atomic command.
- **DEC-015 / DEC-016:** unchanged. A refused overlapping call is neither an operational backend failure nor a clock concern.

## Canonical Contract / Current Owner

Implemented and accepted ([PR #266](https://github.com/Maatify/php-rate-limiter/pull/266), `main@cacdb3a6e2571b68cdae6fe2a73639ea4d4f112e`). The code owners are the ownership coordinator `src/Service/OwnershipRegistry.php` (with `ResourceSlot`, `CallScope`, `ContextDomain`, `FiberContextProvider` and `src/Contract/ExecutionContextProviderInterface.php`), the refusal `src/Exception/OverlappingRequestException.php`, the runtime entries (`RateLimiterEngine`, `FixedWindowSimpleRateLimiter`, the operational readers) and `RedisFullCapabilityStore`, `RequestAtomicStoreInterface` for the nested-call sentence, `RateLimiterBuilder::withExecutionContextProvider()`, `docs/FAILURE_SEMANTICS.md` and the usage guide. The investigation records are `investigations/fiber-request-ownership-260/RESULTS.md` and `EXCLUSIVITY_DESIGN.md`.

## Supersedes

None.

## Superseded By

None.
