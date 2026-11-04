<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Score arithmetic that stays a PHP int (#185, F-185-11). PHP turns an `int` overflow into a `float`, and a later
 * `(int)` cast narrows it to an arbitrary value (a negative score that is stored and allowed). Each operation here
 * checks the bound explicitly before computing and raises `RateLimiterException` (an input-contract failure, never a
 * backend failure) when the exact result is not representable.
 *
 * @internal
 */
final class ScoreArithmetic
{
    /** `$a * $b` for non-negative operands. */
    public static function product(int $a, int $b, string $what): int
    {
        if ($a < 0 || $b < 0) {
            throw new RateLimiterException('Score arithmetic: ' . $what . ' has a negative operand.');
        }
        if ($a !== 0 && $b > intdiv(PHP_INT_MAX, $a)) {
            throw self::overflow($what);
        }

        return $a * $b;
    }

    public static function sum(int $a, int $b, string $what): int
    {
        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b)) {
            throw self::overflow($what);
        }

        return $a + $b;
    }

    public static function difference(int $a, int $b, string $what): int
    {
        if (($b < 0 && $a > PHP_INT_MAX + $b) || ($b > 0 && $a < PHP_INT_MIN + $b)) {
            throw self::overflow($what);
        }

        return $a - $b;
    }

    private static function overflow(string $what): RateLimiterException
    {
        return new RateLimiterException('Score arithmetic overflow: ' . $what . ' is not representable as a PHP integer.');
    }
}
