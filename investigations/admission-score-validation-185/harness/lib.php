<?php

declare(strict_types=1);

/**
 * F-185-01 harness: loads `Maatify\RateLimiter\` from the `src/` directory named by --src (prepended, so a base checkout's
 * `src/` can be measured with this checkout's test support), and connects to the Redis named by --host / --port.
 */

use Maatify\RateLimiter\Repository\Redis\RedisAdmissionOperations;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

/** @return array<string, string> */
function f185_options(): array
{
    $options = getopt('', ['src:', 'host:', 'port:', 'iterations::', 'rounds::']);
    foreach (['src', 'host', 'port'] as $required) {
        if (! isset($options[$required]) || ! is_string($options[$required])) {
            fwrite(STDERR, "--src, --host and --port are required.\n");
            exit(2);
        }
    }
    /** @var array<string, string> $options */
    return $options;
}

function f185_boot(string $src): void
{
    $real = realpath($src);
    if ($real === false || ! is_file($real . '/Repository/Redis/RedisAdmissionOperations.php')) {
        fwrite(STDERR, "--src must be a package src/ directory.\n");
        exit(2);
    }
    // After Composer's loader (which registers itself prepended), so this one is consulted first.
    require dirname(__DIR__, 3) . '/vendor/autoload.php';
    spl_autoload_register(static function (string $class) use ($real): void {
        $prefix = 'Maatify\\RateLimiter\\';
        if (! str_starts_with($class, $prefix) || str_starts_with($class, $prefix . 'Tests\\')) {
            return;
        }
        $file = $real . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }, true, true);
    $loaded = (new ReflectionClass(RedisAdmissionOperations::class))->getFileName();
    if ($loaded !== $real . '/Repository/Redis/RedisAdmissionOperations.php') {
        fwrite(STDERR, 'RedisAdmissionOperations was not loaded from --src: ' . var_export($loaded, true) . "\n");
        exit(2);
    }
}

function f185_redis(string $host, int $port): RespRedisCommandExecutor
{
    return new RespRedisCommandExecutor($host, $port);
}

/** @param non-empty-list<string> $command */
function f185_raw(RespRedisCommandExecutor $redis, string ...$command): mixed
{
    return $redis->execute(array_values($command));
}

/**
 * Key, type, content (members with scores, or the string value) and whether it has a TTL, of every key in the db.
 *
 * @return array<string, array{type: string, value: mixed, ttl: int}>
 */
function f185_dump(RespRedisCommandExecutor $redis): array
{
    $keys = f185_raw($redis, 'KEYS', '*');
    $keys = is_array($keys) ? $keys : [];
    sort($keys);
    $dump = [];
    foreach ($keys as $key) {
        $type = (string) f185_raw($redis, 'TYPE', $key);
        $value = match ($type) {
            'zset' => f185_raw($redis, 'ZRANGE', $key, '0', '-1', 'WITHSCORES'),
            'string' => f185_raw($redis, 'GET', $key),
            'hash' => f185_raw($redis, 'HGETALL', $key),
            'set' => (static function () use ($redis, $key): array {
                $m = f185_raw($redis, 'SMEMBERS', $key);
                $m = is_array($m) ? $m : [];
                sort($m);

                return $m;
            })(),
            default => null,
        };
        $dump[$key] = ['type' => $type, 'value' => $value, 'ttl' => (int) f185_raw($redis, 'TTL', $key)];
    }

    return $dump;
}
