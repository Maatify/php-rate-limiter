<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Clock;

/** Measures elapsed test harness time without depending on the wall clock. */
final class MonotonicClock
{
    public static function nanoseconds(): int|float
    {
        /** @var int|float|false $sample hrtime() documents false when the clock is unavailable. */
        $sample = hrtime(true);
        if ($sample === false) {
            throw new \RuntimeException('The monotonic clock is unavailable.');
        }

        // On PHP32 hrtime(true) returns float. Casting the sample to int would overflow.
        return $sample;
    }

    public static function elapsedSeconds(int|float $start): float
    {
        return (self::nanoseconds() - $start) / 1_000_000_000;
    }
}
