<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use DateTimeImmutable;
use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * The caller's semantic time in epoch milliseconds as a canonical decimal string (#215, DEC-039).
 *
 * A millisecond epoch value (about 1.7e12) does not fit a native integer where `PHP_INT_SIZE` is 4,
 * so the decimal-millisecond store capabilities carry it as a string: `0` or digits with no leading
 * zero, at most 9007199254740991 (the exact integer range of Redis Lua, the same bound the Lua
 * scripts already enforce on their last argument). DEC-017 still applies: a negative value is a
 * contract violation.
 *
 * @internal Shared by the runtime dispatch and the bundled stores; not part of the supported API.
 */
final class CallerTimeMilliseconds
{
    /** The largest millisecond value, in decimal, that Redis Lua represents exactly (2^53 − 1). */
    public const MAXIMUM = '9007199254740991';

    private function __construct() {}

    /**
     * The exact canonical millisecond decimal of an instant. A negative instant yields a negative
     * decimal, which `validate()` rejects as DEC-017 requires, so the store classifies it.
     */
    public static function fromInstant(DateTimeImmutable $instant): string
    {
        $seconds = $instant->getTimestamp();
        if ($seconds < 0) {
            return '-1';
        }
        $milliseconds = (int) $instant->format('v');

        return $seconds === 0 ? (string) $milliseconds : $seconds . str_pad((string) $milliseconds, 3, '0', STR_PAD_LEFT);
    }

    /**
     * The value as a native integer for a store that only has the native-integer method.
     *
     * @throws RateLimiterException when the value is not representable as a native integer on this PHP build;
     *                              raised before the store is called, so the clock is never changed silently
     */
    public static function nativeInteger(string $milliseconds): int
    {
        $integer = filter_var($milliseconds, FILTER_VALIDATE_INT);
        if (! is_int($integer)) {
            throw new RateLimiterException('Caller time in milliseconds is not representable as a native integer on this PHP build; the store must implement the decimal-millisecond capability.');
        }

        return $integer;
    }

    /**
     * @throws RateLimiterException when the value is negative (DEC-017), not canonical, or above the exact range
     */
    public static function validate(string $milliseconds, string $label = 'Caller time'): string
    {
        if ($milliseconds !== '' && $milliseconds[0] === '-') {
            throw new RateLimiterException($label . ' must be a non-negative Unix timestamp.');
        }
        if (preg_match('/^(0|[1-9][0-9]*)$/', $milliseconds) !== 1) {
            throw new RateLimiterException($label . ' in milliseconds must be a canonical decimal integer.');
        }
        if (strlen($milliseconds) > 16 || (strlen($milliseconds) === 16 && $milliseconds > self::MAXIMUM)) {
            throw new RateLimiterException($label . ' in milliseconds is not representable.');
        }

        return $milliseconds;
    }

    /** The whole second of a validated millisecond decimal (the last three digits dropped). */
    public static function seconds(string $validated): string
    {
        return strlen($validated) <= 3 ? '0' : substr($validated, 0, -3);
    }
}
