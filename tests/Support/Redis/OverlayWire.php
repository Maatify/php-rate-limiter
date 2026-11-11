<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Redis;

/**
 * How a test recognises a store script on the wire now that one request runs through the request overlay (T-5): the
 * script text is wrapped, its changes are held back until one commit script saves them, and its reply carries the
 * held changes next to the script's own result. Arguments and keys keep their positions.
 */
final class OverlayWire
{
    /** @param list<int|string|float> $command */
    public static function isScript(array $command, string $script): bool
    {
        $text = $command[1] ?? null;

        return $command[0] === 'EVAL' && is_string($text) && ($text === $script || str_contains($text, $script));
    }

    /**
     * The save of a request; the read-only `verify` call of the same script is not one.
     *
     * @param list<int|string|float> $command
     */
    public static function isCommit(array $command): bool
    {
        $text = $command[1] ?? null;

        return $command[0] === 'EVAL' && is_string($text) && str_contains($text, 'request commit') && end($command) !== 'verify';
    }

    /** @param list<int|string|float> $command */
    public static function commitTouches(array $command, string $key): bool
    {
        if (! self::isCommit($command)) {
            return false;
        }
        $count = $command[2] ?? 0;

        return in_array($key, array_slice($command, 3, is_int($count) ? $count : 0), true);
    }

    /**
     * The script's own reply, whether or not it ran through the overlay.
     *
     * @param list<int|string|float> $command
     */
    public static function result(array $command, mixed $reply): mixed
    {
        $text = $command[1] ?? null;
        if (is_string($text) && str_contains($text, '__ov_result') && is_array($reply)) {
            return $reply[1] ?? null;
        }

        return $reply;
    }
}
