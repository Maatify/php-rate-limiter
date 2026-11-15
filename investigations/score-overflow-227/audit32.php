<?php

declare(strict_types=1);

// Audit of the int-returning counter paths of RedisFullCapabilityStore for the pattern fixed for the score increment in #227:
// the script writes a value that this PHP's native int cannot hold, and PHP rejects the reply afterwards.
//   php audit32.php   (REDIS_INTEGRATION_HOST / REDIS_INTEGRATION_PORT; run on PHP_INT_SIZE 4 for a meaningful result)
// Each case seeds a counter at the caller's PHP_INT_MAX, increments it by one, and reports the outcome and whether the stored
// state changed (a byte-exact DUMP and the exact PEXPIRETIME of every key, before and after).

use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$host = getenv('REDIS_INTEGRATION_HOST') ?: '127.0.0.1';
$port = (int) (getenv('REDIS_INTEGRATION_PORT') ?: 6379);
$now = 1_793_793_600;
$ns = 'audit227';
$executor = new RespRedisCommandExecutor($host, $port);
$store = new RedisFullCapabilityStore($executor, $ns);
$max = (string) PHP_INT_MAX;
$raw = static fn(string $family, string $logical): string => 'maatify:rate-limiter:v1:' . hash('sha256', $ns) . ':' . $family . ':' . hash('sha256', $logical);

$state = static function () use ($executor): array {
    $keys = $executor->execute(['KEYS', '*']);
    sort($keys);
    $out = [];
    foreach ($keys as $key) {
        $out[$key] = [bin2hex((string) $executor->execute(['DUMP', $key])), $executor->execute(['PEXPIRETIME', $key])];
    }

    return $out;
};

$cases = [
    'score increment (#227, fixed)' => [
        static function () use ($executor, $raw, $max, $now): void {
            $executor->execute(['HSET', $raw('score', 'c'), 'value', $max, 'updatedAt', (string) $now]);
            $executor->execute(['EXPIRE', $raw('score', 'c'), '3600']);
        },
        static fn() => $store->incrementAt('c', 3600, 1, $now + 1),
    ],
    'budget increment' => [
        static function () use ($executor, $raw, $max, $now): void {
            $executor->execute(['HSET', $raw('budget', 'c'), 'count', $max, 'epochStart', (string) $now, 'epochDuration', '86400']);
            $executor->execute(['EXPIRE', $raw('budget', 'c'), '86400']);
        },
        static fn() => $store->incrementBudgetAt('c', 86400, 1, $now + 1),
    ],
    'budget increment with seed' => [
        static function () use ($executor, $raw, $max, $now): void {
            $executor->execute(['HSET', $raw('budget', 'c'), 'count', $max, 'epochStart', (string) $now, 'epochDuration', '86400']);
            $executor->execute(['EXPIRE', $raw('budget', 'c'), '86400']);
        },
        static fn() => $store->incrementBudgetWithSeedAt('c', 86400, new Maatify\RateLimiter\DTO\BudgetStateDTO(1, $now), 1, $now + 1),
    ],
    'watch flag increment' => [
        static function () use ($executor, $raw, $max, $now): void {
            $executor->execute(['SET', $raw('watch', 'c'), $max]);
            $executor->execute(['EXPIRE', $raw('watch', 'c'), '1800']);
            $executor->execute(['HSET', $raw('watch-meta', 'c'), 'expiresAt', (string) ($now + 1800)]);
            $executor->execute(['EXPIRE', $raw('watch-meta', 'c'), '1800']);
        },
        static fn() => $store->incrementWatchFlagAt('c', 1800, $now),
    ],
];

printf("PHP %s, PHP_INT_SIZE %d, PHP_INT_MAX %s\n\n| Path | Outcome | Stored state changed |\n| --- | --- | --- |\n", PHP_VERSION, PHP_INT_SIZE, $max);
foreach ($cases as $name => [$seed, $call]) {
    $executor->execute(['FLUSHDB']);
    $seed();
    $before = $state();
    try {
        $result = $call();
        $outcome = 'returned ' . (is_object($result) ? get_class($result) : var_export($result, true));
    } catch (Throwable $e) {
        $outcome = get_class($e) . ': ' . $e->getMessage();
    }
    printf("| %s | %s | %s |\n", $name, $outcome, $state() === $before ? 'no' : '**yes**');
}
