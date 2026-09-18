<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Contract\BlockPolicyInterface;
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
use Maatify\RateLimiter\Policy\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Policy\LoginProtectionPolicy;
use Maatify\RateLimiter\Policy\OtpProtectionPolicy;
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

    public function testCurrentCharacterizationLoginL1StallsLaterFailuresBeforeUpdates(): void
    {
        $engine = $this->createEngine(new LoginProtectionPolicy());
        $context = new RateLimitContextDTO(
            '198.51.100.10',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'login-stall-account',
            ['device' => 'stable']
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

        // Current behavior: checkThresholds() returns before processUpdates(), so the score and budget stall.
        $third = $engine->limit($context, $command);
        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $third->decision);
        $this->assertSame(1, $third->blockLevel);
        $thirdScore = $this->store->get($k4Key);
        $this->assertNotNull($thirdScore);
        $this->assertSame(6, $thirdScore->value);
        $this->assertSame(2, $this->store->getBudget($k4Key)?->count);
    }

    public function testCurrentCharacterizationLoginBudgetCrossingReturnsHardBlockAtTwenty(): void
    {
        $engine = $this->createEngine(new LoginProtectionPolicy());
        $context = new RateLimitContextDTO(
            '198.51.100.11',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'login-budget-crossing',
            ['device' => 'stable']
        );
        $k4Key = $this->key('login_protection', 'k4', 'login-budget-crossing');
        $this->store->set($k4Key, 0, 86400);
        $this->store->incrementBudget($k4Key, 86400, 19);

        $beforeScore = $this->store->get($k4Key);
        $this->assertNotNull($beforeScore);
        $this->assertSame(0, $beforeScore->value);
        $this->assertSame(19, $this->store->getBudget($k4Key)?->count);

        $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertSame(3, $result->blockLevel);
        $this->assertSame(20, $this->store->getBudget($k4Key)?->count);
        $afterScore = $this->store->get($k4Key);
        $this->assertNotNull($afterScore);
        $this->assertSame(3, $afterScore->value);
        $this->assertLessThan(8, $afterScore->value);
        $block = $this->store->checkBlock($k4Key);
        $this->assertNotNull($block);
        $this->assertSame(3, $block->level);
    }

    public function testCurrentCharacterizationLoginActiveBudgetHasNoCooldownBetweenEligibleRequests(): void
    {
        $engine = $this->createEngine(new LoginProtectionPolicy());
        $context = new RateLimitContextDTO(
            '198.51.100.12',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'login-cooldown-absence',
            ['device' => 'stable']
        );
        $k4Key = $this->key('login_protection', 'k4', 'login-cooldown-absence');
        $this->store->incrementBudget($k4Key, 86400, 20);

        $first = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $first->decision);
        $this->assertSame(3, $first->blockLevel);
        $this->assertSame(86400, $first->retryAfter);

        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:00:30'));
        $second = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $second->decision);
        $this->assertSame(3, $second->blockLevel);
        $this->assertSame(86370, $second->retryAfter);
        $this->assertSame(20, $this->store->getBudget($k4Key)?->count);
    }

    public function testCurrentCharacterizationOtpBudgetCrossingHasNoRecoveryCollisionGuard(): void
    {
        $engine = $this->createEngine(new OtpProtectionPolicy());
        $context = new RateLimitContextDTO(
            '198.51.100.13',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'otp-recovery-collision',
            ['device' => 'trusted'],
            'session-device-1',
            true
        );
        $k4Key = $this->key('otp_protection', 'k4', 'otp-recovery-collision');
        $this->store->incrementBudget($k4Key, 86400, 9);

        $result = $engine->limit($context, RateLimitCommand::recordFailure('otp_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertSame(4, $result->blockLevel);
        $this->assertSame(10, $this->store->getBudget($k4Key)?->count);
        $score = $this->store->get($k4Key);
        $this->assertNotNull($score);
        $this->assertSame(5, $score->value);
        $block = $this->store->checkBlock($k4Key);
        $this->assertNotNull($block);
        $this->assertSame(4, $block->level);
    }

    public function testCurrentCharacterizationApiK2CrossingMapsEqualThresholdsToHardBlock(): void
    {
        $policy = $this->smallApiPolicy();
        $engine = $this->createEngine($policy);
        $context = new RateLimitContextDTO(
            '198.51.100.14',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'api-k2-mapping',
            ['device' => 'stable']
        );
        $command = new RateLimitCommand('api_heavy_protection');

        $result = $engine->limit($context, $command);

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertSame(3, $result->blockLevel);
        $k2Key = $this->key(
            'api_heavy_protection',
            'k2',
            '198.51.100.14:' . DeviceIdentityResolver::normalizeUserAgent($context->ua)
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
            ['device' => 'stable']
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

    public function testCurrentCharacterizationFiveAccountIdsFromOneIpDoNotCreateCredentialSprayIpBlock(): void
    {
        $engine = $this->createEngine(new LoginProtectionPolicy());
        $ip = '198.51.100.16';
        $ua = 'Mozilla/5.0 Chrome/123.0.0.0';

        for ($index = 1; $index <= 5; $index++) {
            $context = new RateLimitContextDTO(
                $ip,
                $ua,
                "spray-account-{$index}",
                ['device' => 'stable']
            );

            $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

            $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }

        $k1Key = $this->key('login_protection', 'k1', $ip);
        $this->assertSame(25, $this->store->get($k1Key)?->value);
        $this->assertNull($this->store->checkBlock($k1Key));
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

        foreach ($requests as $request) {
            $context = new RateLimitContextDTO(
                $ip,
                $request['ua'],
                $accountId,
                ['device' => $request['device']]
            );

            $result = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));

            $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }

        $k4Key = $this->key('login_protection', 'k4', $accountId);
        $this->assertNull($this->store->get($k4Key));
        $this->assertNull($this->store->checkBlock($k4Key));
    }

    private function createEngine(BlockPolicyInterface ...$policies): RateLimiterEngine
    {
        $emitter = new RecordingFailureSignalEmitter();
        $pipeline = new EvaluationPipeline(
            $this->store,
            $this->correlationStore,
            new BudgetTracker($this->store, $this->clock),
            new AntiEquilibriumGate($this->correlationStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($this->correlationStore),
            'test_secret',
            'prod',
            $this->clock
        );

        return new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher('test_secret')),
            $pipeline,
            new CircuitBreaker(new InMemoryCircuitBreakerStore(), $emitter, $this->clock),
            new FailureModeResolver(),
            $emitter,
            $this->clock,
            $policies
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

    private function key(string $policy, string $type, string $scope): string
    {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:{$type}:v2:prod:{$scope}",
            'test_secret'
        );
    }
}
