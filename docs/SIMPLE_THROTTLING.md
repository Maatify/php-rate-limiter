# RateLimiter — Simple Fixed-Window Throttling (Official)

**Module:** RateLimiter
**Namespace:** `Maatify\RateLimiter`
**Status:** LOCKED — Simple Throttling Contract
**Spec Version:** `1.0.0`

This document is the canonical contract for the package's generic/simple
fixed-window throttling capability, decided by `docs/decisions/DEC-009_GENERIC_SIMPLE_THROTTLING_SEMANTIC_CONTRACT.md`
and composed into the default runtime by `docs/decisions/DEC-010_COMPOSITE_RATE_LIMITING_COMPOSITION_SURFACE_EVOLUTION.md`.

It is a first-class capability, distinct from the package's score-based
security-policy model (`docs/DECISION_MATRIX.md`, `docs/POLICIES.md`). It
answers one common, reusable requirement:

```
allow up to N events in an interval
then deny until that interval ends
```

---

## 1. Version 1 Scope

```
FIXED WINDOW
cost = 1 per consume
one policy-defined limit
one policy-defined interval
one caller-supplied subject
atomic consume
deterministic fixed reset boundary
FAIL_CLOSED only
```

There is no `check()`/later-`consume()` split, no `peek()`, no `consumeMany()`,
and no variable cost. See §11 for the complete list of non-goals.

## 2. Fixed-Window Semantics

For one `policyName + subject` pair:

1. The first `consume()` starts the fixed window.
2. Consumes `1..limit` return `allowed = true`.
3. Consume `limit + 1` and every later consume inside the same window return
   `allowed = false`.
4. A denied consume MUST NOT renew or extend the window: `resetAt` stays the
   window's original fixed end instant.
5. The next window begins only after the current window's fixed end has
   passed and a new consume occurs.
6. `remaining` is `max(0, limit - persistedCount)`; it is clamped at zero
   once the limit is exhausted and never goes negative.
7. `resetAt` is `epochStart + intervalSeconds`, the stable end instant of the
   current fixed window.
8. `retryAfter` on denial is `max(1, resetAt - now)`, a positive number of
   seconds; on an allowed consume, `retryAfter` is `0`.

Rotation resolution may read both Current and Previous before deciding which
mutation to apply (§8). The single state-changing mutation that actually
executes — `incrementBudget()` on a normal path, or
`incrementBudgetWithSeed()` on a migration path — is the one atomic store
primitive: there is no read-then-write race window between it and the quota
decision, because the quota decision in `consume()` is derived entirely from
that mutation's own returned state, not from any earlier read.

## 3. Policy Contract

```php
namespace Maatify\RateLimiter\Config;

interface SimpleThrottlePolicyInterface
{
    public function getName(): string;
    public function getLimit(): int;
    public function getIntervalSeconds(): int;
}
```

`SimpleThrottlePolicyInterface` is independent of `BlockPolicyInterface`,
`ScoreThresholdsDTO`, `ScoreDeltasDTO`, and `BudgetConfigDTO`. It defines a
stable policy name, a positive integer limit, and a positive integer
interval in seconds. Version 1 uses unit cost only.

`Maatify\RateLimiter\Config\FixedWindowThrottlePolicy` is the package-owned
immutable convenience implementation. Its constructor requires an explicit
name, limit, and interval and rejects a blank name, a non-positive limit, or
a non-positive interval with a `RateLimiterException`. The package supplies
no default limits and no default simple policies: a Host opts in explicitly
by registering a policy.

This same validation — blank name, non-positive limit, non-positive
interval — is enforced package-side against every
`SimpleThrottlePolicyInterface` implementation, not only
`FixedWindowThrottlePolicy`: `FixedWindowSimpleRateLimiter`'s constructor
validates each supplied policy before indexing it, so a Host directly
implementing the interface on the Advanced Path is rejected just as early,
and always before any storage mutation can occur (§7).

## 4. Result Contract

```php
namespace Maatify\RateLimiter\DTO;

final readonly class SimpleRateLimitResultDTO implements \JsonSerializable
{
    public const NORMAL = 'NORMAL';
    public const FAIL_CLOSED = 'FAIL_CLOSED';

    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public ?int $retryAfter,
        public ?int $resetAt,
        public string $failureMode,
    ) {}
}
```

`SimpleRateLimitResultDTO` is a dedicated result contract, separate from
`RateLimitResultDTO`: it does not invent a block level, a decision class, or
score metadata that do not exist for this capability.

| Outcome | `allowed` | `remaining` | `retryAfter` | `resetAt` | `failureMode` |
| --- | --- | --- | --- | --- | --- |
| Normal allowed consume | `true` | `max(0, limit - count)` | `0` | fixed epoch end | `NORMAL` |
| Normal quota denial | `false` | `0` | positive seconds until epoch end | same stable fixed epoch end | `NORMAL` |
| Backend/runtime storage failure | `false` | `0` | `null` | `null` | `FAIL_CLOSED` |

No fake window timing is invented when the backend state cannot be
determined: a `FAIL_CLOSED` result never guesses a `retryAfter` or `resetAt`.

## 5. Service Contract

```php
namespace Maatify\RateLimiter\Service;

interface SimpleRateLimiterInterface
{
    public function consume(string $policyName, string $subject): SimpleRateLimitResultDTO;
}
```

`consume()` is the only public operation in Version 1. An unregistered
`$policyName` or a blank `$subject` raises `RateLimiterException` (§7); it is
never reported as a typed result.

## 6. Key and Privacy Contract

The raw subject never crosses the `RateLimitStoreInterface` boundary. The
package derives one physical storage key per `policyName + limit +
intervalSeconds + environmentScope + subject` combination using canonical,
collision-safe, length-prefixed component encoding, HMAC-SHA256'd with the
package's configured key secret. See `docs/KEY_STRATEGY.md` §4.7 for the
exact locked preimage order and encoding.

Including `limit` and `intervalSeconds` in the key derivation is
intentional: reconfiguring a policy's limit or interval changes its semantic
state namespace. A reconfigured policy therefore never reinterprets a
persisted epoch under new specifications; it starts a fresh window under its
own distinct key.

The simple-throttling key namespace (`simple_fixed_window`) is
package-owned and structurally distinct from every K1-K5 score/budget
namespace, so a Host reusing a score-policy name for a simple policy still
gets fully isolated state (§10).

## 7. Failure Contract

Three outcomes are kept strictly separate:

* **Normal quota exhaustion** is not a failure. It is a typed
  `SimpleRateLimitResultDTO` with `failureMode = NORMAL` and `allowed =
  false` (§4).
* **Backend/runtime storage failure** — any failure a store call raises at
  its own call site while reading or incrementing the window, regardless of
  its exception class — is a typed `FAIL_CLOSED` denial (§4). This
  explicitly includes a `RateLimiterException` the store implementation
  itself throws from `getBudget()`, `incrementBudget()`, or
  `incrementBudgetWithSeed()`: the distinction from a configuration/contract
  failure below is structural (which call site raised it), never based on
  the exception's class alone. There is no `FAIL_OPEN` mode and no
  `DEGRADED_MODE` for simple throttling in Version 1; see
  `docs/FAILURE_SEMANTICS.md` §4.4.
* **Configuration/contract failures** remain exceptions, not results:

  * an unregistered policy name;
  * a blank subject;
  * an invalid policy — a blank name, a non-positive limit, or a
    non-positive interval — checked package-side against every
    `SimpleThrottlePolicyInterface` implementation at construction, before
    any storage mutation, not only against `FixedWindowThrottlePolicy`;
  * a required key-rotation migration whose store does not implement
    `BudgetSeedStoreInterface` (see §6 below and `docs/KEY_STRATEGY.md`
    §4.7). This check is never wrapped by the storage-failure handling
    above, so it always raises `RateLimiterException` even though it sits
    between two store calls in the rotation path (§8).

  Each of these raises `Maatify\RateLimiter\Exception\RateLimiterException`
  and MUST NOT be converted into a normal decision or a typed `FAIL_CLOSED`
  result.

`simple consume() ≠ score limit()`. Quota denial is not `HARD_BLOCK`; a
fixed-window policy is not a `BlockPolicyInterface`.

## 8. Rotation Contract

Simple throttling reuses the package's existing atomic budget-epoch
persistence primitives — no new backend family is introduced:

* `RateLimitStoreInterface::getBudget()`
* `RateLimitStoreInterface::incrementBudget()`
* `BudgetSeedStoreInterface::incrementBudgetWithSeed()` when rotation
  migration is required

When `previousKeySecret === null`, only the current-generation key is used.
When a previous key generation is configured:

1. Read Current. If Current holds a valid (non-expired) epoch, Current is
   authoritative and is incremented normally; Previous is not read.
2. If Current is absent, read Previous.
3. If Previous holds a valid epoch, it is atomically seeded into Current via
   `incrementBudgetWithSeed()`: Current's new count is `previous.count + 1`,
   and `previous.epochStart` — and therefore the fixed epoch end — is
   preserved exactly. Previous itself is never written to.
4. If Previous is absent or expired, a normal new Current epoch starts.

There is no `max(current, previous)` and no `sum(current, previous)` merge.
A store that lacks `BudgetSeedStoreInterface` when a valid Previous
migration is genuinely required raises `RateLimiterException` (§7) rather
than silently resetting the window. The atomic seed primitive itself is
responsible for resolving races on Current's initialization.

## 9. Composition Contract

`docs/decisions/DEC-010_COMPOSITE_RATE_LIMITING_COMPOSITION_SURFACE_EVOLUTION.md`
governs how this capability is reached. `RateLimiterBuilder` remains the
single package-owned default composition owner:

```
RateLimiterBuilder
    |
    +-- score-based BlockPolicyInterface registry (unchanged)
    |
    +-- simple fixed-window policy registry (new, starts empty)
    |
    +-- one build()
            |
            +-- one CompositeRateLimiterRuntime
                    |
                    +-- RateLimiterRuntimeInterface  (delegates to the existing RateLimiterEngine)
                    +-- SimpleRateLimiterInterface    (delegates to FixedWindowSimpleRateLimiter)
```

```php
public function withSimpleThrottlePolicy(SimpleThrottlePolicyInterface $policy): self;
```

A matching policy name replaces the existing registration; a new name is
appended. The registry starts empty: there are no package-default simple
policies, because limit and interval are use-case-specific.

`RateLimiterBuilder::build()` returns
`Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface`, which
extends both the existing `RateLimiterRuntimeInterface` and the new
`SimpleRateLimiterInterface`. The result remains assignable to
`RateLimiterRuntimeInterface` for every existing consumer, so registering no
simple policy leaves the score-based runtime fully source-compatible and
unaffected. `RateLimiterEngine`'s constructor and public methods are
unchanged; `CompositeRateLimiterRuntime` is a thin delegating object and
does not re-implement either collaborator's behavior.

## 10. Isolation from the Score-Based Model

Simple throttling MUST NOT create K1-K5 score state, call `DecayCalculator`
or `PenaltyLadder`, create `BlockState`, create a `BudgetConfigDTO`, use
`BlockPolicyInterface`, observe correlation sets, create DEC-007 lifecycle
state, or modify authentication budgets or the score-policy failure
fallback. Its key namespace stays independent of the authentication budget
namespace even though it reuses the same underlying budget-epoch persistence
primitive (§8) — including when a Host reuses a score-policy name as a
simple-policy name.

A Host may use both models together when they protect different concerns;
the package never automatically translates, merges, or escalates state
between them.

## 11. Non-Goals (Version 1)

Explicitly out of scope for this contract:

* sliding window, token bucket, or leaky bucket algorithms
* burst capacity or weighted/variable consume cost
* a non-mutating `check()` prior to `consume()`, or `consumeMany()`
* manual reset or unblock operations
* statistics/reporting expansion
* PSR middleware/framework adapters
* Redis Cluster portability
* a new storage backend family
* `FAIL_OPEN` or `DEGRADED_MODE` for simple throttling
* a local fallback model
* package-default simple policies

## 12. Examples

```php
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;

$limiter = (new RateLimiterBuilder($config, $rateLimitStore, $correlationStore, $circuitBreakerStore, $failureSignalEmitter))
    ->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy(
        name: 'checkout_attempts',
        limit: 3,
        intervalSeconds: 60,
    ))
    ->build();

$result = $limiter->consume('checkout_attempts', $customerId);

if (! $result->allowed) {
    // $result->retryAfter seconds until $result->resetAt
}
```

See `examples/simple-fixed-window.php` for a complete runnable example, and
`examples/basic-rate-limit.php` for the score-based model this capability
coexists with on the same composite runtime.

---

**This document is authoritative.
Simple throttling semantics, its key contract, and its failure contract MUST NOT be altered without explicit versioning.**
