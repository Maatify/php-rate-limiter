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
- Host implementations of the storage and signal contracts listed in [Integration Boundaries](#integration-boundaries).

The package is proprietary and is currently in pre-release development. Follow the applicable authorization or written license agreement before integrating it.

## Non-Goals

The package does not provide permanent bans, WAF/CDN behavior, advanced browser fingerprinting, user tracking across contexts, HTTP controllers, routes, middleware, permissions, UI dashboards, or export formats. It also does not own host account identity, authentication, or business lifecycle state.

## Default Composition

Use `RateLimiterBuilder` for the production default graph. The Host may provide the four required integration boundaries separately or provide one concrete `FullCapabilityStoreInterface`; the package supplies the internal orchestration, UTC default clock, default identity resolver, and three policy presets.

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

If one host adapter owns all four additive storage capabilities, use the named
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

Secrets are explicit and independently rotatable. `RateLimiterConfig` rejects empty or whitespace-only active, previous, and environment values without trimming valid caller input. The builder does not create a service container or no-op production adapters. Use `withClock()`, `withDeviceIdentityResolver()`, or `withPolicy()` only for the targeted overrides defined by the public contract; low-level constructors remain the Advanced Path.

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

The host supplies implementations for:

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
- <code>FullCapabilityStoreInterface</code>: aggregate contract for the budget-seed, bounded snapshot-rotation, circuit-probe, and hard-block-cycle capabilities; it declares no additional methods.
- <code>ClockInterface</code> from <code>maatify/shared-common</code>: current time and timezone.

The host may also provide a custom <code>DeviceIdentityResolverInterface</code> or a custom <code>BlockPolicyInterface</code> through the builder's targeted overrides. A same-name policy replaces the matching default, while a new name is added. <code>BudgetSeedStoreInterface</code> is an additive storage capability used when a host store supports atomic budget-epoch seeding during key rotation.

The package owns enforcement decisions, key construction, scoring, decay, bounded correlation logic, budget eligibility, circuit-breaker behavior, and result semantics. The host owns storage implementation, account and session truth, transport response behavior, authorization, logging destinations, and cross-domain reporting.

The base correlation contract keeps its existing signatures and remains sufficient
without a previous key secret. Its concrete implementation must establish the first
window TTL atomically and must not refresh that TTL on later writes. During key-secret
rotation, the host store must implement <code>BoundedCorrelationRotationStoreInterface</code>
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
the boundary. The core package does not include a Redis or other concrete correlation adapter.

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
          → Result → RateLimitResultDTO plus updated state through the host store adapters
          → Boundary → Host persists through its adapters and applies the returned decision

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

This package is **In Scope** for Operational Read / Reporting because it owns the persisted operational semantics of score state, temporary blocks, account budgets, known-device micro-caps, budget cooldowns, and circuit-breaker state. The Host supplies the concrete persistence backend, account/session source of truth, HTTP/transport, permissions, dashboards/UI, cross-package aggregation, and exports.

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
