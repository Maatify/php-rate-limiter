<div align="center">

# Maatify Rate Limiter

![Maatify.dev](https://www.maatify.dev/assets/img/img/maatify_logo_white.svg)

[![Status](https://img.shields.io/badge/Status-Development-blue)](README.md)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4.svg)](composer.json)
[![License: Proprietary](https://img.shields.io/badge/License-Proprietary-red.svg)](LICENSE)
[![PHPStan Level Max](https://img.shields.io/badge/PHPStan-Level%20Max-4F5B93.svg)](phpstan.neon)

[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-blueviolet)](https://github.com/Maatify)

[![Usage Guide](https://img.shields.io/badge/Docs-Usage%20Guide-blue.svg)](docs/guides/USAGE_GUIDE.md)
[![Examples](https://img.shields.io/badge/Docs-Examples-blue.svg)](examples/)
[![Package Reference](https://img.shields.io/badge/Docs-Package%20Reference-blue.svg)](RATE_LIMITER_PACKAGE_REFERENCE.md)
[![Changelog](https://img.shields.io/badge/Docs-Changelog-blue.svg)](CHANGELOG.md)
[![Contributing Guide](https://img.shields.io/badge/Docs-Contributing-blue.svg)](CONTRIBUTING.md)

PHP library for deterministic, multi-signal rate-limit decisions.

</div>

---

## Package Status

This package is in pre-release development. It is proprietary software, has no published stable release, and is not currently distributed through Packagist. Repository visibility does not grant open-source or general usage rights; authorized use requires written authorization or an applicable written license agreement from Maatify.

## Key Features

- Device fingerprinting with bounded device handling.
- Multi-signal rate-limit decisions across IPv4 and IPv6 key strategies.
- Explicit failure modes with circuit-breaker and local fallback contracts.
- DTO- and contract-based public boundaries for deterministic integration.

## Requirements

- PHP `^8.4`.
- PHP extensions `filter`, `hash`, `json`, and `pcre`.
- `maatify/exceptions` `^1.0`.
- `maatify/shared-common` `^1.0`.

## Installation

The package is not yet available through a published Composer registry. Authorized development consumers may use this repository as the Composer package source according to their project repository policy and the applicable written authorization or license agreement. Published installation instructions will be added when distribution is approved.

## Usage

The package provides storage and signal contracts; the consumer supplies implementations for those boundaries. `RateLimiterBuilder` supplies the production default composition around those explicit Host boundaries, including the `fromFullCapabilityStore()` convenience path for one aggregate storage adapter. See the [Usage Guide](docs/guides/USAGE_GUIDE.md) for the integration contract and [basic runnable example](examples/basic-rate-limit.php) for a complete in-memory assembly.

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

For one host adapter implementing the aggregate storage contract:

```php
$limiter = RateLimiterBuilder::fromFullCapabilityStore(
    $config,
    $fullCapabilityStore,
    $failureSignalEmitter,
)->build();
```

`FullCapabilityStoreInterface` adds no methods of its own. Its concrete adapter
is supplied by the consumer; the core package includes no Redis, PDO, Lua, or
`ext-redis` implementation.

## Public Runtime API

`RateLimiterInterface::limit()` is the framework-agnostic consumer entrypoint. Hosts provide a `RateLimitContextDTO` and a `RateLimitCommand`, then handle the returned `RateLimitResultDTO` at their transport boundary.

The public runtime surface also includes the `login_protection`, `otp_protection`, and `api_heavy_protection` policy presets; typed context, command, result, identity, state, operational snapshot, and metadata DTOs; `RateLimitOperationalReaderInterface::read()` for read-only point-in-time operational inspection; and extension contracts for rate-limit storage, the aggregate full-capability storage contract (`FullCapabilityStoreInterface`), atomic L2+ hard-block cycle tracking (`HardBlockCycleStoreInterface`), correlation storage, circuit-breaker state, failure signals, device identity resolution, and custom policies. A base-only rate-limit store remains compatible for reads and L1 writes, but L2+ persistence requires the additive hard-block cycle capability.

The [Package Reference](RATE_LIMITER_PACKAGE_REFERENCE.md) contains the complete public interface, command, DTO, concrete service, policy, and extension-boundary inventory. Runtime behavior is defined by the current source and the linked decision, policy, device, key, and failure documents.

## Exception and Error Propagation

Storage and atomicity failures are handled through the package's policy-specific failure semantics; host storage adapters must not swallow integration failures or silently weaken required atomic guarantees. The enforcement path owns the resulting rate-limit failure decision, while the read-only operational reader does not convert integration failures into an enforcement result.

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
- [Decision Matrix](docs/DECISION_MATRIX.md)
- [Device Fingerprint](docs/DEVICE_FINGERPRINT.md)
- [Failure Semantics](docs/FAILURE_SEMANTICS.md)
- [Key Strategy](docs/KEY_STRATEGY.md)
- [Policy Presets](docs/POLICIES.md)

## Quality Status

The repository quality gate covers strict Composer validation, dependency compatibility, platform requirements, PHP syntax, PHPStan at level `max`, the full PHPUnit suite, the focused `composer test:integration` suite, standalone example smoke execution, Composer security auditing, workflow linting, and whitespace verification. The package remains pre-release and has no published stable support line.

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
