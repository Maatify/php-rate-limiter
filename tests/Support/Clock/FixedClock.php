<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Clock;

use Maatify\SharedCommon\Contracts\ClockInterface;

class FixedClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(string $time = '2025-01-01 12:00:00')
    {
        $this->now = new \DateTimeImmutable($time);
    }

    public function setNow(\DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function sleep(int $seconds): void
    {
        // No-op
    }

    public function getTimezone(): \DateTimeZone
    {
        return $this->now->getTimezone();
    }
}
