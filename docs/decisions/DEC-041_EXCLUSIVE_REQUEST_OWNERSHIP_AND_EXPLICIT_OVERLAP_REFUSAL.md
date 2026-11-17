# DEC-041 — Exclusive Request Ownership and Explicit Refusal of an Overlapping Call

## Decision ID

`DEC-041`

## Status

`ACTIVE` — for **principle B only**, approved by the Owner on 2026-11-18 (Africa/Cairo) on [#260](https://github.com/Maatify/php-rate-limiter/issues/260) ([record of the approval](https://github.com/Maatify/php-rate-limiter/issues/260#issuecomment-6964289182)).

**Implementation status: not implemented.** At `main@316b1de99e784fec95b40f849d83a04dec772e2b` and at the head of the investigation PR the code does **not** enforce this principle: an overlapping independent call is still held silently in another call's request (see Context). The approval of the principle authorizes design documents only. It is not authority to change runtime code, interfaces, exceptions, Lua, tests, defaults, the pin, or workflows, and it does not supersede or amend any other decision.

## Date

2026-11-18

## Decision Authority / Deciders

**Owner**, who chose direction B over direction A on #260 and authorized design documents only: "approve direction B; prepare the executor assignment for #260 alone: the design of exclusivity and explicit refusal, covering the shared store and preserving nested calls, with no change to runtime code, merge, or release" (recorded by the Lead in the comment linked above, which is the source of this record).

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

## Rationale

Direction B repairs the observed failures by refusing the misuse instead of making overlapping calls safe: per-call ownership of every piece of request state (direction A) was judged a larger change across the store, clock, circuit breaker and engine, while exclusivity keeps the existing per-object state valid by guaranteeing that only one call uses it at a time. Silence about overlap had already produced silent loss and cross-call effects, which is why the refusal has to be explicit and has to be enforced where the shared state lives.

## Consequences

- The behaviour at the time of this record does not conform to the principle; the gap is tracked by #260, which stays open. A later, separately authorized work unit has to implement and verify it.
- Proposals for the mechanism, the refusal shape, the supported-scheduler contract, the compatibility effects and the verification plan exist only as **unapproved proposals** in `investigations/fiber-request-ownership-260/EXCLUSIVITY_DESIGN.md`. They are not part of this decision.
- The design may show that a text of an ACTIVE contract has to change (for example the nested-call guarantee of `RequestAtomicStoreInterface`, described in DEC-037 item 7). Any such change needs its own approval before implementation; this decision does not make it.
- The decision does not move the outcome claim (#258, DEC-036), the fallback accounting (#259), the retry budget, the signals contract or the clock rules.

## Relationship to Other Decisions

- **DEC-037:** unchanged. DEC-037 keeps owning the request overlay, its conditional save and its retry on Redis conflicts; this decision concerns who may use the in-memory request state, not how it is saved.
- **DEC-036:** unchanged. The outcome claim stays an independent atomic command.
- **DEC-015 / DEC-016:** unchanged. A refused overlapping call is neither an operational backend failure nor a clock concern.

## Canonical Contract / Current Owner

None yet. The principle is approved but not implemented, so no standard, interface or code currently owns it. The investigation records are [PR #266](https://github.com/Maatify/php-rate-limiter/pull/266) and `investigations/fiber-request-ownership-260/RESULTS.md`; the unapproved design proposal is `investigations/fiber-request-ownership-260/EXCLUSIVITY_DESIGN.md`.

## Supersedes

None.

## Superseded By

None.
