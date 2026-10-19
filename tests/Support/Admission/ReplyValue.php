<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

/** Strict readers for raw Redis replies in the store gates: a reply of another shape is a test failure, never coerced. */
final class ReplyValue
{
    public static function text(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        throw new \UnexpectedValueException('Redis reply is not text: ' . get_debug_type($value));
    }

    public static function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A-?\d+\z/D', $value) === 1) {
            return intval($value);
        }
        throw new \UnexpectedValueException('Redis reply is not an integer: ' . get_debug_type($value));
    }

    /** @return list<mixed> */
    public static function list(mixed $value): array
    {
        if (! is_array($value)) {
            throw new \UnexpectedValueException('Redis reply is not a list: ' . get_debug_type($value));
        }

        return array_values($value);
    }
}
