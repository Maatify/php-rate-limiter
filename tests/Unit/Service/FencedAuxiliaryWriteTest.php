<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\FailureStateDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-17 / F-185-18 (#185, DEC-031): the fenced circuit-breaker save emits its signals only after a save that applied,
 * drops one failure without an exception when every attempt misses, and `build()` requires both capabilities.
 */
#[Group('f-185-17')]
#[Group('f-185-18')]
final class FencedAuxiliaryWriteTest extends TestCase
{
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-11-04 12:00:00');
    }

    public function testTheThirdFailureOpensTheCircuitAndEmitsOnceAfterTheSave(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        $emitter = new RecordingFailureSignalEmitter();
        $breaker = new CircuitBreaker($store, $emitter, $this->clock);

        $breaker->reportFailure('p');
        $breaker->reportFailure('p');
        self::assertCount(0, $emitter->getEmitted(), 'No signal before the circuit opens.');
        $breaker->reportFailure('p');

        self::assertSame(FailureStateDTO::STATE_OPEN, $store->load('p')?->status);
        $types = [];
        foreach ($emitter->getEmitted() as $signal) {
            self::assertInstanceOf(FailureSignalDTO::class, $signal);
            $types[] = $signal->type;
        }
        self::assertSame([FailureSignalDTO::TYPE_CB_OPENED], $types);
    }

    public function testEveryAttemptMissingDropsTheFailureWithoutAnExceptionOrASignal(): void
    {
        $store = new class extends InMemoryCircuitBreakerStore {
            public int $attempts = 0;

            public function saveIfVersion(string $policyName, string $expectedVersion, CircuitBreakerStateDTO $state): bool
            {
                $this->attempts++;
                // Another process saved in between, every time.
                $this->save($policyName, new CircuitBreakerStateDTO(FailureStateDTO::STATE_CLOSED, [1_793_793_600, 1_793_793_600], 1_793_793_600, 0, 0, [], 0));

                return false;
            }
        };
        $emitter = new RecordingFailureSignalEmitter();

        (new CircuitBreaker($store, $emitter, $this->clock))->reportFailure('p');

        self::assertSame(3, $store->attempts, 'Three fenced attempts.');
        self::assertSame(2, count($store->load('p')->failures ?? []), 'The dropped failure is not saved.');
        self::assertSame([], $emitter->getEmitted(), 'No signal for a transition that was not saved.');
    }

    private function builder(\Maatify\RateLimiter\Repository\RateLimitStoreInterface $rateLimitStore, \Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface $circuitBreakerStore): RateLimiterBuilder
    {
        return (new RateLimiterBuilder(
            new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod'),
            $rateLimitStore,
            new StatefulInMemoryCorrelationStore($this->clock),
            $circuitBreakerStore,
            new RecordingFailureSignalEmitter(),
        ))->withClock($this->clock);
    }

    public function testBuildRequiresTheFencedCircuitBreakerSave(): void
    {
        $probeOnly = new class implements \Maatify\RateLimiter\Repository\CircuitBreakerProbeStoreInterface {
            public function load(string $policyName): ?CircuitBreakerStateDTO
            {
                return null;
            }

            public function save(string $policyName, CircuitBreakerStateDTO $state): void {}

            public function acquireProbeLease(string $policyName, int $now, int $leaseSeconds): bool
            {
                return true;
            }
        };

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('The circuit-breaker store requires CircuitBreakerStateCasStoreInterface (#185, DEC-031).');

        $this->builder(new InMemoryRateLimitStore($this->clock), $probeOnly)->build();
    }

    public function testBuildRequiresTheMarkerReplaceForARepeatedMissingFingerprintPolicy(): void
    {
        $withoutReplace = $this->createStub(\Maatify\RateLimiter\Tests\Support\RateLimiter\LifecycleAndFencedScoreStoreInterface::class);

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('records repeated missing fingerprints and requires ScoreReplaceStoreInterface (#185, DEC-031).');

        $this->builder($withoutReplace, new InMemoryCircuitBreakerStore())->build();
    }

    public function testControlTheInMemoryStoresBuild(): void
    {
        $this->builder(new InMemoryRateLimitStore($this->clock), new InMemoryCircuitBreakerStore())->build();

        $this->addToAssertionCount(1);
    }
}
