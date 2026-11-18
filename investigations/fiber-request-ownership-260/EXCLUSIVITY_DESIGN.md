# #260 — Design proposal: exclusive request ownership and explicit refusal of an overlapping call (revision 2)

**Status of this document: a design proposal for review. Nothing here is implemented, and nothing here except the principle of DEC-041 is approved.**
It authorizes no change to `src/`, interfaces, DTOs, exceptions, Lua, tests, runners, Composer, workflows, the pin or defaults. No prototype was built, nothing was executed to produce this revision, and every statement about cost is an estimate.

Markers: **[APPROVED]** the principle of [DEC-041](../../docs/decisions/DEC-041_EXCLUSIVE_REQUEST_OWNERSHIP_AND_EXPLICIT_OVERLAP_REFUSAL.md) (direction B, Owner, 2026-11-18), and nothing else. **[PROPOSED]** a design detail recommended here. **[APPROVAL M#]** a material item that needs its own approval, listed in section 11. **[OPEN]** a question left to a decision. **[ESTIMATE]** an unmeasured size or cost statement.

Baseline: `main@316b1de99e784fec95b40f849d83a04dec772e2b` (tree `fe75547412fcb21b695644b21b86b387fc26bd31`). Accepted evidence: [PR #266](https://github.com/Maatify/php-rate-limiter/pull/266) at `515b09f02396ddf41a137d54eba147d5f3ed6ca5` (`RESULTS.md`, `FiberRequestOwnershipRedisTest`, 14 characterization tests on PHP_INT_SIZE 8 and 4). #258 (outcome claim as its own command) and #259 (fallback allowance charged once from the chosen answer) are taken as given.

## 0. What changed from revision 1 (head `e209253a`) and which review finding each change closes

| Review finding | Revision 1 | Revision 2 |
| --- | --- | --- |
| F266-05: crossing depth alone cannot tell a legitimate nested call from an independent call when a direct `transactRequest($evaluate)` callback suspends | O-8 left that case unrefused and replaced test 1 with an easier variant | The owner carries an **execution-context identity** (default: the current Fiber, optional Host provider). A call is nested only if it comes from **the owner's context and no Host crossing is open**; crossing markers are a supplementary guard, not the proof. Case 1 stays a required **refused** case; the callback contract is enumerated (3.7) |
| F266-06: checks keyed on `requestState`, direct capabilities and claims exempt, owner without a transaction unguarded | `requestState !== null` as the independence test; claims and PING exempt | Admission is decided from the **owner state of the resource**, never from `requestState`; **every backend operation is admitted** (a direct capability, claim or PING included) with a complete call lifetime; only pure local getters are exempt, with a reason (sections 3.5, 4) |
| F266-07: one unspecified store slot; the Builder accepts separate rate-limit, correlation and circuit stores | "the store" had the owner slot; stores without the capability were documented as unprotected | Ownership is per **resource object**; an entry acquires the deduplicated set of every store, clock and breaker object its graph uses, all-or-none and in canonical order, **before the first Host call or write**; partially shared graphs contend on the shared object. One primary behaviour for stores without cooperation: **every graph entry protects them** (3.2, 5) |
| F266-08: a public Host interface returning the concrete `CallOwnership` | `CallOwnershipStoreInterface` | **Removed.** The coordinator is package-owned and keyed by object identity, so no Host store has to implement or construct anything. The only public additions are an exception class and an **optional** context-provider interface (3.6, 8) |

## 1. What the design has to achieve

[APPROVED] B: a runtime graph and every store object it is built on serve one independent call at a time; an independent call that begins while another is in flight is refused explicitly, before it can alter, consume or observe any state of the call in flight, including when two runtimes share a store object; legitimate nesting stays; independent graphs stay supported; documentation alone is not enforcement.

Evidence the mechanism has to answer (demonstrated in #266): an independent call joins the open request of another and is answered before it is saved; the Host clock reading of the call in flight is used by a later call; a backend failure of one call is reported for the other; a held recovery signal is released by another call before its save; two runtimes on one store object share the request but not the clock or signal buffer; a Host callback suspended inside a direct `transactRequest()` lets an independent store call join silently.

Out of scope: Redis conflicts between valid requests of separate graphs or processes (DEC-037, unchanged), the outcome claim (#258/DEC-036), the fallback allowance (#259), any change of values, time-outs, DTOs, defaults, the clock rules, and the place where a claim or a probe is saved or signals are sent.

## 2. Definitions [PROPOSED]

- **Resource.** An object that holds in-process state belonging to one request, or through which such state is reached. Concretely, per graph: the rate-limit store, the correlation store, the circuit-breaker store (and the admission store, which is the rate-limit store), the `RequestScopedClock` (its `held` reading), the `CircuitBreaker` (its `heldSignals`), and the engine (its `sequentialRecovery` flag and the final fallback charge). Resources are identified by object identity.
- **Resource set of an entry.** The deduplicated set of the resources an entry's call may touch (section 5.1 inventories it per composition path).
- **Public entry.** A method a Host calls on the graph: `limit()`, `consume()`, `claimPostPunishmentReentry()`, `revokeAccountBinding()`, `bindingEpoch()`, `openAdmissionFence()`, `rebindAdmissionSharedConfig()`, and the operational readers' `read*()`. The library never calls its own public entries, so a public entry is **always** an independent call. The guard sits on the class that **owns the resources** (`RateLimiterEngine`, `FixedWindowSimpleRateLimiter`, the readers); a delegating wrapper such as `CompositeRateLimiterRuntime` forwards to that entry and does not acquire, so one call is never acquired twice and cannot refuse itself.
- **Direct capability call.** A call by a Host or low-level user to a method of a store object (`transactRequest()`, `acquireProbeLease()`, `increment*`, claims, `isHealthy()`, …) outside a public entry.
- **Logical request (a call).** Everything one independent call does from acquisition to release: every attempt of `transactRequest()`, the abandoned attempts, the recovery step and its sequential repetition, the pipeline, the admission scripts, the breaker's report, the release of held signals, the final fallback charge and the clearing of the clock reading.
- **Execution context.** The identity of the stack of control that is running: a Fiber, a coroutine, or the main stack.
- **Host crossing.** The library calling code it does not own: the Redis executor, the signal emitter, the Host clock, the device identity resolver, and policy callbacks.
- **Owner / token.** The single `CallToken` a call holds on every resource of its set while it is in flight. It records the owner's execution context and a counter of open Host crossings.
- **Nested operation.** An operation made on a resource owned by the caller's own token, from **the owner's execution context while no Host crossing of that token is open**.

## 3. Recommended mechanism (one): resource-set ownership with execution-context identity, supplemented by Host-crossing markers [PROPOSED]

### 3.1 The rule, and why two proofs

A call reaches a resource in one of two ways, and the outcome is decided by the owner state of that resource alone:

| Owner on the resource? | Caller is the owner's execution context? | A Host crossing of the owner is open? | Public entry (acquire) | Direct capability call or any operation on the resource (admit) |
| --- | --- | --- | --- | --- |
| no | n/a | n/a | acquire the set; the call becomes the owner | the call becomes a short-lived owner for the whole method (3.5) |
| yes | any | any | **refuse** (a public entry is never nested) | see the next rows |
| yes | **yes** | **no** | n/a | **allowed: nested** (no token, no cost beyond the check) |
| yes | yes | **yes** | n/a | **refuse**: it comes from inside Host code (a re-entrant callback) |
| yes | **no** | any | n/a | **refuse**: a different execution context is an independent call |

Why both proofs are needed:

- **Identity alone** would let a re-entrant call from an executor or emitter callback, made on the owner's own stack, pass as nested when the Host has no identity provider for its scheduler. The crossing marker refuses it.
- **Crossing alone** (revision 1) cannot separate "the owner's callback calls a capability" from "the owner's callback is suspended and another call arrives": both have an owner, an open request and no crossing open. The execution context separates them: the second arrives from another context.

The two do not replace each other: identity is the proof that a call is nested; the crossing marker refuses what identity cannot see (the same context re-entering through Host code) and covers schedulers whose context cannot be identified.

### 3.2 Resources, the coordinator, and who constructs it [PROPOSED]

- **Package-owned coordinator.** `OwnershipRegistry` is an internal class holding a process-wide `WeakMap` from a resource object to its current `CallToken` (or none). It is keyed by object identity, so two graphs that share an object contend on the same slot with no cooperation from that object. It is not a public type; no Host store implements or constructs anything. A resource object that is garbage-collected drops out of the map by itself, so a long-lived worker does not accumulate entries.
- **Resource discovery.** Each package class that is a public entry or a collaborator exposes an `@internal` method that returns the resources it owns or reaches (`ownedResources(): list<object>`), computed at construction from its collaborators and deduplicated by object identity. Entry classes combine the sets of their collaborators. Consequently an entry class constructed **directly** (without the Builder) is protected exactly like a Builder-built one; no optional constructor parameter is needed.
- **Stores that do not cooperate.** A Host-implemented store is a resource like any other: every graph entry that uses it acquires its slot, so two graphs sharing it are protected **at the graph entries** without the store doing anything. This is the one primary behaviour for stores without cooperation.
- **Package-owned stores admit their own direct calls.** `RedisFullCapabilityStore` calls the registry for `$this` at its own entries (3.5), so a direct overlapping call to it is refused too.

### 3.3 Acquisition, refusal and release (all-or-none) [PROPOSED]

```text
acquire(resources, entry):                    // a public entry; before the clock, the breaker, any Host call or any write
    set   = dedupe(resources) ordered by a fixed canonical order (object id)
    token = new CallToken(context = provider.current(), hostDepth = 0)
    taken = []
    for r in set:
        if registry.owner(r) != none:         // any owner, whoever it is
            for t in reverse(taken): registry.clear(t, token)   // only slots this call took; never another owner's
            throw OverlappingRequestException(entry)
        registry.set(r, token); taken.push(r)
    return token
```

- **No partial hold.** A refused acquisition leaves every slot exactly as it found it; it clears only what the refused call itself took, never another call's slot. A third call can acquire the resources a refused call briefly held.
- **Deduplication.** The aggregate store passed as rate-limit, correlation and circuit-breaker store is one resource; it is acquired once, so a call cannot refuse itself.
- **Canonical order** makes the sequence deterministic; with a single-threaded runtime it also removes any ordering dependence between two graphs that share part of their sets.
- **Release.** At the outermost `finally` of the entry, in reverse order of acquisition, after everything else the call held has been released: (1) held signals are released (emission is a Host crossing; the owner is still set), (2) the clock reading is cleared, (3) the slots are cleared, last. `release` is idempotent for a released token and refuses to clear a slot that does not hold the caller's token (an internal integrity error, never releasing another owner's slot).

### 3.4 Execution-context identity [PROPOSED; the provider is APPROVAL M2]

- **Default provider (package-owned, no Host action):** `Fiber::getCurrent()`. The token stores a `WeakReference` to that Fiber (or a marker for the main stack) and compares identity with `===`. It does not use `spl_object_id`, so a reused id cannot match a different Fiber.
- **Optional Host provider** for schedulers whose contexts are not PHP Fibers (a coroutine extension, for example):

```php
interface ExecutionContextProviderInterface
{
    /** @return int|string|object|null opaque identity of the running execution context; equal only for the same context while it lives */
    public function currentContext(): int|string|object|null;
}
// RateLimiterBuilder::withExecutionContextProvider(ExecutionContextProviderInterface $provider): self   (optional, default: the Fiber provider)
```

- **Honest limit:** without a provider, a non-Fiber scheduler's contexts all look like the main stack, so identity cannot separate them; the crossing marker still refuses overlapping calls that arrive while the owner is inside a guarded crossing (executor, emitter, clock, resolver). What it cannot refuse is overlap that happens inside an unguarded Host callback of a non-Fiber scheduler (3.7). The mechanism does not claim to recognize every coroutine implementation from `Fiber::getCurrent()`.
- **Compatibility.** A Host on ordinary sequential PHP or on Fibers changes nothing. The provider is optional and additive.

### 3.5 Admission of direct capability calls and transactions: complete lifetimes, no `requestState` test [PROPOSED]

`admit(resource, entry)` is called first by every operation that touches a backend or owned state, **before** any state of the resource is inspected or altered and before any callback runs:

```php
// Package-owned store: every public capability that sends a command or opens a request.
private function guarded(string $entry, callable $operation): mixed
{
    $own = OwnershipRegistry::admit($this, $entry);   // owner exists + same context + no crossing: returns null (nested)
                                                       // owner exists otherwise: throws OverlappingRequestException
                                                       // no owner: acquires a short-lived token and returns it
    try { return $operation(); }
    finally { if ($own !== null) { OwnershipRegistry::release($own); } }
}
```

- **A direct capability has a lifetime even when it issues one Lua command.** With no owner it becomes a short-lived owner for the whole method, including its Host executor crossing, so another call arriving while it is suspended in that crossing is refused (counterexample trace F266-06).
- **No `requestState` test decides independence.** `requestState` keeps its present meaning for the owner's own nesting only (a nested `transactRequest()` joins the owner's request). Admission happens first and does not depend on whether a request is open: the window in which an owner exists and `requestState` is empty (before the first transaction, during the Host clock call, after the held state was cleared and while the commit runs) is covered because the token is held for the whole call.
- **`transactRequest()`:**

```php
public function transactRequest(callable $evaluate): mixed
{
    $own = OwnershipRegistry::admit($this, 'transactRequest');       // before the callback and before any state is inspected
    try {
        if ($this->requestState !== null) { return $evaluate(); }   // the owner's nested transaction, as today
        /* the existing attempt loop, unchanged, rethrowing OverlappingRequestException without retry */
    } finally { if ($own !== null) { OwnershipRegistry::release($own); } }
}
```

  A no-write direct transaction (a callback that changes nothing, which sends no command) is admitted and released like any other, so it cannot slip past a command-level check.
- **Claims and PING are not exempt.** `claimAdmissionPermitWithOutcome()` stays its own atomic Redis command (#258, DEC-036), but its **caller** is admitted like any other: inside the owning call it is nested (same context, no crossing) and runs; from an unrelated overlapping caller it is refused. `isHealthy()` is admitted too.
- **Which methods get a method-level wrapper.** The rule is that the lifetime of a direct capability is the whole method. For a method that issues exactly one command the admission may sit at the command choke point; any method that issues several commands or runs a callback (`transactRequest()`, `commitBoundedObservationGroup()`, `commitDeviceCapPlans()`, and any other found by the inventory) must be wrapped as a whole. The implementation unit starts by generating a table of all 78 public methods from the source, classifying each, and the acceptance plan covers each class.
- **Exempt, with a reason:** pure local getters and value objects (policy getters, DTO accessors, enums) hold no shared mutable state and touch no backend; a lock on them would cost without protecting anything.

### 3.6 Public surface of the proposal, and what stays internal [APPROVAL M2, M3]

| Type | Visibility | Owner | Notes |
| --- | --- | --- | --- |
| `OverlappingRequestException extends RateLimiterException` | **public**, new | package | the refusal (section 7); carries the refused entry's name |
| `ExecutionContextProviderInterface` and `RateLimiterBuilder::withExecutionContextProvider()` | **public**, new, **optional** | Host implements only for a non-Fiber scheduler | additive |
| `OwnershipRegistry`, `CallToken`, the `@internal` `ownedResources()` methods | internal (`@internal`), new | package | **not** Host-implemented and not required of Host stores; visible `public` methods exist only because PHP has no package visibility, and they carry no support promise |
| `CallOwnershipStoreInterface` (revision 1) | **dropped** | n/a | removed: a Host store no longer implements anything for ownership |

Who constructs what: the registry is created by the package (a static `WeakMap`); tokens are created by `acquire()` / `admit()`; the provider is created by the Host only for a non-Fiber scheduler. Identity of a resource across graphs and direct calls is the object identity of the store, clock and breaker, which is guaranteed by PHP and needs no Host guarantee.

### 3.7 Supported execution contexts and the callback contract (enumerated) [PROPOSED, APPROVAL M7]

| Context or callback | Outcome | Requirement on the Host |
| --- | --- | --- |
| sequential calls, one at a time per graph and per shared store | supported, unchanged | none |
| PHP Fibers, suspension anywhere (inside the executor, emitter, clock, resolver **or inside a Host callback handed to a direct `transactRequest()`**) | supported: an overlapping call from another Fiber is refused, by identity and by the crossing marker | none |
| a Host callback of a direct `transactRequest($evaluate)` | may call capabilities of the same store as nested operations from its own context; may suspend under Fibers; a call from another context is refused | none |
| a re-entrant call from inside an executor, emitter, clock or resolver callback on the same stack | refused (crossing open) | none |
| a non-Fiber scheduler **with** an `ExecutionContextProviderInterface` | supported: identity separates its contexts | supply the provider |
| a non-Fiber scheduler **without** a provider | supported only for switches inside the guarded crossings (executor, emitter, clock, resolver) | do not yield from a Host callback handed to the library (a direct `transactRequest()` callback, a custom policy) unless a provider is supplied |
| custom policies and other Host callbacks the engine calls (`BlockPolicyInterface` methods, `PostPunishmentReentryPolicyInterface`) | guarded as Host crossings; under Fibers also covered by identity | a non-Fiber scheduler must not yield there without a provider |
| independent graphs per context (own store, clock, breaker objects) | supported, the preferred way to run concurrent contexts; Redis conflicts under DEC-037 | one graph and one connection per concurrent context |
| preemptive threads sharing one graph | not applicable to single-threaded PHP; a threaded extension must use one graph per thread | stated, not enforced |

### 3.8 Stated limits (no unsupported pattern is relabelled as repaired) [PROPOSED]

1. **Decorated aliasing.** If two graphs wrap the same underlying store in two different Host decorator objects, the registry sees two different resources and cannot see the sharing. The package cannot guard code it does not own. Primary rule: such a composition is **not supported**; a Host that shares a store between graphs must share the same object. Whether the Builder should also detect a package-owned store reached through a decorator is [OPEN, O-3].
2. **A Host store used directly outside any graph** is Host code; the package guards the direct entries of its own `RedisFullCapabilityStore` and every graph entry, but cannot intercept a Host store's own methods.
3. **An owner that is never unwound.** Release is by unwinding the owner's stack. PHP unwinds a destroyed Fiber's stack and runs its `finally` blocks, so destroying the Fiber releases the owner; a Fiber that is never resumed and never destroyed keeps its resources owned and every later call on a graph that shares them is refused. No time-based release is proposed (it would bring back silent overlap). This is a Host duty and is an accepted cost of explicit refusal [OPEN, O-4 for any remedy API].
4. **Non-Fiber schedulers without a provider** are covered only inside guarded crossings (3.7).

## 4. Entry and resource matrix [PROPOSED]

Resources: **S** rate-limit/admission store, **K** correlation store, **B** circuit-breaker store, **C** `RequestScopedClock`, **G** `CircuitBreaker` (held signals), **E** engine flags and the final fallback charge.

| Entry / contract | Resource set | Context of the owner | Check point | The owner itself | An independent call | Cleanup |
| --- | --- | --- | --- | --- | --- | --- |
| `limit()` | S, K, B, C, G, E | token from `acquire()` | first statement of `RateLimiterEngine::limit()` (the composite forwards without acquiring), before the clock | runs; its nested operations are admitted by 3.1 | refused if **any** resource is owned, before any clock reading, breaker or store touch | release order of 3.3 |
| `consume()` | S (the simple runtime's store), C | token | the simple runtime's entry (the composite forwards without acquiring) | runs | refused | 3.3 |
| `claimPostPunishmentReentry()` | S, K, B, C, G, E | token | entry | runs | refused | 3.3 |
| `revokeAccountBinding()`, `bindingEpoch()`, `openAdmissionFence()`, `rebindAdmissionSharedConfig()` | S, C | token | entry | runs | refused | 3.3 |
| operational readers `read()`, `readScorePolicy()`, `readSimpleThrottle()` | S, B, C (their own clock object when built by `buildOperationalReader()`) | token | reader entry | runs | **refused** (primary decision D3); the pass-through variant is APPROVAL M6 | 3.3 |
| `transactRequest()` (direct) | its store | token if no owner, else nested/refused by 3.1 | method entry, before the callback and before `requestState` is inspected | nested transaction joins the owner's request as today | refused (different context, or crossing open) | release in `finally` when it acquired |
| store capabilities that send commands (`acquireProbeLease()`, `increment*`, `addDistinct*`, `block*`, `save*`, state readers, admission `reserve*`/`apply*`/epoch/config) | their store | short-lived token when direct | method (or the single command choke point where it issues exactly one command) | nested: allowed | refused | release in `finally` |
| `claimAdmissionPermitWithOutcome()` and the permit claim | their store | short-lived token when direct | method entry | nested inside the owning call: runs as its own atomic command | **refused** for an unrelated overlapping caller (revision 1 exempted it; withdrawn) | release in `finally` |
| `isHealthy()` | its store | short-lived token when direct | method entry | nested: runs | refused | release in `finally` |
| `commitBoundedObservationGroup()`, `commitDeviceCapPlans()` | their store | short-lived token | whole method (callback) | nested: allowed | refused | release in `finally` |
| `CircuitBreaker::holdSignals()/releaseSignals()`, `RequestScopedClock::holding()`, `RateLimiterEngine` private flow | G, C, E | the owner | never reached by an independent call: the public entry refused it first | run | unreachable | 3.3 |
| `FailureSignalEmitterInterface::emit()`, `ClockInterface::now()`, `DeviceIdentityResolverInterface::resolve()`, the executor's `execute()`, policy callbacks | Host code | the owner is inside a crossing | wrapped by a crossing marker on the owner's token | n/a | a call arriving from here, or from another context, is refused | the crossing counter is decremented in `finally` |
| pure local getters, DTOs, enums, policy getters | none | none | not guarded | n/a | not refused | none |

## 5. Composition inventory and coordination cases [PROPOSED]

### 5.1 Actual resources by composition path

| Composition path | Resource set of its entries |
| --- | --- |
| `RateLimiterBuilder::fromFullCapabilityStore($store, …)->build()` | `{store}` (one object for S, K and B), `C` (built by `build()`), `G`, engine, and the simple runtime's store (the same object) |
| `new RateLimiterBuilder($config, $rateLimitStore, $correlationStore, $circuitBreakerStore, …)->build()` with three distinct stores | `{rateLimitStore, correlationStore, circuitBreakerStore}`, `C`, `G`, engine |
| the same with two of them aliased (for example one store for K and B) | the deduplicated objects |
| `buildOperationalReader()` | the rate-limit store, the circuit-breaker store and the reader's own `RequestScopedClock` |
| `RateLimiterEngine`, `CompositeRateLimiterRuntime`, `FixedWindowSimpleRateLimiter` and the readers constructed directly | each computes its set from the collaborators it was given |
| one `RedisFullCapabilityStore` used directly by a low-level caller | the store object itself |

### 5.2 Two runtimes on one store, and graphs that share only part of a set

- **Two aggregate graphs on one store object.** B's entry acquires its set in canonical order; the first resource it needs that A owns makes it refuse, having cleared only what it took (3.3). B never reads its own clock reading or touches its breaker before refusing. After A releases, B runs; sequential sharing is unchanged.
- **Partially shared graphs** (separate rate-limit stores, one correlation or circuit-breaker store in common). The shared object is in both sets, so the second entry refuses on it. This is the case revision 1's single slot did not cover.
- **All-or-none cleanup.** If B holds slot R1 (free) and finds R2 owned by A, it clears R1 and refuses; a third call C may then acquire R1. A's slots are never touched.
- **Separate graphs over one Redis** share no resource object and are independent; their conflicts stay under DEC-037.

### 5.3 Nested calls inside one request

The engine's recovery step → pipeline → `transactRequest()` → capability commands, and a Host callback's capability calls inside a direct `transactRequest()`, are made from the owner's context with no crossing open; they are admitted as nested by 3.1. The nested `transactRequest()` joins the owner's request (one commit). A blanket refusal because a request is open is not used.

## 6. Ownership over time, refusal and failures [PROPOSED]

### 6.1 State diagram of one resource slot and its token

```mermaid
stateDiagram-v2
    [*] --> Free
    Free --> Owned: acquire() / admit() takes the slot (token: context, crossings = 0)
    Owned --> InHost: a Host crossing begins (crossings += 1)
    InHost --> Owned: the crossing ends in finally (crossings -= 1)
    Owned --> Owned: nested operation (same context, crossings = 0): no token, no change
    Owned --> Free: release() in the outermost finally
    Owned --> Owned: another context, or a public entry: OverlappingRequestException, slot unchanged
    InHost --> InHost: any other call: OverlappingRequestException, slot unchanged
```

### 6.2 Sequence diagrams

Successful call with a nested operation, every resource acquired up front:

```mermaid
sequenceDiagram
    participant H as Host
    participant R as Runtime.limit()
    participant O as OwnershipRegistry
    participant S as Store
    participant X as Executor (Host)
    H->>R: limit(A)
    R->>O: acquire({store, clock, breaker}) -> token A (all-or-none)
    R->>S: transactRequest()
    S->>O: admit(store): owner, same context, no crossing -> nested
    S->>O: crossing begins
    S->>X: execute(commit)
    X-->>S: reply
    S->>O: crossing ends
    R->>R: release signals, clear the clock reading
    R->>O: release(token A) (last)
    R-->>H: result
```

A suspended inside a Host callback of a direct `transactRequest()` (required case 1), then independent B from another Fiber:

```mermaid
sequenceDiagram
    participant A as Call A (Fiber 1)
    participant B as Call B (Fiber 2)
    participant O as OwnershipRegistry
    A->>O: admit(store): no owner -> token A (context Fiber 1)
    A->>A: evaluate callback: nested capability call (Fiber 1, no crossing) -> allowed
    A->>A: callback suspends (Fiber::suspend)
    B->>O: admit(store) direct capability, or acquire(set) from a runtime
    O-->>B: OverlappingRequestException (context is Fiber 2, not Fiber 1)
    A->>A: resumes, completes
    A->>O: release(token A)
    B->>O: later call: acquires normally
```

A suspended inside the Host executor and B on a second runtime that shares only the correlation store, then B's all-or-none refusal:

```mermaid
sequenceDiagram
    participant A as Call A (graph 1)
    participant B as Call B (graph 2)
    participant O as OwnershipRegistry
    A->>O: acquire({S1, K, B1, C1, G1}) -> token A
    A->>O: crossing begins (executor)
    B->>O: acquire({S2, K, B2, C2, G2}) in canonical order
    O->>O: S2 free: B takes it
    O->>O: K owned by A: refuse
    O->>O: B clears S2 only (never K, never A's slots)
    O-->>B: OverlappingRequestException
```

Two graphs with their own store, clock and breaker objects over one Redis (valid; DEC-037 unchanged):

```mermaid
sequenceDiagram
    participant A as Graph 1 call
    participant B as Graph 2 call
    participant R as Redis
    A->>R: commands (graph 1 resources)
    B->>R: commands (graph 2 resources)
    B->>R: commit
    A->>R: commit (conflict: A recalculates under DEC-037)
```

A direct claim from an unrelated caller while another direct capability is suspended in its executor:

```mermaid
sequenceDiagram
    participant A as Direct capability A (Fiber 1)
    participant B as Direct claim B (Fiber 2)
    participant O as OwnershipRegistry
    A->>O: admit(store): no owner -> short-lived token A
    A->>O: crossing begins; A suspends in the executor
    B->>O: admit(store) for claimAdmissionPermitWithOutcome
    O-->>B: OverlappingRequestException (the claim stays its own atomic command for a legitimate caller)
    A->>O: crossing ends, release(token A)
```

### 6.3 Lifetime across retries, failures and cancellation

| Situation | Behaviour of the ownership |
| --- | --- |
| Redis conflict and the attempts of `transactRequest()` | one owner for the whole call; an abandoned attempt never releases ownership. Its in-memory request state is reset as today |
| save that fails before it is applied; the sequential/emergency recovery repetition | the repetition runs inside the same call and token |
| save applied and the reply lost (DEC-037: unknown outcome) | semantics unchanged; the owner is released when the call unwinds; nothing is reinterpreted as "not saved" |
| classified error, `RateLimitConcurrencyException`, `BackendFailureException` | unwinds through the entry's `finally`: held signals are released (dropped or emitted exactly as today), the clock reading cleared, the slots cleared last |
| exception from an executor, emitter, clock, resolver or policy callback | the crossing counter is decremented in `finally`; the entry's `finally` still runs; the exception propagates |
| an emitter that re-enters the library | the owner is still held and a crossing is open: the re-entrant call is refused; the emission completes |
| Fiber destroyed while the owner is suspended | PHP unwinds its stack and runs `finally`: the slots are cleared when the Fiber object is destroyed, which depends on the Host dropping the last reference |
| Fiber never resumed and never destroyed | the slots stay owned and every later call on a graph that shares them is refused until the Fiber is destroyed (3.8, O-4) |
| refusal of B | B never acquired a slot it did not already see free, clears only what it took, and never touches A's clock reading, held request state, failure flag, signals, probe or admission state, fallback counters or the sequential-recovery flag |
| process exit or fatal error | the memory is gone with the process |
| long-lived worker | the registry is a `WeakMap`; a slot exists only while a call is in flight; nothing accumulates |

No rollback is proposed for the outcome claim to hide a late check: the refusal is made at the entry, before any claim or other irreversible write; the claim boundary of #258 / DEC-036 is untouched.

## 7. Refusal contract and what it must not be confused with [PROPOSED, APPROVAL M3]

Proposed shape: a thrown `Maatify\RateLimiter\Exception\OverlappingRequestException extends RateLimiterException`, with a message naming the refused entry and that another call is in flight. It carries no `RateLimitResultDTO`.

- **Why an exception.** A result would turn an integration error into a decision (allow, block or fallback), which B forbids. It is thrown at the entry, outside the engine's failure-mode machinery.
- **Not `BackendFailureException`.** An overlap is not an outage: the engine's backend-failure handling does not see it, it reports nothing to the circuit breaker, opens no fallback and consumes no fallback allowance (so it cannot move the circuit or the #259 counters).
- **Not `RateLimitConcurrencyException`.** That one means a Redis conflict persisted until the retry bound and is answered by the engine's concurrency block. The new exception is a distinct class, is never retried by the library and is never converted into the concurrency block. `RedisFullCapabilityStore::transactRequest()` must rethrow it unchanged and must not classify it as a retryable error over stale state (its `catch (\Throwable)` classification excludes it).
- **Not a policy refusal or a fallback denial.** The refused call never becomes `ALLOW`, never writes an allowance or a block, and never erases the allowance or the effects of the call in flight.
- **Host-visible.** The Host sees a `RateLimiterException` subclass; retrying is its choice, safe only after the call in flight has finished; the library offers no automatic retry and no wait.

## 8. Compatibility, before and after [PROPOSED; every change below is a proposal]

| Surface | Before (baseline) | After (proposed) | Source of compatibility / where it can break |
| --- | --- | --- | --- |
| `RateLimiterBuilder::fromFullCapabilityStore()` / constructor / `build()` / `buildOperationalReader()` | wires the graph; no ownership | same signatures; the entries acquire the resource set internally | default path needs no wiring or Host change; additive `withExecutionContextProvider()` |
| Host-implemented stores (any `RateLimitStoreInterface` / `CorrelationStoreInterface` / `CircuitBreakerStoreInterface` implementation) | used as given | **no new interface or method required**; protected by object identity at every graph entry | none; direct use of a Host store outside a graph stays the Host's responsibility (3.8) |
| `RedisFullCapabilityStore` | constructor and 78 public capability methods | constructor unchanged; its capabilities admit their callers (3.5) | overlapping direct use now refused (it was silently joined); the implementation unit classifies all 78 methods |
| `RequestAtomicStoreInterface::transactRequest()` | signature unchanged; last guarantee: runs inside an open request of the store itself | signature unchanged; **the guarantee's wording changes**: a call made from the owner's execution context while no Host crossing is open joins the request; any other call is refused | [APPROVAL M4] contract text and the matching DEC-037 item 7 sentence; Host stores implementing the interface keep working but do not refuse unless they adopt the same rule |
| `CompositeRateLimiterRuntime`, `RateLimiterEngine`, `FixedWindowSimpleRateLimiter`, operational readers | constructors without ownership | constructors unchanged; each computes its resource set from its collaborators | classes built directly are protected like Builder-built ones |
| `RequestScopedClock`, `CircuitBreaker`, `EvaluationPipeline` | public API as today | no public API change; internal crossing markers and `@internal` `ownedResources()` methods | none |
| new `OverlappingRequestException` | none | new public exception | [APPROVAL M3]; Hosts catching `RateLimiterException` already handle it |
| new `ExecutionContextProviderInterface` | none | optional, additive | [APPROVAL M2]; needed only by a non-Fiber scheduler that wants identity |
| `CallOwnershipStoreInterface` (revision 1) | n/a | **dropped** | n/a |
| shared store (aggregate or partial), sequential use by several runtimes | works | works | unchanged |
| shared store, overlapping use | silent loss and cross-call effects | refused explicitly | behaviour change only for a pattern that was already incorrect |
| low-level direct capability use | joins any open request | admitted by the rule of 3.1 | direct overlapping users must hold one store object per context |
| decorated aliasing of a store | invisible | still invisible: **not supported** (3.8) | a Host sharing a store between graphs must share the object |
| PHP32 / PHP64 | no integer-width involvement | none: the mechanism uses counters and object identity | none |
| connection lifecycle | owned by the Host | unchanged: the mechanism neither opens, closes nor multiplexes connections | one connection per concurrent context stays the Host's obligation |
| tests that start a second runtime from inside an executor hook | those read for this design (`RequestAtomicityBoundaries…`, the #258 and #259 suites) build the second runtime on its **own** store object | still valid | not scanned exhaustively: the implementation unit scans the whole suite; a test that reused one store object from inside a hook would now be refused |

## 9. Acceptance plan for a later implementation unit (not executed, not authorized)

Every test runs on actual PHP_INT_SIZE 8 and 4 in Docker with an isolated Redis; wherever no change is claimed, exact `DUMP` and `PEXPIRETIME` are read from outside Lua; traces of commands, clock readings and signals are kept per call.

### 9.1 The 14 existing cases

| # | Present test | Intended behaviour under DEC-041 and revision 2 | Conversion |
| --- | --- | --- | --- |
| 1 | `testAStoreCallMadeWhileAnotherRequestIsOpen…` (A suspends **inside the Host callback** of a direct `transactRequest()`) | **required refused case**: B's `acquireProbeLease()` from another Fiber is refused with `OverlappingRequestException` (identity); Redis unchanged before and after A's rejection; the same call succeeds after A has ended | rewrite; no easier variant is substituted. An executor-crossing variant is **added** next to it |
| 2 | `testSequentialCallsAreSavedBeforeEachReturns…` | unchanged control | keep |
| 3 | `testANestedRequestInsideOneRequest…` | unchanged: one commit, one clock reading, **no** refusal for the callback's nested capability calls | keep; add the engine recovery nesting case |
| 4 | `testACompletedIndependentCallIsNotSaved…` | B refused at its entry; no key of B; A completes and the stored set equals "A alone" | rewrite |
| 5 | `testTheFailureOfACompletedIndependentCall…` | B refused (the Host sees a refusal, never an `ALLOW`); A rejected; Redis unchanged | rewrite |
| 6 | `testTwoCallsSuspendedInsideOneRequest…` (2 resume orders) | whichever call starts second is refused, in both orders; the first stores exactly its own effects | rewrite |
| 7 | `testTheSampleOfTheHostClock…` | B refused; A's reading unchanged; a call after A ends uses the Host clock's new value | rewrite |
| 8 | `testABackendFailureOfOneCall…` | B refused before it sends any command, so no failure exists to cross; A completes with its own answer (not `FAIL_CLOSED`); assert zero commands from B and no circuit write | rewrite |
| 9 | `testTwoRuntimesSharingOneStoreObject…Loss…` | B on runtime 2 refused at its entry; A unaffected | rewrite |
| 10 | `testTheHostClockOfTheOtherRuntime…` | B refused while A is in flight; after A ends B's effects carry B's own clock reading | rewrite |
| 11 | `testRuntimesWithTheirOwnStoreAndClock…` | unchanged control: separate graphs over one Redis are independent | keep |
| 12 | `testARecoveryTransitionIsAnnounced…` (2 endings) | B refused; no `CB_RECOVERED` until A has saved, none when A is rejected; the circuit unchanged | rewrite |

### 9.2 Counterexamples from the review, and the guard tests they require

| Id | Counterexample | Intended behaviour |
| --- | --- | --- |
| CE-1 | A's callback legitimately calls a nested capability; A's callback is suspended and independent B calls (F266-05) | the nested call runs; B from another context is refused; the two are distinguished by the owner's execution context, not by crossing depth |
| CE-2 | A is suspended in its guarded Host-clock call before any store transaction (owner exists, `requestState` empty); B calls `transactRequest(fn() => 123)` directly (F266-06) | B refused although it would send no command |
| CE-3 | the window after the held state was cleared while the commit runs | an independent call arriving there is refused (the token is held for the whole call) |
| CE-4 | a standalone direct capability suspended in its executor; B calls another capability directly | B refused; the first is a short-lived owner for its whole method |
| CE-5 | a direct claim and a direct `isHealthy()` overlapped by an unrelated caller | refused; the same claim inside the owning call runs as its own atomic command (#258/DEC-036 boundary unchanged) |
| CE-6 | two graphs with separate rate-limit stores that share only the correlation store, or only the circuit-breaker store (F266-07) | the second entry refuses on the shared object |
| CE-7 | all-or-none acquisition: B takes a free resource, finds the next owned | B clears only what it took, A's slots untouched, a third call acquires the freed resource |
| CE-8 | the aggregate store aliased as S, K and B | acquired once; the call never refuses itself |
| CE-9 | decorator aliasing of one store | characterization of the **unsupported** composition (documented), not a repaired case |
| CE-10 | a stub scheduler with and without an `ExecutionContextProviderInterface` | with it identity separates contexts; without it overlap inside a guarded crossing is refused and the unguarded-callback limit is documented |
| CE-11 | a re-entrant call from inside an executor hook and inside an emitter callback on the same stack | refused (crossing open) |
| CE-12 | same context, no crossing: nested allowed; different context: refused | both asserted, under Fibers |
| CE-13 | `OverlappingRequestException` is not `BackendFailureException` (no circuit report, no fallback consumption), not `RateLimitConcurrencyException` (no concurrency block), and `transactRequest()` rethrows it without retry | asserted |
| CE-14 | mutants: no entry guard; refusal keyed on `requestState` alone; claims exempt; crossing-only identity; release before signals are emitted; a stale token released | each fails at least one test above |
| CE-15 | a destroyed suspended Fiber releases every slot and a later call succeeds; double release; a foreign-token release is an integrity error | asserted |
| CE-16 | many graphs created and discarded; sequences of calls | no owner is left behind and the registry holds no entry for a collected resource |
| CE-17 | regression: #258 (`AdmissionOutcomeDuringRecovery…`, `RequestAtomicityBoundaries…`), #259 (`LocalFallbackRecoveryAccounting…`), the retry and lost-reply contracts of DEC-037, and the separate-graph Redis conflict control | unchanged results |

The existing characterization tests, logs and preservation controls of #266 stay as the historical before-state.

## 10. Documentation to update in the implementation unit (a map, not done now)

`docs/FAILURE_SEMANTICS.md` (the refusal is not a failure mode), `docs/guides/USAGE_GUIDE.md` (one call at a time per graph and shared store object; one graph per concurrent context; the callback contract; the optional context provider), `RATE_LIMITER_PACKAGE_REFERENCE.md` (the exception and the optional interface), the `RequestAtomicStoreInterface` docblock and DEC-037 item 7 wording (APPROVAL M4), `README.md` (hosting patterns), `CHANGELOG.md`, and `DECISIONS_INDEX.md` (the status of DEC-041 when the implementation lands). None of them may say that exclusivity works before it does.

## 11. Decisions: primary decisions of this proposal, material approval items, open questions

**Primary decisions of the proposal (recommended; each is part of the mechanism, not separately approved):**

- D1 Enforcement: per-resource ownership acquired at every public entry, plus admission of every direct backend operation; one primary behaviour for stores without cooperation (they are protected at graph entries).
- D2 `consume()` and the simple runtime enter the same ownership (they share the store and the clock object); this is a design classification from the code path, not a demonstrated failure.
- D3 Operational readers are refused under overlap (consistent; monitoring that must run during traffic uses its own graph, store object and connection).
- D4 Capability and constructor paths: no new Host interface, no constructor change; classes built directly are protected from their collaborators.
- D5 Claims and PING are admitted, not exempt.

**Material approval items (separate, none approved):**

- **M1** the mechanism of section 3 (resource-set ownership, identity plus crossing markers, all-or-none acquisition).
- **M2** the optional public `ExecutionContextProviderInterface` and `RateLimiterBuilder::withExecutionContextProvider()`.
- **M3** the new public `OverlappingRequestException`, its place under `RateLimiterException`, and its use by every guarded entry.
- **M4** the changed wording of the `RequestAtomicStoreInterface` nested-call guarantee and the matching DEC-037 item 7 sentence.
- **M5** that every public entry refuses when **any** resource of its set is owned, whoever the owner is.
- **M6** the reader behaviour: refuse (D3, recommended) or the pass-through read variant (changes the readers' semantics, keeps monitoring available under load).
- **M7** the supported execution-context and callback contract of 3.7, including the stated limit for non-Fiber schedulers without a provider and the not-supported status of decorated aliasing.

**Open questions:** **O-1** the final name and namespace of the exception and the provider; **O-2** whether the refusal also needs a result-level metadata cause for Hosts that only handle results (not proposed); **O-3** whether the Builder should detect a package-owned store reached through a Host decorator; **O-4** any remedy for an owner that is never unwound (a documented Host duty is the proposal; an explicit abandon call would be a new API); **O-5** whether custom policy methods should be wrapped as crossings in the first implementation or documented only (3.7 proposes wrapping).

**[ESTIMATE]** The change touches one internal registry class and token, one new exception, one optional interface and a Builder method, the `@internal` resource accessors and the entry guard in each entry class, the crossing markers at the Host callouts, and the admission wrapper on the package-owned store's capability methods (a mechanical change across up to 78 methods once classified). A per-call cost of one acquisition and a few identity comparisons is expected for a graph entry, and a token per direct capability call. No prototype exists and nothing was measured. The risks that matter are O-4 and the size of the 78-method classification.

## 12. Traceability and what this revision does not claim

The evidence is `RESULTS.md` and `FiberRequestOwnershipRedisTest` at the accepted head; #258 and #259 are relied on as merged; the principle is DEC-041 (unchanged); the entry points and resources of sections 4 and 5 were read from `CompositeRateLimiterRuntime`, `RateLimiterEngine`, `RateLimiterBuilder`'s constructor and `build()`, the operational reader interfaces, `FixedWindowSimpleRateLimiter` and the public methods of `RedisFullCapabilityStore`. The counterexamples of the Lead's review are **design-level traces**, not executed implementation failures, and this revision does not claim prototype verification, measured cost or implemented enforcement. `consume()`, the readers, the direct admission entries, partially shared graphs and decorated aliasing are design classifications from the code, not measured results.
