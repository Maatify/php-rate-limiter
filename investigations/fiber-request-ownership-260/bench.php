<?php

declare(strict_types=1);

// #260 cost of the ownership guard (DEC-041). One workload per process, one tree per run (the tree whose vendor/autoload.php is loaded):
//   php bench.php <workload> <iterations> <warm-up>
// workloads: noop (an empty call, the timer floor), ping (one Redis round trip over the same executor, the network floor),
//            direct (a direct store capability call, one command), nested (a store transaction with two nested capability calls),
//            limit (a whole public `limit()` call of a graph: policy evaluation, Redis, the guard)
// Prints one JSON document with p50/p95/p99/mean in microseconds. Percentiles are nearest-rank on the measured samples; nothing is dropped.

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

require getcwd() . '/vendor/autoload.php';

[, $workload, $iterations, $warmUp] = $_SERVER['argv'] + [null, 'noop', '1000', '100'];
$iterations = (int) $iterations;
$warmUp = (int) $warmUp;
$host = (string) getenv('REDIS_INTEGRATION_HOST');
$port = (int) getenv('REDIS_INTEGRATION_PORT');

$executor = new RespRedisCommandExecutor($host, $port);
$executor->execute(['FLUSHALL']);
$clock = new FixedClock('@1000000');
$store = new RedisFullCapabilityStore($executor, 'bench-260');
$runtime = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('test_secret', 'test_fp', 'prod'), $store, new RecordingFailureSignalEmitter())->withClock($clock)->build();

$call = match ($workload) {
    'noop' => static function (int $i): void {},
    'ping' => static function (int $i) use ($executor): void {
        $executor->execute(['PING']);
    },
    'direct' => static function (int $i) use ($store): void {
        $store->acquireProbeLease('policy-' . ($i % 500), 1_000_000, 120);
    },
    'nested' => static function (int $i) use ($store): void {
        $store->transactRequest(static function () use ($store, $i): void {
            $store->acquireProbeLease('outer-' . ($i % 500), 1_000_000, 120);
            $store->acquireProbeLease('inner-' . ($i % 500), 1_000_000, 120);
        });
    },
    'limit' => static function (int $i) use ($runtime): void {
        $runtime->limit(new RateLimitContextDTO(ip: '198.51.100.' . (1 + $i % 200), ua: 'bench', accountId: 'bench-' . ($i % 1000)), RateLimitCommand::checkOnly('login_protection'));
    },
    default => throw new InvalidArgumentException('unknown workload ' . $workload),
};

for ($i = 0; $i < $warmUp; $i++) {
    $call($i);
}
$executor->execute(['FLUSHALL']);
$samples = [];
for ($i = 0; $i < $iterations; $i++) {
    $started = hrtime(true);
    $call($i);
    $samples[] = (hrtime(true) - $started) / 1000.0;
}
sort($samples);
$pct = static function (array $s, float $p): float {
    $n = count($s);

    return $s[max(0, (int) ceil($p / 100 * $n) - 1)];
};
echo json_encode([
    'workload' => $workload,
    'n' => $iterations,
    'warmUp' => $warmUp,
    'php' => PHP_VERSION,
    'intSize' => PHP_INT_SIZE,
    'p50_us' => round($pct($samples, 50), 2),
    'p95_us' => round($pct($samples, 95), 2),
    'p99_us' => round($pct($samples, 99), 2),
    'mean_us' => round(array_sum($samples) / count($samples), 2),
], JSON_THROW_ON_ERROR), "\n";
