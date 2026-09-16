<div align="center">

# Maatify Rate Limiter

![Maatify.dev](https://www.maatify.dev/assets/img/img/maatify_logo_white.svg)

[![Package Status](https://img.shields.io/badge/status-pre--release%20development-orange.svg)](#package-status)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4.svg)](composer.json)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-blueviolet)](https://github.com/Maatify)
[![Changelog](https://img.shields.io/badge/Changelog-View-blue.svg)](CHANGELOG.md)
[![Package Reference](https://img.shields.io/badge/Reference-Read-blue.svg)](RATE_LIMITER_PACKAGE_REFERENCE.md)

PHP library for deterministic, multi-signal rate-limit decisions.

</div>

---

## Package Status

This package is in pre-release development. It has no published stable release and is not currently distributed through Packagist. Until an approved distribution is published, access is through this repository.

## Key Features

- Device fingerprinting with bounded device handling.
- Multi-signal rate-limit decisions across IPv4 and IPv6 key strategies.
- Explicit failure modes with circuit-breaker and local fallback contracts.
- DTO- and contract-based public boundaries for deterministic integration.

## Requirements

- PHP `^8.4`.
- PHP extensions `filter`, `hash`, `json`, and `pcre`.
- `maatify/shared-common` `^1.0`.

## Installation

The package is not yet available through a published Composer registry. For development, use this repository as the Composer package source according to your consumer project's repository policy. Published installation instructions will be added when distribution is approved.

## Usage

The package provides storage and signal contracts; the consumer supplies implementations for those boundaries.

```php
use Maatify\RateLimiter\Contract\CircuitBreakerStoreInterface;
use Maatify\RateLimiter\Contract\CorrelationStoreInterface;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\Contract\RateLimitStoreInterface;
use Maatify\RateLimiter\Device\DeviceIdentityResolver;
use Maatify\RateLimiter\Device\EphemeralBucket;
use Maatify\RateLimiter\Device\FingerprintHasher;
use Maatify\RateLimiter\Engine\CircuitBreaker;
use Maatify\RateLimiter\Engine\EvaluationPipeline;
use Maatify\RateLimiter\Engine\FailureModeResolver;
use Maatify\RateLimiter\Engine\RateLimiterEngine;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitRequestDTO;
use Maatify\RateLimiter\Penalty\AntiEquilibriumGate;
use Maatify\RateLimiter\Penalty\BudgetTracker;
use Maatify\RateLimiter\Penalty\DecayCalculator;
use Maatify\RateLimiter\Policy\LoginProtectionPolicy;
use Maatify\RateLimiter\Policy\OtpProtectionPolicy;
use Maatify\SharedCommon\Contracts\ClockInterface;

/** @var RateLimitStoreInterface $rateLimitStore */
/** @var CorrelationStoreInterface $correlationStore */
/** @var CircuitBreakerStoreInterface $circuitBreakerStore */
/** @var FailureSignalEmitterInterface $emitter */
/** @var ClockInterface $clock */

$deviceResolver = new DeviceIdentityResolver(new FingerprintHasher('active-key'));
$budgetTracker = new BudgetTracker($rateLimitStore, $clock);
$pipeline = new EvaluationPipeline(
    $rateLimitStore,
    $correlationStore,
    $budgetTracker,
    new AntiEquilibriumGate($correlationStore),
    new DecayCalculator($clock),
    new EphemeralBucket($correlationStore),
    'active-key',
    'prod',
    $clock,
    'previous-key'
);
$circuitBreaker = new CircuitBreaker($circuitBreakerStore, $emitter, $clock);
$engine = new RateLimiterEngine(
    $deviceResolver,
    $pipeline,
    $circuitBreaker,
    new FailureModeResolver(),
    $emitter,
    $clock,
    [new LoginProtectionPolicy(), new OtpProtectionPolicy()]
);

$context = new RateLimitContextDTO(
    ip: '203.0.113.1',
    ua: 'Mozilla/5.0',
    accountId: 'user_123'
);
$result = $engine->limit($context, RateLimitRequestDTO::checkOnly('login_protection'));

if (!$result->isAllowed()) {
    http_response_code(429);
    header('Retry-After: ' . $result->retryAfter);
    exit;
}
```

## Contracts

- `RateLimitStoreInterface`: storage for counters, blocks, and budgets.
- `CorrelationStoreInterface`: storage for distinct counts and watch flags.
- `CircuitBreakerStoreInterface`: persistence for circuit-breaker state.
- `FailureSignalEmitterInterface`: delivery boundary for failure signals.
- `BlockPolicyInterface`: policy thresholds, deltas, failure mode, and budgets.

## Documentation

- [Package Reference](RATE_LIMITER_PACKAGE_REFERENCE.md)
- [Decision Matrix](docs/DECISION_MATRIX.md)
- [Device Fingerprint](docs/DEVICE_FINGERPRINT.md)
- [Failure Semantics](docs/FAILURE_SEMANTICS.md)
- [Key Strategy](docs/KEY_STRATEGY.md)
- [Policy Presets](docs/POLICIES.md)

## Quality Status

The repository quality gate covers strict Composer validation, dependency compatibility, platform requirements, PHP syntax, PHPStan at level `max`, PHPUnit, Composer security auditing, workflow linting, and whitespace verification. The package remains pre-release and has no published stable support line.

## License

MIT. See [LICENSE](LICENSE).

## 👤 Author

Engineered by **Mohamed Abdulalim** ([@megyptm](https://github.com/megyptm))<br>
Backend Lead & Technical Architect<br>
[https://www.maatify.dev](https://www.maatify.dev)

---

<div align="center">

[Built with ❤️ by Maatify.dev — Unified Ecosystem for Modern PHP Libraries](https://www.maatify.dev)

</div>
