<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class RateLimiterEngineCurrentRuntimeCharacterizationTest extends TestCase
{
    private FixedClock $clock;
    private InMemoryRateLimitStore $store;
    private StatefulInMemoryCorrelationStore $correlationStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new InMemoryRateLimitStore($this->clock);
        $this->correlationStore = new StatefulInMemoryCorrelationStore($this->clock);
    }

    public function testLoginL1DoesNotStallLaterFailureUpdates(): void
    {
        $engine = $this->createEngine(new LoginProtectionPolicy());
        $context = new RateLimitContextDTO(
            '198.51.100.10',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'login-stall-account',
            ['device' => 'stable'],
        );
        $command = RateLimitCommand::recordFailure('login_protection');
        $k4Key = $this->key('login_protection', 'k4', 'login-stall-account');

        $first = $engine->limit($context, $command);
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $first->decision);
        $firstScore = $this->store->get($k4Key);
        $this->assertNotNull($firstScore);
        $this->assertSame(3, $firstScore->value);
        $this->assertSame(1, $this->store->getBudget($k4Key)?->count);

        $second = $engine->limit($context, $command);
        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $second->decision);
        $this->assertSame(1, $second->blockLevel);
        $secondScore = $this->store->get($k4Key);
        $this->assertNotNull($secondScore);
        $this->assertSame(6, $secondScore->value);
        $this->assertSame(2, $this->store->getBudget($k4Key)?->count);

        $preCheck = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));
        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $preCheck->decision);
        $this->assertSame(1, $preCheck->blockLevel);

        $third = $engine->limit($context, $command);
        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $third->decision);
        $this->assertSame(2, $third->blockLevel);
        $thirdScore = $this->store->get($k4Key);
        $this->assertNotNull($thirdScore);
        $this->assertSame(9, $thirdScore->value);
        $this->assertSame(3, $this->store->getBudget($k4Key)?->count);
    }

    public function testLoginBudgetOnlyCrossingRemainsSoftAndStoresNonHardBlock(): void
    {
        $engine = $this->createEngine(new LoginProtectionPolicy());
        $context = new RateLimitContextDTO(
            '198.51.100.11',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'login-budget-crossing',
            ['device' => 'stable'],
        );
        $k4Key = $this->key('login_protection', 'k4', 'login-budget-crossing');
        $this->store->set($k4Key, 0, 86400);
        $this->store->incrementBudget($k4Key, 86400, 19);

        $beforeScore = $this->store->get($k4Key);
        $this->assertNotNull($beforeScore);
        $this->assertSame(0, $beforeScore->value);
        $this->assertSame(19, $this->store->getBudget($k4Key)?->count);

        $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $result->decision);
        $this->assertSame(3, $result->blockLevel);
        $this->assertSame(20, $this->store->getBudget($k4Key)?->count);
        $afterScore = $this->store->get($k4Key);
        $this->assertNotNull($afterScore);
        $this->assertSame(3, $afterScore->value);
        $this->assertLessThan(8, $afterScore->value);
        $this->assertNull($this->store->checkBlock($k4Key));

        $subsequent = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $subsequent->decision);
        $this->assertSame(1, $subsequent->blockLevel);
        $this->assertSame(1, $this->store->checkBlock($k4Key)?->level);
    }

    public function testLoginBudgetWithMissingSessionIdentifierDoesNotReceiveTrustedDowngrade(): void
    {
        $engine = $this->createEngine(new LoginProtectionPolicy());
        $accountId = 'login-trusted-without-session-id';
        $context = new RateLimitContextDTO(
            '198.51.100.21',
            'Mozilla/5.0 Chrome/123.0.0.0',
            $accountId,
            null,
            null,
            true,
        );
        $k4Key = $this->key('login_protection', 'k4', $accountId);
        $this->store->incrementBudget($k4Key, 86400, 20);

        $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $result->decision);
        $this->assertSame(3, $result->blockLevel);
    }

    public function testLoginBudgetWithValidTrustedSessionKeepsTrustedDowngrade(): void
    {
        $engine = $this->createEngine(new LoginProtectionPolicy());
        $accountId = 'login-trusted-with-session-id';
        $context = new RateLimitContextDTO(
            '198.51.100.22',
            'Mozilla/5.0 Chrome/123.0.0.0',
            $accountId,
            null,
            'session-device-4',
            true,
        );
        $k4Key = $this->key('login_protection', 'k4', $accountId);
        $this->store->incrementBudget($k4Key, 86400, 20);

        $result = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $result->decision);
        $this->assertSame(2, $result->blockLevel);
    }

    public function testLoginBudgetRotationReadsPreviousSecretBudgetAsV2Fallback(): void
    {
        $engine = $this->createEngineWithSecrets('new_secret', 'old_secret', new LoginProtectionPolicy());
        $accountId = 'login-budget-rotation';
        $context = new RateLimitContextDTO(
            '198.51.100.23',
            'Mozilla/5.0 Chrome/123.0.0.0',
            $accountId,
        );
        $oldK4Key = $this->key('login_protection', 'k4', $accountId, 'old_secret');
        $newK4Key = $this->key('login_protection', 'k4', $accountId, 'new_secret');
        $oldBudget = $this->store->incrementBudget($oldK4Key, 86400, 20);

        $result = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $result->decision);
        $this->assertSame(3, $result->blockLevel);

        $storedOldBudget = $this->store->getBudget($oldK4Key);
        $this->assertNotNull($storedOldBudget);
        $this->assertSame(20, $storedOldBudget->count);
        $this->assertSame($oldBudget->epochStart, $storedOldBudget->epochStart);
        $this->assertNull($this->store->getBudget($newK4Key));
    }

    public function testLoginActiveBudgetDoesNotMaskStrongerK4ScoreThreshold(): void
    {
        $engine = $this->createEngine(new LoginProtectionPolicy());
        $accountId = 'login-budget-before-score-threshold';
        $context = new RateLimitContextDTO(
            '198.51.100.24',
            'Mozilla/5.0 Chrome/123.0.0.0',
            $accountId,
        );
        $k4Key = $this->key('login_protection', 'k4', $accountId);
        $this->store->set($k4Key, 8, 86400);
        $this->store->incrementBudget($k4Key, 86400, 20);

        $this->assertNull($this->store->checkBlock($k4Key));

        $result = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertSame(2, $result->blockLevel);
        $this->assertSame(8, $this->store->get($k4Key)?->value);
        $cooldownKey = hash_hmac(
            'sha256',
            'login_protection:rate_limiter:budget_cooldown:v1:prod:' . $accountId,
            'test_secret',
        );
        $this->assertNull($this->store->get($cooldownKey));
    }

    public function testLoginActiveBudgetDoesNotShortCircuitFailureUpdates(): void
    {
        $engine = $this->createEngine(new LoginProtectionPolicy());
        $accountId = 'login-budget-short-circuit';
        $context = new RateLimitContextDTO(
            '198.51.100.25',
            'Mozilla/5.0 Chrome/123.0.0.0',
            $accountId,
            ['device' => 'stable'],
        );
        $k4Key = $this->key('login_protection', 'k4', $accountId);
        $this->store->set($k4Key, 0, 86400);
        $this->store->incrementBudget($k4Key, 86400, 20);

        $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $result->decision);
        $this->assertSame(3, $result->blockLevel);
        $this->assertSame(3, $this->store->get($k4Key)?->value);
        $this->assertSame(21, $this->store->getBudget($k4Key)?->count);
    }

    public function testKnownTrustedLoginDeviceBuildsMicroCapBeforeAccountBudget(): void
    {
        $engine = $this->createEngine(new LoginProtectionPolicy());
        $accountId = 'login-known-trusted-before-microcap';
        $context = new RateLimitContextDTO(
            '198.51.100.26',
            'Mozilla/5.0 Chrome/123.0.0.0',
            $accountId,
            null,
            'session-device-5',
            true,
        );
        $device = (new DeviceIdentityResolver(new FingerprintHasher('test_secret')))->resolve($context);
        $this->assertNotNull($device->fingerprintHash);
        $k4Key = $this->key('login_protection', 'k4', $accountId);
        $k5Key = $this->key('login_protection', 'k5', "{$accountId}:{$device->fingerprintHash}");
        $microCapKey = hash_hmac(
            'sha256',
            "login_protection:rate_limiter:microcap:k5:v1:{$accountId}:{$device->fingerprintHash}",
            'test_secret',
        );

        $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        $this->assertSame(2, $this->store->get($k5Key)?->value);
        $this->assertSame(1, $this->store->getBudget($microCapKey)?->count);
        $this->assertNull($this->store->getBudget($k4Key));
    }

    public function testCurrentCharacterizationLoginBudgetKeepsFixedEpochStartWithinTwentyFourHours(): void
    {
        $engine = $this->createEngine(new LoginProtectionPolicy());
        $accountId = 'login-budget-fixed-epoch';
        $context = new RateLimitContextDTO(
            '198.51.100.27',
            'Mozilla/5.0 Chrome/123.0.0.0',
            $accountId,
            ['device' => 'stable'],
        );
        $k4Key = $this->key('login_protection', 'k4', $accountId);

        $first = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $firstBudget = $this->store->getBudget($k4Key);
        $this->assertNotNull($firstBudget);
        $this->assertSame(1, $firstBudget->count);
        $epochStart = $firstBudget->epochStart;

        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:00:30'));
        $second = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $secondBudget = $this->store->getBudget($k4Key);

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $first->decision);
        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $second->decision);
        $this->assertNotNull($secondBudget);
        $this->assertSame(2, $secondBudget->count);
        $this->assertSame($epochStart, $secondBudget->epochStart);
    }

    public function testLoginBudgetUsesIndependentCooldownWithoutEpochRetryAfter(): void
    {
        $engine = $this->createEngine(new LoginProtectionPolicy());
        $context = new RateLimitContextDTO(
            '198.51.100.12',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'login-cooldown-absence',
            ['device' => 'stable'],
        );
        $k4Key = $this->key('login_protection', 'k4', 'login-cooldown-absence');
        $this->store->incrementBudget($k4Key, 86400, 20);

        $first = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $first->decision);
        $this->assertSame(3, $first->blockLevel);
        $this->assertSame(3600, $first->retryAfter);

        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:00:30'));
        $second = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $second->decision);
        $this->assertSame(1, $second->blockLevel);
        $this->assertSame(1200, $second->retryAfter);
        $this->assertSame(22, $this->store->getBudget($k4Key)?->count);
        $cooldownKey = hash_hmac(
            'sha256',
            'login_protection:rate_limiter:budget_cooldown:v1:prod:login-cooldown-absence',
            'test_secret',
        );
        $this->assertSame(1, $this->store->get($cooldownKey)?->value);
    }

    public function testOtpRecoveryCollisionGuardSuppressesBudgetActivationAtNineToTen(): void
    {
        $engine = $this->createEngine(new OtpProtectionPolicy());
        $context = new RateLimitContextDTO(
            '198.51.100.13',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'otp-recovery-collision',
            ['device' => 'trusted'],
            'session-device-1',
            true,
        );
        $k4Key = $this->key('otp_protection', 'k4', 'otp-recovery-collision');
        $this->store->incrementBudget($k4Key, 86400, 9);

        $result = $engine->limit($context, RateLimitCommand::recordFailure('otp_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $result->decision);
        $this->assertSame(2, $result->blockLevel);
        $this->assertSame(10, $this->store->getBudget($k4Key)?->count);
        $score = $this->store->get($k4Key);
        $this->assertNull($score);
        $k5Key = $this->key('otp_protection', 'k5', 'otp-recovery-collision:' . (new DeviceIdentityResolver(new FingerprintHasher('test_secret')))->resolve($context)->fingerprintHash);
        $this->assertSame(4, $this->store->get($k5Key)?->value);
        $this->assertNull($this->store->checkBlock($k4Key));
        $cooldownKey = hash_hmac(
            'sha256',
            'otp_protection:rate_limiter:budget_cooldown:v1:prod:otp-recovery-collision',
            'test_secret',
        );
        $this->assertNull($this->store->get($cooldownKey));
    }

    public function testCurrentCharacterizationApiK2CrossingMapsEqualThresholdsToHardBlock(): void
    {
        $policy = $this->smallApiPolicy();
        $engine = $this->createEngine($policy);
        $context = new RateLimitContextDTO(
            '198.51.100.14',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'api-k2-mapping',
            ['device' => 'stable'],
        );
        $command = new RateLimitCommand('api_heavy_protection');

        $result = $engine->limit($context, $command);

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertSame(3, $result->blockLevel);
        $k2Key = $this->key(
            'api_heavy_protection',
            'k2',
            '198.51.100.14:' . DeviceIdentityResolver::normalizeUserAgent($context->ua),
        );
        $block = $this->store->checkBlock($k2Key);
        $this->assertNotNull($block);
        $this->assertSame(3, $block->level);
    }

    public function testCurrentCharacterizationApiThresholdPathCreatesK4BlockDespiteNoApiK4Contract(): void
    {
        $policy = $this->smallApiPolicy();
        $engine = $this->createEngine($policy);
        $context = new RateLimitContextDTO(
            '198.51.100.15',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'api-k4-mapping',
            ['device' => 'stable'],
        );
        $command = new RateLimitCommand('api_heavy_protection');

        $result = $engine->limit($context, $command);

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertSame(3, $result->blockLevel);
        $this->assertNull($policy->getScoreThresholds()->k4);
        $this->assertNull($policy->getBudgetConfig());

        $k4Key = $this->key('api_heavy_protection', 'k4', 'api-k4-mapping');
        $block = $this->store->checkBlock($k4Key);
        $this->assertNotNull($block);
        $this->assertSame(3, $block->level);
    }

    public function testCredentialSprayBlocksTheFifthDistinctSubjectDuringPrecheck(): void
    {
        $engine = $this->createEngine(new LoginProtectionPolicy());
        $ip = '198.51.100.16';
        $ua = 'Mozilla/5.0 Chrome/123.0.0.0';

        for ($index = 1; $index <= 5; $index++) {
            $context = new RateLimitContextDTO(
                $ip,
                $ua,
                "spray-account-{$index}",
                ['device' => 'stable'],
            );

            $result = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));

            if ($index < 5) {
                $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
            } else {
                $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
                $this->assertSame(2, $result->blockLevel);
            }
        }

        $k1Key = $this->key('login_protection', 'k1', $ip);
        $this->assertSame(2, $this->store->checkBlock($k1Key)?->level);
    }

    public function testCurrentCharacterizationFourDevicesForOneAccountDoNotCreateInvolvedK5Block(): void
    {
        $engine = $this->createEngine(new LoginProtectionPolicy());
        $accountId = 'distributed-account';
        $ip = '198.51.100.17';
        $requests = [
            ['ua' => 'Mozilla/5.0 Chrome/123.0.0.0', 'device' => 'device-1'],
            ['ua' => 'Mozilla/5.0 Firefox/124.0', 'device' => 'device-2'],
            ['ua' => 'Mozilla/5.0 Safari/17.0', 'device' => 'device-3'],
            ['ua' => 'Mozilla/5.0 Edge/120.0', 'device' => 'device-4'],
        ];
        $resolver = new DeviceIdentityResolver(new FingerprintHasher('test_secret'));
        /** @var list<string> $resolvedFingerprintHashes */
        $resolvedFingerprintHashes = [];

        foreach ($requests as $request) {
            $context = new RateLimitContextDTO(
                $ip,
                $request['ua'],
                $accountId,
                ['device' => $request['device']],
            );

            $device = $resolver->resolve($context);
            $this->assertNotNull($device->fingerprintHash);
            $resolvedFingerprintHashes[] = $device->fingerprintHash;

            $result = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));

            $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }

        $k4Key = $this->key('login_protection', 'k4', $accountId);
        $this->assertNull($this->store->get($k4Key));
        $this->assertNull($this->store->checkBlock($k4Key));

        foreach ($resolvedFingerprintHashes as $fingerprintHash) {
            $k5Key = $this->key('login_protection', 'k5', "{$accountId}:{$fingerprintHash}");
            $this->assertNull($this->store->checkBlock($k5Key));
        }
    }

    private function createEngine(BlockPolicyInterface ...$policies): RateLimiterEngine
    {
        return $this->createEngineWithSecrets('test_secret', null, ...$policies);
    }

    private function createEngineWithSecrets(
        string $currentSecret,
        ?string $previousSecret,
        BlockPolicyInterface ...$policies,
    ): RateLimiterEngine {
        $emitter = new RecordingFailureSignalEmitter();
        $pipeline = new EvaluationPipeline(
            $this->store,
            $this->correlationStore,
            new BudgetTracker($this->store, $this->clock),
            new AntiEquilibriumGate($this->correlationStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($this->correlationStore),
            $currentSecret,
            'prod',
            $this->clock,
            $previousSecret,
        );

        return new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher($currentSecret)),
            $pipeline,
            new CircuitBreaker(new InMemoryCircuitBreakerStore(), $emitter, $this->clock),
            new FailureModeResolver(),
            $emitter,
            $this->clock,
            $policies,
        );
    }

    private function smallApiPolicy(): ApiHeavyProtectionPolicy
    {
        return new ApiHeavyProtectionPolicy([
            'k1' => 10,
            'k2' => 1,
            'k3' => 10,
        ]);
    }

    private function key(string $policy, string $type, string $scope, string $secret = 'test_secret'): string
    {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:{$type}:v2:prod:{$scope}",
            $secret,
        );
    }
}
