<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\RateLimitOperationalReader;
use Maatify\RateLimiter\Service\SimpleRateLimitOperationalReader;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\ThrowingRateLimitStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-26 (#185, DEC-032): `buildOperationalReader()` requires the read-only block and budget reads. A store without
 * `OperationalReadOnlyStoreInterface` fails there, with a message that names the interface; the composition never falls back
 * to `checkBlock()` / `getBudget()`, which delete an expired key as they read it. The readers' own constructors are unchanged
 * (DEC-012 §4), as DEC-031 leaves the low-level constructors.
 */
#[Group('f-185-26')]
final class OperationalReadOnlyPreflightTest extends TestCase
{
    private const MESSAGE = 'Operational Read requires a store implementing OperationalReadOnlyStoreInterface (#185, DEC-032).';

    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-11-05 12:00:00');
    }

    private function builder(RateLimitStoreInterface $store): RateLimiterBuilder
    {
        return (new RateLimiterBuilder(
            new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod'),
            $store,
            new StatefulInMemoryCorrelationStore($this->clock),
            new InMemoryCircuitBreakerStore(),
            new RecordingFailureSignalEmitter(),
        ))->withClock($this->clock);
    }

    public function testBuildOperationalReaderRequiresTheReadOnlyReads(): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage(self::MESSAGE);

        $this->builder(new ThrowingRateLimitStore())->buildOperationalReader();
    }

    /** DEC-012 §4 (the DEC-031 pattern): a reader built through its constructor over a store without them still builds. */
    public function testTheReadersBuiltDirectlyKeepThePreviousReads(): void
    {
        new RateLimitOperationalReader(
            new DeviceIdentityResolver(new FingerprintHasher('fingerprint-secret')),
            new ThrowingRateLimitStore(),
            new InMemoryCircuitBreakerStore(),
            new DecayCalculator($this->clock),
            $this->clock,
            'key-secret',
            'prod',
        );
        new SimpleRateLimitOperationalReader([new FixedWindowThrottlePolicy('checkout', 2, 60)], new ThrowingRateLimitStore(), $this->clock, 'key-secret', 'prod');

        $this->addToAssertionCount(1);
    }

    public function testControlAStoreWithTheReadOnlyReadsBuildsTheReader(): void
    {
        $this->builder(new InMemoryRateLimitStore($this->clock))->buildOperationalReader();

        $this->addToAssertionCount(1);
    }
}
