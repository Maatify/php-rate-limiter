<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use ConsumerVerification\FixedClock;
use ConsumerVerification\InMemoryCircuitBreakerStore;
use ConsumerVerification\InMemoryCorrelationStore;
use ConsumerVerification\InMemoryRateLimitStore;
use ConsumerVerification\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Contract\BudgetSeedStoreInterface;
use Maatify\RateLimiter\Device\DeviceIdentityResolver;
use Maatify\RateLimiter\Device\EphemeralBucket;
use Maatify\RateLimiter\Device\FingerprintHasher;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Engine\CircuitBreaker;
use Maatify\RateLimiter\Engine\EvaluationPipeline;
use Maatify\RateLimiter\Engine\FailureModeResolver;
use Maatify\RateLimiter\Engine\RateLimiterEngine;
use Maatify\RateLimiter\Penalty\AntiEquilibriumGate;
use Maatify\RateLimiter\Penalty\BudgetTracker;
use Maatify\RateLimiter\Penalty\DecayCalculator;
use Maatify\RateLimiter\Policy\LoginProtectionPolicy;
use Maatify\RateLimiter\Policy\OtpProtectionPolicy;

require __DIR__ . '/vendor/autoload.php';

/** @throws RuntimeException */
function requireCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$installPath = InstalledVersions::getInstallPath('maatify/php-rate-limiter');
if ($installPath === null) {
    throw new RuntimeException('Composer did not install maatify/php-rate-limiter.');
}
requireCondition(!is_link($installPath), 'The package was installed as a symlink.');
requireCondition(
    class_exists(RateLimiterEngine::class),
    'The production PSR-4 autoload did not expose RateLimiterEngine.'
);
requireCondition(
    !class_exists('Maatify\\RateLimiter\\Tests\\Support\\Clock\\FixedClock'),
    'The package test namespace leaked into the external consumer.'
);

$clock = new FixedClock(new DateTimeImmutable('2025-01-01 12:00:00', new DateTimeZone('UTC')));
$rateLimitStore = new InMemoryRateLimitStore($clock);
$correlationStore = new InMemoryCorrelationStore($clock);
$circuitBreakerStore = new InMemoryCircuitBreakerStore();
$failureSignalEmitter = new RecordingFailureSignalEmitter();

requireCondition(
    $rateLimitStore instanceof BudgetSeedStoreInterface,
    'The consumer store does not implement the BudgetSeedStoreInterface capability.'
);

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
$circuitBreaker = new CircuitBreaker($circuitBreakerStore, $failureSignalEmitter, $clock);
$engine = new RateLimiterEngine(
    $deviceResolver,
    $pipeline,
    $circuitBreaker,
    new FailureModeResolver(),
    $failureSignalEmitter,
    $clock,
    [new LoginProtectionPolicy(), new OtpProtectionPolicy()]
);

$context = new RateLimitContextDTO(
    ip: '203.0.113.1',
    ua: 'Mozilla/5.0',
    accountId: 'consumer-user-123',
    clientFingerprint: ['device' => 'consumer-device']
);

$preflight = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));
requireCondition(
    $preflight->decision === RateLimitResultDTO::DECISION_ALLOW,
    'The documented login pre-check did not allow a clean request.'
);

$failure = $engine->limit($context, RateLimitCommand::recordFailure('otp_protection'));
requireCondition(
    $failure->decision === RateLimitResultDTO::DECISION_SOFT_BLOCK,
    'The OTP failure workflow did not return the documented soft-block result.'
);
requireCondition($failure->blockLevel === 1, 'The OTP failure workflow returned an unexpected block level.');
requireCondition($rateLimitStore->writeCount() > 0, 'The consumer rate-limit storage boundary was not written.');
requireCondition(
    $correlationStore->operationCount() > 0,
    'The consumer correlation storage boundary was not used.'
);

echo json_encode([
    'status' => 'PASS',
    'packageInstallPath' => $installPath,
    'preflightDecision' => $preflight->decision,
    'failureDecision' => $failure->decision,
    'failureBlockLevel' => $failure->blockLevel,
    'rateLimitStoreWrites' => $rateLimitStore->writeCount(),
    'correlationStoreOperations' => $correlationStore->operationCount(),
    'failureSignals' => count($failureSignalEmitter->signals()),
], JSON_THROW_ON_ERROR) . PHP_EOL;
