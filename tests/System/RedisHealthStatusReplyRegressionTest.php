<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\FailureStateDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Service\RateLimitOperationalReader;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\NullCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

/**
 * Issue #91 / DEC-018: an ext-redis-shaped healthy PING reply (bool true)
 * must neither falsify Operational Read nor block circuit recovery.
 */
final class RedisHealthStatusReplyRegressionTest extends TestCase
{
    public function testOperationalReadReportsBackendHealthyForBooleanTruePing(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new PhpRedisHealthRateLimitStore($clock, self::phpRedisShapedHealth());
        $reader = new RateLimitOperationalReader(
            new DeviceIdentityResolver(new FingerprintHasher('test-secret')),
            $store,
            new InMemoryCircuitBreakerStore(),
            new DecayCalculator($clock),
            $clock,
            'test-secret',
            'prod',
        );

        $snapshot = $reader->read(
            new RateLimitContextDTO('203.0.113.10', 'Mozilla/5.0 Chrome/123', 'account-91'),
            new ApiHeavyProtectionPolicy(),
        );

        self::assertTrue($snapshot->backendHealthy);
    }

    public function testCircuitRecoversThroughBooleanTruePingWithoutWallClockWaiting(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new PhpRedisHealthRateLimitStore($clock, self::phpRedisShapedHealth());
        $circuitStore = new InMemoryCircuitBreakerStore();
        $emitter = new RecordingFailureSignalEmitter();
        $engine = $this->createEngine($clock, $store, $circuitStore, $emitter);
        $context = new RateLimitContextDTO('198.51.100.91', 'Mozilla/5.0', 'api-account-91');
        $openedAt = $clock->now()->getTimestamp();
        $circuitStore->save('api_heavy_protection', new CircuitBreakerStateDTO(
            FailureStateDTO::STATE_OPEN,
            [1, 2, 3],
            $openedAt,
            $openedAt,
            0,
            [$openedAt],
        ));

        $clock->setNow(new \DateTimeImmutable('@' . ($openedAt + 300)));
        $first = $engine->limit($context, RateLimitCommand::checkOnly('api_heavy_protection'));
        self::assertSame('DEGRADED_MODE', $first->failureMode);
        $halfOpen = $circuitStore->load('api_heavy_protection');
        self::assertNotNull($halfOpen);
        self::assertSame(FailureStateDTO::STATE_HALF_OPEN, $halfOpen->status);

        $clock->setNow(new \DateTimeImmutable('@' . ($openedAt + 420)));
        $second = $engine->limit($context, RateLimitCommand::checkOnly('api_heavy_protection'));
        self::assertSame('NORMAL', $second->failureMode);
        $closed = $circuitStore->load('api_heavy_protection');
        self::assertNotNull($closed);
        self::assertSame(FailureStateDTO::STATE_CLOSED, $closed->status);
        self::assertSame(2, $store->healthCalls);
        self::assertSame(
            [FailureSignalDTO::TYPE_CB_RECOVERED],
            array_values(array_map(
                static fn(FailureSignalDTO $signal): string => $signal->type,
                $emitter->getEmitted(),
            )),
        );
    }

    private static function phpRedisShapedHealth(): RedisFullCapabilityStore
    {
        return new RedisFullCapabilityStore(
            new CallableRedisCommandExecutor(static fn(array $command): mixed => $command === ['PING'] ? true : null),
            'issue-91',
        );
    }

    private function createEngine(
        FixedClock $clock,
        InMemoryRateLimitStore $store,
        InMemoryCircuitBreakerStore $circuitStore,
        RecordingFailureSignalEmitter $emitter,
    ): RateLimiterEngine {
        $correlationStore = new NullCorrelationStore();
        $pipeline = new EvaluationPipeline(
            $store,
            $correlationStore,
            new BudgetTracker($store, $clock),
            new AntiEquilibriumGate($correlationStore),
            new DecayCalculator($clock),
            new EphemeralBucket($correlationStore),
            'test-secret',
            'prod',
            $clock,
        );

        return new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher('test-secret')),
            $pipeline,
            new CircuitBreaker($circuitStore, $emitter, $clock),
            new FailureModeResolver(),
            $emitter,
            $clock,
            [new ApiHeavyProtectionPolicy()],
        );
    }
}

/**
 * In-memory enforcement state whose only Redis-backed behavior is the real
 * RedisFullCapabilityStore::isHealthy() under an ext-redis-shaped reply.
 */
final class PhpRedisHealthRateLimitStore extends InMemoryRateLimitStore
{
    public int $healthCalls = 0;

    public function __construct(FixedClock $clock, private readonly RedisFullCapabilityStore $health)
    {
        parent::__construct($clock);
    }

    public function isHealthy(): bool
    {
        $this->healthCalls++;

        return $this->health->isHealthy();
    }
}
