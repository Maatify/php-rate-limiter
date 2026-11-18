<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Ownership;

use Maatify\SharedCommon\Contracts\ClockInterface;

/** A Host clock that counts its readings and lets the probe re-enter or suspend inside `now()` (DEC-041). */
final class ProbeClock implements ClockInterface
{
    public function __construct(private readonly CrossingProbe $probe) {}

    public function now(): \DateTimeImmutable
    {
        $this->probe->reads++;
        $this->probe->suspendHere();
        $this->probe->fire();

        return new \DateTimeImmutable('@1000000');
    }

    public function getTimezone(): \DateTimeZone
    {
        return new \DateTimeZone('UTC');
    }
}
