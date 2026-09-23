<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use ConsumerVerification\FixedClock;
use ConsumerVerification\RecordingFailureSignalEmitter;
use ConsumerVerification\RespRedisCommandExecutor;
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;

require __DIR__ . '/vendor/autoload.php';

function requireCondition(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function resultShape(RateLimitResultDTO $result): array
{
    return ['decision' => $result->decision, 'blockLevel' => $result->blockLevel, 'retryAfter' => $result->retryAfter, 'failureMode' => $result->failureMode];
}

function redisHashMap(mixed $flat): array
{
    if (! is_array($flat) || count($flat) % 2 !== 0) {
        return [];
    }
    $map = [];
    for ($index = 0; $index < count($flat); $index += 2) {
        $map[(string) $flat[$index]] = (string) $flat[$index + 1];
    }
    return $map;
}

$installPath = InstalledVersions::getInstallPath('maatify/php-rate-limiter');
requireCondition(is_string($installPath), 'Composer did not install maatify/php-rate-limiter.');
requireCondition(! is_link($installPath), 'The package was installed as a symlink.');
requireCondition(class_exists(RateLimiterBuilder::class), 'Production PSR-4 autoload is unavailable.');
requireCondition(! class_exists('Maatify\\RateLimiter\\Tests\\Support\\Clock\\FixedClock'), 'Package test namespace leaked into consumer.');

$host = getenv('REDIS_INTEGRATION_HOST');
$port = getenv('REDIS_INTEGRATION_PORT');
requireCondition(is_string($host) && is_string($port) && ctype_digit($port), 'Redis lifecycle variables are missing.');
$raw = new RespRedisCommandExecutor($host, (int) $port);
$raw->execute(['FLUSHDB']);
$executor = new CallableRedisCommandExecutor(static fn(array $command): mixed => $raw->execute($command));
$store = new RedisFullCapabilityStore($executor, 'consumer-verification');
$clock = new FixedClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
$signals = new RecordingFailureSignalEmitter();
$limiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('consumer-key', 'consumer-fingerprint', 'prod'), $store, $signals)->build();
$context = static function (string $subject, string $ip = '203.0.113.10', bool $trusted = false, ?string $correlation = null): RateLimitContextDTO {
    $account = str_starts_with($subject, 'spray-') ? 'consumer-spray-account' : 'consumer-account-' . $subject;
    return new RateLimitContextDTO($ip, 'Mozilla/5.0 consumer-verification-' . $subject, $account, ['device' => $subject], $trusted ? 'trusted-device' : null, $trusted, [], $trusted, $correlation);
};
$sprayContext = static fn(string $correlation, bool $trusted = false): RateLimitContextDTO => new RateLimitContextDTO(
    '198.51.100.50',
    'Mozilla/5.0 consumer-verification-spray',
    'consumer-spray-account',
    ['device' => 'consumer-spray-device'],
    $trusted ? 'consumer-spray-device' : null,
    $trusted,
    [],
    $trusted,
    $correlation,
);
$rotationContext = static fn(string $scope, int $index): RateLimitContextDTO => new RateLimitContextDTO(
    '192.0.2.50',
    'Mozilla/5.0 consumer-verification-rotation-' . $index,
    'consumer-rotation-' . $scope,
    ['device' => 'rotation-device-' . $index],
);

$loginContext = $context('login');
$loginCheck = $limiter->limit($loginContext, RateLimitCommand::checkOnly('login_protection'));
$loginFailure = $limiter->limit($loginContext, RateLimitCommand::recordFailure('login_protection'));
$loginSuccess = $limiter->limit($loginContext, RateLimitCommand::recordSuccess('login_protection'));
requireCondition($loginCheck->decision === RateLimitResultDTO::DECISION_ALLOW, 'Fresh login check was not allowed: ' . json_encode(resultShape($loginCheck), JSON_THROW_ON_ERROR));
requireCondition($loginFailure->decision === RateLimitResultDTO::DECISION_ALLOW, 'First login failure was not allowed.');

$otp = $limiter->limit($context('otp'), RateLimitCommand::recordFailure('otp_protection'));
requireCondition($otp->decision === RateLimitResultDTO::DECISION_SOFT_BLOCK && $otp->blockLevel === 1, 'OTP default contract failed.');
$api = $limiter->limit($context('api'), RateLimitCommand::recordFailure('api_heavy_protection'));
requireCondition($api->decision === RateLimitResultDTO::DECISION_ALLOW, 'API Heavy default contract failed.');

$spray = [];
$spray[] = $limiter->limit($sprayContext('spray-subject-1'), RateLimitCommand::checkOnly('login_protection'));
$spraySuccess = $limiter->limit($sprayContext('spray-subject-1'), RateLimitCommand::recordSuccess('login_protection'));
$spraySubjectOneRepeat = $limiter->limit($sprayContext('spray-subject-1'), RateLimitCommand::checkOnly('login_protection'));
for ($index = 2; $index <= 5; $index++) {
    $spray[] = $limiter->limit($sprayContext('spray-subject-' . $index), RateLimitCommand::checkOnly('login_protection'));
}
requireCondition($spray[0]->decision === 'ALLOW' && $spray[1]->decision === 'ALLOW' && $spray[2]->decision === 'ALLOW' && $spray[3]->decision === 'ALLOW', 'Credential spray early subjects changed: ' . json_encode(array_map('resultShape', $spray), JSON_THROW_ON_ERROR));
requireCondition($spray[4]->decision === RateLimitResultDTO::DECISION_HARD_BLOCK && $spray[4]->blockLevel === 2, 'Credential spray threshold was not observed: ' . json_encode(resultShape($spray[4]), JSON_THROW_ON_ERROR));
requireCondition($spraySuccess->decision === RateLimitResultDTO::DECISION_ALLOW, 'Spray success lifecycle did not remain observable as ALLOW.');
requireCondition($spraySubjectOneRepeat->decision === RateLimitResultDTO::DECISION_ALLOW, 'Spray subject was observed again after success.');
$trusted = $limiter->limit($sprayContext('spray-subject-5', true), RateLimitCommand::checkOnly('login_protection'));
$untrustedFollowUp = $limiter->limit($sprayContext('spray-subject-follow-up'), RateLimitCommand::checkOnly('login_protection'));

$rotationCases = [];
foreach (['outer-only' => ['old-outer', 'stable-fingerprint', 'new-outer', 'stable-fingerprint'], 'fingerprint-only' => ['stable-outer', 'old-fingerprint', 'stable-outer', 'new-fingerprint'], 'both' => ['old-both-outer', 'old-both-fingerprint', 'new-both-outer', 'new-both-fingerprint']] as $name => [$oldOuter, $oldFingerprint, $newOuter, $newFingerprint]) {
    $old = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig($oldOuter, $oldFingerprint, 'prod'), $store, $signals)->build();
    for ($i = 1; $i <= 4; $i++) {
        $old->limit($rotationContext($name, $i), RateLimitCommand::checkOnly('login_protection'));
    }
    $rotated = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig($newOuter, $newFingerprint, 'prod', $oldOuter, $oldFingerprint), $store, $signals)->build();
    $rotationCases[$name] = resultShape($rotated->limit($rotationContext($name, 5), RateLimitCommand::checkOnly('login_protection')));
    requireCondition($rotationCases[$name]['decision'] === RateLimitResultDTO::DECISION_HARD_BLOCK && $rotationCases[$name]['blockLevel'] === 2, $name . ' rotation continuity was not observed.');
}

$budgetContext = new RateLimitContextDTO('192.0.2.80', 'Mozilla/5.0 consumer-budget', 'consumer-budget-account', ['device' => 'consumer-budget-device']);
$budgetKeysBefore = $raw->execute(['KEYS', 'maatify:rate-limiter:v1:*:budget:*']);
$budgetOld = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('budget-old-key', 'budget-old-fingerprint', 'prod'), $store, $signals)->build();
$budgetOld->limit($budgetContext, RateLimitCommand::recordFailure('login_protection'));
$budgetKeysAfterOld = $raw->execute(['KEYS', 'maatify:rate-limiter:v1:*:budget:*']);
$previousBudgetKeys = array_values(array_diff(array_map('strval', is_array($budgetKeysAfterOld) ? $budgetKeysAfterOld : []), array_map('strval', is_array($budgetKeysBefore) ? $budgetKeysBefore : [])));
requireCondition(count($previousBudgetKeys) > 0, 'Previous budget state was not persisted.');
$previousBudgetKey = $previousBudgetKeys[0];
$previousBudgetState = $raw->execute(['HGETALL', $previousBudgetKey]);
$budgetNew = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('budget-new-key', 'budget-new-fingerprint', 'prod', 'budget-old-key', 'budget-old-fingerprint'), $store, $signals)->build();
$budgetNew->limit($budgetContext, RateLimitCommand::recordFailure('login_protection'));
$budgetKeysAfterNew = $raw->execute(['KEYS', 'maatify:rate-limiter:v1:*:budget:*']);
$newBudgetKeys = array_values(array_diff(array_map('strval', is_array($budgetKeysAfterNew) ? $budgetKeysAfterNew : []), array_map('strval', is_array($budgetKeysAfterOld) ? $budgetKeysAfterOld : [])));
requireCondition(count($newBudgetKeys) > 0, 'Current budget state was not created during migration.');
$currentBudgetState = $raw->execute(['HGETALL', $newBudgetKeys[0]]);
$previousBudgetMap = redisHashMap($previousBudgetState);
$currentBudgetMap = redisHashMap($currentBudgetState);
requireCondition($previousBudgetMap !== [] && $currentBudgetMap !== [], 'Budget Redis state was malformed.');
requireCondition((int) ($currentBudgetMap['count'] ?? -1) === (int) ($previousBudgetMap['count'] ?? -2) + 1, 'Budget migration count did not advance by one.');
requireCondition(($currentBudgetMap['epochStart'] ?? null) === ($previousBudgetMap['epochStart'] ?? null), 'Budget migration did not preserve epochStart.');
requireCondition($raw->execute(['HGETALL', $previousBudgetKey]) === $previousBudgetState, 'Previous budget state was modified during migration.');

$cycleClock = new FixedClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
$cycleLimiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('cycle-key', 'cycle-fingerprint', 'prod'), $store, $signals)->withClock($cycleClock)->build();
$cycleContext = new RateLimitContextDTO('192.0.2.90', 'Mozilla/5.0 consumer-cycle', 'consumer-cycle-account', ['device' => 'consumer-cycle-device']);
$firstCycle = null;
for ($attempt = 1; $attempt <= 6; $attempt++) {
    $candidate = $cycleLimiter->limit($cycleContext, RateLimitCommand::recordFailure('login_protection'));
    if ($candidate->decision === RateLimitResultDTO::DECISION_HARD_BLOCK) {
        $firstCycle = $candidate;
        break;
    }
}
requireCondition($firstCycle instanceof RateLimitResultDTO, 'Default login path did not produce the first hard-block cycle.');
$cycleClock->setNow($cycleClock->now()->modify('+601 seconds'));
$secondCycle = null;
for ($attempt = 1; $attempt <= 3; $attempt++) {
    $candidate = $cycleLimiter->limit($cycleContext, RateLimitCommand::recordFailure('login_protection'));
    if ($candidate->decision === RateLimitResultDTO::DECISION_HARD_BLOCK) {
        $secondCycle = $candidate;
        break;
    }
}
requireCondition($secondCycle instanceof RateLimitResultDTO, 'Default login path did not produce the second hard-block cycle.');
requireCondition(($secondCycle->retryAfter ?? 0) >= 600, 'Second hard-block cycle did not expose the retained pause behavior.');

$failureRaw = new RespRedisCommandExecutor($host, (int) $port);
$failureExecutor = new CallableRedisCommandExecutor(static function (array $command) use ($failureRaw): mixed {
    if (strtoupper((string) ($command[0] ?? '')) === 'EVAL') {
        throw new RuntimeException('controlled consumer backend failure');
    }
    return $failureRaw->execute($command);
});
$failureStore = new RedisFullCapabilityStore($failureExecutor, 'consumer-failure-semantics');
$failureSignals = new RecordingFailureSignalEmitter();
$failureLimiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('failure-key', 'failure-fingerprint', 'prod'), $failureStore, $failureSignals)->build();
$failureContext = new RateLimitContextDTO('192.0.2.91', 'Mozilla/5.0 consumer-failure', 'consumer-failure-account', ['device' => 'consumer-failure-device']);
$loginFailureMode = $failureLimiter->limit($failureContext, RateLimitCommand::checkOnly('login_protection'));
$otpFailureMode = $failureLimiter->limit($failureContext, RateLimitCommand::checkOnly('otp_protection'));
$apiFailureMode = $failureLimiter->limit($failureContext, RateLimitCommand::checkOnly('api_heavy_protection'));
requireCondition($loginFailureMode->decision === RateLimitResultDTO::DECISION_HARD_BLOCK && $loginFailureMode->failureMode === 'FAIL_CLOSED', 'Login backend failure did not fail closed.');
requireCondition($otpFailureMode->decision === RateLimitResultDTO::DECISION_HARD_BLOCK && $otpFailureMode->failureMode === 'FAIL_CLOSED', 'OTP backend failure did not fail closed.');
requireCondition($apiFailureMode->decision === RateLimitResultDTO::DECISION_ALLOW && $apiFailureMode->failureMode === 'FAIL_OPEN', 'API Heavy backend failure did not fail open.');

$circuitDown = true;
$circuitEvalCalls = 0;
$circuitExecutor = new CallableRedisCommandExecutor(static function (array $command) use (&$circuitDown, &$circuitEvalCalls, $raw): mixed {
    $name = strtoupper((string) ($command[0] ?? ''));
    if ($name === 'EVAL') {
        $circuitEvalCalls++;
        if ($circuitDown) {
            throw new RuntimeException('controlled circuit backend failure');
        }
    }
    if ($name === 'PING' && $circuitDown) {
        throw new RuntimeException('controlled circuit health failure');
    }
    return $raw->execute($command);
});
$circuitStore = new RedisFullCapabilityStore($circuitExecutor, 'consumer-circuit');
$circuitSignals = new RecordingFailureSignalEmitter();
$circuitClock = new FixedClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
$circuitLimiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('circuit-key', 'circuit-fingerprint', 'prod'), $circuitStore, $circuitSignals)->withClock($circuitClock)->build();
$circuitContext = new RateLimitContextDTO('192.0.2.92', 'Mozilla/5.0 consumer-circuit', 'consumer-circuit-account', ['device' => 'consumer-circuit-device']);
for ($attempt = 1; $attempt <= 3; $attempt++) {
    $circuitLimiter->limit($circuitContext, RateLimitCommand::checkOnly('api_heavy_protection'));
}
$evalCallsAtOpen = $circuitEvalCalls;
$openGuardResult = $circuitLimiter->limit($circuitContext, RateLimitCommand::checkOnly('api_heavy_protection'));
requireCondition($openGuardResult->failureMode === 'DEGRADED_MODE' && $circuitEvalCalls === $evalCallsAtOpen, 'OPEN circuit executed normal Redis workload.');
$circuitDown = false;
$circuitClock->setNow($circuitClock->now()->modify('+301 seconds'));
$halfOpenResult = $circuitLimiter->limit($circuitContext, RateLimitCommand::checkOnly('api_heavy_protection'));
requireCondition($halfOpenResult->failureMode === 'DEGRADED_MODE', 'Healthy recovery probe did not enter HALF_OPEN.');
$circuitClock->setNow($circuitClock->now()->modify('+121 seconds'));
$closedResult = $circuitLimiter->limit($circuitContext, RateLimitCommand::checkOnly('api_heavy_protection'));
requireCondition($closedResult->failureMode === 'NORMAL' && $circuitEvalCalls > $evalCallsAtOpen, 'Circuit did not close after the healthy interval.');

$keys = $raw->execute(['KEYS', 'maatify:rate-limiter:v1:*']);
requireCondition(is_array($keys) && count($keys) > 0, 'No Redis persistence was observable.');
echo json_encode(['status' => 'PASS', 'packageInstallPath' => $installPath, 'productionAutoload' => true, 'packageTestNamespaceAvailable' => false, 'login' => ['checkOnly' => resultShape($loginCheck), 'recordFailure' => resultShape($loginFailure), 'recordSuccess' => resultShape($loginSuccess)], 'otp' => resultShape($otp), 'apiHeavy' => resultShape($api), 'credentialSpray' => array_map('resultShape', $spray), 'sprayLifecycle' => ['recordSuccess' => resultShape($spraySuccess), 'repeatCheckOnly' => resultShape($spraySubjectOneRepeat)], 'trustedSession' => resultShape($trusted), 'untrustedFollowUp' => resultShape($untrustedFollowUp), 'rotations' => $rotationCases, 'budgetMigration' => ['previousCount' => (int) $previousBudgetMap['count'], 'currentCount' => (int) $currentBudgetMap['count'], 'epochStartPreserved' => true, 'previousReadOnly' => true], 'hardBlockCyclePause' => ['firstCycle' => resultShape($firstCycle), 'secondCycle' => resultShape($secondCycle), 'pauseRetained' => true], 'failureSemantics' => ['login' => resultShape($loginFailureMode), 'otp' => resultShape($otpFailureMode), 'apiHeavy' => resultShape($apiFailureMode)], 'circuit' => ['openGuard' => resultShape($openGuardResult), 'halfOpenProbe' => resultShape($halfOpenResult), 'closedRecovery' => resultShape($closedResult), 'normalWorkloadSuppressedWhileOpen' => true], 'redisPersistence' => ['keyCount' => count($keys)], 'failureSignals' => count($signals->signals())], JSON_THROW_ON_ERROR) . PHP_EOL;
