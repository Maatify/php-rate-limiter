<?php

declare(strict_types=1);

namespace ConsumerVerification;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\SharedCommon\Contracts\ClockInterface;

final class FixedClock implements ClockInterface
{
    public function __construct(private readonly DateTimeImmutable $currentTime) {}

    public function now(): DateTimeImmutable
    {
        return $this->currentTime;
    }

    public function getTimezone(): DateTimeZone
    {
        return $this->currentTime->getTimezone();
    }
}
