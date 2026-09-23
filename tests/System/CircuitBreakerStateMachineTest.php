<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\FailureStateDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface;
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
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\NullCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerStateMachineTest extends TestCase
{
    private FixedClock $clock;
    private InMemoryCircuitBreakerStore $store;
    private RecordingFailureSignalEmitter $emitter;
    private CircuitBreaker $circuitBreaker;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new InMemoryCircuitBreakerStore();
        $this->emitter = new RecordingFailureSignalEmitter();
        $this->circuitBreaker = new CircuitBreaker($this->store, $this->emitter, $this->clock);
    }

    public function testProbeLeaseIsAtomicAndPolicyScoped(): void
    {
        $now = $this->clock->now()->getTimestamp();

        self::assertTrue($this->store->acquireProbeLease('one', $now, 120));
        self::assertFalse($this->store->acquireProbeLease('one', $now + 119, 120));
        self::assertTrue($this->store->acquireProbeLease('one', $now + 120, 120));
        self::assertTrue($this->store->acquireProbeLease('two', $now, 120));
        self::assertSame($now + 240, $this->store->probeLeaseExpiresAt('one'));
        self::assertSame($now + 120, $this->store->probeLeaseExpiresAt('two'));
        self::assertSame(3, $this->store->probeAcquisitionCount());
    }

    public function testThirdFailureTripsOpenAndSignalsOnce(): void
    {
        $this->circuitBreaker->reportFailure('api');
        $this->circuitBreaker->reportFailure('api');

        self::assertSame(FailureStateDTO::STATE_CLOSED, $this->circuitBreaker->getState('api')->state);

        $this->circuitBreaker->reportFailure('api');

        self::assertSame(FailureStateDTO::STATE_OPEN, $this->circuitBreaker->getState('api')->state);
        self::assertSame([FailureSignalDTO::TYPE_CB_OPENED], $this->signalTypes());
        $state = $this->store->load('api');
        self::assertNotNull($state);
        self::assertCount(1, $state->reEntries);
    }

    public function testFirstHealthyProbeEntersHalfOpenAndSecondProbeCloses(): void
    {
        $openedAt = $this->clock->now()->getTimestamp();
        $this->store->save('api', new CircuitBreakerStateDTO(
            FailureStateDTO::STATE_OPEN,
            [1, 2, 3],
            $openedAt,
            $openedAt,
            0,
            [$openedAt],
        ));

        $this->clock->setNow(new \DateTimeImmutable('@' . ($openedAt + 300)));
        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', static fn(): bool => true));
        self::assertSame(FailureStateDTO::STATE_HALF_OPEN, $this->circuitBreaker->getState('api')->state);
        self::assertSame($openedAt + 300, $this->store->load('api')?->lastSuccess);
        self::assertSame([], $this->signalTypes());

        $this->clock->setNow(new \DateTimeImmutable('@' . ($openedAt + 420)));
        self::assertTrue($this->circuitBreaker->attemptRecoveryProbe('api', static fn(): bool => true));
        self::assertSame(FailureStateDTO::STATE_CLOSED, $this->circuitBreaker->getState('api')->state);
        self::assertSame(
            [FailureSignalDTO::TYPE_CB_RECOVERED],
            $this->signalTypes(),
        );
    }

    public function testOpenProbeFailureRestartsOpenWithoutReEntry(): void
    {
        $openedAt = $this->clock->now()->getTimestamp();
        $this->store->save('api', new CircuitBreakerStateDTO(
            FailureStateDTO::STATE_OPEN,
            [1, 2, 3],
            $openedAt,
            $openedAt,
            0,
            [$openedAt],
        ));

        $this->clock->setNow(new \DateTimeImmutable('@' . ($openedAt + 300)));
        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', static fn(): bool => false));

        $state = $this->store->load('api');
        self::assertNotNull($state);
        self::assertSame(FailureStateDTO::STATE_OPEN, $state->status);
        self::assertSame($openedAt + 300, $state->openSince);
        self::assertSame([$openedAt], $state->reEntries);
        self::assertSame([], $this->signalTypes());
    }

    public function testReportSuccessCannotRecoverOpenOrHalfOpen(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->store->save('api', new CircuitBreakerStateDTO(
            FailureStateDTO::STATE_OPEN,
            [1, 2, 3],
            $now,
            $now,
            0,
            [$now],
        ));

        $this->circuitBreaker->reportSuccess('api');
        self::assertSame(FailureStateDTO::STATE_OPEN, $this->circuitBreaker->getState('api')->state);

        $this->store->save('api', new CircuitBreakerStateDTO(
            FailureStateDTO::STATE_HALF_OPEN,
            [1, 2, 3],
            $now,
            $now,
            $now,
            [$now],
        ));
        $this->circuitBreaker->reportSuccess('api');

        self::assertSame(FailureStateDTO::STATE_HALF_OPEN, $this->circuitBreaker->getState('api')->state);
    }

    public function testMissingProbeCapabilityFailsOnlyWhenRecoveryIsEligible(): void
    {
        $baseStore = new BaseOnlyCircuitBreakerStore();
        $baseStore->save('api', new CircuitBreakerStateDTO(
            FailureStateDTO::STATE_OPEN,
            [],
            0,
            $this->clock->now()->getTimestamp(),
            0,
            [],
        ));
        $circuitBreaker = new CircuitBreaker($baseStore, $this->emitter, $this->clock);

        self::assertFalse($circuitBreaker->attemptRecoveryProbe('api', static fn(): bool => false));

        $this->clock->setNow(new \DateTimeImmutable('@' . ($this->clock->now()->getTimestamp() + 300)));
        $this->expectException(RateLimiterException::class);
        $circuitBreaker->attemptRecoveryProbe('api', static fn(): bool => true);
    }

    public function testEngineShortCircuitsBeforeDeviceResolutionAndResumesAfterRecovery(): void
    {
        $store = new TrackingRateLimitStore($this->clock);
        $resolver = new TrackingDeviceIdentityResolver(new FingerprintHasher('test_secret'));
        $engine = $this->createEngine($store, $resolver);
        $openedAt = $this->clock->now()->getTimestamp();
        $this->store->save('api_heavy_protection', new CircuitBreakerStateDTO(
            FailureStateDTO::STATE_OPEN,
            [1, 2, 3],
            $openedAt,
            $openedAt,
            0,
            [$openedAt],
        ));

        $this->clock->setNow(new \DateTimeImmutable('@' . ($openedAt + 300)));
        $first = $engine->limit(
            new RateLimitContextDTO('198.51.100.50', 'Mozilla/5.0', 'api-account'),
            RateLimitCommand::checkOnly('api_heavy_protection'),
        );

        self::assertSame('DEGRADED_MODE', $first->failureMode);
        self::assertSame(1, $store->healthCalls);
        self::assertSame(0, $resolver->resolveCalls);

        $this->clock->setNow(new \DateTimeImmutable('@' . ($openedAt + 420)));
        $second = $engine->limit(
            new RateLimitContextDTO('198.51.100.50', 'Mozilla/5.0', 'api-account'),
            RateLimitCommand::checkOnly('api_heavy_protection'),
        );

        self::assertSame('NORMAL', $second->failureMode);
        self::assertSame(2, $store->healthCalls);
        self::assertSame(1, $resolver->resolveCalls);
        self::assertSame(1, count(array_filter(
            $this->emitter->getEmitted(),
            static fn(FailureSignalDTO $signal): bool => $signal->type === FailureSignalDTO::TYPE_CB_RECOVERED,
        )));
    }

    public function testGuardIsAuthoritativeAndRetryAfterCountsDown(): void
    {
        $this->tripAndOpen();
        $this->completeHealthyIntervalAndFailProbe();
        $this->completeHealthyIntervalAndFailProbe();

        $state = $this->store->load('api');
        self::assertNotNull($state);
        self::assertSame(FailureStateDTO::STATE_OPEN, $state->status);
        self::assertSame($this->clock->now()->getTimestamp() + 600, $state->failClosedUntil);
        self::assertTrue($this->circuitBreaker->isReEntryGuardViolated('api'));
        self::assertSame(600, $this->circuitBreaker->getReEntryGuardRemaining('api'));

        $this->clock->setNow(new \DateTimeImmutable('@' . ($this->clock->now()->getTimestamp() + 540)));
        self::assertSame(60, $this->circuitBreaker->getReEntryGuardRemaining('api'));
        $this->circuitBreaker->reportSuccess('api');
        self::assertSame(60, $this->circuitBreaker->getReEntryGuardRemaining('api'));
    }

    public function testEngineGuardPreflightSkipsProbeDeviceAndBackendAndUsesRemainingRetryAfter(): void
    {
        $store = new TrackingRateLimitStore($this->clock);
        $resolver = new TrackingDeviceIdentityResolver(new FingerprintHasher('test_secret'));
        $engine = $this->createEngine($store, $resolver);
        $now = $this->clock->now()->getTimestamp();
        $this->store->save('api_heavy_protection', new CircuitBreakerStateDTO(
            FailureStateDTO::STATE_CLOSED,
            [],
            0,
            0,
            0,
            [],
            $now + 600,
        ));

        $request = RateLimitCommand::checkOnly('api_heavy_protection');
        $context = new RateLimitContextDTO('198.51.100.51', 'Mozilla/5.0', 'guarded-account');
        $first = $engine->limit($context, $request);

        self::assertSame('FAIL_CLOSED', $first->failureMode);
        self::assertSame(600, $first->retryAfter);
        self::assertSame(0, $store->healthCalls);
        self::assertSame(0, $resolver->resolveCalls);

        $this->clock->setNow(new \DateTimeImmutable('@' . ($now + 540)));
        $second = $engine->limit($context, $request);
        self::assertSame(60, $second->retryAfter);
        self::assertSame(0, $store->healthCalls);
        self::assertSame(0, $resolver->resolveCalls);
    }

    /**
     * @return list<string>
     */
    private function signalTypes(): array
    {
        return array_values(array_map(
            static fn(FailureSignalDTO $signal): string => $signal->type,
            $this->emitter->getEmitted(),
        ));
    }

    private function tripAndOpen(): void
    {
        $this->circuitBreaker->reportFailure('api');
        $this->circuitBreaker->reportFailure('api');
        $this->circuitBreaker->reportFailure('api');
    }

    private function completeHealthyIntervalAndFailProbe(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->clock->setNow(new \DateTimeImmutable('@' . ($now + 300)));
        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', static fn(): bool => true));

        $now = $this->clock->now()->getTimestamp();
        $this->clock->setNow(new \DateTimeImmutable('@' . ($now + 120)));
        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', static fn(): bool => false));
    }

    private function createEngine(
        TrackingRateLimitStore $store,
        TrackingDeviceIdentityResolver $resolver,
    ): RateLimiterEngine {
        $correlationStore = new NullCorrelationStore();
        $pipeline = new EvaluationPipeline(
            $store,
            $correlationStore,
            new BudgetTracker($store, $this->clock),
            new AntiEquilibriumGate($correlationStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($correlationStore),
            'test_secret',
            'prod',
            $this->clock,
        );

        return new RateLimiterEngine(
            $resolver,
            $pipeline,
            $this->circuitBreaker,
            new FailureModeResolver(),
            $this->emitter,
            $this->clock,
            [new ApiHeavyProtectionPolicy()],
        );
    }
}

final class TrackingRateLimitStore extends InMemoryRateLimitStore
{
    public int $healthCalls = 0;

    public bool $healthy = true;

    public function isHealthy(): bool
    {
        $this->healthCalls++;

        return $this->healthy;
    }
}

final class TrackingDeviceIdentityResolver extends DeviceIdentityResolver
{
    public int $resolveCalls = 0;

    public function resolve(RateLimitContextDTO $context): \Maatify\RateLimiter\DTO\DeviceIdentityDTO
    {
        $this->resolveCalls++;

        return parent::resolve($context);
    }
}

final class BaseOnlyCircuitBreakerStore implements CircuitBreakerStoreInterface
{
    /** @var array<string, CircuitBreakerStateDTO> */
    private array $states = [];

    public function load(string $policyName): ?CircuitBreakerStateDTO
    {
        return $this->states[$policyName] ?? null;
    }

    public function save(string $policyName, CircuitBreakerStateDTO $state): void
    {
        $this->states[$policyName] = $state;
    }
}
