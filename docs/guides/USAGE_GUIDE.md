# Maatify Rate Limiter Usage Guide

This guide describes the current consumer contract of <code>maatify/php-rate-limiter</code>. It is a practical entrypoint; the [Package Reference](../../RATE_LIMITER_PACKAGE_REFERENCE.md) remains the canonical inventory and stable contract.

## Package Fit

Use this package when a host application needs deterministic, multi-signal enforcement for login, OTP/step-up, or API-heavy operations. The package evaluates a typed request context, applies a selected policy, and returns a typed decision that the host can enforce at its transport or application boundary.

The package is framework-agnostic and storage-agnostic. It does not require a specific HTTP framework, database, cache, queue, or dependency-injection container.

For IPv6, enforcement remains canonical at `/64`. The bounded `/48` → `/40` →
`/32` hierarchy is internal correlation detection for Spray and Churn only; it
does not create macro score or block keys, and the returned decision persists
only the current `/64` K1 or `/64 + UA` K2 state.

## Requirements

- PHP <code>^8.4</code>.
- PHP extensions <code>filter</code>, <code>hash</code>, <code>json</code>, and <code>pcre</code>.
- <code>maatify/exceptions</code> <code>^1.0</code>.
- <code>maatify/shared-common</code> <code>^1.0</code>.
- A Host-provided `FailureSignalEmitterInterface` and either the optional official Redis store with a Host-supplied command executor/client lifecycle or Host implementations of the storage contracts listed in [Integration Boundaries](#integration-boundaries).

The package is proprietary and is currently in pre-release development. Follow the applicable authorization or written license agreement before integrating it.

## Non-Goals

The package does not provide permanent bans, WAF/CDN behavior, advanced browser fingerprinting, user tracking across contexts, HTTP controllers, routes, middleware, permissions, UI dashboards, or export formats. It also does not own host account identity, authentication, or business lifecycle state.

## Default Composition

Use `RateLimiterBuilder` for the production default graph. The package supplies the optional official Redis full-capability store; otherwise the Host may provide the four required integration boundaries separately or provide one custom `FullCapabilityStoreInterface`. The package supplies the internal orchestration, UTC default clock, default identity resolver, and three policy presets.

```php
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\RateLimiterConfig;

/** @var RateLimitStoreInterface $rateLimitStore */
/** @var CorrelationStoreInterface $correlationStore */
/** @var CircuitBreakerStoreInterface $circuitBreakerStore */
/** @var FailureSignalEmitterInterface $failureSignalEmitter */

$limiter = new RateLimiterBuilder(
    new RateLimiterConfig(
        keySecret: $activeKeySecret,
        fingerprintSecret: $activeFingerprintSecret,
        environmentScope: 'production',
        previousKeySecret: $previousKeySecret,
        previousFingerprintSecret: $previousFingerprintSecret,
    ),
    $rateLimitStore,
    $correlationStore,
    $circuitBreakerStore,
    $failureSignalEmitter,
)
    ->build();
```

If one adapter—either the package-owned official Redis store or a Host-owned custom
adapter—implements all four additive storage capabilities, use the named
full-capability composition path:

```php
use Maatify\RateLimiter\Builder\RateLimiterBuilder;

$limiter = RateLimiterBuilder::fromFullCapabilityStore(
    $config,
    $fullCapabilityStore,
    $failureSignalEmitter,
)->build();
```

`FullCapabilityStoreInterface` is an aggregate contract with no methods of its
own. The package includes an optional built-in non-clustered Redis store; the Host
owns the concrete client and supplies its command executor. No Redis client or
`ext-redis` runtime dependency is added, and the existing multi-store constructor
remains valid.

Secrets are explicit and independently rotatable. `RateLimiterConfig` rejects empty or whitespace-only active, previous, and environment values without trimming valid caller input. The builder does not create a service container or no-op production adapters. Use `withClock()`, `withDeviceIdentityResolver()`, `withPolicy()`, or `withSimpleThrottlePolicy()` only for the targeted overrides defined by the public contract; low-level constructors remain the Advanced Path.

`build()` returns `CompositeRateLimiterRuntimeInterface`, which stays assignable to `RateLimiterRuntimeInterface` for every existing consumer.

## Simple Fixed-Window Throttling

Beside the score-based model above, the package owns a first-class generic/simple fixed-window throttling capability (see `docs/SIMPLE_THROTTLING.md` for the complete contract). It answers a common, narrower requirement — allow up to N events in an interval, then deny until that interval ends — without translating the request into score thresholds, decay, or the progressive penalty ladder.

```php
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;

$limiter = (new RateLimiterBuilder($config, $rateLimitStore, $correlationStore, $circuitBreakerStore, $failureSignalEmitter))
    ->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy(
        name: 'checkout_attempts',
        limit: 3,
        intervalSeconds: 60,
    ))
    ->build();

$result = $limiter->consume('checkout_attempts', $customerId);
```

`$limiter->consume(string $policyName, string $subject): SimpleRateLimitResultDTO` is the one atomic operation Version 1 exposes. There is no default simple policy: a Host opts in explicitly, and registering none leaves the score-based runtime unchanged. `SimpleRateLimitResultDTO` exposes `allowed`, `limit`, `remaining`, `retryAfter`, `resetAt`, and `failureMode` (`NORMAL` or `FAIL_CLOSED`); it is a dedicated result contract, separate from `RateLimitResultDTO`. Simple throttling is FAIL_CLOSED only, never creates score/budget/correlation state, and raises `RateLimiterException` for an unregistered policy name, a blank subject, or a required key-rotation migration whose store lacks `BudgetSeedStoreInterface`. See `examples/simple-fixed-window.php` for a complete runnable example.

## Primary Public Calls

The normal consumer path uses these public types:

1. Build a <code>RateLimitContextDTO</code> from host-owned request and identity signals.
2. Select a <code>RateLimitCommand</code> with <code>checkOnly()</code>, <code>recordFailure()</code>, or <code>recordSuccess()</code>.
3. Call <code>RateLimiterInterface::limit()</code> on the <code>RateLimiterInterface</code> returned by <code>RateLimiterBuilder::build()</code>.
4. Handle the returned <code>RateLimitResultDTO</code> and its <code>decision</code>, <code>blockLevel</code>, <code>retryAfter</code>, <code>failureMode</code>, and optional <code>metadata</code>.

The available policy preset names are <code>login_protection</code>, <code>otp_protection</code>, and <code>api_heavy_protection</code>. The package reference documents the full DTO and extension inventory.

For <code>api_heavy_protection</code>, K2 minor overuse returns at most <code>SOFT_BLOCK</code> L1, K3 moderate overuse returns <code>HARD_BLOCK</code> L2, and K1 severe overuse returns <code>HARD_BLOCK</code> L3. The preset does not enforce account K4 or account/device K5 blocks. When K3 has LOW device confidence, a moderate signal remains a hard L2 decision and is persisted against K2.

## Inputs and Outputs

<code>RateLimitContextDTO</code> accepts the request IP, user agent, optional account identifier, optional host-provided client fingerprint data that is already normalized, bucketed, and low-entropy, optional session device identifier, trust flags, headers, and the host-owned previously-verified-device signal. The default resolver does not automatically collect or inspect request headers.

<code>RateLimitCommand</code> expresses intent rather than transport semantics:

- <code>checkOnly()</code> evaluates without recording a success or failure event. Authentication pre-checks may update bounded credential-correlation state, so this command is not globally read-only.
- <code>recordFailure()</code> evaluates and records the applicable failure signals.
- <code>recordSuccess()</code> records a successful operation and returns the current <code>RateLimitResultDTO</code>; it does not bypass an already active block.

<code>RateLimitResultDTO</code> returns <code>ALLOW</code>, <code>SOFT_BLOCK</code>, or <code>HARD_BLOCK</code>. The host owns the final response, retry handling, logging, and user-facing presentation.

### Retry-After Semantics

The returned <code>retryAfter</code> has three distinct contracts:

- An active persisted block returns the remaining persisted block TTL. It is authoritative and is not combined with score decay.
- A score-derived <code>SOFT_BLOCK</code> returns the time until the score is below L1. A score-derived <code>HARD_BLOCK</code>, including L3, returns the time until the score is below L2. <code>DecayCalculator</code> uses the package-owned account, device, and IP intervals together with the stored score timestamp and elapsed partial interval. Retained multiple-block-cycle pauses are subtracted from elapsed decay, and an active pause's remaining time is added to score-derived Retry-After.
- A budget result returns the remaining budget cooldown. Correlation, flood, credential-spray, and other non-score decisions retain their own existing retry rules.

The response retry time is independent from persistence: score-derived results may use a decay wait while the persisted block continues to use the configured <code>PenaltyLadder</code> duration.

## Integration Boundaries

The package supplies the optional official `RedisFullCapabilityStore` for one logical
non-clustered Redis server. Consumers selecting that store provide a
`RedisCommandExecutorInterface` implementation, such as
`CallableRedisCommandExecutor`, backed by a Host-owned Redis client and connection
lifecycle. Consumers selecting another backend supply the following storage
implementations:

- <code>RateLimitStoreInterface</code>: counters, blocks, and budget state with the atomicity and TTL behavior required by the package.
- <code>CorrelationStoreInterface</code>: source-compatible base contract for distinct sets and watch flags.
- <code>BoundedCorrelationStoreInterface</code>: required additive capability for bounded device-cap, churn, dilution, and no-rotation spray observations.
- <code>BoundedCorrelationRotationStoreInterface</code>: required additive capability when a current/previous generation observation is active; it preserves previous state through a current-only bridge.
- <code>BoundedCorrelationSnapshotStoreInterface</code>: required additive capability for Login/OTP distributed-account snapshots without a previous generation.
- <code>BoundedCorrelationSnapshotRotationStoreInterface</code>: required additive capability for distributed-account snapshots when a previous generation is active.
- <code>CorrelationRotationStoreInterface</code>: additive capability for rotated WATCH state and the existing credential-spray rotation primitives.
- <code>CircuitBreakerStoreInterface</code>: circuit-breaker state persistence.
- <code>CircuitBreakerProbeStoreInterface</code>: additive atomic per-policy
  recovery-probe lease; required only when a recovery probe becomes eligible.
- <code>FailureSignalEmitterInterface</code>: delivery of circuit-breaker and failure signals.
- <code>HardBlockCycleStoreInterface</code>: atomic Current-only persistence and transition tracking for every persisted L2+ block, plus read-only Current/Previous decay-pause state. It is mandatory before the first L2+ block write; a base-only store remains valid for normal reads and L1 writes but fails explicitly for L2+ persistence.
- <code>PunishmentLifecycleStoreInterface</code>: generation-bound authentication K4 score, punishment-evidence, and one-shot claim capability.
- <code>FullCapabilityStoreInterface</code>: aggregate contract including the punishment lifecycle capability; custom implementers must add its methods.
- <code>ClockInterface</code> from <code>maatify/shared-common</code>: current time and timezone.

The host may also provide a custom <code>DeviceIdentityResolverInterface</code> or a custom <code>BlockPolicyInterface</code> through the builder's targeted overrides. A same-name policy replaces the matching default, while a new name is added. <code>BudgetSeedStoreInterface</code> is an additive storage capability used when a host store supports atomic budget-epoch seeding during key rotation.

The package owns enforcement decisions, key construction, scoring, decay, bounded correlation logic, budget eligibility, circuit-breaker behavior, result semantics, and the official Redis persistence semantics. The Host owns the Redis client and connection lifecycle for that store, custom storage implementations when selecting another backend, account and session truth, transport response behavior, authorization, logging destinations, and cross-domain reporting.

### Generation-bound authentication re-entry

Login and OTP default policies use DEC-007. Build them with a store that
implements `PunishmentLifecycleStoreInterface`; the Builder fails early when
that capability is missing. A valid `checkOnly` ALLOW may contain public
`postPunishmentReentry` metadata, and an application handoff is completed
exactly once through `RateLimiterRuntimeInterface::claimPostPunishmentReentry()`.
Hosts must not reconstruct package keys or generation state. API Heavy and
custom non-opt-in policies are unaffected.

This is a public workflow, not a storage recipe: `checkOnly()` may return
`postPunishmentReentry` metadata only after a coherent unblocked generation
check, and the host passes that metadata to
`claimPostPunishmentReentry()`. The claim returns `true` once and `false` on
replay while leaving evidence intact. A newer mutation, rotation mismatch, or
active K4 block suppresses metadata. Redis uses server time and one atomic
publication primitive, so hosts must not reproduce these checks with separate
reads and writes.

The base correlation contract keeps its existing signatures and remains sufficient
without a previous key secret. Its concrete implementation must establish the first
window TTL atomically and must not refresh that TTL on later writes. During key-secret
rotation, the selected store must implement <code>BoundedCorrelationRotationStoreInterface</code>
and the WATCH rotation capability: the current generation is writable, the previous
generation is read-only, and a current-secret bridge deduplicates subjects while its TTL is
capped by the previous remaining TTL. Bounded device-cap windows are 900 seconds with caps
of 10 account members and 50 IP-scope members; spray, churn, and dilution are capped at 5,
3, and 6. Duplicates are admitted without mutation, while new members at a cap are rejected
without set growth. Login/OTP distributed-account checks additionally require a snapshot
capability. Their 600-second snapshot is capped at four canonical K5 members and returns the
complete logical member set plus fixed expiry; three members create a 30-minute WATCH, the
second qualifying WATCH or fourth member blocks every involved K5 at L2, and the exact third
24-hour occurrence blocks K4 at L4 for 1800 seconds. API Heavy and requests missing account,
fingerprint, or real K4/K5 do not observe this rule. A missing capability or corrupt previous
state fails explicitly rather than silently resetting enforcement. Store keys and members are
opaque keyed-HMAC references; raw account, IP, correlation, and fingerprint values never cross
the boundary. The package includes the optional official Redis full-capability store;
other backends continue through <code>FullCapabilityStoreInterface</code> or the
existing fine-grained interfaces. Redis remains optional and is not the core
abstraction.

The snapshot operation is part of the pre-check lifecycle: <code>checkOnly()</code> does not
record a success or failure, but it may update bounded distributed-correlation state. A later
<code>recordFailure()</code> or <code>recordSuccess()</code> for the same host lifecycle does not
observe the distributed window again. During rotation, previous state and its TTL remain
unchanged, new members are owned by the current generation and bridge, and outer-only,
fingerprint-only, and both-rotated inputs never form Cartesian generation pairs.

## Capability Map

| Consumer capability | Public contract | Walkthrough | Example |
| --- | --- | --- | --- |
| Check a request before an operation | <code>RateLimiterInterface::limit()</code> + <code>RateLimitCommand::checkOnly()</code> | [Pre-check](#walkthrough-pre-check) | [basic-rate-limit.php](../../examples/basic-rate-limit.php) |
| Record a failed login or OTP attempt | <code>RateLimitCommand::recordFailure()</code> | [Failure recording](#walkthrough-failure-recording) | [basic-rate-limit.php](../../examples/basic-rate-limit.php) |
| Record a successful operation | <code>RateLimitCommand::recordSuccess()</code> | [Success recording](#walkthrough-success-recording) | [basic-rate-limit.php](../../examples/basic-rate-limit.php) |
| Use a policy preset | Default <code>RateLimiterBuilder</code> policy registry | [Policy selection](#walkthrough-policy-selection) | [basic-rate-limit.php](../../examples/basic-rate-limit.php) |
| Observe infrastructure failures | <code>FailureSignalEmitterInterface</code> + <code>RateLimitResultDTO::failureMode</code> | [Failure boundary](#walkthrough-failure-boundary) | [infrastructure-failure.php](../../examples/infrastructure-failure.php) |
| Inspect current operational rate-limit state | <code>RateLimitOperationalReaderInterface::read()</code> | [Operational read](#walkthrough-operational-read) | [operational-read.php](../../examples/operational-read.php) |

## Walkthrough: Pre-Check

    Input → RateLimitContextDTO + RateLimitCommand::checkOnly('login_protection')
          → Public Call → RateLimiterInterface::limit()
          → Result → RateLimitResultDTO with ALLOW, SOFT_BLOCK, or HARD_BLOCK
          → Boundary → Host permits the operation or returns its own retry response

The pre-check is appropriate immediately before a protected host operation. A blocked result is an observable decision; the package does not send an HTTP response or throw a transport-specific exception for an ordinary block.

## Walkthrough: Failure Recording

    Input → Host records that the authentication or step-up attempt failed
          → Public Call → RateLimiterInterface::limit($context, RateLimitCommand::recordFailure($policy))
          → Result → RateLimitResultDTO plus updated state through the selected store adapter
          → Boundary → The selected store persists state and the Host applies the returned decision

Use the policy that matches the protected operation. The package keeps the decision and persistence semantics inside its engine; the host does not reproduce scoring or key rules.

## Walkthrough: Success Recording

    Input → Host confirms a successful protected operation
          → Public Call → RateLimiterInterface::limit($context, RateLimitCommand::recordSuccess($policy))
          → Result → RateLimitResultDTO reflecting the current enforcement state
          → Boundary → Host continues its success flow only when the returned decision permits it

## Walkthrough: Policy Selection

    Input → Protected operation category: login, OTP/step-up, or API-heavy
          → Public Call → Select one of the builder's default policy names
          → Result → The command's policy name selects the configured policy at evaluation time
          → Boundary → Host maps the result to the operation's own response contract

The builder registers `login_protection`, `otp_protection`, and `api_heavy_protection` automatically. Use `withPolicy()` before `build()` to replace a same-name policy or add a differently named policy. The policy presets are production runtime classes. Do not invent policy behavior from test fixtures.

Reusable behavior is selected by typed capability opt-in, not by policy name. A
custom policy can implement `PolicyCapabilityProviderInterface` and return
`PolicyCapabilityEnum` enum cases for credential spray, distributed-account
correlation, trusted-authentication advisory behavior, or API overuse. A custom
policy that does not opt in receives base behavior only. `PostPunishmentReentryPolicyInterface`
is a separate DEC-007 lifecycle capability; it requires K4, monotonic positive
K4 thresholds, fail-closed semantics, and lifecycle-capable storage regardless
of the policy's name.

Backend-failure fallback is a separate typed DEC-011 concern from normal-runtime
capabilities, declared through `FailureFallbackConfigurationProviderInterface`.

**Ready-to-use (recommended):** `new LoginProtectionPolicy()`,
`new OtpProtectionPolicy()`, and `new ApiHeavyProtectionPolicy()` resolve their
official locked fallback caps internally. No fallback-specific constructor
argument, builder call, or Host wiring is required to get this behavior.

**Advanced:** a direct custom reusable policy may implement
`FailureFallbackConfigurationProviderInterface` itself and return its own
`FailureFallbackConfigurationDTO` — a list of `FailureFallbackRuleDTO` values,
each pairing a `FailureFallbackDimensionEnum` (`ACCOUNT`, `IP_PREFIX`, or
`IP_PREFIX_NORMALIZED_USER_AGENT`) with a positive limit and window in
seconds. The values are entirely the policy's own and independent of every
official preset; the package validates them with the same rules (positive,
non-duplicate, and the minimum dimensions its declared capabilities require)
and evaluates them through the same `LocalFallbackLimiter` runtime the
official presets use — there is no separate "official" or "custom" fallback
code path. The fallback namespace includes policy identity, so differently
named reusable policies never share process-local counters, even when their
configured numbers are identical.

## Walkthrough: Failure Boundary

    Input → A configured storage or runtime integration throws
          → Public Call → RateLimiterEngine::limit() applies the selected failure semantics
          → Result → RateLimitResultDTO with the resolved failureMode; signals go to FailureSignalEmitterInterface
          → Boundary → Host observes, logs, and applies its own transport or incident handling

Login and OTP policies are security-oriented and use fail-closed semantics with bounded degraded behavior. API-heavy protection may use fail-open semantics with local guardrails. See [Failure Semantics](../FAILURE_SEMANTICS.md) for the detailed contract.

### Circuit-breaker recovery

The engine consults the circuit breaker before resolving device identity or
running normal evaluation. `OPEN` and `HALF_OPEN` requests use the bounded
local fallback and do not contact the shared backend. After the 300-second
minimum `OPEN` duration, one request may acquire the atomic 120-second
`CircuitBreakerProbeStoreInterface` lease and invoke
`EvaluationPipeline::isBackendHealthy()`. This is a read-only delegation to
`RateLimitStoreInterface::isHealthy()`; it does not read or write scores,
blocks, budgets, correlation state, or device state.

The first healthy probe enters `HALF_OPEN` but remains degraded. After a further
120-second healthy interval, the second healthy probe closes the circuit and the
same request may continue normal evaluation. A missing probe capability at that
eligibility point raises `RateLimiterException`; the package never probes
without a lease. The re-entry guard has precedence over every circuit state,
blocks both probing and normal backend work, emits its critical signal once, and
returns the remaining guard duration as `Retry-After`.

## Runnable Example

Run the complete in-memory assembly from the repository root after installing dependencies:

    composer install --no-interaction --prefer-dist --no-progress
    php examples/basic-rate-limit.php

[basic-rate-limit.php](../../examples/basic-rate-limit.php) uses the production autoloader, production runtime classes, and only public contracts. Its in-memory adapters are example scaffolding; replace them with the host's real atomic persistence and observability implementations.

[infrastructure-failure.php](../../examples/infrastructure-failure.php) deterministically
throws from a host-side storage adapter and prints the resulting failure mode and
emitted signal. It demonstrates the current failure boundary without changing the
runtime implementation.

## Operational Read / Reporting Boundary

This package is **In Scope** for Operational Read / Reporting because it owns the persisted operational semantics of score state, temporary blocks, account budgets, known-device micro-caps, budget cooldowns, and circuit-breaker state, including the optional official Redis persistence implementation. The Host supplies the Redis client and connection lifecycle for that implementation or a custom persistence backend when selecting another store, along with account/session source of truth, HTTP/transport, permissions, dashboards/UI, cross-package aggregation, and exports.

The stable, framework-agnostic read contract is <code>RateLimitOperationalReaderInterface::read()</code>, implemented by <code>RateLimitOperationalReader</code>. It accepts a <code>RateLimitContextDTO</code> and <code>BlockPolicyInterface</code>, returns a typed <code>RateLimitOperationalSnapshotDTO</code>, and performs a point-in-time read without changing enforcement state. It is separate from <code>RateLimiterInterface::limit()</code>, which remains the consumer enforcement API.

Correlation distinct-set members, watch-flag internals, churn sets, and dilution sets are intentionally unsupported because they are internal bounded enforcement structures without a stable operational reporting semantic. The read surface has no mutation/reset/unblock, global listing, arbitrary key lookup, raw-key exposure, historical audit store, Host joins, cross-package reporting, or correlation-set inspection.

## Walkthrough: Operational Read

    Host context + policy
        → RateLimitOperationalReaderInterface::read()
        → Package-owned read-only state resolution
        → RateLimitOperationalSnapshotDTO
        → Host monitoring or operations boundary

The operational reader reads current real enforcement keys and applies the documented single previous-generation fallback where configured. It does not call <code>EvaluationPipeline::process()</code>, <code>RateLimiterEngine::limit()</code>, <code>EphemeralBucket</code>, correlation mutation methods, or circuit-breaker mutation methods, and it never exposes raw storage keys, secrets, fingerprints, or Host data.

## Further Reading

- [Package Reference](../../RATE_LIMITER_PACKAGE_REFERENCE.md)
- [Decision Matrix](../DECISION_MATRIX.md)
- [Policy Presets](../POLICIES.md)
- [Device Fingerprint](../DEVICE_FINGERPRINT.md)
- [Key Strategy](../KEY_STRATEGY.md)
- [Failure Semantics](../FAILURE_SEMANTICS.md)
- [Simple Fixed-Window Throttling](../SIMPLE_THROTTLING.md)
