<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Counts the reads of a delegate clock, to prove how often a runtime reads
 * the Host clock for one call.
 */
final class CountingClock implements ClockInterface
{
    private int $reads = 0;

    public function __construct(private readonly ClockInterface $inner) {}

    public function reads(): int
    {
        return $this->reads;
    }

    public function now(): DateTimeImmutable
    {
        $this->reads++;

        return $this->inner->now();
    }

    public function getTimezone(): DateTimeZone
    {
        return $this->inner->getTimezone();
    }
}
