<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\BoundedCorrelationObservationDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\RequestScopedClock;
use Maatify\RateLimiter\Tests\Fixtures\S0Baseline\S0EvaluationPipeline;
use Maatify\RateLimiter\Tests\Fixtures\S0Baseline\S0LoginProtectionPolicy;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PHPUnit\Framework\TestCase;

/**
 * #160 fix (a): the bounded snapshot path reads the clock once per call.
 *
 * The step clock is the one of the #160 phase-1 measurement (M-EP-DET): the
 * first reading is second T, every later reading is T + 1, so a second
 * reading inside addBoundedSnapshot() crosses a second boundary.
 */
final class BoundedSnapshotSingleClockReadTest extends TestCase
{
    private const T = 1_800_000_000;
    private const INVALID_SNAPSHOT = 'Bounded correlation store returned an invalid snapshot.';

    public function testDirectlyBuiltPipelineReturnsAValidSnapshotWhenTheClockStepsBetweenReads(): void
    {
        $clock = new StepSecondClock(self::T);
        $pipeline = $this->newWorker(new FullCapabilityInMemoryStore(new FixedClock('@' . self::T)), $clock);

        $snapshot = $this->addBoundedSnapshot($pipeline);

        self::assertSame(['m160-member'], $snapshot->members);
        self::assertSame(self::T + 600, $snapshot->expiresAt);
        self::assertSame(1, $clock->reads);
    }

    public function testBuiltRuntimeLimitKeepsTheBuilderProtection(): void
    {
        $clock = new StepSecondClock(self::T);
        $runtime = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('m160-key-secret', 'm160-fingerprint-secret', 'prod'),
            new FullCapabilityInMemoryStore(new FixedClock('@' . self::T)),
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->build();

        $result = $runtime->limit(
            new RateLimitContextDTO('198.51.100.80', 'Chrome/123', 'account-a', ['device' => 'd-account-a']),
            RateLimitCommand::checkOnly('login_protection'),
        );

        self::assertInstanceOf(RateLimitResultDTO::class, $result);
        self::assertSame(1, $clock->reads);
    }

    /**
     * Wiring guard for S0 on a store that reads its own clock: store and
     * worker share one RequestScopedClock and process() runs inside holding(),
     * so the fixture's two readings are one reading.
     */
    public function testS0WorkerOnASharedHeldClockReadsTheHostClockOnce(): void
    {
        $host = new StepSecondClock(self::T);
        $scoped = new RequestScopedClock($host);
        $store = new FullCapabilityInMemoryStore($scoped);
        $worker = $this->s0Worker($store, $scoped);

        $result = $scoped->holding(fn(): RateLimitResultDTO => $worker->process(
            new S0LoginProtectionPolicy(),
            new RateLimitContextDTO('198.51.100.80', 'Chrome/123', 'account-s0', ['device' => 'stable']),
            RateLimitCommand::checkOnly('login_protection'),
            new DeviceIdentityDTO('s0-fingerprint', 'HIGH', true, false, 'chrome/123', true),
        ));

        self::assertInstanceOf(RateLimitResultDTO::class, $result);
        self::assertSame(1, $host->reads);
    }

    /**
     * Characterisation: the S0 fixture is the shipped S0 pipeline byte for
     * byte, so with two independent clocks (worker T, store T + 1) it still
     * rejects its own snapshot. The fixture is not changed by the fix.
     */
    public function testS0FixtureWithIndependentClocksStillRejectsTheSnapshot(): void
    {
        $store = new FullCapabilityInMemoryStore(new FixedClock('@' . (self::T + 1)));
        $worker = $this->s0Worker($store, new FixedClock('@' . self::T));

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage(self::INVALID_SNAPSHOT);

        (new \ReflectionMethod(S0EvaluationPipeline::class, 'addBoundedSnapshot'))
            ->invoke($worker, new BoundedCorrelationObservationDTO('m160-window', 'm160-member'), 600, 4);
    }

    private function addBoundedSnapshot(EvaluationPipeline $pipeline): \Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO
    {
        return (new \ReflectionMethod(EvaluationPipeline::class, 'addBoundedSnapshot'))
            ->invoke($pipeline, new BoundedCorrelationObservationDTO('m160-window', 'm160-member'), 600, 4);
    }

    private function newWorker(FullCapabilityInMemoryStore $store, ClockInterface $clock): EvaluationPipeline
    {
        return new EvaluationPipeline($store, $store, new BudgetTracker($store, $clock), new AntiEquilibriumGate($store), new DecayCalculator($clock), new EphemeralBucket($store), 'test_secret', 'prod', $clock, null);
    }

    private function s0Worker(FullCapabilityInMemoryStore $store, ClockInterface $clock): S0EvaluationPipeline
    {
        return new S0EvaluationPipeline($store, $store, new BudgetTracker($store, $clock), new AntiEquilibriumGate($store), new DecayCalculator($clock), new EphemeralBucket($store), 'test_secret', 'prod', $clock, null);
    }
}

/** Second T on the first reading, T + 1 on every later reading. */
final class StepSecondClock implements ClockInterface
{
    public int $reads = 0;

    public function __construct(private readonly int $t) {}

    public function now(): DateTimeImmutable
    {
        $second = $this->reads === 0 ? $this->t : $this->t + 1;
        $this->reads++;

        return new DateTimeImmutable('@' . $second);
    }

    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}
