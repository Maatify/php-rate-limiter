<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use ConsumerVerification\FixedClock;
use ConsumerVerification\RecordingFailureSignalEmitter;
use ConsumerVerification\RespRedisCommandExecutor;
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\FailureFallbackConfigurationProviderInterface;
use Maatify\RateLimiter\Enum\FailureFallbackDimensionEnum;
use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;
use Maatify\RateLimiter\Config\PolicyCapabilityProviderInterface;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\SimpleRateLimitResultDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\FailureFallbackConfigurationDTO;
use Maatify\RateLimiter\DTO\FailureFallbackRuleDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\DeviceIdentityResolverInterface;
use Maatify\RateLimiter\Service\RateLimiterRuntimeInterface;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Service\SimpleRateLimiterInterface;
use Maatify\RateLimiter\Config\PostPunishmentReentryPolicyInterface;
use Maatify\RateLimiter\Exception\RateLimiterException;

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

function simpleResultShape(SimpleRateLimitResultDTO $result): array
{
    return ['allowed' => $result->allowed, 'limit' => $result->limit, 'remaining' => $result->remaining, 'retryAfter' => $result->retryAfter, 'resetAt' => $result->resetAt, 'failureMode' => $result->failureMode];
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

/** @param list<array<int, int|string|float>> $commands @return list<array{command:string, numkeys:int, keys:list<string>}> */
function recordedEvalAccesses(array $commands): array
{
    $accesses = [];
    foreach ($commands as $command) {
        if (strtoupper((string) ($command[0] ?? '')) !== 'EVAL' || ! isset($command[2])) {
            continue;
        }
        $numKeys = (int) $command[2];
        if ($numKeys < 0) {
            continue;
        }
        $keys = array_map('strval', array_slice($command, 3, $numKeys));
        $accesses[] = ['command' => 'EVAL', 'numkeys' => $numKeys, 'keys' => $keys];
    }

    return $accesses;
}

/** @param list<array{command:string, numkeys:int, keys:list<string>}> $accesses @return list<string> */
function recordedRedisKeys(array $accesses): array
{
    $keys = [];
    foreach ($accesses as $access) {
        foreach ($access['keys'] as $key) {
            $keys[$key] = true;
        }
    }

    return array_keys($keys);
}

function diagnosticResponseShape(mixed $response): array
{
    if (is_array($response)) {
        return ['type' => 'array', 'count' => count($response)];
    }

    return ['type' => get_debug_type($response)];
}

/** @param list<array<string,mixed>> $trace @return array{storeMethod:?string, pipelineCaller:?string} */
function diagnosticCallers(array $trace): array
{
    $storeIndex = null;
    $storeMethod = null;
    $helperMethods = ['eval', 'execute', 'tuple', 'integerValue', 'key', 'hashKey'];
    foreach ($trace as $index => $frame) {
        $class = (string) ($frame['class'] ?? '');
        $function = (string) ($frame['function'] ?? '');
        if (str_contains($class, 'RedisFullCapabilityStore') && ! in_array($function, $helperMethods, true)) {
            $storeIndex = $index;
            $storeMethod = $class . '::' . $function;
            break;
        }
    }
    $pipelineCaller = null;
    if ($storeIndex !== null) {
        foreach (array_slice($trace, $storeIndex + 1) as $frame) {
            $class = (string) ($frame['class'] ?? '');
            if (str_contains($class, 'EvaluationPipeline')) {
                $pipelineCaller = $class . '::' . (string) ($frame['function'] ?? '');
                break;
            }
        }
    }

    return ['storeMethod' => $storeMethod, 'pipelineCaller' => $pipelineCaller];
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

/** @param array<string, array{type:string, pttl:int, value:mixed}> $snapshot @return list<array{key:string, state:array<string,string>}> */
function findScoreStates(array $snapshot): array
{
    $states = [];
    foreach ($snapshot as $key => $entry) {
        if (($entry['type'] ?? null) !== 'hash') {
            continue;
        }
        $state = redisHashMap($entry['value'] ?? null);
        if (isset($state['value'], $state['updatedAt']) && ctype_digit($state['value']) && ctype_digit($state['updatedAt'])) {
            $states[] = ['key' => $key, 'state' => $state];
        }
    }

    return $states;
}

/** @param array<string, array{type:string, pttl:int, value:mixed}> $snapshot @return list<array{key:string, state:array<string,string>}> */
function findBlockStates(array $snapshot): array
{
    $states = [];
    foreach ($snapshot as $key => $entry) {
        if (($entry['type'] ?? null) !== 'hash') {
            continue;
        }
        $state = redisHashMap($entry['value'] ?? null);
        if (isset($state['level'], $state['expiresAt']) && ctype_digit($state['level']) && ctype_digit($state['expiresAt'])) {
            $states[] = ['key' => $key, 'state' => $state];
        }
    }

    return $states;
}

/** @param array<string, array{type:string, pttl:int, value:mixed}> $snapshot @return array{key:string, start:int, finish:int, remaining:int}|null */
function findPauseState(array $snapshot, int $now): ?array
{
    foreach ($snapshot as $key => $entry) {
        if (($entry['type'] ?? null) !== 'zset' || ! is_array($entry['value'] ?? null)) {
            continue;
        }
        $members = $entry['value'];
        for ($index = 0; $index + 1 < count($members); $index += 2) {
            $member = (string) $members[$index];
            if (! preg_match('/^(\d+):(\d+)$/', $member, $matches)) {
                continue;
            }
            $start = (int) $matches[1];
            $finish = (int) $matches[2];
            if ($finish >= $start) {
                return ['key' => $key, 'start' => $start, 'finish' => $finish, 'remaining' => max(0, $finish - $now)];
            }
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
$recordRedisCommands = false;
$recordedRedisCommands = [];
$recordedSuccessfulRedisCommands = [];
$recordedEvalDiagnostics = [];
$executor = new CallableRedisCommandExecutor(static function (array $command) use ($raw, &$recordRedisCommands, &$recordedRedisCommands, &$recordedSuccessfulRedisCommands, &$recordedEvalDiagnostics): mixed {
    $isEval = strtoupper((string) ($command[0] ?? '')) === 'EVAL';
    $diagnosticIndex = null;
    if ($recordRedisCommands && $isEval) {
        $diagnosticIndex = count($recordedEvalDiagnostics);
        $recordedEvalDiagnostics[] = ['ordinal' => $diagnosticIndex + 1, 'callers' => diagnosticCallers(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)), 'responseShape' => null];
    }
    if ($recordRedisCommands) {
        $recordedRedisCommands[] = $command;
    }

    try {
        $result = $raw->execute($command);
    } catch (Throwable $exception) {
        throw $exception;
    }
    if ($recordRedisCommands) {
        $recordedSuccessfulRedisCommands[] = $command;
    }
    if ($diagnosticIndex !== null) {
        $recordedEvalDiagnostics[$diagnosticIndex]['responseShape'] = diagnosticResponseShape($result);
    }

    return $result;
});
$store = new RedisFullCapabilityStore($executor, 'consumer-verification');
$clock = new FixedClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
$signals = new RecordingFailureSignalEmitter();
$limiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('consumer-key', 'consumer-fingerprint', 'prod'), $store, $signals)
    ->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('consumer_simple_fixed_window', limit: 2, intervalSeconds: 60))
    ->build();
requireCondition($limiter instanceof RateLimiterRuntimeInterface, 'Full-capability Builder did not expose RateLimiterRuntimeInterface.');
requireCondition($limiter instanceof CompositeRateLimiterRuntimeInterface, 'Full-capability Builder did not expose CompositeRateLimiterRuntimeInterface.');
requireCondition($limiter instanceof SimpleRateLimiterInterface, 'Full-capability Builder did not expose SimpleRateLimiterInterface.');
$context = static function (string $subject, string $ip = '203.0.113.10', bool $trusted = false, ?string $correlation = null): RateLimitContextDTO {
    $account = str_starts_with($subject, 'spray-') ? 'consumer-spray-account' : 'consumer-account-' . $subject;
    return new RateLimitContextDTO($ip, 'Mozilla/5.0 consumer-verification-' . $subject, $account, ['device' => $subject], $trusted ? 'trusted-device' : null, $trusted, [], $trusted, $correlation);
};
$publicPunishmentDuration = static function (RateLimitResultDTO $hard): int {
    return match (min(6, max(1, $hard->blockLevel ?? 1))) {
        1 => 15,
        2 => 60,
        3 => 300,
        4 => 1800,
        5 => 21600,
        default => 86400,
    };
};
$checkPublicReentry = static function (RateLimiterRuntimeInterface $runtime, RateLimitContextDTO $context, string $policy): array {
    // Repeated checkOnly calls are observable public requests. Keep the
    // bounded post-wait verification deliberately small and identity-stable.
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $check = $runtime->limit($context, RateLimitCommand::checkOnly($policy));
        $metadata = $check->metadata?->postPunishmentReentry;
        if ($check->decision === RateLimitResultDTO::DECISION_ALLOW && $metadata !== null) {
            return [$check, $metadata];
        }
        if ($attempt < 1) {
            sleep(2);
        }
    }
    throw new RuntimeException('Public re-entry did not become available after the shared bounded expiry wait: ' . json_encode(['lastCheck' => isset($check) ? resultShape($check) : null], JSON_THROW_ON_ERROR));
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
    ['device' => 'rotation-device', 'stable' => 'rotation-stable-fingerprint'],
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

$reentryLoginContext = $context('public-reentry-login', '203.0.113.15');
$reentryLoginHard = null;
for ($attempt = 1; $attempt <= 6; $attempt++) {
    $candidate = $limiter->limit($reentryLoginContext, RateLimitCommand::recordFailure('login_protection'));
    if ($candidate->decision === RateLimitResultDTO::DECISION_HARD_BLOCK) {
        $reentryLoginHard = $candidate;
        break;
    }
}
requireCondition($reentryLoginHard instanceof RateLimitResultDTO, 'Public Login re-entry fixture did not issue a hard block.');
requireCondition($reentryLoginHard->blockLevel === 2 && $reentryLoginHard->retryAfter === 60, 'Public Login newly-issued K4 L2 retryAfter must be 60 seconds: ' . json_encode(resultShape($reentryLoginHard), JSON_THROW_ON_ERROR));

$reentryOtpAccount = 'consumer-account-public-reentry-otp';
$reentryOtpContext = new RateLimitContextDTO('203.0.113.16', 'Mozilla/5.0 consumer-verification-public-reentry-otp', $reentryOtpAccount, ['device' => 'public-reentry-otp']);
for ($attempt = 1; $attempt <= 3; $attempt++) {
    // Keep the exact same unverified device identity for every OTP failure so
    // this proof exercises K4 only and cannot create an independent K2 churn
    // hard block through device rotation.
    $reentryOtpHard = $limiter->limit($reentryOtpContext, RateLimitCommand::recordFailure('otp_protection'));
    if ($reentryOtpHard->decision === RateLimitResultDTO::DECISION_HARD_BLOCK) {
        break;
    }
}
requireCondition(isset($reentryOtpHard) && $reentryOtpHard->decision === RateLimitResultDTO::DECISION_HARD_BLOCK, 'Public OTP re-entry fixture did not issue a hard block.');
requireCondition($reentryOtpHard->blockLevel === 3, 'Default OTP re-entry fixture did not reach the expected L3 punishment.');
requireCondition($reentryOtpHard->retryAfter === 300, 'Public OTP newly-issued K4 L3 retryAfter must be 300 seconds: ' . json_encode(resultShape($reentryOtpHard), JSON_THROW_ON_ERROR));

$customPolicy = new class extends \Maatify\RateLimiter\Config\LoginProtectionPolicy implements PostPunishmentReentryPolicyInterface {
    public function getName(): string
    {
        return 'consumer_custom_auth_k4';
    }
};
$customLimiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('consumer-custom-key', 'consumer-custom-fingerprint', 'prod'), $store, $signals)->withPolicy($customPolicy)->build();
requireCondition($customLimiter instanceof RateLimiterRuntimeInterface, 'Custom opt-in policy did not preserve the public runtime surface.');
$customContext = $context('public-reentry-custom', '203.0.113.17');
$customHard = null;
for ($attempt = 1; $attempt <= 6; $attempt++) {
    $customResult = $customLimiter->limit($customContext, RateLimitCommand::recordFailure('consumer_custom_auth_k4'));
    if ($customResult->decision === RateLimitResultDTO::DECISION_HARD_BLOCK) {
        $customHard = $customResult;
        break;
    }
}
requireCondition($customHard instanceof RateLimitResultDTO, 'Custom opt-in policy did not issue a hard block.');
requireCondition($customHard->retryAfter === 60, 'Custom newly-issued K4 L2 retryAfter must be 60 seconds: ' . json_encode(resultShape($customHard), JSON_THROW_ON_ERROR));
$customSemanticAuthPolicy = new class implements BlockPolicyInterface, PolicyCapabilityProviderInterface, FailureFallbackConfigurationProviderInterface {
    public function getName(): string
    {
        return 'consumer_custom_auth_semantics';
    }

    /** @return list<PolicyCapabilityEnum> */
    public function getCapabilities(): array
    {
        return [
            PolicyCapabilityEnum::CREDENTIAL_SPRAY,
            PolicyCapabilityEnum::DISTRIBUTED_ACCOUNT,
            PolicyCapabilityEnum::TRUSTED_AUTHENTICATION,
        ];
    }

    // Deliberately different from the official AUTHENTICATION_PRIMARY preset
    // (3/600, 20/600): this is a direct custom policy, not a preset consumer.
    public function getFailureFallbackConfiguration(): FailureFallbackConfigurationDTO
    {
        return new FailureFallbackConfigurationDTO([
            new FailureFallbackRuleDTO(FailureFallbackDimensionEnum::ACCOUNT, 5, 300),
            new FailureFallbackRuleDTO(FailureFallbackDimensionEnum::IP_PREFIX, 30, 300),
        ]);
    }

    public function getScoreThresholds(): PolicyThresholdsDTO
    {
        return new PolicyThresholdsDTO(
            k4: new ScoreThresholdsDTO(5, 8, 12),
        );
    }

    public function getScoreDeltas(): ScoreDeltasDTO
    {
        return new ScoreDeltasDTO(
            k1_spray: 5,
            k2_missing_fp: 4,
            k4_failure: 3,
            k4_repeated_missing_fp: 6,
            k5_failure: 2,
        );
    }

    public function getFailureMode(): string
    {
        return 'FAIL_CLOSED';
    }

    public function getBudgetConfig(): ?BudgetConfigDTO
    {
        return new BudgetConfigDTO(
            threshold: 20,
            block_level: 3,
            cooldown_seconds: 3600,
            trusted_session_floor_level: 2,
            precheck_enforcement: true,
            known_device_micro_cap: 8,
            recovery_collision_guard_enabled: false,
        );
    }
};
$customSemanticAuthLimiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('consumer-semantic-auth-key', 'consumer-semantic-auth-fingerprint', 'prod'), $store, $signals)->withPolicy($customSemanticAuthPolicy)->build();
$customSprayResults = [];
for ($index = 1; $index <= 5; $index++) {
    $customSprayResults[] = $customSemanticAuthLimiter->limit(
        new RateLimitContextDTO('203.0.113.19', 'Mozilla/5.0 consumer-custom-auth', 'consumer-custom-spray-' . $index, ['device' => 'stable']),
        RateLimitCommand::checkOnly('consumer_custom_auth_semantics'),
    );
}
requireCondition($customSprayResults[4]->decision === RateLimitResultDTO::DECISION_HARD_BLOCK, 'Custom auth capability policy did not execute the credential-spray branch.');

$customApiPolicy = new class implements BlockPolicyInterface, PolicyCapabilityProviderInterface, FailureFallbackConfigurationProviderInterface {
    public function getName(): string
    {
        return 'consumer_custom_api_overuse';
    }

    /** @return list<PolicyCapabilityEnum> */
    public function getCapabilities(): array
    {
        return [PolicyCapabilityEnum::API_OVERUSE];
    }

    // Deliberately different from the official API_OVERUSE preset (120/60,
    // 60/60): this is a direct custom policy, not a preset consumer.
    public function getFailureFallbackConfiguration(): FailureFallbackConfigurationDTO
    {
        return new FailureFallbackConfigurationDTO([
            new FailureFallbackRuleDTO(FailureFallbackDimensionEnum::IP_PREFIX, 200, 30),
            new FailureFallbackRuleDTO(FailureFallbackDimensionEnum::IP_PREFIX_NORMALIZED_USER_AGENT, 80, 30),
        ]);
    }

    public function getScoreThresholds(): PolicyThresholdsDTO
    {
        return new PolicyThresholdsDTO(
            k1: new ScoreThresholdsDTO(1000, 1000, 1000),
            k2: new ScoreThresholdsDTO(1000, 1000, 1000),
            k3: new ScoreThresholdsDTO(1, 1, 1),
        );
    }

    public function getScoreDeltas(): ScoreDeltasDTO
    {
        return new ScoreDeltasDTO(access: 1);
    }

    public function getFailureMode(): string
    {
        return 'FAIL_OPEN';
    }

    public function getBudgetConfig(): ?BudgetConfigDTO
    {
        return null;
    }
};
$customApiLimiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('consumer-custom-api-key', 'consumer-custom-api-fingerprint', 'prod'), $store, $signals)->withPolicy($customApiPolicy)->build();
$customApiContext = new RateLimitContextDTO('203.0.113.18', 'Mozilla/5.0 consumer-custom-api', null, []);
$customApiResult = $customApiLimiter->limit($customApiContext, new RateLimitCommand('consumer_custom_api_overuse', 121));
requireCondition($customApiResult->decision === RateLimitResultDTO::DECISION_HARD_BLOCK && $customApiResult->blockLevel === 2, 'Custom API-overuse capability policy did not execute the low-confidence K3-to-K2 remap branch.');
// All three public punishments are issued before one shared wait. This keeps
// the default OTP L3 proof intact while avoiding serial 60s + 300s + 60s waits.
$sharedWaitSeconds = max(
    $publicPunishmentDuration($reentryLoginHard),
    $publicPunishmentDuration($reentryOtpHard),
    $publicPunishmentDuration($customHard),
) + 1;
sleep($sharedWaitSeconds);

[$reentryLoginCheck, $reentryLoginMetadata] = $checkPublicReentry($limiter, $reentryLoginContext, 'login_protection');
$reentryLoginClaim = $limiter->claimPostPunishmentReentry($reentryLoginContext, 'login_protection', $reentryLoginMetadata->id);
$reentryLoginSecondClaim = $limiter->claimPostPunishmentReentry($reentryLoginContext, 'login_protection', $reentryLoginMetadata->id);
requireCondition($reentryLoginClaim && ! $reentryLoginSecondClaim, 'Public Login claim was not one-shot.');

[$reentryOtpCheck, $reentryOtpMetadata] = $checkPublicReentry($limiter, $reentryOtpContext, 'otp_protection');
$reentryOtpClaim = $limiter->claimPostPunishmentReentry($reentryOtpContext, 'otp_protection', $reentryOtpMetadata->id);
$reentryOtpSecondClaim = $limiter->claimPostPunishmentReentry($reentryOtpContext, 'otp_protection', $reentryOtpMetadata->id);
requireCondition($reentryOtpClaim && ! $reentryOtpSecondClaim, 'Public OTP claim was not one-shot.');

[$customCheck, $customMetadata] = $checkPublicReentry($customLimiter, $customContext, 'consumer_custom_auth_k4');
$customClaim = $customLimiter->claimPostPunishmentReentry($customContext, 'consumer_custom_auth_k4', $customMetadata->id);
$customSecondClaim = $customLimiter->claimPostPunishmentReentry($customContext, 'consumer_custom_auth_k4', $customMetadata->id);
requireCondition($customClaim && ! $customSecondClaim, 'Custom opt-in claim was not one-shot.');
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
    $previousScoreStates = findScoreStates($previousRotationState);
    $previousK5Score = null;
    foreach ($previousScoreStates as $scoreState) {
        if ((int) $scoreState['state']['value'] === 2) {
            $previousK5Score = $scoreState;
            break;
        }
    }
    requireCondition($previousK5Score !== null, $name . ' old public failure did not persist the expected K5 contribution of 2.');
    $rotated = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig($newOuter, $newFingerprint, 'prod', $oldOuter, $oldFingerprint), $store, $signals)->build();
    $rotationCases[$name] = resultShape($rotated->limit($probeContext, RateLimitCommand::recordFailure('login_protection')));
    requireCondition(count(array_intersect($currentOnlyKeys, redisKeys($raw))) > 0, $name . ' rotation did not create Current generation state.');
    $currentRotationState = redisStateSnapshot($raw, $currentOnlyKeys);
    $currentScoreValues = array_map(static fn(array $scoreState): int => (int) $scoreState['state']['value'], findScoreStates($currentRotationState));
    requireCondition(in_array(4, $currentScoreValues, true), $name . ' Current K5 score was not a continuation from 2 to 4 after the rotated failure.');
    $previousK5After = redisStateSnapshot($raw, [$previousK5Score['key']]);
    requireReadOnlySnapshot([$previousK5Score['key'] => $previousRotationState[$previousK5Score['key']]], $previousK5After, $name . ' exact Previous K5 score state');
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

$simpleThrottleSubject = 'consumer-simple-fixed-window-subject';
$simpleKeysBefore = redisKeys($raw);
$simpleFirst = $limiter->consume('consumer_simple_fixed_window', $simpleThrottleSubject);
$simpleSecond = $limiter->consume('consumer_simple_fixed_window', $simpleThrottleSubject);
$simpleThird = $limiter->consume('consumer_simple_fixed_window', $simpleThrottleSubject);
requireCondition($simpleFirst->allowed && $simpleFirst->remaining === 1 && $simpleFirst->limit === 2, 'Simple throttle consume #1 was not allowed with remaining=1.');
requireCondition($simpleSecond->allowed && $simpleSecond->remaining === 0, 'Simple throttle consume #2 was not allowed with remaining=0.');
requireCondition(! $simpleThird->allowed && $simpleThird->remaining === 0, 'Simple throttle consume #3 was not denied.');
requireCondition(($simpleThird->retryAfter ?? 0) > 0, 'Simple throttle denial did not return a positive retryAfter.');
requireCondition($simpleFirst->resetAt === $simpleSecond->resetAt && $simpleSecond->resetAt === $simpleThird->resetAt, 'Simple throttle resetAt was not stable across consumes.');
requireCondition(
    $simpleFirst->failureMode === SimpleRateLimitResultDTO::NORMAL && $simpleThird->failureMode === SimpleRateLimitResultDTO::NORMAL,
    'Simple throttle failureMode was not NORMAL.',
);
$simpleKeysAfter = redisKeys($raw);
$simpleNewKeys = newlyCreatedRedisKeys($simpleKeysBefore, $simpleKeysAfter);
requireCondition(count($simpleNewKeys) === 1, 'Simple throttle consume did not persist exactly one new Redis key.');
foreach ($simpleNewKeys as $simpleKey) {
    requireCondition(! str_contains($simpleKey, $simpleThrottleSubject), 'Raw simple-throttle subject leaked into a Redis key.');
}

$unknownSimplePolicyRejected = false;
try {
    $limiter->consume('consumer_never_registered_simple_policy', $simpleThrottleSubject);
} catch (RateLimiterException) {
    $unknownSimplePolicyRejected = true;
}
requireCondition($unknownSimplePolicyRejected, 'An unregistered simple throttle policy name did not raise RateLimiterException.');

$simpleRotationSubject = 'consumer-simple-fixed-window-rotation-subject';
$simpleRotationKeysBefore = redisKeys($raw);
$simpleOldLimiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('simple-old-key', 'simple-old-fingerprint', 'prod'), $store, $signals)
    ->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('consumer_simple_rotation', limit: 2, intervalSeconds: 60))
    ->build();
$simpleOldResult = $simpleOldLimiter->consume('consumer_simple_rotation', $simpleRotationSubject);
requireCondition($simpleOldResult->allowed, 'Old-generation simple throttle consume was not allowed.');
$simpleRotationKeysAfterOld = redisKeys($raw);
$simplePreviousKeys = newlyCreatedRedisKeys($simpleRotationKeysBefore, $simpleRotationKeysAfterOld);
requireCondition(count($simplePreviousKeys) === 1, 'Old-generation simple throttle did not persist exactly one key.');
$simplePreviousKey = $simplePreviousKeys[0];
$simplePreviousStateBefore = redisHashMap($raw->execute(['HGETALL', $simplePreviousKey]));

$simpleRotatedLimiter = RateLimiterBuilder::fromFullCapabilityStore(
    new RateLimiterConfig('simple-new-key', 'simple-new-fingerprint', 'prod', 'simple-old-key', 'simple-old-fingerprint'),
    $store,
    $signals,
)
    ->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('consumer_simple_rotation', limit: 2, intervalSeconds: 60))
    ->build();
$simpleMigrated = $simpleRotatedLimiter->consume('consumer_simple_rotation', $simpleRotationSubject);
requireCondition(
    $simpleMigrated->allowed && $simpleMigrated->remaining === 0,
    'Rotated simple throttle consume did not seed Previous(1) + 1 = 2 into Current.',
);
$simpleRotationKeysAfterNew = redisKeys($raw);
$simpleCurrentKeys = newlyCreatedRedisKeys($simpleRotationKeysAfterOld, $simpleRotationKeysAfterNew);
requireCondition(count($simpleCurrentKeys) === 1, 'Rotation migration did not create exactly one new Current key.');
$simplePreviousStateAfter = redisHashMap($raw->execute(['HGETALL', $simplePreviousKey]));
requireCondition($simplePreviousStateAfter === $simplePreviousStateBefore, 'Previous simple-throttle state was modified during migration.');

$failureRaw = new RespRedisCommandExecutor($host, (int) $port);
$failureExecutor = new CallableRedisCommandExecutor(static function (array $command) use ($failureRaw): mixed {
    if (strtoupper((string) ($command[0] ?? '')) === 'EVAL') {
        throw new RuntimeException('controlled consumer backend failure');
    }
    return $failureRaw->execute($command);
});
$failureStore = new RedisFullCapabilityStore($failureExecutor, 'consumer-failure-semantics');
$failureSignals = new RecordingFailureSignalEmitter();
$failureLimiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('failure-key', 'failure-fingerprint', 'prod'), $failureStore, $failureSignals)
    ->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('consumer_simple_failure', limit: 2, intervalSeconds: 60))
    ->build();
$failureContext = new RateLimitContextDTO('192.0.2.91', 'Mozilla/5.0 consumer-failure', 'consumer-failure-account', ['device' => 'consumer-failure-device']);
$loginFailureMode = $failureLimiter->limit($failureContext, RateLimitCommand::checkOnly('login_protection'));
$otpFailureMode = $failureLimiter->limit($failureContext, RateLimitCommand::checkOnly('otp_protection'));
$apiFailureMode = $failureLimiter->limit($failureContext, RateLimitCommand::checkOnly('api_heavy_protection'));
requireCondition($loginFailureMode->decision === RateLimitResultDTO::DECISION_HARD_BLOCK && $loginFailureMode->failureMode === 'FAIL_CLOSED', 'Login backend failure did not fail closed.');
requireCondition($otpFailureMode->decision === RateLimitResultDTO::DECISION_HARD_BLOCK && $otpFailureMode->failureMode === 'FAIL_CLOSED', 'OTP backend failure did not fail closed.');
requireCondition($apiFailureMode->decision === RateLimitResultDTO::DECISION_ALLOW && $apiFailureMode->failureMode === 'FAIL_OPEN', 'API Heavy backend failure did not fail open.');
$simpleFailureResult = $failureLimiter->consume('consumer_simple_failure', 'consumer-simple-failure-subject');
requireCondition(
    ! $simpleFailureResult->allowed
    && $simpleFailureResult->failureMode === SimpleRateLimitResultDTO::FAIL_CLOSED
    && $simpleFailureResult->retryAfter === null
    && $simpleFailureResult->resetAt === null,
    'Simple throttle backend failure (RuntimeException) did not fail closed.',
);

// R1 regression proof: a storage-originated RateLimiterException — not only
// a plain RuntimeException — must also become a typed FAIL_CLOSED result,
// never propagate as an exception. This is otherwise indistinguishable by
// class from the package's own configuration/contract failure type.
$rateLimiterExceptionFailureRaw = new RespRedisCommandExecutor($host, (int) $port);
$rateLimiterExceptionFailureExecutor = new CallableRedisCommandExecutor(static function (array $command) use ($rateLimiterExceptionFailureRaw): mixed {
    if (strtoupper((string) ($command[0] ?? '')) === 'EVAL') {
        throw new RateLimiterException('simulated store-internal contract violation');
    }
    return $rateLimiterExceptionFailureRaw->execute($command);
});
$rateLimiterExceptionFailureStore = new RedisFullCapabilityStore($rateLimiterExceptionFailureExecutor, 'consumer-failure-semantics-rle');
$rateLimiterExceptionFailureLimiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('failure-rle-key', 'failure-rle-fingerprint', 'prod'), $rateLimiterExceptionFailureStore, $failureSignals)
    ->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('consumer_simple_failure_rle', limit: 2, intervalSeconds: 60))
    ->build();
$simpleFailureResultFromRateLimiterException = $rateLimiterExceptionFailureLimiter->consume('consumer_simple_failure_rle', 'consumer-simple-failure-rle-subject');
requireCondition(
    ! $simpleFailureResultFromRateLimiterException->allowed
    && $simpleFailureResultFromRateLimiterException->failureMode === SimpleRateLimitResultDTO::FAIL_CLOSED
    && $simpleFailureResultFromRateLimiterException->retryAfter === null
    && $simpleFailureResultFromRateLimiterException->resetAt === null,
    'Simple throttle backend failure (RateLimiterException) did not fail closed.',
);

$customPrimaryPolicy = new class extends \Maatify\RateLimiter\Config\LoginProtectionPolicy {
    public function getName(): string
    {
        return 'consumer_custom_primary_fallback';
    }
};
$customPrimaryLimiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('failure-primary-key', 'failure-primary-fingerprint', 'prod'), $failureStore, $failureSignals)->withPolicy($customPrimaryPolicy)->build();
$customPrimaryFallback = [];
for ($attempt = 1; $attempt <= 7; $attempt++) {
    $customPrimaryFallback[] = $customPrimaryLimiter->limit($failureContext, RateLimitCommand::checkOnly('consumer_custom_primary_fallback'));
}
requireCondition($customPrimaryFallback[3]->decision === RateLimitResultDTO::DECISION_ALLOW && $customPrimaryFallback[3]->failureMode === 'DEGRADED_MODE', 'Custom primary-auth policy did not enter its typed fallback profile.');
requireCondition($customPrimaryFallback[6]->decision === RateLimitResultDTO::DECISION_HARD_BLOCK, 'Custom primary-auth fallback did not enforce its account cap.');

$customStepUpPolicy = new class extends \Maatify\RateLimiter\Config\OtpProtectionPolicy {
    public function getName(): string
    {
        return 'consumer_custom_step_up_fallback';
    }
};
$customStepUpLimiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('failure-step-up-key', 'failure-step-up-fingerprint', 'prod'), $failureStore, $failureSignals)->withPolicy($customStepUpPolicy)->build();
$customStepUpFallback = [];
for ($attempt = 1; $attempt <= 6; $attempt++) {
    $customStepUpFallback[] = $customStepUpLimiter->limit($failureContext, RateLimitCommand::checkOnly('consumer_custom_step_up_fallback'));
}
requireCondition($customStepUpFallback[3]->decision === RateLimitResultDTO::DECISION_ALLOW && $customStepUpFallback[3]->failureMode === 'DEGRADED_MODE', 'Custom step-up policy did not enter its typed fallback profile.');
requireCondition($customStepUpFallback[5]->decision === RateLimitResultDTO::DECISION_HARD_BLOCK, 'Custom step-up fallback did not enforce its account cap.');

// Direct custom policies with generic typed fallback configuration values that
// differ from every official preset (5/300 + 30/300 for auth; 200/30 + 80/30
// for API), proven through the same public production path and the same
// bounded-fallback runtime as the official presets above.
$customSemanticAuthFailureLimiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('failure-custom-auth-key', 'failure-custom-auth-fingerprint', 'prod'), $failureStore, $failureSignals)->withPolicy($customSemanticAuthPolicy)->build();
$customSemanticAuthFallback = [];
for ($attempt = 1; $attempt <= 9; $attempt++) {
    $customSemanticAuthFallback[] = $customSemanticAuthFailureLimiter->limit($failureContext, RateLimitCommand::checkOnly('consumer_custom_auth_semantics'));
}
requireCondition($customSemanticAuthFallback[2]->decision === RateLimitResultDTO::DECISION_ALLOW && $customSemanticAuthFallback[2]->failureMode === 'DEGRADED_MODE', 'Custom auth policy did not enter its own typed fallback configuration.');
requireCondition($customSemanticAuthFallback[6]->decision === RateLimitResultDTO::DECISION_ALLOW, 'Custom auth fallback did not honor its own 5-request account cap.');
requireCondition($customSemanticAuthFallback[7]->decision === RateLimitResultDTO::DECISION_HARD_BLOCK, 'Custom auth fallback did not enforce its own account cap boundary.');

$customApiFailureLimiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('failure-custom-api-key', 'failure-custom-api-fingerprint', 'prod'), $failureStore, $failureSignals)->withPolicy($customApiPolicy)->build();
$customApiFallbackContext = new RateLimitContextDTO('192.0.2.93', 'Mozilla/5.0 consumer-custom-api-fallback', null, []);
$customApiFallback = [];
for ($attempt = 1; $attempt <= 81; $attempt++) {
    $customApiFallback[] = $customApiFailureLimiter->limit($customApiFallbackContext, RateLimitCommand::checkOnly('consumer_custom_api_overuse'));
}
requireCondition($customApiFallback[79]->decision === RateLimitResultDTO::DECISION_ALLOW, 'Custom API fallback did not honor its own 80-request IP+UA cap.');
requireCondition($customApiFallback[80]->decision === RateLimitResultDTO::DECISION_HARD_BLOCK, 'Custom API fallback did not enforce its own IP+UA cap boundary.');

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
echo json_encode(['status' => 'PASS', 'packageInstallPath' => $installPath, 'productionAutoload' => true, 'packageTestNamespaceAvailable' => false, 'reentryTiming' => ['loginLevel' => $reentryLoginHard->blockLevel, 'otpLevel' => $reentryOtpHard->blockLevel, 'customLevel' => $customHard->blockLevel, 'sharedWaitSeconds' => $sharedWaitSeconds], 'login' => ['checkOnly' => resultShape($loginCheck), 'recordFailure' => resultShape($loginFailure), 'recordSuccess' => resultShape($loginSuccess), 'progression' => array_map('resultShape', $loginProgression), 'persistenceProof' => true, 'postPunishmentReentry' => ['checkOnly' => resultShape($reentryLoginCheck), 'claim' => $reentryLoginClaim, 'secondClaim' => $reentryLoginSecondClaim]], 'otp' => resultShape($otp), 'otpPostPunishmentReentry' => ['checkOnly' => resultShape($reentryOtpCheck), 'claim' => $reentryOtpClaim, 'secondClaim' => $reentryOtpSecondClaim], 'customOptIn' => ['checkOnly' => resultShape($customCheck), 'claim' => $customClaim, 'secondClaim' => $customSecondClaim], 'apiHeavy' => resultShape($api), 'credentialSpray' => array_map('resultShape', $spray), 'sprayLifecycle' => ['recordSuccess' => resultShape($spraySuccess), 'subjectFourCheckOnly' => resultShape($spraySubjectFour)], 'trustedSession' => resultShape($trusted), 'untrustedFollowUp' => resultShape($untrustedFollowUp), 'trustedNonK1' => resultShape($trustedNonK1), 'rotations' => $rotationCases, 'budgetMigration' => ['previousCount' => (int) $previousBudgetMap['count'], 'currentCount' => (int) $currentBudgetMap['count'], 'epochStartPreserved' => true, 'previousReadOnly' => true], 'simpleThrottle' => ['consume1' => simpleResultShape($simpleFirst), 'consume2' => simpleResultShape($simpleSecond), 'consume3Denied' => simpleResultShape($simpleThird), 'newRedisKeys' => count($simpleNewKeys), 'rawSubjectHiddenFromKeys' => true, 'unknownPolicyRejected' => $unknownSimplePolicyRejected, 'rotation' => ['oldGeneration' => simpleResultShape($simpleOldResult), 'migratedIntoCurrent' => simpleResultShape($simpleMigrated), 'previousReadOnly' => $simplePreviousStateAfter === $simplePreviousStateBefore], 'backendFailure' => simpleResultShape($simpleFailureResult), 'backendFailureFromRateLimiterException' => simpleResultShape($simpleFailureResultFromRateLimiterException)], 'failureSemantics' => ['login' => resultShape($loginFailureMode), 'otp' => resultShape($otpFailureMode), 'apiHeavy' => resultShape($apiFailureMode)], 'customFallbackConfiguration' => ['auth' => ['enteredFallback' => resultShape($customSemanticAuthFallback[2]), 'accountCapAllowed' => resultShape($customSemanticAuthFallback[6]), 'accountCapExceeded' => resultShape($customSemanticAuthFallback[7])], 'api' => ['ipUaCapAllowed' => resultShape($customApiFallback[79]), 'ipUaCapExceeded' => resultShape($customApiFallback[80])]], 'circuit' => ['openGuard' => resultShape($openGuardResult), 'halfOpenProbe' => resultShape($halfOpenResult), 'closedRecovery' => resultShape($closedResult), 'normalWorkloadSuppressedWhileOpen' => true, 'signalCount' => count($circuitSignals->signals()), 'signalTypes' => array_values(array_unique(array_map(static fn($signal): string => $signal->type, $circuitSignals->signals())))], 'redisPersistence' => ['keyCount' => count($keys)], 'failureSignals' => count($signals->signals())], JSON_THROW_ON_ERROR) . PHP_EOL;
