<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Shared checked big-endian unsigned 32-bit length/count framing for canonical key preimages (DEC-047).
 *
 * Every length and count is checked against `0..4294967295` (`0..0xFFFFFFFF`) before `pack('N', ...)` so neither
 * silent modulo-2^32 wrap on 64-bit PHP nor float/narrowing packing on 32-bit PHP can occur. Lengths are byte
 * lengths (`strlen`), never character counts, and field bytes are never trimmed or normalized.
 *
 * @internal
 */
final class KeyPreimageFraming
{
    /** Largest unsigned 32-bit integer representable by `pack('N', ...)`. */
    public const MAX_U32 = 0xFFFFFFFF;

    private function __construct() {}

    /**
     * Encode an unsigned 32-bit integer in big-endian byte order after checking `0..4294967295`.
     *
     * @throws RateLimiterException before packing when `$value` is outside `0..4294967295`.
     */
    public static function encodeU32(int $value, string $context): string
    {
        if ($value < 0 || $value > self::MAX_U32) {
            throw new RateLimiterException("A {$context} length or count must lie within 0..4294967295.");
        }

        return pack('N', $value);
    }

    /**
     * Encode one opaque byte string as `u32be(strlen(field)) || field`.
     *
     * @param ?\Closure(string): int $length Internal boundary seam for testing lengths without huge allocations.
     * @throws RateLimiterException before packing when the field byte length is outside `0..4294967295`.
     */
    public static function encodeField(string $field, string $context, ?\Closure $length = null): string
    {
        $bytes = $length === null ? strlen($field) : $length($field);

        return self::encodeU32($bytes, $context) . $field;
    }

    /**
     * Encode a fixed sequence of opaque byte strings without a magic header or count prefix.
     *
     * Used by the simple fixed-window key derivation to preserve its established wire bytes while enforcing the
     * checked `u32be` bound on every component.
     *
     * @param list<string> $fields
     * @param ?\Closure(string): int $length Internal boundary seam for testing lengths without huge allocations.
     * @throws RateLimiterException before packing when any field byte length is outside `0..4294967295`.
     */
    public static function encodeFields(array $fields, string $context, ?\Closure $length = null): string
    {
        $out = '';
        foreach ($fields as $field) {
            $out .= self::encodeField($field, $context, $length);
        }

        return $out;
    }

    /**
     * Encode a magic-prefixed, count-prefixed tuple of opaque byte strings:
     * `magic || u32be(count(fields)) || enc(f_1) || ... || enc(f_N)`.
     *
     * @param list<string> $fields
     * @param ?\Closure(string): int $length Internal boundary seam for testing field lengths without huge allocations.
     * @param ?\Closure(list<string>): int $count Internal boundary seam for testing field counts without huge allocations.
     * @throws RateLimiterException before packing when count or any field byte length is outside `0..4294967295`.
     */
    public static function encodeTuple(
        string $magic,
        array $fields,
        string $context,
        ?\Closure $length = null,
        ?\Closure $count = null,
    ): string {
        $fieldCount = $count === null ? count($fields) : $count($fields);
        $out = $magic . self::encodeU32($fieldCount, $context);
        foreach ($fields as $field) {
            $out .= self::encodeField($field, $context, $length);
        }

        return $out;
    }
}
