<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;

/**
 * Type-aware dump of the admission keys of one namespace, for before / after comparisons. The dump holds the type,
 * the content and whether the key carries a TTL; `pttl()` is kept apart so a test can assert that no TTL was refreshed.
 */
final class AdmissionRedisDump
{
    /** @return array<string, array{type: string, value: mixed, hasTtl: bool}> */
    public static function take(RedisCommandExecutorInterface $redis, string $pattern = '*'): array
    {
        $out = [];
        foreach (ReplyValue::list($redis->execute(['KEYS', $pattern])) as $reply) {
            $key = ReplyValue::text($reply);
            $type = ReplyValue::text($redis->execute(['TYPE', $key]));
            $value = match ($type) {
                'string' => $redis->execute(['GET', $key]),
                'zset' => self::pairs($redis->execute(['ZRANGE', $key, 0, -1, 'WITHSCORES'])),
                'set' => self::sorted($redis->execute(['SMEMBERS', $key])),
                'hash' => self::hash($redis->execute(['HGETALL', $key])),
                default => 'type:' . $type,
            };
            $pttl = $redis->execute(['PTTL', $key]);
            $out[$key] = ['type' => $type, 'value' => $value, 'hasTtl' => is_int($pttl) && $pttl > 0];
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, int> PTTL in milliseconds per key, -1 when none. */
    public static function pttl(RedisCommandExecutorInterface $redis, string $pattern = '*'): array
    {
        $out = [];
        foreach (ReplyValue::list($redis->execute(['KEYS', $pattern])) as $reply) {
            $key = ReplyValue::text($reply);
            $out[$key] = ReplyValue::integer($redis->execute(['PTTL', $key]));
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, string> */
    private static function pairs(mixed $flat): array
    {
        $out = [];
        $flat = ReplyValue::list($flat);
        for ($i = 0; $i + 1 < count($flat); $i += 2) {
            $out[ReplyValue::text($flat[$i])] = ReplyValue::text($flat[$i + 1]);
        }
        ksort($out);

        return $out;
    }

    /** @return list<string> */
    private static function sorted(mixed $members): array
    {
        $out = array_map(ReplyValue::text(...), ReplyValue::list($members));
        sort($out);

        return $out;
    }

    /** @return array<string, string> */
    private static function hash(mixed $flat): array
    {
        return self::pairs($flat);
    }
}
