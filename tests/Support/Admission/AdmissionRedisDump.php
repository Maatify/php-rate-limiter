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
        $keys = $redis->execute(['KEYS', $pattern]);
        $out = [];
        foreach (is_array($keys) ? $keys : [] as $key) {
            $key = (string) $key;
            $type = (string) $redis->execute(['TYPE', $key]);
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
        $keys = $redis->execute(['KEYS', $pattern]);
        $out = [];
        foreach (is_array($keys) ? $keys : [] as $key) {
            $pttl = $redis->execute(['PTTL', (string) $key]);
            $out[(string) $key] = is_int($pttl) ? $pttl : -2;
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, string> */
    private static function pairs(mixed $flat): array
    {
        $out = [];
        $flat = is_array($flat) ? array_values($flat) : [];
        for ($i = 0; $i + 1 < count($flat); $i += 2) {
            $out[(string) $flat[$i]] = (string) $flat[$i + 1];
        }
        ksort($out);

        return $out;
    }

    /** @return list<string> */
    private static function sorted(mixed $members): array
    {
        $out = array_map('strval', is_array($members) ? $members : []);
        sort($out);

        return $out;
    }

    /** @return array<string, string> */
    private static function hash(mixed $flat): array
    {
        return self::pairs($flat);
    }
}
