<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Redis;

/**
 * Exact arithmetic on non-negative canonical decimal strings, for test fixtures whose values (epoch milliseconds, Redis absolute
 * expiries above 2^53) do not fit a native `int` on a 32-bit PHP build and must never pass through a float (#234).
 *
 * A Redis integer reply that is not a native `int` arrives as a decimal string (see `RespRedisCommandExecutor`); `fromReply()` accepts both.
 * No `bcmath` is assumed: the actual 32-bit image does not ship it.
 */
final class ExactDecimal
{
    /** The decimal of a Redis integer reply (`int`, or a digit string past the native range). */
    public static function fromReply(mixed $reply): string
    {
        if (is_int($reply) && $reply >= 0) {
            return (string) $reply;
        }
        if (is_string($reply) && preg_match('/^(0|[1-9][0-9]*)$/', $reply) === 1) {
            return $reply;
        }

        throw new \InvalidArgumentException('Not a non-negative integer reply: ' . var_export($reply, true));
    }

    /** -1, 0 or 1. */
    public static function compare(string $a, string $b): int
    {
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
    }

    public static function add(string $a, string $b): string
    {
        $a = strrev($a);
        $b = strrev($b);
        $length = max(strlen($a), strlen($b));
        $carry = 0;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $sum = (int) ($a[$i] ?? '0') + (int) ($b[$i] ?? '0') + $carry;
            $out .= (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }
        if ($carry > 0) {
            $out .= (string) $carry;
        }

        return strrev($out);
    }

    /** `$a - $b`, with `$a >= $b`. */
    public static function subtract(string $a, string $b): string
    {
        if (self::compare($a, $b) < 0) {
            throw new \InvalidArgumentException('The result would be negative.');
        }
        $a = strrev($a);
        $b = strrev($b);
        $borrow = 0;
        $out = '';
        for ($i = 0; $i < strlen($a); $i++) {
            $difference = (int) $a[$i] - (int) ($b[$i] ?? '0') - $borrow;
            $borrow = $difference < 0 ? 1 : 0;
            $out .= (string) ($difference + 10 * $borrow);
        }

        return ltrim(strrev($out), '0') ?: '0';
    }

    /** The decimal of `$whole * 1000` (seconds to milliseconds), exact. */
    public static function thousand(string $whole): string
    {
        return $whole === '0' ? '0' : $whole . '000';
    }

    /** @param non-empty-list<string> $values */
    public static function min(array $values): string
    {
        $least = $values[0];
        foreach ($values as $value) {
            if (self::compare($value, $least) < 0) {
                $least = $value;
            }
        }

        return $least;
    }
}
