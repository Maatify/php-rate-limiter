<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Builder;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Config\SimpleThrottlePolicyInterface;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Service\RateLimiterRuntimeInterface;
use Maatify\RateLimiter\Service\SimpleRateLimiterInterface;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class RateLimiterBuilderSimpleThrottleTest extends TestCase
{
    private RateLimiterConfig $config;
    private FixedClock $clock;
    private RateLimitStoreInterface $rateLimitStore;
    private CorrelationStoreInterface $correlationStore;
    private CircuitBreakerStoreInterface $circuitBreakerStore;
    private FailureSignalEmitterInterface $failureSignalEmitter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->config = new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod');
        $this->rateLimitStore = new InMemoryRateLimitStore($this->clock);
        $this->correlationStore = new StatefulInMemoryCorrelationStore($this->clock);
        $this->circuitBreakerStore = new InMemoryCircuitBreakerStore();
        $this->failureSignalEmitter = new RecordingFailureSignalEmitter();
    }

    public function testBuildResultSatisfiesEveryComposedContract(): void
    {
        $runtime = $this->builder()->build();

        self::assertInstanceOf(CompositeRateLimiterRuntimeInterface::class, $runtime);
        self::assertInstanceOf(RateLimiterRuntimeInterface::class, $runtime);
        self::assertInstanceOf(SimpleRateLimiterInterface::class, $runtime);
    }

    public function testNoSimplePolicyRegisteredLeavesTheScoreRuntimeFullyFunctional(): void
    {
        $runtime = $this->builder()->build();

        $result = $runtime->limit(
            new RateLimitContextDTO('192.0.2.10', 'Mozilla/5.0', 'account-1', ['fp' => 'one']),
            RateLimitCommand::checkOnly('login_protection'),
        );

        self::assertInstanceOf(RateLimitResultDTO::class, $result);
    }

    public function testSameNameSimplePolicyReplacesThePreviousRegistration(): void
    {
        $original = new FixedWindowThrottlePolicy('checkout', 1, 60);
        $replacement = new FixedWindowThrottlePolicy('checkout', 5, 120);

        $runtime = $this->builder()
            ->withSimpleThrottlePolicy($original)
            ->withSimpleThrottlePolicy($replacement)
            ->build();

        $result = $runtime->consume('checkout', 'subject-1');

        self::assertTrue($result->allowed);
        self::assertSame(5, $result->limit);
        self::assertSame(4, $result->remaining);
    }

    public function testNewNameSimplePolicyIsAppendedAlongsideExisting(): void
    {
        $checkout = new FixedWindowThrottlePolicy('checkout', 1, 60);
        $search = new FixedWindowThrottlePolicy('search', 1, 60);

        $runtime = $this->builder()
            ->withSimpleThrottlePolicy($checkout)
            ->withSimpleThrottlePolicy($search)
            ->build();

        $checkoutResult = $runtime->consume('checkout', 'subject-1');
        $searchResult = $runtime->consume('search', 'subject-1');

        self::assertTrue($checkoutResult->allowed);
        self::assertTrue($searchResult->allowed);
    }

    public function testUnregisteredSimplePolicyNameFailsFast(): void
    {
        $runtime = $this->builder()->build();

        $this->expectException(\Maatify\RateLimiter\Exception\RateLimiterException::class);
        $runtime->consume('never_registered', 'subject-1');
    }

    public function testSimpleThrottleStateIsIndependentFromScoreRuntimeState(): void
    {
        $runtime = $this->builder()
            ->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('login_protection', 2, 60))
            ->build();

        $context = new RateLimitContextDTO('198.51.100.10', 'Mozilla/5.0', 'account-shared');

        $scoreResult = $runtime->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $simpleFirst = $runtime->consume('login_protection', 'account-shared');
        $simpleSecond = $runtime->consume('login_protection', 'account-shared');
        $simpleThird = $runtime->consume('login_protection', 'account-shared');

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $scoreResult->decision);
        self::assertTrue($simpleFirst->allowed);
        self::assertTrue($simpleSecond->allowed);
        self::assertFalse($simpleThird->allowed, 'Reusing a score policy name for a simple policy must not share state with score enforcement.');
    }

    private function builder(): RateLimiterBuilder
    {
        return new RateLimiterBuilder(
            $this->config,
            $this->rateLimitStore,
            $this->correlationStore,
            $this->circuitBreakerStore,
            $this->failureSignalEmitter,
        );
    }
}
