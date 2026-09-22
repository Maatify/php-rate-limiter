<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\Correlation\NullCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\ThrowingRateLimitStore;
use PHPUnit\Framework\Attributes\DataProvider;
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
            $this->clock,
        );

        $circuitBreaker = new CircuitBreaker(
            $this->circuitBreakerStore,
            $this->failureSignalEmitter,
            $this->clock,
        );

        $deviceIdentityResolver = new DeviceIdentityResolver(
            new FingerprintHasher('test_secret'),
        );

        $failureModeResolver = new FailureModeResolver();

        $policies = [
            'otp_protection' => new OtpProtectionPolicy(),
            'login_protection' => new LoginProtectionPolicy(),
            'api_heavy_protection' => new ApiHeavyProtectionPolicy(),
        ];

        $this->engine = new RateLimiterEngine(
            $deviceIdentityResolver,
            $pipeline,
            $circuitBreaker,
            $failureModeResolver,
            $this->failureSignalEmitter,
            $this->clock,
            $policies,
        );

        // Clear local fallback state
        $reflection = new \ReflectionClass(\Maatify\RateLimiter\Service\LocalFallbackLimiter::class);
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

    #[DataProvider('preCheckPolicies')]
    public function testOtpLoginPreCheckBehavior(string $policyName): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla/5.0', 'acct_123', ['fp_data' => 1]);
        $command = RateLimitCommand::checkOnly($policyName);

        $result = $this->engine->limit($context, $command);

        $this->assertEquals(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        $this->assertEquals(0, $result->blockLevel);

        $k4Key = hash_hmac('sha256', "{$policyName}:rate_limiter:k4:v2:prod:acct_123", 'test_secret');
        $this->assertNull($this->store->get($k4Key));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function preCheckPolicies(): iterable
    {
        yield 'otp protection' => ['otp_protection'];
        yield 'login protection' => ['login_protection'];
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

    public function testOtpAndLoginStoreFailuresFailClosed(): void
    {
        foreach ([new OtpProtectionPolicy(), new LoginProtectionPolicy()] as $policy) {
            $engine = $this->createEngineWithStore(new ThrowingRateLimitStore(), $policy);
            $result = $engine->limit(
                new RateLimitContextDTO('198.51.100.10', 'Mozilla/5.0', $policy->getName()),
                RateLimitCommand::checkOnly($policy->getName()),
            );

            $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
            $this->assertSame(2, $result->blockLevel);
            $this->assertSame(600, $result->retryAfter);
            $this->assertSame('FAIL_CLOSED', $result->failureMode);
        }
    }

    public function testApiHeavyStoreFailureMovesFromFailOpenToDegradedModeAfterCircuitTrip(): void
    {
        $engine = $this->createEngineWithStore(new ThrowingRateLimitStore(), new ApiHeavyProtectionPolicy());
        $context = new RateLimitContextDTO('198.51.100.11', 'Mozilla/5.0', 'api-account');
        $command = RateLimitCommand::checkOnly('api_heavy_protection');

        $beforeTrip = $engine->limit($context, $command);
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $beforeTrip->decision);
        $this->assertSame('FAIL_OPEN', $beforeTrip->failureMode);

        $secondBeforeTrip = $engine->limit($context, $command);
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $secondBeforeTrip->decision);
        $this->assertSame('FAIL_OPEN', $secondBeforeTrip->failureMode);

        $afterTrip = $engine->limit($context, $command);
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $afterTrip->decision);
        $this->assertSame('DEGRADED_MODE', $afterTrip->failureMode);
    }

    public function testApiHeavyFallbackK2CapThroughEngine(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36', 'acct_123');
        $command = RateLimitCommand::checkOnly('api_heavy_protection'); // API Heavy is FAIL_OPEN

        // Force the real Engine through its local fallback path with a failing store.
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
            $this->clock,
        );

        $deviceIdentityResolver = new DeviceIdentityResolver(new FingerprintHasher('test_secret'));
        $failureModeResolver = new FailureModeResolver();

        $engineWithThrowingStore = new RateLimiterEngine(
            $deviceIdentityResolver,
            $pipelineWithThrowingStore,
            new CircuitBreaker(
                $this->circuitBreakerStore,
                $this->failureSignalEmitter,
                $this->clock,
            ),
            $failureModeResolver,
            $this->failureSignalEmitter,
            $this->clock,
            [new ApiHeavyProtectionPolicy()],
        );

        // The Engine's local fallback K2 bucket allows the first 60 requests.
        for ($i = 0; $i < 60; $i++) {
            $result = $engineWithThrowingStore->limit($context, $command);
            $this->assertEquals(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }

        // The 61st request is rejected by the Engine's fallback K2 cap.
        $result = $engineWithThrowingStore->limit($context, $command);
        $this->assertEquals(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);

        $meta = $result->metadata;
        $this->assertNotNull($meta);

        $contextMeta = $meta->context;
        $this->assertNotNull($contextMeta);

        $this->assertEquals('fallback_limit_exceeded', $contextMeta->reason);
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
            'old_secret',
        );

        $engine = new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher('new_secret')),
            $pipelineWithPrevSecret,
            new CircuitBreaker($this->circuitBreakerStore, $this->failureSignalEmitter, $this->clock),
            new FailureModeResolver(),
            $this->failureSignalEmitter,
            $this->clock,
            [new ApiHeavyProtectionPolicy()],
        );

        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla/5.0', 'acct_123');
        $command = new RateLimitCommand('api_heavy_protection', 1, false, false, false);

        // Seed a readable score under the previous secret. The active key is absent.
        $oldK1 = hash_hmac('sha256', "api_heavy_protection:rate_limiter:k1:v2:prod:127.0.0.1", 'old_secret');
        $this->store->set($oldK1, 7, 3600);

        // The real pipeline must read the old score and write the update under the active secret.
        $result = $engine->limit($context, $command);

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);

        $newK1 = hash_hmac('sha256', "api_heavy_protection:rate_limiter:k1:v2:prod:127.0.0.1", 'new_secret');
        $oldState = $this->store->get($oldK1);
        $newState = $this->store->get($newK1);

        $this->assertNotNull($oldState);
        $this->assertNotNull($newState);
        $this->assertSame(7, $oldState->value);
        $this->assertSame(8, $newState->value);
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

    public function testApiHeavyDoesNotPersistIpv6MacroHierarchyBlocksBeforeAdaptiveAggregationRemediation(): void
    {
        $policy = new ApiHeavyProtectionPolicy(['k1' => 1, 'k2' => 100, 'k3' => 100]);
        $engine = $this->createEngineWithStore($this->store, $policy);
        $context = new RateLimitContextDTO('2001:db8:85a3::8a2e:370:7334', 'Mozilla/5.0', 'acct_123');
        $hex = bin2hex((string) inet_pton($context->ip));
        $keyForPrefix = static fn(string $prefix): string => hash_hmac(
            'sha256',
            "api_heavy_protection:rate_limiter:k1:v2:prod:{$prefix}",
            'test_secret',
        );
        $k1Key = $keyForPrefix(substr($hex, 0, 16));
        $macroKeys = [
            $keyForPrefix(substr($hex, 0, 12)),
            $keyForPrefix(substr($hex, 0, 10)),
            $keyForPrefix(substr($hex, 0, 8)),
        ];
        $this->store->set($k1Key, 1, 3600);
        foreach ($macroKeys as $macroKey) {
            $this->store->set($macroKey, 1, 3600);
        }

        $result = $engine->limit($context, RateLimitCommand::checkOnly('api_heavy_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertSame(3, $result->blockLevel);
        $this->assertSame(3, $this->store->checkBlock($k1Key)?->level);
        foreach ($macroKeys as $macroKey) {
            $this->assertNull($this->store->checkBlock($macroKey));
        }
        $this->assertNull($this->store->checkBlock(hash_hmac(
            'sha256',
            'api_heavy_protection:rate_limiter:k4:v2:prod:acct_123',
            'test_secret',
        )));
    }

    public function testApiHeavyDoesNotPersistIpv6MacroHierarchyBlocksAfterScoreUpdate(): void
    {
        $policy = new ApiHeavyProtectionPolicy(['k1' => 1, 'k2' => 100, 'k3' => 100]);
        $engine = $this->createEngineWithStore($this->store, $policy);
        $context = new RateLimitContextDTO('2001:db8:85a3::8a2e:370:7334', 'Mozilla/5.0', 'acct_123');
        $hex = bin2hex((string) inet_pton($context->ip));
        $keyForPrefix = static fn(string $prefix): string => hash_hmac(
            'sha256',
            "api_heavy_protection:rate_limiter:k1:v2:prod:{$prefix}",
            'test_secret',
        );
        $k1Key = $keyForPrefix(substr($hex, 0, 16));
        $macroKeys = [
            $keyForPrefix(substr($hex, 0, 12)),
            $keyForPrefix(substr($hex, 0, 10)),
            $keyForPrefix(substr($hex, 0, 8)),
        ];

        $result = $engine->limit($context, new RateLimitCommand('api_heavy_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertSame(3, $result->blockLevel);
        $this->assertSame(3, $this->store->checkBlock($k1Key)?->level);
        foreach ($macroKeys as $macroKey) {
            $this->assertNull($this->store->checkBlock($macroKey));
        }
        $this->assertNull($this->store->checkBlock(hash_hmac(
            'sha256',
            'api_heavy_protection:rate_limiter:k4:v2:prod:acct_123',
            'test_secret',
        )));
    }

    private function createEngineWithStore(RateLimitStoreInterface $store, BlockPolicyInterface ...$policies): RateLimiterEngine
    {
        $correlationStore = new NullCorrelationStore();
        $emitter = new RecordingFailureSignalEmitter();
        $clock = $this->clock;

        $pipeline = new EvaluationPipeline(
            $store,
            $correlationStore,
            new BudgetTracker($store, $clock),
            new AntiEquilibriumGate($correlationStore),
            new DecayCalculator($clock),
            new EphemeralBucket($correlationStore),
            'test_secret',
            'prod',
            $clock,
        );

        return new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher('test_secret')),
            $pipeline,
            new CircuitBreaker(new InMemoryCircuitBreakerStore(), $emitter, $clock),
            new FailureModeResolver(),
            $emitter,
            $clock,
            $policies,
        );
    }
}
