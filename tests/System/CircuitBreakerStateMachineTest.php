<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
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

    public function testFailuresOutsideRollingTenSecondWindowAreDroppedWithoutOpening(): void
    {
        $startedAt = $this->clock->now()->getTimestamp();
        $this->circuitBreaker->reportFailure('api');

        $this->clock->setNow(new \DateTimeImmutable('@' . ($startedAt + 11)));
        $this->circuitBreaker->reportFailure('api');

        $this->clock->setNow(new \DateTimeImmutable('@' . ($startedAt + 22)));
        $this->circuitBreaker->reportFailure('api');

        $state = $this->store->load('api');
        self::assertNotNull($state);
        self::assertSame(FailureStateDTO::STATE_CLOSED, $state->status);
        self::assertSame([$startedAt + 22], $state->failures);
        self::assertSame($startedAt + 22, $state->lastFailure);
        self::assertSame([], $this->signalTypes());
    }

    public function testRollingTenSecondBoundaryRetainsFailureAtInclusiveBoundary(): void
    {
        $startedAt = $this->clock->now()->getTimestamp();
        $this->circuitBreaker->reportFailure('api');

        $this->clock->setNow(new \DateTimeImmutable('@' . ($startedAt + 10)));
        $this->circuitBreaker->reportFailure('api');

        $state = $this->store->load('api');
        self::assertNotNull($state);
        self::assertSame(FailureStateDTO::STATE_CLOSED, $state->status);
        self::assertSame([$startedAt, $startedAt + 10], $state->failures);
        self::assertSame([], $this->signalTypes());
    }

    public function testOpenBeforeMinimumDurationDoesNotAcquireLeaseOrProbe(): void
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
        $saveCount = $this->store->saveCount();
        $probeCalls = 0;

        $this->clock->setNow(new \DateTimeImmutable('@' . ($openedAt + 299)));
        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', function () use (&$probeCalls): bool {
            $probeCalls++;

            return true;
        }));

        self::assertSame(0, $probeCalls);
        self::assertSame(0, $this->store->probeAcquisitionCount());
        self::assertSame($saveCount, $this->store->saveCount());
    }

    public function testActiveProbeLeaseSuppressesASecondHealthCallback(): void
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
        self::assertTrue($this->store->acquireProbeLease('api', $openedAt + 300, 120));
        $saveCount = $this->store->saveCount();
        $probeCalls = 0;

        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', function () use (&$probeCalls): bool {
            $probeCalls++;

            return true;
        }));

        self::assertSame(0, $probeCalls);
        self::assertSame(1, $this->store->probeAcquisitionCount());
        self::assertSame($saveCount, $this->store->saveCount());
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

    public function testProbeTransitionsUseCompletionTimestampAndFullHealthyInterval(): void
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
        $probeStartedAt = $this->clock->now()->getTimestamp();
        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', function () use ($probeStartedAt): bool {
            $this->clock->setNow(new \DateTimeImmutable('@' . ($probeStartedAt + 17)));

            return true;
        }));

        $state = $this->store->load('api');
        self::assertNotNull($state);
        self::assertSame($probeStartedAt + 17, $state->lastSuccess);

        $this->clock->setNow(new \DateTimeImmutable('@' . ($probeStartedAt + 17 + 119)));
        $probeCalls = 0;
        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', function () use (&$probeCalls): bool {
            $probeCalls++;

            return true;
        }));
        self::assertSame(0, $probeCalls);

        $this->clock->setNow(new \DateTimeImmutable('@' . ($probeStartedAt + 17 + 120)));
        self::assertTrue($this->circuitBreaker->attemptRecoveryProbe('api', function () use (&$probeCalls): bool {
            $probeCalls++;

            return true;
        }));
        self::assertSame(1, $probeCalls);
        self::assertSame(FailureStateDTO::STATE_CLOSED, $this->circuitBreaker->getState('api')->state);
    }

    public function testThrownOpenProbeUsesCompletionTimestampForNewOpenEpoch(): void
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
        $probeStartedAt = $this->clock->now()->getTimestamp();

        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', function () use ($probeStartedAt): bool {
            $this->clock->setNow(new \DateTimeImmutable('@' . ($probeStartedAt + 23)));
            throw new \RuntimeException('health unavailable');
        }));

        $state = $this->store->load('api');
        self::assertNotNull($state);
        self::assertSame($probeStartedAt + 23, $state->openSince);
        self::assertSame(0, $state->lastSuccess);
        self::assertSame([$openedAt], $state->reEntries);
        self::assertSame([], $this->signalTypes());
    }

    public function testFailedOpenProbeWaitsFullIntervalFromCompletion(): void
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
        $probeStartedAt = $this->clock->now()->getTimestamp();

        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', function () use ($probeStartedAt): bool {
            $this->clock->setNow(new \DateTimeImmutable('@' . ($probeStartedAt + 23)));
            throw new \RuntimeException('health unavailable');
        }));

        $state = $this->store->load('api');
        self::assertNotNull($state);
        self::assertSame($probeStartedAt + 23, $state->openSince);
        $probeCalls = 0;
        $this->clock->setNow(new \DateTimeImmutable('@' . ($state->openSince + 299)));
        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', function () use (&$probeCalls): bool {
            $probeCalls++;

            return true;
        }));
        self::assertSame(0, $probeCalls);

        $this->clock->setNow(new \DateTimeImmutable('@' . ($state->openSince + 300)));
        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', function () use (&$probeCalls): bool {
            $probeCalls++;

            return true;
        }));
        self::assertSame(1, $probeCalls);
        $recovered = $this->store->load('api');
        self::assertNotNull($recovered);
        self::assertSame(FailureStateDTO::STATE_HALF_OPEN, $recovered->status);
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

    public function testHalfOpenFailureUsesCompletionTimestampAndStartsOneFreshOpenEpoch(): void
    {
        $openedAt = $this->clock->now()->getTimestamp();
        $this->store->save('api', new CircuitBreakerStateDTO(
            FailureStateDTO::STATE_HALF_OPEN,
            [1, 2, 3],
            $openedAt,
            $openedAt,
            $openedAt,
            [$openedAt],
        ));
        $this->clock->setNow(new \DateTimeImmutable('@' . ($openedAt + 120)));
        $probeStartedAt = $this->clock->now()->getTimestamp();

        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', function () use ($probeStartedAt): bool {
            $this->clock->setNow(new \DateTimeImmutable('@' . ($probeStartedAt + 19)));

            return false;
        }));

        $state = $this->store->load('api');
        self::assertNotNull($state);
        self::assertSame(FailureStateDTO::STATE_OPEN, $state->status);
        self::assertSame($probeStartedAt + 19, $state->openSince);
        self::assertSame(0, $state->lastSuccess);
        self::assertSame([$openedAt, $probeStartedAt + 19], $state->reEntries);
        self::assertSame([FailureSignalDTO::TYPE_CB_OPENED], $this->signalTypes());

        $signals = $this->signalTypes();
        $reEntries = $state->reEntries;
        $this->clock->setNow(new \DateTimeImmutable('@' . ($state->openSince + 1)));
        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', static fn(): bool => true));
        self::assertSame($reEntries, $this->store->load('api')?->reEntries);
        self::assertSame($signals, $this->signalTypes());

        $probeCalls = 0;
        $this->clock->setNow(new \DateTimeImmutable('@' . ($state->openSince + 299)));
        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', function () use (&$probeCalls): bool {
            $probeCalls++;

            return true;
        }));
        self::assertSame(0, $probeCalls);

        $this->clock->setNow(new \DateTimeImmutable('@' . ($state->openSince + 300)));
        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', function () use (&$probeCalls): bool {
            $probeCalls++;

            return true;
        }));
        self::assertSame(1, $probeCalls);
        /** @var CircuitBreakerStateDTO $recovered */
        $recovered = $this->store->load('api');
        self::assertSame(FailureStateDTO::STATE_HALF_OPEN, $recovered->status);
    }

    /**
     * @dataProvider guardedStates
     */
    public function testCircuitBreakerGuardBlocksRecoveryForEveryPersistedState(string $status): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->store->save('api', new CircuitBreakerStateDTO(
            $status,
            $status === FailureStateDTO::STATE_OPEN ? [1, 2, 3] : [],
            0,
            $status === FailureStateDTO::STATE_OPEN ? $now - 300 : 0,
            $status === FailureStateDTO::STATE_HALF_OPEN ? $now - 120 : 0,
            [],
            $now + 600,
        ));
        $before = $this->store->load('api');
        $saveCount = $this->store->saveCount();
        $probeCalls = 0;

        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', function () use (&$probeCalls): bool {
            $probeCalls++;

            return true;
        }));

        self::assertSame(0, $probeCalls);
        self::assertSame(0, $this->store->probeAcquisitionCount());
        self::assertSame($saveCount, $this->store->saveCount());
        self::assertSame($before, $this->store->load('api'));
        self::assertSame([], $this->signalTypes());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function guardedStates(): iterable
    {
        yield 'CLOSED' => [FailureStateDTO::STATE_CLOSED];
        yield 'OPEN' => [FailureStateDTO::STATE_OPEN];
        yield 'HALF_OPEN' => [FailureStateDTO::STATE_HALF_OPEN];
    }

    public function testActiveGuardPrecedesMissingProbeCapability(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $store = new BaseOnlyCircuitBreakerStore();
        $store->save('api', new CircuitBreakerStateDTO(
            FailureStateDTO::STATE_CLOSED,
            [],
            0,
            0,
            0,
            [],
            $now + 600,
        ));
        $before = $store->load('api');
        $probeCalls = 0;
        $circuitBreaker = new CircuitBreaker($store, $this->emitter, $this->clock);

        self::assertFalse($circuitBreaker->attemptRecoveryProbe('api', function () use (&$probeCalls): bool {
            $probeCalls++;

            return true;
        }));

        self::assertSame(0, $probeCalls);
        self::assertSame($before, $store->load('api'));
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
        $before = $baseStore->load('api');
        $probeCalls = 0;

        self::assertFalse($circuitBreaker->attemptRecoveryProbe('api', function () use (&$probeCalls): bool {
            $probeCalls++;

            return false;
        }));

        $this->clock->setNow(new \DateTimeImmutable('@' . ($this->clock->now()->getTimestamp() + 300)));
        $this->expectException(RateLimiterException::class);
        try {
            $circuitBreaker->attemptRecoveryProbe('api', function () use (&$probeCalls): bool {
                $probeCalls++;

                return true;
            });
        } finally {
            self::assertSame(0, $probeCalls);
            self::assertSame($before, $baseStore->load('api'));
        }
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

    public function testSubsequentOpenApiRequestsDoNotTouchNormalBackend(): void
    {
        $store = new TrackingRateLimitStore($this->clock);
        $store->healthy = false;
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

        $context = new RateLimitContextDTO('198.51.100.52', 'Mozilla/5.0', 'api-open-account');
        $request = RateLimitCommand::checkOnly('api_heavy_protection');

        $this->clock->setNow(new \DateTimeImmutable('@' . ($openedAt + 300)));
        $first = $engine->limit($context, $request);
        self::assertSame('DEGRADED_MODE', $first->failureMode);
        self::assertSame(1, $store->healthCalls);
        self::assertSame(0, $resolver->resolveCalls);

        $this->clock->setNow(new \DateTimeImmutable('@' . ($openedAt + 301)));
        $second = $engine->limit($context, $request);
        self::assertSame('DEGRADED_MODE', $second->failureMode);
        self::assertSame(1, $store->healthCalls);
        self::assertSame(0, $resolver->resolveCalls);
    }

    public function testFailureModeResolverMatrix(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $resolver = new FailureModeResolver();
        /** @var list<array{string, BlockPolicyInterface, string, int, string}> $cases */
        $cases = [
            ['guarded-closed', new ApiHeavyProtectionPolicy(), FailureStateDTO::STATE_CLOSED, $now + 600, 'FAIL_CLOSED'],
            ['guarded-open', new ApiHeavyProtectionPolicy(), FailureStateDTO::STATE_OPEN, $now + 600, 'FAIL_CLOSED'],
            ['guarded-half-open', new ApiHeavyProtectionPolicy(), FailureStateDTO::STATE_HALF_OPEN, $now + 600, 'FAIL_CLOSED'],
            ['open-no-guard', new ApiHeavyProtectionPolicy(), FailureStateDTO::STATE_OPEN, 0, 'DEGRADED_MODE'],
            ['half-open-no-guard', new ApiHeavyProtectionPolicy(), FailureStateDTO::STATE_HALF_OPEN, 0, 'DEGRADED_MODE'],
            ['closed-fail-open', new ApiHeavyProtectionPolicy(), FailureStateDTO::STATE_CLOSED, 0, 'FAIL_OPEN'],
            ['closed-fail-closed', new LoginProtectionPolicy(), FailureStateDTO::STATE_CLOSED, 0, 'FAIL_CLOSED'],
        ];

        foreach ($cases as [$label, $policy, $status, $failClosedUntil, $expected]) {
            $this->store->save($policy->getName(), new CircuitBreakerStateDTO(
                $status,
                $status === FailureStateDTO::STATE_OPEN ? [1, 2, 3] : [],
                0,
                $status === FailureStateDTO::STATE_OPEN ? $now : 0,
                $status === FailureStateDTO::STATE_HALF_OPEN ? $now : 0,
                [],
                $failClosedUntil,
            ));

            self::assertSame(
                $expected,
                $resolver->resolve($policy, $this->circuitBreaker),
                $label,
            );
        }
    }

    public function testReEntryWindowPrunesOldEntriesWithoutFalseGuardActivation(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->store->save('api', new CircuitBreakerStateDTO(
            FailureStateDTO::STATE_HALF_OPEN,
            [1, 2, 3],
            $now,
            $now,
            $now - 120,
            [$now - 1801, $now - 1800],
        ));

        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', static fn(): bool => false));

        $state = $this->store->load('api');
        self::assertNotNull($state);
        self::assertSame(FailureStateDTO::STATE_OPEN, $state->status);
        self::assertSame([$now - 1800, $now], $state->reEntries);
        self::assertFalse($this->circuitBreaker->isReEntryGuardViolated('api'));
        self::assertSame([], array_filter(
            $this->signalTypes(),
            static fn(string $type): bool => $type === FailureSignalDTO::TYPE_CB_RE_ENTRY_VIOLATION,
        ));
    }

    public function testGuardIsAuthoritativeAndRetryAfterCountsDown(): void
    {
        $this->tripAndOpen();
        $this->completeHealthyIntervalAndFailProbe();
        $secondReEntry = $this->completeHealthyIntervalAndFailProbe(17);

        $state = $this->store->load('api');
        self::assertNotNull($state);
        self::assertSame(FailureStateDTO::STATE_OPEN, $state->status);
        self::assertSame($secondReEntry['transitionAt'] + 600, $state->failClosedUntil);
        self::assertTrue($this->circuitBreaker->isReEntryGuardViolated('api'));
        self::assertSame(600, $this->circuitBreaker->getReEntryGuardRemaining('api'));
        self::assertCount(1, array_filter(
            $this->signalTypes(),
            static fn(string $type): bool => $type === FailureSignalDTO::TYPE_CB_RE_ENTRY_VIOLATION,
        ));

        $saveCount = $this->store->saveCount();
        $leaseCount = $this->store->probeAcquisitionCount();
        $signals = $this->signalTypes();
        $probeCalls = 0;
        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', function () use (&$probeCalls): bool {
            $probeCalls++;

            return true;
        }));
        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', function () use (&$probeCalls): bool {
            $probeCalls++;

            return true;
        }));
        self::assertSame(0, $probeCalls);
        self::assertSame($saveCount, $this->store->saveCount());
        self::assertSame($leaseCount, $this->store->probeAcquisitionCount());
        self::assertSame($signals, $this->signalTypes());

        $this->clock->setNow(new \DateTimeImmutable('@' . ($this->clock->now()->getTimestamp() + 540)));
        self::assertSame(60, $this->circuitBreaker->getReEntryGuardRemaining('api'));
        $this->circuitBreaker->reportSuccess('api');
        self::assertSame(60, $this->circuitBreaker->getReEntryGuardRemaining('api'));
    }

    public function testOpenRecoveryEligibilityReturnsAfterGuardExpires(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->store->save('api', new CircuitBreakerStateDTO(
            FailureStateDTO::STATE_OPEN,
            [1, 2, 3],
            $now,
            $now,
            0,
            [$now],
            $now + 600,
        ));

        $probeCalls = 0;
        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', function () use (&$probeCalls): bool {
            $probeCalls++;

            return true;
        }));
        self::assertSame(0, $probeCalls);
        self::assertSame(0, $this->store->probeAcquisitionCount());

        $this->clock->setNow(new \DateTimeImmutable('@' . ($now + 600)));
        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', function () use (&$probeCalls): bool {
            $probeCalls++;

            return true;
        }));
        self::assertSame(1, $probeCalls);
        self::assertSame(FailureStateDTO::STATE_HALF_OPEN, $this->store->load('api')?->status);
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

    /**
     * @return array{probeStartedAt:int, transitionAt:int}
     */
    private function completeHealthyIntervalAndFailProbe(int $failureProbeDelay = 0): array
    {
        $now = $this->clock->now()->getTimestamp();
        $this->clock->setNow(new \DateTimeImmutable('@' . ($now + 300)));
        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', static fn(): bool => true));

        $now = $this->clock->now()->getTimestamp();
        $this->clock->setNow(new \DateTimeImmutable('@' . ($now + 120)));
        $probeStartedAt = $this->clock->now()->getTimestamp();
        self::assertFalse($this->circuitBreaker->attemptRecoveryProbe('api', function () use ($failureProbeDelay, $probeStartedAt): bool {
            if ($failureProbeDelay > 0) {
                $this->clock->setNow(new \DateTimeImmutable('@' . ($probeStartedAt + $failureProbeDelay)));
            }

            return false;
        }));

        return [
            'probeStartedAt' => $probeStartedAt,
            'transitionAt' => $this->clock->now()->getTimestamp(),
        ];
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
