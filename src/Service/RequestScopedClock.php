<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * One reading of the Host clock per runtime call (#153 phase 2, LT-P2-05).
 *
 * While `holding()` runs, every `now()` returns the single value read when it
 * started, so every timestamp a request stores or compares is the same
 * instant. Outside `holding()` it delegates to the Host clock unchanged.
 *
 * @internal Built by RateLimiterBuilder; not a public extension point.
 */
final class RequestScopedClock implements ClockInterface
{
    private ?DateTimeImmutable $held = null;

    public function __construct(private readonly ClockInterface $hostClock) {}

    /**
     * Run one runtime call on a single reading of the Host clock. A nested
     * call keeps the outer reading.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function holding(callable $operation): mixed
    {
        if ($this->held !== null) {
            return $operation();
        }

        $this->held = $this->hostClock->now();
        try {
            return $operation();
        } finally {
            $this->held = null;
        }
    }

    public function now(): DateTimeImmutable
    {
        return $this->held ?? $this->hostClock->now();
    }

    public function getTimezone(): DateTimeZone
    {
        return $this->hostClock->getTimezone();
    }
}
