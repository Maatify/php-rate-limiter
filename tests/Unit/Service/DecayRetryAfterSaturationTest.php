<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-14 (#185, LT-185-11a option (a)): `secondsUntilBelowThreshold()` computes `remainingPoints * interval`,
 * `- elapsed % interval` and `+ max(0, activePauseUntil - now)` with explicit bounds and saturates at `PHP_INT_MAX`. On
 * `4e90064` a score above `PHP_INT_MAX / interval` overflowed to a float and the `int` return type raised `TypeError`.
 */
#[Group('f-185-14')]
final class DecayRetryAfterSaturationTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /** @return iterable<string, array{int, int, int, int}> score, block level, active pause until, expected seconds */
    public static function boundaries(): iterable
    {
        $ip = intdiv(PHP_INT_MAX, 180);
        $ipLevel2 = intdiv(PHP_INT_MAX, 360);
        yield 'IP, intdiv(PHP_INT_MAX, 180) - 1' => [$ip - 1, 1, 0, ($ip - 1) * 180];
        yield 'IP, intdiv(PHP_INT_MAX, 180)' => [$ip, 1, 0, $ip * 180];
        yield 'IP, intdiv(PHP_INT_MAX, 180) + 1 saturates' => [$ip + 1, 1, 0, PHP_INT_MAX];
        yield 'IP at level 2 (interval 360), intdiv(PHP_INT_MAX, 360)' => [$ipLevel2, 2, 0, $ipLevel2 * 360];
        yield 'IP at level 2, intdiv(PHP_INT_MAX, 360) + 1 saturates' => [$ipLevel2 + 1, 2, 0, PHP_INT_MAX];
        yield 'IP at level 2, intdiv(PHP_INT_MAX, 180) saturates (twice the interval)' => [$ip, 2, 0, PHP_INT_MAX];
        yield 'an active pause that overflows the sum saturates' => [$ip, 1, self::NOW + (PHP_INT_MAX - $ip * 180) + 1, PHP_INT_MAX];
        yield 'an active pause that fits is added' => [$ip, 1, self::NOW + (PHP_INT_MAX - $ip * 180), PHP_INT_MAX];
        yield 'control: a small score is unchanged' => [10, 1, 0, 10 * 180];
    }

    #[DataProvider('boundaries')]
    public function testTheDurationIsExactOrSaturatesWithoutTypeError(int $score, int $level, int $activePauseUntil, int $expected): void
    {
        $calculator = new DecayCalculator(new FixedClock('@' . self::NOW));

        self::assertSame($expected, $calculator->secondsUntilBelowThreshold($score, self::NOW, $level, 'ip', 1, 0, $activePauseUntil));
    }
}
