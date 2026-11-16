<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Redis;

use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use PHPUnit\Framework\Assert;

/**
 * Every key's byte-exact `DUMP` and its exact absolute expiry (`PEXPIRETIME`), read outside Lua (#240, F241-01).
 *
 * `DUMP` does not carry a key's expiration, so a write that only moves a TTL leaves it unchanged; the expiry is therefore part of the
 * snapshot, with no tolerance. `PEXPIRETIME` may be an `int` or, past a 32-bit `int`, a decimal string; it is kept as the exact text
 * (`-1` is a key without expiry).
 */
final class StateSnapshot
{
    /** @return array<string, string> key => `bin2hex(DUMP)@PEXPIRETIME`, sorted by key. */
    public static function all(RedisCommandExecutorInterface $executor): array
    {
        $keys = $executor->execute(['KEYS', '*']);
        Assert::assertIsArray($keys);
        $snapshot = [];
        foreach ($keys as $key) {
            Assert::assertIsString($key);
            $snapshot[$key] = self::of($executor, $key);
        }
        ksort($snapshot);

        return $snapshot;
    }

    /** The snapshot entry of one key (an absent key is `absent`). */
    public static function of(RedisCommandExecutorInterface $executor, string $key): string
    {
        $dump = $executor->execute(['DUMP', $key]);
        if ($dump === null) {
            return 'absent';
        }
        Assert::assertIsString($dump);
        $expiry = $executor->execute(['PEXPIRETIME', $key]);
        Assert::assertTrue(is_int($expiry) || is_string($expiry), 'PEXPIRETIME is an integer reply.');

        return bin2hex($dump) . '@' . $expiry;
    }

    /**
     * Move one key's absolute expiry by exactly +1 ms, leaving its bytes untouched: the expiry-only write the snapshot must reject.
     * The key must have an expiry.
     */
    public static function perturbExpiryByOneMillisecond(RedisCommandExecutorInterface $executor, string $key): void
    {
        $expiry = $executor->execute(['PEXPIRETIME', $key]);
        Assert::assertTrue(is_int($expiry) && $expiry > 0 || is_string($expiry), 'The perturbed key has an expiry.');
        $moved = ExactDecimal::add(ExactDecimal::fromReply($expiry), '1');
        Assert::assertSame(1, $executor->execute(['PEXPIREAT', $key, $moved]));
    }
}
