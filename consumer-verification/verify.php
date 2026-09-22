<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use ConsumerVerification\FixedClock;
use ConsumerVerification\InMemoryCircuitBreakerStore;
use ConsumerVerification\InMemoryCorrelationStore;
use ConsumerVerification\InMemoryRateLimitStore;
use ConsumerVerification\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Repository\BudgetSeedStoreInterface;
use Maatify\RateLimiter\Repository\BoundedCorrelationRotationStoreInterface;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalSnapshotDTO;
use Maatify\RateLimiter\Repository\CorrelationRotationStoreInterface;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Service\RateLimitOperationalReader;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;

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
    'The production PSR-4 autoload did not expose RateLimiterEngine.',
);
requireCondition(
    !class_exists('Maatify\\RateLimiter\\Tests\\Support\\Clock\\FixedClock'),
    'The package test namespace leaked into the external consumer.',
);

$clock = new FixedClock(new DateTimeImmutable('2025-01-01 12:00:00', new DateTimeZone('UTC')));
$rateLimitStore = new InMemoryRateLimitStore($clock);
$correlationStore = new InMemoryCorrelationStore($clock);
$circuitBreakerStore = new InMemoryCircuitBreakerStore();
$failureSignalEmitter = new RecordingFailureSignalEmitter();

requireCondition(
    $rateLimitStore instanceof BudgetSeedStoreInterface,
    'The consumer store does not implement the BudgetSeedStoreInterface capability.',
);
requireCondition(
    $correlationStore instanceof CorrelationRotationStoreInterface,
    'The consumer correlation store does not implement the CorrelationRotationStoreInterface capability.',
);
requireCondition(
    $correlationStore instanceof BoundedCorrelationRotationStoreInterface,
    'The consumer correlation store does not implement the bounded rotation capability.',
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
    'previous-key',
);
$circuitBreaker = new CircuitBreaker($circuitBreakerStore, $failureSignalEmitter, $clock);
$engine = new RateLimiterEngine(
    $deviceResolver,
    $pipeline,
    $circuitBreaker,
    new FailureModeResolver(),
    $failureSignalEmitter,
    $clock,
    [new LoginProtectionPolicy(), new OtpProtectionPolicy()],
);

$context = new RateLimitContextDTO(
    ip: '203.0.113.1',
    ua: 'Mozilla/5.0',
    accountId: 'consumer-user-123',
    clientFingerprint: ['device' => 'consumer-device'],
);

$preflight = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));
requireCondition(
    $preflight->decision === RateLimitResultDTO::DECISION_ALLOW,
    'The documented login pre-check did not allow a clean request.',
);

$failure = $engine->limit($context, RateLimitCommand::recordFailure('otp_protection'));
requireCondition(
    $failure->decision === RateLimitResultDTO::DECISION_SOFT_BLOCK,
    'The OTP failure workflow did not return the documented soft-block result.',
);
requireCondition($failure->blockLevel === 1, 'The OTP failure workflow returned an unexpected block level.');
requireCondition($rateLimitStore->writeCount() > 0, 'The consumer rate-limit storage boundary was not written.');
requireCondition(
    $correlationStore->operationCount() > 0,
    'The consumer correlation storage boundary was not used.',
);

$writesBeforeOperationalRead = $rateLimitStore->writeCount();
$operationalReader = new RateLimitOperationalReader(
    $deviceResolver,
    $rateLimitStore,
    $circuitBreakerStore,
    new DecayCalculator($clock),
    $clock,
    'active-key',
    'prod',
    'previous-key',
);
$snapshot = $operationalReader->read($context, new OtpProtectionPolicy());
requireCondition($snapshot instanceof RateLimitOperationalSnapshotDTO, 'The operational reader did not return its typed snapshot.');
requireCondition($snapshot->policyName === 'otp_protection', 'The operational snapshot policy name is incorrect.');
requireCondition($snapshot->scopes->k4?->score?->value === 5, 'The operational snapshot did not expose the runtime K4 state.');
requireCondition($writesBeforeOperationalRead === $rateLimitStore->writeCount(), 'Operational read mutated the consumer store.');

echo json_encode([
    'status' => 'PASS',
    'packageInstallPath' => $installPath,
    'preflightDecision' => $preflight->decision,
    'failureDecision' => $failure->decision,
    'failureBlockLevel' => $failure->blockLevel,
    'operationalK4Score' => $snapshot->scopes->k4?->score?->value,
    'operationalBackendHealthy' => $snapshot->backendHealthy,
    'rateLimitStoreWrites' => $rateLimitStore->writeCount(),
    'correlationStoreOperations' => $correlationStore->operationCount(),
    'failureSignals' => count($failureSignalEmitter->signals()),
], JSON_THROW_ON_ERROR) . PHP_EOL;
