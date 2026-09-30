<div align="center">

# Maatify Rate Limiter

![Maatify.dev](https://www.maatify.dev/assets/img/img/maatify_logo_white.svg)

[![Status](https://img.shields.io/badge/Status-Release%20Candidate-orange)](README.md)
[![Published Version](https://img.shields.io/badge/Published%20Version-1.0.0--rc.1-orange)](https://packagist.org/packages/maatify/php-rate-limiter)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4.svg)](composer.json)
[![License: Proprietary](https://img.shields.io/badge/License-Proprietary-red.svg)](LICENSE)
[![PHPStan Level Max](https://img.shields.io/badge/PHPStan-Level%20Max-4F5B93.svg)](phpstan.neon)

[![Packagist](https://img.shields.io/packagist/v/maatify/php-rate-limiter?include_prereleases&label=Packagist)](https://packagist.org/packages/maatify/php-rate-limiter)
[![Monthly Downloads](https://img.shields.io/packagist/dm/maatify/php-rate-limiter)](https://packagist.org/packages/maatify/php-rate-limiter)
[![Total Downloads](https://img.shields.io/packagist/dt/maatify/php-rate-limiter)](https://packagist.org/packages/maatify/php-rate-limiter)
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-blueviolet)](https://github.com/Maatify)
[![Install RC1](https://img.shields.io/badge/Install-1.0.0--rc.1-blue)](https://packagist.org/packages/maatify/php-rate-limiter)

[![Usage Guide](https://img.shields.io/badge/Docs-Usage%20Guide-blue.svg)](docs/guides/USAGE_GUIDE.md)
[![Examples](https://img.shields.io/badge/Docs-Examples-blue.svg)](examples/)
[![Package Reference](https://img.shields.io/badge/Docs-Package%20Reference-blue.svg)](RATE_LIMITER_PACKAGE_REFERENCE.md)
[![Changelog](https://img.shields.io/badge/Docs-Changelog-blue.svg)](CHANGELOG.md)
[![Security Policy](https://img.shields.io/badge/Docs-Security%20Policy-blue.svg)](SECURITY.md)
[![Contributing Guide](https://img.shields.io/badge/Docs-Contributing-blue.svg)](CONTRIBUTING.md)

PHP library for deterministic, multi-signal rate-limit decisions.

</div>

---

## Package Status

This package's published pre-release is **Release Candidate `1.0.0-rc.1`**, distributed through Packagist. No Published Stable release or Stable support line exists. It is proprietary software; repository visibility and registry presence do not grant open-source or general usage rights. Authorized use requires written authorization or an applicable written license agreement from Maatify.

## Key Features

- Device fingerprinting with bounded device handling.
- Multi-signal rate-limit decisions across IPv4 and IPv6 key strategies.
- Explicit failure modes with circuit-breaker and local fallback contracts.
- DTO- and contract-based public boundaries for deterministic integration.

## Requirements

- PHP `^8.4`.
- PHP extensions `date`, `filter`, `hash`, `json`, `pcre`, and `random`.
- `maatify/exceptions` `^1.0`.
- `maatify/shared-common` `^1.0`.

## Installation

Install the exact published Release Candidate:

```bash
composer require maatify/php-rate-limiter:1.0.0-rc.1
```

Use remains subject to written authorization or an applicable written license agreement from Maatify.

## Usage

The production default path is one `RateLimiterConfig`, one official Redis
`RedisFullCapabilityStore` or Host implementation of
`FullCapabilityStoreInterface`, and the named Builder convenience path. The
Host retains ownership of the Redis client/connection lifecycle and supplies a
command executor. See the [Usage Guide](docs/guides/USAGE_GUIDE.md) for the
integration contract and [basic runnable example](examples/basic-rate-limit.php)
for a complete in-memory assembly.

```php
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\RateLimiterConfig;

/** @var FullCapabilityStoreInterface $fullCapabilityStore */
/** @var FailureSignalEmitterInterface $failureSignalEmitter */

$config = new RateLimiterConfig(
    keySecret: $activeKeySecret,
    fingerprintSecret: $activeFingerprintSecret,
    environmentScope: 'production',
);

$limiter = RateLimiterBuilder::fromFullCapabilityStore(
    $config,
    $fullCapabilityStore,
    $failureSignalEmitter,
)
    ->withPolicy($customPolicy) // Optional typed policy extension.
    ->build();
```

For the official Redis adapter, construct `RedisFullCapabilityStore` with the
Host-owned `RedisCommandExecutorInterface` (or
`CallableRedisCommandExecutor`) and pass that store as `$fullCapabilityStore`.

### Advanced composition path

Consumers that intentionally keep separate storage boundaries may use the
lower-level multi-store constructor:

```php
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;

/** @var RateLimitStoreInterface $rateLimitStore */
/** @var CorrelationStoreInterface $correlationStore */
/** @var CircuitBreakerStoreInterface $circuitBreakerStore */
/** @var FailureSignalEmitterInterface $failureSignalEmitter */

$limiter = new RateLimiterBuilder(
    new RateLimiterConfig($activeKeySecret, $activeFingerprintSecret, 'production'),
    $rateLimitStore,
    $correlationStore,
    $circuitBreakerStore,
    $failureSignalEmitter,
)->build();

$context = new RateLimitContextDTO(
    ip: '203.0.113.10',
    ua: 'Mozilla/5.0 Chrome/123',
    accountId: 'account-123'
);
$result = $limiter->limit($context, RateLimitCommand::checkOnly('login_protection'));

if ($result->isBlocked()) {
    http_response_code(429);
    header('Retry-After: ' . (string) $result->retryAfter);
}
```

`FullCapabilityStoreInterface` adds no methods of its own. The package includes
an official optional non-clustered Redis implementation under `Repository\\Redis`,
but consumers choosing another backend still install the package without a Redis
extension or client. Redis consumers own the concrete client and pass commands
through `RedisCommandExecutorInterface` or `CallableRedisCommandExecutor`.

## Public Runtime API

`RateLimiterInterface::limit()` is the framework-agnostic consumer entrypoint. Hosts provide a `RateLimitContextDTO` and a `RateLimitCommand`, then handle the returned `RateLimitResultDTO` at their transport boundary.

The additive simple fixed-window enforcement path is:

`RateLimiterBuilder` → `withSimpleThrottlePolicy(...)` → `build()` → `CompositeRateLimiterRuntimeInterface` → `SimpleRateLimiterInterface::consume(policyName, subject, cost = 1)` → `SimpleRateLimitResultDTO`.

It is an opt-in weighted quota path governed by DEC-013 and DEC-014; it does not replace the existing score-based `RateLimiterInterface::limit()` path.

The default Login and OTP policies opt into DEC-007 generation-bound K4
post-punishment re-entry. Consumers that need the one-shot application handoff
may type-hint `RateLimiterRuntimeInterface` and call
`claimPostPunishmentReentry()` using only public metadata from a valid
`checkOnly` result. API Heavy and non-opt-in policies retain normal score/decay
behavior.

The public runtime surface also includes the `login_protection`, `otp_protection`, and `api_heavy_protection` policy presets; typed context, command, result, identity, state, operational snapshot, and metadata DTOs; `RateLimitOperationalReaderInterface::read()` for read-only point-in-time operational inspection; and extension contracts for rate-limit storage, the aggregate full-capability storage contract (`FullCapabilityStoreInterface`), atomic L2+ hard-block cycle tracking (`HardBlockCycleStoreInterface`), correlation storage, circuit-breaker state, failure signals, device identity resolution, and custom policies. A base-only rate-limit store remains compatible for reads and L1 writes, but L2+ persistence requires the additive hard-block cycle capability.

`RateLimiterBuilder::buildOperationalReader()` (DEC-012) is the Production Default Read Path for Operational Read: it returns `CompositeRateLimitOperationalReaderInterface`, built from the Builder's own current registered state, so `readScorePolicy(context, policyName)` and `readSimpleThrottle(policyName, subject)` resolve the exact same score/simple policy definitions, clock, device resolver, and key configuration the Builder's `build()` enforces with — no Host reconstruction of policy semantics and no second `BlockPolicyInterface` needed. The existing `RateLimitOperationalReaderInterface`/`RateLimitOperationalReader` remain fully available as the Advanced Path. Simple fixed-window persisted state (DEC-013) is included in the Operational Read classification via `SimpleRateLimitOperationalReaderInterface`/`SimpleRateLimitOperationalReader` and `SimpleRateLimitOperationalSnapshotDTO`; this is a read-only inspection, not a second `consume()`.

For DEC-007, the Redis lifecycle primitive atomically couples the generation-bound
K4 score, L2+ block, score-expiry evidence, and generation fence. Reads use a
coherent current/previous snapshot and Redis server time; active blocks suppress
evidence for every command. The claim does not consume punishment satisfaction
or evidence; it consumes only the one-shot application handoff marker, so replay
is safe. Custom opt-in authentication policies
must implement the policy marker and lifecycle capability; API Heavy remains
outside this flow.

The [Package Reference](RATE_LIMITER_PACKAGE_REFERENCE.md) contains the complete public interface, command, DTO, concrete service, policy, and extension-boundary inventory. Runtime behavior is defined by the current source and the linked decision, policy, device, key, and failure documents.

## Exception and Error Propagation

Only an explicitly classified operational backend failure represented by
`BackendFailureException` enters the score-runtime circuit and bounded fallback.
Invalid input, configuration, missing capability, malformed or corrupt state,
programming failures, and unknown or untyped throwables retain their explicit
package/contract exception behavior; Host adapters must not reclassify unknown
failures as backend outages. The enforcement path owns the resulting score
failure decision, while the read-only operational reader does not convert
integration failures into an enforcement result. Simple fixed-window storage
failures remain the separate DEC-013 `FAIL_CLOSED` contract.

See [Failure Semantics](docs/FAILURE_SEMANTICS.md) and the [Package Reference](RATE_LIMITER_PACKAGE_REFERENCE.md) for the detailed failure contract.

## Transaction and Concurrency Boundary

The package does not open a general database transaction or own backend locks around `RateLimiterInterface::limit()`. Concrete host store implementations own the transaction, locking, and native atomic primitives required by their backend, and every operation declared atomic by the package contracts must remain atomic.

There is no package-level distributed transaction across the rate-limit, correlation, circuit-breaker, and failure-signal boundaries, and the package does not promise a shared atomic commit with the host application's business transaction.

See the [Package Reference](RATE_LIMITER_PACKAGE_REFERENCE.md) for the full transaction, locking, and concurrency contract.

## Security and Trust Boundaries

The host remains the authority for account, session, authentication, authorization, and previously verified device truth. Device fingerprinting is a bounded risk signal used for rate-limit decisions; it is not authentication, proof of device ownership, or a cross-context tracking identity.

The package keeps transport behavior and host business identity outside its boundary, and its operational read API does not expose raw storage keys, raw fingerprint material, or host-owned records.

See [Device Fingerprint](docs/DEVICE_FINGERPRINT.md), [Failure Semantics](docs/FAILURE_SEMANTICS.md), and the [Package Reference](RATE_LIMITER_PACKAGE_REFERENCE.md) for the detailed contracts.

## Documentation

- [Usage Guide](docs/guides/USAGE_GUIDE.md)
- [Runnable Examples](examples/)
- [Package Reference](RATE_LIMITER_PACKAGE_REFERENCE.md)
- [Future Upgrade Roadmap](docs/ROADMAP.md)
- [Decision Matrix](docs/DECISION_MATRIX.md)
- [Device Fingerprint](docs/DEVICE_FINGERPRINT.md)
- [Failure Semantics](docs/FAILURE_SEMANTICS.md)
- [Key Strategy](docs/KEY_STRATEGY.md)
- [Policy Presets](docs/POLICIES.md)
- [Security Policy](SECURITY.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)

## Quality Status

The repository quality gate covers strict Composer validation, dependency compatibility, platform requirements, PHP syntax, PHPStan at level `max`, the full PHPUnit suite, the focused `composer test:integration` suite, standalone example smoke execution, Composer security auditing, workflow linting, and whitespace verification. The package is a published pre-release and has no Published Stable release or Stable support line.

## License

Proprietary. All rights reserved. See [LICENSE](LICENSE).

## 👤 Author

Engineered by **Mohamed Abdulalim** ([@megyptm](https://github.com/megyptm))<br>
Backend Lead & Technical Architect<br>
[https://www.maatify.dev](https://www.maatify.dev)

---

<div align="center">

[Built with ❤️ by Maatify.dev — Unified Ecosystem for Modern PHP Libraries](https://www.maatify.dev)

</div>
