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

/** @return list<string> */
function redisKeys(mixed $redis): array
{
    $keys = $redis->execute(['KEYS', '*']);
    if (! is_array($keys)) {
        return [];
    }
    $keys = array_map('strval', $keys);
    sort($keys);

    return $keys;
}

/** @param list<string> $before @param list<string> $after @return list<string> */
function newlyCreatedRedisKeys(array $before, array $after): array
{
    return array_values(array_diff($after, $before));
}

/** @param list<string> $keys @return array<string, array{type:string, pttl:int, value:mixed}> */
function redisStateSnapshot(mixed $redis, array $keys): array
{
    $snapshot = [];
    foreach ($keys as $key) {
        $type = (string) $redis->execute(['TYPE', $key]);
        $value = match ($type) {
            'string' => $redis->execute(['GET', $key]),
            'hash' => $redis->execute(['HGETALL', $key]),
            'set' => $redis->execute(['SMEMBERS', $key]),
            'zset' => $redis->execute(['ZRANGE', $key, '0', '-1', 'WITHSCORES']),
            'list' => $redis->execute(['LRANGE', $key, '0', '-1']),
            default => null,
        };
        $snapshot[$key] = [
            'type' => $type,
            'pttl' => (int) $redis->execute(['PTTL', $key]),
            'value' => $value,
        ];
    }

    return $snapshot;
}

/** @param list<string> $keys @return array{key:string, state:array<string,string>}|null */
function findBudgetState(mixed $redis, array $keys, ?int $expectedCount = null): ?array
{
    foreach ($keys as $key) {
        if ((string) $redis->execute(['TYPE', $key]) !== 'hash') {
            continue;
        }
        $state = redisHashMap($redis->execute(['HGETALL', $key]));
        if (isset($state['count'], $state['epochStart'], $state['epochDuration'])
            && ($expectedCount === null || (int) $state['count'] === $expectedCount)) {
            return ['key' => $key, 'state' => $state];
        }
    }

    return null;
}

function requireReadOnlySnapshot(array $before, array $after, string $label): void
{
    foreach ($before as $key => $state) {
        requireCondition(($after[$key]['value'] ?? null) === ($state['value'] ?? null), $label . ' changed persisted content.');
        if (($state['pttl'] ?? -1) >= 0 && ($after[$key]['pttl'] ?? -2) >= 0) {
            requireCondition(($after[$key]['pttl'] ?? -2) <= ($state['pttl'] ?? -1) + 2, $label . ' extended a TTL.');
        }
    }
}

function deleteRedisKeys(mixed $redis, array $keys): void
{
    foreach ($keys as $key) {
        $redis->execute(['DEL', $key]);
    }
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
foreach ([':not-an-integer' . "\r\n", '$not-a-length' . "\r\n", '*not-an-array-length' . "\r\n"] as $malformedReply) {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    requireCondition(is_array($pair), 'Unable to create RESP regression socket pair.');
    $reflection = new ReflectionClass(RespRedisCommandExecutor::class);
    /** @var RespRedisCommandExecutor $malformedExecutor */
    $malformedExecutor = $reflection->newInstanceWithoutConstructor();
    $socketProperty = $reflection->getProperty('socket');
    $socketProperty->setValue($malformedExecutor, $pair[0]);
    fwrite($pair[1], $malformedReply);
    $malformed = false;
    try {
        $malformedExecutor->execute(['PING']);
    } catch (RuntimeException) {
        $malformed = true;
    }
    fclose($pair[0]);
    fclose($pair[1]);
    requireCondition($malformed, 'Malformed RESP numeric field was accepted.');
}
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
$rotationContext = static fn(string $scope): RateLimitContextDTO => new RateLimitContextDTO(
    '192.0.2.50',
    'Mozilla/5.0 consumer-verification-rotation',
    'consumer-rotation-' . $scope,
    ['device' => 'rotation-device'],
    null,
    false,
    [],
    true,
);

$loginContext = $context('login');
$loginCheck = $limiter->limit($loginContext, RateLimitCommand::checkOnly('login_protection'));
$loginFailure = $limiter->limit($loginContext, RateLimitCommand::recordFailure('login_protection'));
requireCondition($loginCheck->decision === RateLimitResultDTO::DECISION_ALLOW, 'Fresh login check was not allowed: ' . json_encode(resultShape($loginCheck), JSON_THROW_ON_ERROR));
requireCondition($loginFailure->decision === RateLimitResultDTO::DECISION_ALLOW, 'First login failure was not allowed.');
$loginProgressContext = new RateLimitContextDTO('203.0.113.13', 'Mozilla/5.0 consumer-login-progression', 'consumer-login-progression', ['device' => 'consumer-login-progression']);
$loginProgression = [];
for ($attempt = 1; $attempt <= 6; $attempt++) {
    $loginProgression[] = $limiter->limit($loginProgressContext, RateLimitCommand::recordFailure('login_protection'));
    if ($loginProgression[array_key_last($loginProgression)]->decision === RateLimitResultDTO::DECISION_HARD_BLOCK) {
        break;
    }
}
$loginSuccessContext = new RateLimitContextDTO('203.0.113.14', 'Mozilla/5.0 consumer-login-success', 'consumer-login-success', ['device' => 'consumer-login-success']);
$loginSuccess = $limiter->limit($loginSuccessContext, RateLimitCommand::recordSuccess('login_protection'));
requireCondition($loginProgression[array_key_last($loginProgression)]->decision === RateLimitResultDTO::DECISION_HARD_BLOCK && $loginProgression[array_key_last($loginProgression)]->blockLevel >= 2, 'Default login failure progression did not reach L2 hard block.');
requireCondition($loginSuccess->decision === RateLimitResultDTO::DECISION_ALLOW, 'Clean login success command was not executable.');
$loginPersistenceContext = new RateLimitContextDTO('203.0.113.11', 'Mozilla/5.0 consumer-login-persistence', 'consumer-login-persistence', ['device' => 'consumer-login-persistence']);
$loginKeysBefore = redisKeys($raw);
$loginPersistenceResult = $limiter->limit($loginPersistenceContext, RateLimitCommand::recordFailure('login_protection'));
$loginKeysAfter = redisKeys($raw);
requireCondition($loginPersistenceResult->decision === RateLimitResultDTO::DECISION_ALLOW, 'Login persistence fixture did not remain allowed.');
requireCondition(count(newlyCreatedRedisKeys($loginKeysBefore, $loginKeysAfter)) > 0, 'Login failure did not persist scenario-local Redis state.');

$otp = $limiter->limit($context('otp'), RateLimitCommand::recordFailure('otp_protection'));
requireCondition($otp->decision === RateLimitResultDTO::DECISION_SOFT_BLOCK && $otp->blockLevel === 1, 'OTP default contract failed.');
$api = $limiter->limit($context('api'), RateLimitCommand::recordFailure('api_heavy_protection'));
requireCondition($api->decision === RateLimitResultDTO::DECISION_ALLOW, 'API Heavy default contract failed.');
$apiPersistenceContext = new RateLimitContextDTO('203.0.113.12', 'Mozilla/5.0 consumer-api-persistence', 'consumer-api-persistence', ['device' => 'consumer-api-persistence']);
$apiKeysBefore = redisKeys($raw);
$apiPersistenceResult = $limiter->limit($apiPersistenceContext, RateLimitCommand::recordFailure('api_heavy_protection'));
$apiKeysAfter = redisKeys($raw);
requireCondition($apiPersistenceResult->decision === RateLimitResultDTO::DECISION_ALLOW, 'API Heavy persistence fixture did not remain allowed.');
requireCondition(count(newlyCreatedRedisKeys($apiKeysBefore, $apiKeysAfter)) > 0, 'API Heavy request did not persist scenario-local Redis state.');

$spray = [];
$spray[] = $limiter->limit($sprayContext('spray-subject-1'), RateLimitCommand::checkOnly('login_protection'));
$spray[] = $limiter->limit($sprayContext('spray-subject-2'), RateLimitCommand::checkOnly('login_protection'));
$spray[] = $limiter->limit($sprayContext('spray-subject-3'), RateLimitCommand::checkOnly('login_protection'));
$spraySubjectFour = $limiter->limit($sprayContext('spray-subject-4'), RateLimitCommand::checkOnly('login_protection'));
$spraySuccess = $limiter->limit($sprayContext('spray-subject-4'), RateLimitCommand::recordSuccess('login_protection'));
$spray[] = $spraySubjectFour;
for ($index = 5; $index <= 5; $index++) {
    $spray[] = $limiter->limit($sprayContext('spray-subject-' . $index), RateLimitCommand::checkOnly('login_protection'));
}
requireCondition($spray[0]->decision === 'ALLOW' && $spray[1]->decision === 'ALLOW' && $spray[2]->decision === 'ALLOW' && $spraySubjectFour->decision === 'ALLOW', 'Credential spray early subjects changed: ' . json_encode(array_map('resultShape', $spray), JSON_THROW_ON_ERROR));
requireCondition($spraySuccess->decision === RateLimitResultDTO::DECISION_ALLOW, 'Spray lifecycle command triggered an early L2 result.');
requireCondition($spray[4]->decision === RateLimitResultDTO::DECISION_HARD_BLOCK && $spray[4]->blockLevel === 2, 'Credential spray threshold was not observed: ' . json_encode(resultShape($spray[4]), JSON_THROW_ON_ERROR));
$trusted = $limiter->limit($sprayContext('spray-subject-5', true), RateLimitCommand::checkOnly('login_protection'));
$untrustedFollowUp = $limiter->limit($sprayContext('spray-subject-follow-up'), RateLimitCommand::checkOnly('login_protection'));
requireCondition($trusted->decision === RateLimitResultDTO::DECISION_ALLOW, 'Trusted fifth spray subject was rejected solely by K1.');
requireCondition($untrustedFollowUp->decision === RateLimitResultDTO::DECISION_HARD_BLOCK && $untrustedFollowUp->blockLevel === 2, 'Untrusted follow-up did not observe the persisted K1 block.');
$trustedNonK1Context = new RateLimitContextDTO('198.51.100.51', 'Mozilla/5.0 consumer-verification-trusted-authoritative', 'consumer-trusted-authoritative-account', ['device' => 'consumer-trusted-authoritative-device']);
$authoritativeResults = [];
for ($attempt = 1; $attempt <= 6; $attempt++) {
    $authoritativeResults[] = $limiter->limit($trustedNonK1Context, RateLimitCommand::recordFailure('login_protection'));
    if ($authoritativeResults[array_key_last($authoritativeResults)]->decision === RateLimitResultDTO::DECISION_HARD_BLOCK) {
        break;
    }
}
$trustedNonK1TrustedContext = new RateLimitContextDTO('198.51.100.51', 'Mozilla/5.0 consumer-verification-trusted-authoritative', 'consumer-trusted-authoritative-account', ['device' => 'consumer-trusted-authoritative-device'], 'consumer-trusted-authoritative-device', true, [], true);
$trustedNonK1 = $limiter->limit($trustedNonK1TrustedContext, RateLimitCommand::checkOnly('login_protection'));
requireCondition($authoritativeResults[array_key_last($authoritativeResults)]->decision === RateLimitResultDTO::DECISION_HARD_BLOCK, 'Authoritative K4 fixture did not reach L2.');
requireCondition($trustedNonK1->decision === RateLimitResultDTO::DECISION_HARD_BLOCK && $trustedNonK1->blockLevel >= 2, 'Trusted traffic bypassed authoritative non-K1 enforcement: ' . json_encode(resultShape($trustedNonK1), JSON_THROW_ON_ERROR));

$rotationCases = [];
foreach (['outer-only' => ['old-outer', 'stable-fingerprint', 'new-outer', 'stable-fingerprint'], 'fingerprint-only' => ['stable-outer', 'old-fingerprint', 'stable-outer', 'new-fingerprint'], 'both' => ['old-both-outer', 'old-both-fingerprint', 'new-both-outer', 'new-both-fingerprint']] as $name => [$oldOuter, $oldFingerprint, $newOuter, $newFingerprint]) {
    $probeContext = $rotationContext($name);
    $probeBefore = redisKeys($raw);
    $probeOld = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig($oldOuter, $oldFingerprint, 'prod'), $store, $signals)->build();
    $probeOld->limit($probeContext, RateLimitCommand::recordFailure('login_protection'));
    $probeOldKeys = newlyCreatedRedisKeys($probeBefore, redisKeys($raw));
    deleteRedisKeys($raw, $probeOldKeys);
    $probeNewBefore = redisKeys($raw);
    $probeNew = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig($newOuter, $newFingerprint, 'prod'), $store, $signals)->build();
    $probeNew->limit($probeContext, RateLimitCommand::recordFailure('login_protection'));
    $probeNewKeys = newlyCreatedRedisKeys($probeNewBefore, redisKeys($raw));
    $previousOnlyKeys = array_values(array_diff($probeOldKeys, $probeNewKeys));
    $currentOnlyKeys = array_values(array_diff($probeNewKeys, $probeOldKeys));
    requireCondition(count($previousOnlyKeys) > 0 && count($currentOnlyKeys) > 0, $name . ' differential rotation probe did not isolate generation state.');
    deleteRedisKeys($raw, $probeNewKeys);
    $scenarioBefore = redisKeys($raw);
    $old = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig($oldOuter, $oldFingerprint, 'prod'), $store, $signals)->build();
    $old->limit($probeContext, RateLimitCommand::recordFailure('login_protection'));
    $scenarioOldKeys = newlyCreatedRedisKeys($scenarioBefore, redisKeys($raw));
    $previousRotationKeys = array_values(array_intersect($scenarioOldKeys, $previousOnlyKeys));
    $previousRotationState = redisStateSnapshot($raw, $previousRotationKeys);
    $rotated = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig($newOuter, $newFingerprint, 'prod', $oldOuter, $oldFingerprint), $store, $signals)->build();
    $rotationCases[$name] = resultShape($rotated->limit($probeContext, RateLimitCommand::recordFailure('login_protection')));
    requireCondition(count(array_intersect($currentOnlyKeys, redisKeys($raw))) > 0, $name . ' rotation did not create Current generation state.');
    requireReadOnlySnapshot($previousRotationState, redisStateSnapshot($raw, $previousRotationKeys), $name . ' generation-distinct Previous state');
}

$budgetContext = new RateLimitContextDTO('192.0.2.80', 'Mozilla/5.0 consumer-budget', 'consumer-budget-account', ['device' => 'consumer-budget-device']);
$budgetKeysBefore = redisKeys($raw);
$budgetOld = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('budget-old-key', 'budget-old-fingerprint', 'prod'), $store, $signals)->build();
$budgetOld->limit($budgetContext, RateLimitCommand::recordFailure('login_protection'));
$budgetKeysAfterOld = redisKeys($raw);
$previousBudgetKeys = newlyCreatedRedisKeys($budgetKeysBefore, $budgetKeysAfterOld);
$previousBudget = findBudgetState($raw, $previousBudgetKeys, 1);
requireCondition($previousBudget !== null, 'Previous budget state was not persisted.');
$budgetNew = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('budget-new-key', 'budget-new-fingerprint', 'prod', 'budget-old-key', 'budget-old-fingerprint'), $store, $signals)->build();
$budgetNew->limit($budgetContext, RateLimitCommand::recordFailure('login_protection'));
$budgetKeysAfterNew = redisKeys($raw);
$currentBudgetKeys = newlyCreatedRedisKeys($budgetKeysAfterOld, $budgetKeysAfterNew);
$currentBudget = findBudgetState($raw, $currentBudgetKeys, 2);
requireCondition($currentBudget !== null, 'Current budget state was not created during migration.');
$budgetNew->limit($budgetContext, RateLimitCommand::recordFailure('login_protection'));
$currentBudget = findBudgetState($raw, [$currentBudget['key']], 3);
requireCondition($currentBudget !== null, 'Current budget state did not advance on the current generation.');
$previousBudgetMap = $previousBudget['state'];
$currentBudgetMap = $currentBudget['state'];
requireCondition((int) ($previousBudgetMap['count'] ?? -1) === 1, 'Previous budget count was not 1.');
requireCondition((int) ($currentBudgetMap['count'] ?? -1) === 3, 'Current budget count was not 3.');
requireCondition(($currentBudgetMap['epochStart'] ?? null) === ($previousBudgetMap['epochStart'] ?? null), 'Budget migration did not preserve epochStart.');
requireCondition(redisHashMap($raw->execute(['HGETALL', $previousBudget['key']])) === $previousBudgetMap, 'Previous budget state was modified during migration.');

$cycleClock = new FixedClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
$cycleLimiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('cycle-key', 'cycle-fingerprint', 'prod'), $store, $signals)->withClock($cycleClock)->build();
$cycleContext = new RateLimitContextDTO('192.0.2.90', 'Mozilla/5.0 consumer-cycle', 'consumer-cycle-account', ['device' => 'consumer-cycle-device']);
$cycleKeysBefore = redisKeys($raw);
$firstCycle = null;
for ($attempt = 1; $attempt <= 6; $attempt++) {
    $candidate = $cycleLimiter->limit($cycleContext, RateLimitCommand::recordFailure('login_protection'));
    if ($candidate->decision === RateLimitResultDTO::DECISION_HARD_BLOCK) {
        $firstCycle = $candidate;
        break;
    }
}
requireCondition($firstCycle instanceof RateLimitResultDTO, 'Default login path did not produce the first hard-block cycle.');
$firstCycleKeys = newlyCreatedRedisKeys($cycleKeysBefore, redisKeys($raw));
$firstCycleState = redisStateSnapshot($raw, $firstCycleKeys);
$firstCycleRepeat = $cycleLimiter->limit($cycleContext, RateLimitCommand::checkOnly('login_protection'));
requireCondition($firstCycleRepeat->decision === RateLimitResultDTO::DECISION_HARD_BLOCK, 'Active first cycle was not enforced by the public check path.');
requireReadOnlySnapshot($firstCycleState, redisStateSnapshot($raw, $firstCycleKeys), 'Active first cycle');
$cycleRotated = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('cycle-new-key', 'cycle-new-fingerprint', 'prod', 'cycle-key', 'cycle-fingerprint'), $store, $signals)->withClock($cycleClock)->build();
$rotatedCycle = $cycleRotated->limit($cycleContext, RateLimitCommand::checkOnly('login_protection'));
requireCondition($rotatedCycle->decision === RateLimitResultDTO::DECISION_HARD_BLOCK, 'Rotated cycle did not retain the previous hard block.');
requireReadOnlySnapshot($firstCycleState, redisStateSnapshot($raw, $firstCycleKeys), 'Previous cycle state');
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
$secondCycleRepeat = $cycleLimiter->limit($cycleContext, RateLimitCommand::checkOnly('login_protection'));
requireCondition($secondCycleRepeat->decision === RateLimitResultDTO::DECISION_HARD_BLOCK, 'Active second cycle was not enforced by the public check path.');

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
$circuitSignalTypes = array_values(array_map(static fn($signal): string => $signal->type, $circuitSignals->signals()));
requireCondition(count($circuitSignalTypes) === 2 && $circuitSignalTypes === ['CB_OPENED', 'CB_RECOVERED'], 'Circuit transition signals were not emitted exactly once.');

$keys = redisKeys($raw);
requireCondition(count($keys) > 0, 'No Redis persistence was observable.');
echo json_encode(['status' => 'PASS', 'packageInstallPath' => $installPath, 'productionAutoload' => true, 'packageTestNamespaceAvailable' => false, 'login' => ['checkOnly' => resultShape($loginCheck), 'recordFailure' => resultShape($loginFailure), 'recordSuccess' => resultShape($loginSuccess), 'progression' => array_map('resultShape', $loginProgression), 'persistenceProof' => true], 'otp' => resultShape($otp), 'apiHeavy' => resultShape($api), 'credentialSpray' => array_map('resultShape', $spray), 'sprayLifecycle' => ['recordSuccess' => resultShape($spraySuccess), 'subjectFourCheckOnly' => resultShape($spraySubjectFour)], 'trustedSession' => resultShape($trusted), 'untrustedFollowUp' => resultShape($untrustedFollowUp), 'trustedNonK1' => resultShape($trustedNonK1), 'rotations' => $rotationCases, 'budgetMigration' => ['previousCount' => (int) $previousBudgetMap['count'], 'currentCount' => (int) $currentBudgetMap['count'], 'epochStartPreserved' => true, 'previousReadOnly' => true], 'hardBlockCyclePause' => ['firstCycle' => resultShape($firstCycle), 'secondCycle' => resultShape($secondCycle), 'pauseRetained' => true], 'failureSemantics' => ['login' => resultShape($loginFailureMode), 'otp' => resultShape($otpFailureMode), 'apiHeavy' => resultShape($apiFailureMode)], 'circuit' => ['openGuard' => resultShape($openGuardResult), 'halfOpenProbe' => resultShape($halfOpenResult), 'closedRecovery' => resultShape($closedResult), 'normalWorkloadSuppressedWhileOpen' => true, 'signalCount' => count($circuitSignals->signals()), 'signalTypes' => array_values(array_unique(array_map(static fn($signal): string => $signal->type, $circuitSignals->signals())))], 'redisPersistence' => ['keyCount' => count($keys)], 'failureSignals' => count($signals->signals())], JSON_THROW_ON_ERROR) . PHP_EOL;
