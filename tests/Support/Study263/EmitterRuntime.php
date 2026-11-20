<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Study263;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\Study262\FallbackStudyRuntime;
use Maatify\RateLimiter\Tests\Support\Study262\OutageRateLimitStore;

/**
 * #263 study: a public runtime on a typed-outage backend whose FailureSignalEmitterInterface is supplied by the caller, so what the
 * library hands to the Host's emitter can be observed. The policy and stores come from the #262 study support.
 */
final class EmitterRuntime
{
    public static function build(string $policyName, FailureSignalEmitterInterface $emitter): CompositeRateLimiterRuntimeInterface
    {
        $clock = new FixedClock(FallbackStudyRuntime::NOW);

        return (new RateLimiterBuilder(
            new RateLimiterConfig('study-263-key-secret', 'study-263-fingerprint-secret', 'production'),
            new OutageRateLimitStore(true, $clock),
            new StatefulInMemoryCorrelationStore($clock),
            new InMemoryCircuitBreakerStore(),
            $emitter,
        ))
            ->withClock($clock)
            ->withPolicy(FallbackStudyRuntime::policy($policyName, 2))
            ->build();
    }
}
