<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
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
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\NullCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\ClaimFailureRateLimitStore;
use PHPUnit\Framework\TestCase;

final class RateLimiterEngineClaimFailureTest extends TestCase
{
    public function testRuntimeBackendFailureIsReportedOnceAndPropagates(): void
    {
        $this->assertClaimFailureIsReportedOnce(new \RuntimeException('Redis client unavailable'));
    }

    public function testMalformedBackendFailureIsReportedOnceAndPropagates(): void
    {
        $this->assertClaimFailureIsReportedOnce(new \UnexpectedValueException('malformed lifecycle state'));
    }

    public function testStaleLifecycleMissReturnsFalseWithoutCircuitFailure(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new \Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore($clock);
        $circuitStore = new InMemoryCircuitBreakerStore();
        $engine = $this->engine($store, $clock, $circuitStore);
        $context = new RateLimitContextDTO('198.51.100.40', 'Mozilla/5.0 stable', 'claim-stale', ['device' => 'stable']);

        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $hard = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        self::assertSame('HARD_BLOCK', $hard->decision);

        $clock->setNow($clock->now()->modify('+61 seconds'));
        self::assertFalse($engine->claimPostPunishmentReentry($context, 'login_protection', str_repeat('a', 32)));
        self::assertSame(0, $circuitStore->saveCount());
    }

    public function testIndependentK2HardBlockSuppressesPostPunishmentMetadata(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new \Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore($clock);
        $engine = $this->engine($store, $clock, new InMemoryCircuitBreakerStore());
        $context = new RateLimitContextDTO('198.51.100.42', 'Mozilla/5.0 stable', 'metadata-suppression', ['device' => 'stable']);

        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $hard = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        self::assertSame('HARD_BLOCK', $hard->decision);

        $clock->setNow($clock->now()->modify('+61 seconds'));
        $k2 = hash_hmac(
            'sha256',
            'login_protection:rate_limiter:k2:v2:prod:198.51.100.42:other/0',
            'test_secret',
        );
        $store->block($k2, 2, 600);

        $result = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));
        self::assertSame('HARD_BLOCK', $result->decision);
        self::assertNull($result->metadata?->postPunishmentReentry);
    }

    private function assertClaimFailureIsReportedOnce(\Exception $failure): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new ClaimFailureRateLimitStore($clock, $failure);
        $circuitStore = new InMemoryCircuitBreakerStore();
        $engine = $this->engine($store, $clock, $circuitStore);

        $this->expectExceptionObject($failure);
        try {
            $engine->claimPostPunishmentReentry(
                new RateLimitContextDTO('198.51.100.41', 'Mozilla/5.0 stable', 'claim-failure', ['device' => 'stable']),
                'login_protection',
                str_repeat('b', 32),
            );
        } finally {
            self::assertSame(1, $circuitStore->saveCount());
        }
    }

    private function engine(
        RateLimitStoreInterface $store,
        FixedClock $clock,
        InMemoryCircuitBreakerStore $circuitStore,
    ): RateLimiterEngine {
        $emitter = new RecordingFailureSignalEmitter();
        $pipeline = new EvaluationPipeline(
            $store,
            new NullCorrelationStore(),
            new BudgetTracker($store, $clock),
            new AntiEquilibriumGate(new NullCorrelationStore()),
            new DecayCalculator($clock),
            new EphemeralBucket(new NullCorrelationStore()),
            'test_secret',
            'prod',
            $clock,
        );

        return new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher('test_secret')),
            $pipeline,
            new CircuitBreaker($circuitStore, $emitter, $clock),
            new FailureModeResolver(),
            $emitter,
            $clock,
            [new LoginProtectionPolicy()],
        );
    }
}
