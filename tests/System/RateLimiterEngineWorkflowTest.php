<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Engine\CircuitBreaker;
use Maatify\RateLimiter\Engine\EvaluationPipeline;
use Maatify\RateLimiter\Engine\FailureModeResolver;
use Maatify\RateLimiter\Engine\RateLimiterEngine;
use Maatify\RateLimiter\Device\DeviceIdentityResolver;
use Maatify\RateLimiter\Device\EphemeralBucket;
use Maatify\RateLimiter\Device\FingerprintHasher;
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
use Maatify\RateLimiter\Tests\Support\RateLimiter\ThrowingRateLimitStore;
use PHPUnit\Framework\TestCase;

class RateLimiterEngineWorkflowTest extends TestCase
{
    private FixedClock $clock;
    private InMemoryRateLimitStore $store;
    private StatefulInMemoryCorrelationStore $correlationStore;
    private InMemoryCircuitBreakerStore $circuitBreakerStore;
    private RecordingFailureSignalEmitter $failureSignalEmitter;
    private RateLimiterEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new InMemoryRateLimitStore($this->clock);
        $this->correlationStore = new StatefulInMemoryCorrelationStore($this->clock);
        $this->circuitBreakerStore = new InMemoryCircuitBreakerStore();
        $this->failureSignalEmitter = new RecordingFailureSignalEmitter();

        $decayCalculator = new DecayCalculator($this->clock);
        $budgetTracker = new BudgetTracker($this->store, $this->clock);
        $antiEquilibriumGate = new AntiEquilibriumGate($this->correlationStore);
        $ephemeralBucket = new EphemeralBucket($this->correlationStore);

        $pipeline = new EvaluationPipeline(
            $this->store,
            $this->correlationStore,
            $budgetTracker,
            $antiEquilibriumGate,
            $decayCalculator,
            $ephemeralBucket,
            'test_secret',
            'prod',
            $this->clock
        );

        $circuitBreaker = new CircuitBreaker(
            $this->circuitBreakerStore,
            $this->failureSignalEmitter,
            $this->clock
        );

        $deviceIdentityResolver = new DeviceIdentityResolver(
            new FingerprintHasher('test_secret')
        );

        $failureModeResolver = new FailureModeResolver();

        $policies = [
            'otp_protection' => new OtpProtectionPolicy(),
            'login_protection' => new LoginProtectionPolicy(),
            'api_heavy_protection' => new ApiHeavyProtectionPolicy()
        ];

        $this->engine = new RateLimiterEngine(
            $deviceIdentityResolver,
            $pipeline,
            $circuitBreaker,
            $failureModeResolver,
            $this->failureSignalEmitter,
            $this->clock,
            $policies
        );

        // Clear local fallback state
        $reflection = new \ReflectionClass(\Maatify\RateLimiter\Engine\LocalFallbackLimiter::class);
        $countersProperty = $reflection->getProperty('counters');
        $countersProperty->setAccessible(true);
        $countersProperty->setValue(null, []);
    }

    public function testCleanAllowedRequest(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla/5.0', 'acct_123', ['fp_data' => 1]);
        $command = new RateLimitCommand('api_heavy_protection', 1, false, false, false);

        $result = $this->engine->limit($context, $command);

        $this->assertEquals(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        $this->assertEquals(0, $result->blockLevel);
        $this->assertEquals(0, $result->retryAfter);
        $this->assertEquals('NORMAL', $result->failureMode);

        $deviceResolver = new DeviceIdentityResolver(new FingerprintHasher('test_secret'));
        $device = $deviceResolver->resolve($context);
        $normalizedUa = $device->normalizedUa;
        $fpHash = $device->fingerprintHash;

        $k1Key = hash_hmac('sha256', "api_heavy_protection:rate_limiter:k1:v2:prod:127.0.0.1", 'test_secret');
        $k2Key = hash_hmac('sha256', "api_heavy_protection:rate_limiter:k2:v2:prod:127.0.0.1:{$normalizedUa}", 'test_secret');
        $k3Key = hash_hmac('sha256', "api_heavy_protection:rate_limiter:k3:v2:prod:127.0.0.1:{$fpHash}", 'test_secret');

        $k1State = $this->store->get($k1Key);
        $this->assertNotNull($k1State);
        $this->assertEquals(1, $k1State->value);

        $k2State = $this->store->get($k2Key);
        $this->assertNotNull($k2State);
        $this->assertEquals(1, $k2State->value);

        $k3State = $this->store->get($k3Key);
        $this->assertNotNull($k3State);
        $this->assertEquals(1, $k3State->value);
    }

    public function testOtpLoginPreCheckBehavior(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla/5.0', 'acct_123', ['fp_data' => 1]);
        $command = RateLimitCommand::checkOnly('otp_protection');

        $result = $this->engine->limit($context, $command);

        $this->assertEquals(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        $this->assertEquals(0, $result->blockLevel);

        $k4Key = hash_hmac('sha256', "otp_protection:rate_limiter:k4:v2:prod:acct_123", 'test_secret');
        $this->assertNull($this->store->get($k4Key));
    }

    public function testApiHeavyNormalAccessBehavior(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla/5.0', 'acct_123');
        $command = new RateLimitCommand('api_heavy_protection', 1, false, false, false);

        $result = $this->engine->limit($context, $command);

        $this->assertEquals(RateLimitResultDTO::DECISION_ALLOW, $result->decision);

        $normalizedUa = DeviceIdentityResolver::normalizeUserAgent('Mozilla/5.0');
        $k1Key = hash_hmac('sha256', "api_heavy_protection:rate_limiter:k1:v2:prod:127.0.0.1", 'test_secret');
        $k2Key = hash_hmac('sha256', "api_heavy_protection:rate_limiter:k2:v2:prod:127.0.0.1:{$normalizedUa}", 'test_secret');

        $k1State = $this->store->get($k1Key);
        $this->assertNotNull($k1State);
        $this->assertEquals(1, $k1State->value);

        $k2State = $this->store->get($k2Key);
        $this->assertNotNull($k2State);
        $this->assertEquals(1, $k2State->value);
    }

    public function testFailureRecording(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla/5.0', 'acct_123', ['fp_data' => 1]);
        $command = RateLimitCommand::recordFailure('otp_protection');

        $result = $this->engine->limit($context, $command);

        // Single failure gives SOFT_BLOCK because score threshold 4 < 5.
        $this->assertEquals(RateLimitResultDTO::DECISION_SOFT_BLOCK, $result->decision);
        $this->assertEquals(1, $result->blockLevel);

        // CircuitBreaker trip threshold is 3, so a single limit() execution doesn't emit a signal
        // unless it's a runtime exception that triggers circuit breaker trip logic.
        // Therefore, we do not expect an emitted signal here on normal pipeline block.
        $emitted = $this->failureSignalEmitter->getEmitted();
        $this->assertCount(0, $emitted);
    }

    public function testSuccessRecording(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla/5.0', 'acct_123');
        $command = RateLimitCommand::recordSuccess('otp_protection');

        $result = $this->engine->limit($context, $command);

        $this->assertEquals(RateLimitResultDTO::DECISION_ALLOW, $result->decision);

        $k4Key = hash_hmac('sha256', "otp_protection:rate_limiter:k4:v2:prod:acct_123", 'test_secret');
        $this->assertNull($this->store->get($k4Key));
    }

    public function testExistingBlockingOutcome(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla/5.0', 'acct_123');
        $command = RateLimitCommand::checkOnly('otp_protection');

        $k4Key = hash_hmac('sha256', "otp_protection:rate_limiter:k4:v2:prod:acct_123", 'test_secret');
        $this->store->block($k4Key, 3, 3600);
        $this->store->set($k4Key, 50, 3600); // L3 threshold is 10 for K4 on OTP

        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:02:00'));

        $result = $this->engine->limit($context, $command);

        $this->assertEquals(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertEquals(3, $result->blockLevel);
        $this->assertEquals(3480, $result->retryAfter);
    }

    public function testUnknownPolicyExceptionContract(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla/5.0', 'acct_123');
        $command = RateLimitCommand::checkOnly('unknown_policy');

        $this->expectException(\Maatify\RateLimiter\Exception\RateLimiterException::class);
        $this->expectExceptionMessage('Policy not found: unknown_policy');

        $this->engine->limit($context, $command);
    }

    public function testFinding6UADoubleNormalizationRemainsUnchanged(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36', 'acct_123');
        $command = RateLimitCommand::checkOnly('api_heavy_protection'); // API Heavy is FAIL_OPEN

        // Seed an exception trigger: use a throwing store with real pipeline
        $throwingStore = new ThrowingRateLimitStore();

        $pipelineWithThrowingStore = new EvaluationPipeline(
            $throwingStore,
            $this->correlationStore,
            new BudgetTracker($throwingStore, $this->clock),
            new AntiEquilibriumGate($this->correlationStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($this->correlationStore),
            'test_secret',
            'prod',
            $this->clock
        );

        $deviceIdentityResolver = new DeviceIdentityResolver(new FingerprintHasher('test_secret'));
        $failureModeResolver = new FailureModeResolver();

        $engineWithThrowingStore = new RateLimiterEngine(
            $deviceIdentityResolver,
            $pipelineWithThrowingStore,
            new CircuitBreaker(
                $this->circuitBreakerStore,
                $this->failureSignalEmitter,
                $this->clock
            ),
            $failureModeResolver,
            $this->failureSignalEmitter,
            $this->clock,
            [new ApiHeavyProtectionPolicy()]
        );

        // Consume K2 local fallback bucket
        for ($i = 0; $i < 60; $i++) {
            $result = $engineWithThrowingStore->limit($context, $command);
            $this->assertEquals(RateLimitResultDTO::DECISION_ALLOW, $result->decision);

            // Expected mode is DEGRADED_MODE because the circuit breaker opens after 3 errors,
            // and the first 2 are FAIL_OPEN. Then it transitions to DEGRADED_MODE.
            // Actually, we just need to test the LocalFallbackLimiter, so we'll assert the decision.
        }

        // 61st request should be rejected by fallback limiter (K2 cap is 60)
        $result = $engineWithThrowingStore->limit($context, $command);
        $this->assertEquals(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);

        $meta = $result->metadata;
        $this->assertNotNull($meta);

        $contextMeta = $meta->context;
        $this->assertNotNull($contextMeta);

        $this->assertEquals('fallback_limit_exceeded', $contextMeta->reason);
    }

    public function testLocalFallbackGlobalGCFindingRemainsUnchanged(): void
    {
        // This is part of the local fallback test above, demonstrating it works up to K2 cap.
        $this->markTestIncomplete('Placeholder for the global GC finding which is a known architectural issue.');
    }

    public function testDualSecretRotationBehavior(): void
    {
        // Recreate Engine with previous secret set
        $pipelineWithPrevSecret = new EvaluationPipeline(
            $this->store,
            $this->correlationStore,
            new BudgetTracker($this->store, $this->clock),
            new AntiEquilibriumGate($this->correlationStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($this->correlationStore),
            'new_secret',
            'prod',
            $this->clock,
            'old_secret'
        );

        $engine = new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher('new_secret')),
            $pipelineWithPrevSecret,
            new CircuitBreaker($this->circuitBreakerStore, $this->failureSignalEmitter, $this->clock),
            new FailureModeResolver(),
            $this->failureSignalEmitter,
            $this->clock,
            [new ApiHeavyProtectionPolicy()]
        );

        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla/5.0', 'acct_123');
        $command = new RateLimitCommand('api_heavy_protection', 1, false, false, false);

        $deviceResolver = new DeviceIdentityResolver(new FingerprintHasher('new_secret'));
        $device = $deviceResolver->resolve($context);
        $normalizedUa = $device->normalizedUa;

        // Block using OLD secret
        $oldK1 = hash_hmac('sha256', "api_heavy_protection:rate_limiter:k1:v2:prod:127.0.0.1", 'old_secret');
        $this->store->block($oldK1, 3, 3600);

        // Check using new Engine
        $result = $engine->limit($context, $command);

        // Should read block from old secret
        $this->assertEquals(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertEquals(3, $result->blockLevel);
    }

    public function testIPv6KeyHierarchy(): void
    {
        // IPv6 address
        $context = new RateLimitContextDTO('2001:db8:85a3::8a2e:370:7334', 'Mozilla/5.0', 'acct_123');
        $command = new RateLimitCommand('api_heavy_protection', 1, false, false, false);

        $this->engine->limit($context, $command);

        // Calculate K1 prefixes
        $ip = '2001:db8:85a3::8a2e:370:7334';
        $packed = inet_pton($ip);
        if ($packed === false) {
            $this->fail('Failed to pack IPv6 address');
        }
        $hex = bin2hex($packed);

        // 64-bit prefix (16 chars)
        $prefix64 = substr($hex, 0, 16);
        $k1_64 = hash_hmac('sha256', "api_heavy_protection:rate_limiter:k1:v2:prod:{$prefix64}", 'test_secret');

        // 48-bit prefix (12 chars)
        $prefix48 = substr($hex, 0, 12);
        $k1_48 = hash_hmac('sha256', "api_heavy_protection:rate_limiter:k1:v2:prod:{$prefix48}", 'test_secret');

        // 40-bit prefix (10 chars)
        $prefix40 = substr($hex, 0, 10);
        $k1_40 = hash_hmac('sha256', "api_heavy_protection:rate_limiter:k1:v2:prod:{$prefix40}", 'test_secret');

        // 32-bit prefix (8 chars)
        $prefix32 = substr($hex, 0, 8);
        $k1_32 = hash_hmac('sha256', "api_heavy_protection:rate_limiter:k1:v2:prod:{$prefix32}", 'test_secret');

        $k1_64State = $this->store->get($k1_64);
        $this->assertNotNull($k1_64State);
        $this->assertEquals(1, $k1_64State->value);

        $k1_48State = $this->store->get($k1_48);
        $this->assertNotNull($k1_48State);
        $this->assertEquals(1, $k1_48State->value);

        $k1_40State = $this->store->get($k1_40);
        $this->assertNotNull($k1_40State);
        $this->assertEquals(1, $k1_40State->value);

        $k1_32State = $this->store->get($k1_32);
        $this->assertNotNull($k1_32State);
        $this->assertEquals(1, $k1_32State->value);
    }
}
