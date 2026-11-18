<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Ownership;

use Maatify\SharedCommon\Contracts\ClockInterface;

/** A Host store that ALSO implements the clock interface (DEC-041, F266-14): its role in the graph is the store, so it stays protected. */
final class HostStoreWithClock extends HostStore implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@1000000');
    }

    public function getTimezone(): \DateTimeZone
    {
        return new \DateTimeZone('UTC');
    }
}
