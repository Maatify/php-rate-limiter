<?php

declare(strict_types=1);

/**
 * Runnable standalone example for Admission-Reservation policies (DEC-048, DEC-049, DEC-050).
 *
 * Uses ONLY the official public composition (`RateLimiterConfig`, `AdmissionCredentialCodec::fromConfig`,
 * `RateLimiterBuilder::fromFullCapabilityStore`, `RedisFullCapabilityStore`) with production PSR-4 autoloading:
 *   1. Provisioning a stable random >= 32-byte `admissionStateSecret` once outside source code.
 *   2. Fail-fast validation of incomplete Admission configuration before any backend command.
 *   3. Unified `RateLimiterConfig` + `AdmissionCredentialCodec::fromConfig($config)` setup.
 *   4. Minting, verifying, and rotating `bindingKeySecret` independently of `admissionStateSecret`.
 *   5. Re-establishing a fresh isolated `admissionTrustDomain` + new `admissionStateSecret` if persistent
 *      store continuity is ever lost (never reusing the prior state secret).
 *   6. When `REDIS_INTEGRATION_HOST` and `REDIS_INTEGRATION_PORT` are set (e.g. via
 *      `scripts/ci/run-with-redis-service.sh`), executes the full live `RedisFullCapabilityStore` runtime:
 *      opening the fleet fence, `checkOnly` + permit-bound outcome, reading `bindingEpoch()`, revoking an
 *      account (`revokeAccountBinding`), surviving an outer `keySecret` rotation, and verifying accept/refuse.
 *
 * No custom/in-memory store adapter is implemented here; `RedisFullCapabilityStore` is the official store.
 */

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionAdmissionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\AdmissionCredentialVerdictEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AdmissionBindingControlInterface;
use Maatify\RateLimiter\Service\AdmissionBindingEpochInterface;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

// 1. Provision stable secrets outside source code (env / secret manager).
//    Generate `admissionStateSecret` ONCE with `random_bytes(32)` when creating an isolated scope.
$admissionTrustDomain = 'auth-edge.example.test';
$admissionStateSecret = random_bytes(RateLimiterConfig::MIN_ADMISSION_STATE_SECRET_BYTES);
$outerKeyV1 = 'example-outer-key-v1';
$outerKeyV2 = 'example-outer-key-v2';
$fingerprintSecret = 'example-fingerprint-secret';
$bindingKeyV1 = 'example-binding-key-v1';
$bindingKeyV2 = 'example-binding-key-v2';

// 2. Fail-fast configuration validation rejects incomplete/short Admission secrets before any backend call.
$rejectedShortSecret = false;
try {
    new RateLimiterConfig($outerKeyV1, $fingerprintSecret, 'production', bindingKeySecret: $bindingKeyV1, admissionTrustDomain: $admissionTrustDomain, admissionStateSecret: 'too-short');
} catch (RateLimiterException) {
    $rejectedShortSecret = true;
}

// 3. Unified setup: one RateLimiterConfig drives both RateLimiterBuilder and AdmissionCredentialCodec::fromConfig().
$configV1 = new RateLimiterConfig(
    keySecret: $outerKeyV1,
    fingerprintSecret: $fingerprintSecret,
    environmentScope: 'production',
    bindingKeySecret: $bindingKeyV1,
    admissionTrustDomain: $admissionTrustDomain,
    admissionStateSecret: $admissionStateSecret,
);
$codecV1 = AdmissionCredentialCodec::fromConfig($configV1);

// 4. Offline synthetic credential minting, verification, and independent bindingKeySecret rotation.
$now = time();
$accountId = 'acct-owner-100';
$deviceInstance = 'device-laptop-01';
$offlineCredEpoch0 = $codecV1->encode(new AdmissionCredentialDTO(
    account: $accountId,
    subject: $accountId,
    budgetGroups: ['login'],
    instance: $deviceInstance,
    jti: bin2hex(random_bytes(8)),
    expiresAt: $now + 86400,
    boundAt: $now,
    epoch: 0,
));

$configBindingRotated = new RateLimiterConfig(
    keySecret: $outerKeyV1,
    fingerprintSecret: $fingerprintSecret,
    environmentScope: 'production',
    bindingKeySecret: $bindingKeyV2,
    previousBindingKeySecret: $bindingKeyV1,
    admissionTrustDomain: $admissionTrustDomain,
    admissionStateSecret: $admissionStateSecret,
);
$codecRotated = AdmissionCredentialCodec::fromConfig($configBindingRotated);
$verifiedAfterBindingRotation = $codecRotated->verify($offlineCredEpoch0, $accountId, $accountId, 'login', $deviceInstance, $now, 0)->verdict;
$rejectedWrongAccount = $codecRotated->verify($offlineCredEpoch0, 'acct-stranger', $accountId, 'login', $deviceInstance, $now, 0)->verdict;

// 5. Loss of persistence continuity (distinct from routine rotation):
//    Keep the same outer/binding keys to isolate trust-domain + state-secret loss: never reuse `admissionStateSecret`.
$freshRecoveryConfig = new RateLimiterConfig(
    keySecret: $outerKeyV1,
    fingerprintSecret: $fingerprintSecret,
    environmentScope: 'production',
    bindingKeySecret: $bindingKeyV1,
    admissionTrustDomain: 'auth-edge-recovery.example.test',
    admissionStateSecret: random_bytes(RateLimiterConfig::MIN_ADMISSION_STATE_SECRET_BYTES),
);
$freshCodec = AdmissionCredentialCodec::fromConfig($freshRecoveryConfig);
$legacyVerdictInFreshScope = $freshCodec->verify($offlineCredEpoch0, $accountId, $accountId, 'login', $deviceInstance, $now, 0)->verdict;

$output = [
    'mode' => 'offline-codec-and-config',
    'rejectedShortStateSecret' => $rejectedShortSecret,
    'verifiedAfterBindingRotation' => $verifiedAfterBindingRotation->value,
    'rejectedWrongAccount' => $rejectedWrongAccount->value,
    'legacyVerdictInFreshIsolatedScope' => $legacyVerdictInFreshScope->value,
];

// 6. Explicit live official Redis composition when Redis connection env vars are provided (fail-closed if incomplete).
$redisHost = getenv('REDIS_INTEGRATION_HOST');
$redisPort = getenv('REDIS_INTEGRATION_PORT');
if ($redisHost !== false || $redisPort !== false) {
    if (! is_string($redisHost) || $redisHost === '' || ! is_string($redisPort) || ! ctype_digit($redisPort)) {
        throw new RuntimeException('Live mode requires both REDIS_INTEGRATION_HOST and numeric REDIS_INTEGRATION_PORT.');
    }
    if (! extension_loaded('redis')) {
        throw new RuntimeException('Live mode requires the PHP redis extension.');
    }
    $redis = new Redis();
    if (! $redis->connect($redisHost, (int) $redisPort, 2.0)) {
        throw new RuntimeException('Failed to connect to Redis.');
    }
    $executor = new CallableRedisCommandExecutor(static function (array $command) use ($redis): mixed {
        /** @var string $cmd */
        $cmd = (string) $command[0];
        return $redis->rawCommand($cmd, ...array_slice($command, 1));
    });
    // Per-run isolated namespace matching the per-run random admissionStateSecret.
    $store = new RedisFullCapabilityStore($executor, 'example-admission-hardening-' . bin2hex(random_bytes(8)));
    $signals = new class implements FailureSignalEmitterInterface {
        public function emit(FailureSignalDTO $signal): void {}
    };

    $runtimeV1 = RateLimiterBuilder::fromFullCapabilityStore($configV1, $store, $signals)
        ->withPolicy(new LoginProtectionAdmissionPolicy())
        ->build();
    if (! $runtimeV1 instanceof AdmissionFenceControlInterface
        || ! $runtimeV1 instanceof AdmissionBindingControlInterface
        || ! $runtimeV1 instanceof AdmissionBindingEpochInterface) {
        throw new RateLimiterException('Expected admission control interfaces on composite runtime.');
    }
    $fence = $runtimeV1->openAdmissionFence('login');
    if ($fence < 1) {
        throw new RuntimeException('Expected open admission fence >= 1.');
    }

    // Initial unproven check + simulated host authentication (e.g. verified password) + clean outcome settlement.
    $ip = '198.51.100.55';
    $initialCtx = new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/126', $accountId, clientInstanceId: $deviceInstance);
    $check = $runtimeV1->limit($initialCtx, RateLimitCommand::checkOnly('login_protection'));
    $permit = $check->metadata?->permit;
    if ($check->decision !== RateLimitResultDTO::DECISION_ALLOW || $permit === null) {
        throw new RateLimiterException('Expected initial unproven admission.');
    }
    $initialSettle = $runtimeV1->limit($initialCtx, new RateLimitCommand('login_protection', 1, false, false, true, $permit));
    if (! $initialSettle->isAllowed() || $initialSettle->metadata?->cause !== null) {
        throw new RuntimeException('Expected clean initial settlement.');
    }

    // Only AFTER host verification and clean outcome settlement: read authoritative bindingEpoch and mint live epoch-0 credential.
    $liveEpoch0 = $runtimeV1->bindingEpoch($accountId);
    if ($liveEpoch0 !== 0) {
        throw new RuntimeException('Expected authoritative binding epoch 0 before revocation.');
    }
    $liveCredEpoch0 = $codecV1->encode(new AdmissionCredentialDTO(
        account: $accountId,
        subject: $accountId,
        budgetGroups: ['login'],
        instance: $deviceInstance,
        jti: bin2hex(random_bytes(8)),
        expiresAt: $now + 86400,
        boundAt: $now,
        epoch: $liveEpoch0,
    ));

    // Revoke account binding (epoch 0 -> 1) and re-issue to eligible device using authoritative bindingEpoch.
    $runtimeV1->revokeAccountBinding($accountId);
    $epoch1 = $runtimeV1->bindingEpoch($accountId);
    if ($epoch1 !== 1) {
        throw new RuntimeException('Expected binding epoch 1 after revoke.');
    }
    $credEpoch1 = $codecV1->encode(new AdmissionCredentialDTO($accountId, $accountId, ['login'], $deviceInstance, bin2hex(random_bytes(8)), $now + 86400, $now, $epoch1));

    // Supported outer rotation (V1 -> V2/V1): reopen fence under V2; revocation survives without intermediate traffic.
    $configV2 = new RateLimiterConfig(
        keySecret: $outerKeyV2,
        fingerprintSecret: $fingerprintSecret,
        environmentScope: 'production',
        previousKeySecret: $outerKeyV1,
        bindingKeySecret: $bindingKeyV2,
        previousBindingKeySecret: $bindingKeyV1,
        admissionTrustDomain: $admissionTrustDomain,
        admissionStateSecret: $admissionStateSecret,
    );
    $runtimeV2 = RateLimiterBuilder::fromFullCapabilityStore($configV2, $store, $signals)
        ->withPolicy(new LoginProtectionAdmissionPolicy())
        ->build();
    if (! $runtimeV2 instanceof AdmissionFenceControlInterface || ! $runtimeV2 instanceof AdmissionBindingEpochInterface) {
        throw new RateLimiterException('Expected admission controls after outer rotation.');
    }
    if ($runtimeV2->openAdmissionFence('login') < 1) {
        throw new RuntimeException('Expected open fence after outer rotation.');
    }
    $epochAfterOuterRotation = $runtimeV2->bindingEpoch($accountId);
    if ($epochAfterOuterRotation !== 1) {
        throw new RuntimeException('Expected revoked epoch 1 to survive outer rotation.');
    }

    // Spend the remaining 23 source slots on Lane U (initialCtx already consumed 1 of 24), verifying every filler succeeds.
    for ($i = 0; $i < 23; $i++) {
        $filler = new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/126', 'filler-' . $i);
        $r = $runtimeV2->limit($filler, RateLimitCommand::checkOnly('login_protection'));
        if (! $r->isAllowed() || $r->metadata?->permit === null) {
            throw new RuntimeException("Expected filler #{$i} admission to be allowed.");
        }
        $fillerSettle = $runtimeV2->limit($filler, new RateLimitCommand('login_protection', 1, false, false, true, $r->metadata->permit));
        if (! $fillerSettle->isAllowed() || $fillerSettle->metadata?->cause !== null) {
            throw new RuntimeException("Expected filler #{$i} settlement to succeed.");
        }
    }

    // Verify revoked live epoch-0 credential is rejected to Lane U (`source_capacity`), while re-issued epoch-1 is Lane P (`ALLOW`).
    $revokedAttempt = $runtimeV2->limit(
        new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/126', $accountId, clientInstanceId: $deviceInstance, admissionCredential: $liveCredEpoch0),
        RateLimitCommand::checkOnly('login_protection'),
    );
    if ($revokedAttempt->isAllowed() || $revokedAttempt->metadata?->cause !== 'source_capacity') {
        throw new RuntimeException('Expected revoked epoch-0 credential to be refused by exhausted Lane U source_capacity.');
    }
    $reissuedCtx = new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/126', $accountId, clientInstanceId: $deviceInstance, admissionCredential: $credEpoch1);
    $reissuedAttempt = $runtimeV2->limit($reissuedCtx, RateLimitCommand::checkOnly('login_protection'));
    if ($reissuedAttempt->decision !== RateLimitResultDTO::DECISION_ALLOW || $reissuedAttempt->metadata?->permit === null) {
        throw new RuntimeException('Expected re-issued epoch-1 credential to be allowed on Lane P.');
    }
    $reissuedSettle = $runtimeV2->limit($reissuedCtx, new RateLimitCommand('login_protection', 1, false, false, true, $reissuedAttempt->metadata->permit));
    if (! $reissuedSettle->isAllowed() || $reissuedSettle->metadata?->cause !== null) {
        throw new RuntimeException('Expected re-issued epoch-1 settlement to succeed.');
    }

    $output = array_merge($output, [
        'mode' => 'live-redis-official-composition',
        'fenceOpened' => $fence,
        'epochAfterRevoke' => $epoch1,
        'epochAfterOuterRotation' => $epochAfterOuterRotation,
        'revokedEpoch0CauseOnExhaustedSource' => $revokedAttempt->metadata->cause,
        'reissuedEpoch1DecisionOnExhaustedSource' => $reissuedAttempt->decision,
        'liveAssertionsPassed' => true,
    ]);
}

if (
    $output['rejectedShortStateSecret'] !== true
    || $output['verifiedAfterBindingRotation'] !== AdmissionCredentialVerdictEnum::VALID->value
    || $output['rejectedWrongAccount'] !== AdmissionCredentialVerdictEnum::WRONG_ACCOUNT->value
    || $output['legacyVerdictInFreshIsolatedScope'] !== AdmissionCredentialVerdictEnum::INVALID_SIGNATURE->value
) {
    throw new RuntimeException('Example assertions failed.');
}

echo json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
