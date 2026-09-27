# DEC-012 — Coordinated Operational Read Composition and Simple Fixed-Window Inspection

## Decision ID

`DEC-012`

## Title

Coordinated Operational Read Composition and Simple Fixed-Window Inspection

## Status

`ACTIVE`

## Date

2026-09-27

## Decision Authority

Owner-approved WU-S4-03D-F02 decision (Option A), approved 2026-09-27.

## Scope / Concern

Package-owned typed read-only operational inspection for persisted simple
fixed-window state, and coordinated default composition of Operational Read
so it stays aligned with the Builder's registered policy/configuration/clock/
resolver/key-generation state instead of being assembled manually outside
`RateLimiterBuilder`.

## Context

WU-S4-03D-F02 identified two related pre-host gaps:

1. Simple fixed-window persisted state (DEC-013) has no package-owned typed
   read-only operational surface, unlike score-based state, which already has
   `RateLimitOperationalReaderInterface`/`RateLimitOperationalReader`.
2. Operational reads were composed manually outside `RateLimiterBuilder`
   (see the previous version of `examples/operational-read.php`), which
   requires a Host to reconstruct the resolver, clock, decay calculator,
   secrets, and policy object a second time. That manual reconstruction can
   drift from the Builder's actual registered policy/configuration/clock/
   resolver/key-generation state — the very state the runtime enforces
   against.

DEC-004/DEC-010 already establish `RateLimiterBuilder` as the single
package-owned default composition owner for the enforcement runtime. This
decision extends that same coordination principle to Operational Read without
collapsing enforcement and inspection into one responsibility.

## Decision

### 1. Enforcement and Operational Read remain separate

No operational-read method is added to `CompositeRateLimiterRuntimeInterface`
or `RateLimiterRuntimeInterface`. The enforcement runtime is unchanged.

### 2. `RateLimiterBuilder` remains the single default composition owner

Exactly one additive public method is added:

```php
public function buildOperationalReader(): CompositeRateLimitOperationalReaderInterface;
```

No second builder, factory, service container, facade, or Host-owned
operational composition is introduced.

### 3. The Production Default Read Path owns Builder registries and resolves policies by name

The reader `buildOperationalReader()` returns is built from the Builder's
*current* effective state: the score-policy registry after every
`withPolicy()`, the simple-policy registry after every
`withSimpleThrottlePolicy()`, the effective clock, the effective
`DeviceIdentityResolverInterface`, the active/previous key configuration, the
environment scope, the rate-limit store, and the circuit-breaker store. A
Host customizing a policy through the Builder gets that same registered
policy resolved by name on the read path; the Host never has to pass a
second `BlockPolicyInterface` to the Production Default Read Path.

`buildOperationalReader()` is a read-only composition method. It does not run
`build()`'s mutation-only capability preflight
(`RateLimiterBuilder::assertCapabilitiesSatisfied()`): Operational Read never
calls a mutation-only primitive (bounded correlation, snapshot mutation,
circuit-probe acquisition, punishment mutation), so read-only construction
must not fail on capabilities it never uses. It does not weaken or change
`build()` or any of its existing fail-fast behavior.

### 4. The existing `RateLimitOperationalReaderInterface` remains the Advanced Path

`RateLimitOperationalReaderInterface::read(RateLimitContextDTO $context,
BlockPolicyInterface $policy): RateLimitOperationalSnapshotDTO` keeps its
exact existing public signature. `RateLimitOperationalReader` remains usable
directly, unchanged. The new Builder-owned composite reader wraps/reuses it;
it does not replace it.

### 5. Simple fixed-window state gains typed read-only inspection

Two new public contracts are added:

```php
interface SimpleRateLimitOperationalReaderInterface
{
    public function read(string $policyName, string $subject): SimpleRateLimitOperationalSnapshotDTO;
}

interface CompositeRateLimitOperationalReaderInterface
{
    public function readScorePolicy(RateLimitContextDTO $context, string $policyName): RateLimitOperationalSnapshotDTO;
    public function readSimpleThrottle(string $policyName, string $subject): SimpleRateLimitOperationalSnapshotDTO;
}
```

`SimpleRateLimitOperationalSnapshotDTO` exposes exactly: `policyName`,
`observedAt`, `backendHealthy`, `limit`, `intervalSeconds`, `count`,
`remaining`, `epochStart`, `resetAt`, `fromPreviousGeneration`. It does not
add `allowed`, a decision, `retryAfter`, a block level, a score, a
failure-mode decision, the raw key, or the raw subject.

### 6. Simple inspection is not DEC-013 enforcement

`SimpleRateLimitOperationalReaderInterface::read()` is strictly a read-only
point-in-time inspection. It is not `check()`, not `peek()` deciding whether a
future consume is allowed, not reservation or pre-authorization, and not
mutation. It derives the exact same DEC-013 state identity
(`rate_limiter:simple_fixed_window:v1:environmentScope:policyName:limit:
intervalSeconds:subject`, length-prefixed component encoding, HMAC-SHA256'd
with the selected outer generation secret) that `FixedWindowSimpleRateLimiter`
writes, and it uses only the read-only store operations needed for inspection:
`RateLimitStoreInterface::getBudget()` and `RateLimitStoreInterface::isHealthy()`.
It never invokes `incrementBudget()`, `incrementBudgetWithSeed()`, `block()`,
`set()`, or another mutation primitive.

### 7. Current-before-Previous fallback is read-only

For a configured simple policy and subject: derive Current, read Current; if
Current's epoch is active, Current wins. Otherwise, if a previous key secret
is configured, read Previous; if Previous's epoch is active, report Previous
with `fromPreviousGeneration = true`. Otherwise there is no active window.
Current always wins when present; there is never a `max()`/sum() merge of
Current and Previous, and an absent/expired Previous is never seeded or
migrated into Current during a read.

### 8. No migration/write occurs during inspection

Reading Previous never creates Current, never increments Previous, and never
changes an existing epoch's start or reset boundary.

### 9. No dashboard/reset/unblock/raw-key/API transport ownership moves into the package

This decision does not add a mutation/reset/unblock API, a global listing, an
arbitrary key lookup, raw-key exposure, a historical audit store, Host joins,
cross-package reporting, or a generic reporting/statistics API. Dashboards,
UI, permissions, export, and HTTP/transport behavior remain Host-owned.

### 10. Real supported-backend Integration proof is required

Package Standard §15 applies because this package owns persisted operational
state. `tests/Integration/Redis/RedisSimpleFixedWindowIntegrationTest.php`
proves, against a real, non-mocked `RedisFullCapabilityStore`: that a real
`consume()` creates Current persisted state; that an operational read
observes the exact persisted count/epoch/reset; that the read itself adds no
consume and creates no additional Redis key; that the raw subject is absent
from persisted keys and the serialized snapshot; that a Previous-only read
reports Previous with `fromPreviousGeneration = true` while Previous's raw
persisted value stays byte-identical and Current is not created; and that
when Current also exists, Current wins outright without a merge.

## Rationale

Splitting simple-throttle inspection from score inspection while keeping
Operational Read manually composed outside the Builder would let the two
inspection paths drift from what the Builder's registered graph actually
enforces — the exact class of bug this WU closes. Keeping
`RateLimiterBuilder` as the single composition owner for *both* enforcement
and inspection, and resolving policies by name from its own registries,
removes that drift without introducing a second builder, factory, or
Host-owned composition path. Reusing the existing Advanced Path
(`RateLimitOperationalReaderInterface`) and only adding a thin
delegating/composition owner (`CompositeRateLimitOperationalReader`, in the
same spirit as `CompositeRateLimiterRuntime`) keeps the change additive and
avoids re-implementing already-proven score-read semantics.

## Consequences

- `RateLimiterBuilder` gains exactly one additive public method,
  `buildOperationalReader(): CompositeRateLimitOperationalReaderInterface`.
- Enforcement (`build()`) and Operational Read (`buildOperationalReader()`)
  remain two separate public responsibilities of the same single composition
  owner; neither collapses into the other.
- Simple fixed-window persisted state moves from having no package-owned
  reporting classification to being an explicit Operational Read concept,
  alongside score state, temporary blocks, account budgets, known-device
  micro-caps, budget cooldowns, and circuit-breaker state.
- A Host customizing a score or simple policy through the Builder gets that
  exact registered definition resolved by name on the Production Default Read
  Path; no Host reconstruction of policy semantics is required or permitted
  on that path.
- The existing Advanced Path (`RateLimitOperationalReaderInterface`,
  `RateLimitOperationalReader`) remains fully source-compatible and
  available for direct use.
- Read-only construction (`buildOperationalReader()`) must not depend on
  mutation-only capabilities (`build()`'s F01 preflight) that Operational
  Read never invokes.

## Canonical Contract / Current Owner

`RateLimiterBuilder::buildOperationalReader()`,
`Maatify\RateLimiter\Service\CompositeRateLimitOperationalReaderInterface`,
`Maatify\RateLimiter\Service\CompositeRateLimitOperationalReader`,
`Maatify\RateLimiter\Service\SimpleRateLimitOperationalReaderInterface`,
`Maatify\RateLimiter\Service\SimpleRateLimitOperationalReader`,
`Maatify\RateLimiter\DTO\SimpleRateLimitOperationalSnapshotDTO`, and the
unchanged existing Advanced Path
`Maatify\RateLimiter\Service\RateLimitOperationalReaderInterface` /
`Maatify\RateLimiter\Service\RateLimitOperationalReader`.

## Supersedes

None.

## Superseded By

None.

DEC-012 does not supersede DEC-013 or DEC-010; it extends the package
Operational Read/composition contract without changing their enforcement
semantics.
