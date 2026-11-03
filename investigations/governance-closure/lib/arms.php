<?php

declare(strict_types=1);

// #181 S1 (PLAN §10, LT-181-04/05): the two store arms of M-121 and M-98, behind the public Builder API.
//
//   memory  the reference store (tests/Support/FullCapability/FullCapabilityInMemoryStore), on the fixed clock;
//   redis   RedisFullCapabilityStore on a real Redis (REDIS_INTEGRATION_HOST/PORT, as scripts/ci/run-with-redis-service.sh
//           sets them), FLUSHALL before every case; the store receives the same fixed clock (caller time, DEC-016).
//
// A missing Redis is an error (exit 3), never a skip.

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Repository\FullCapabilityStoreInterface;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

const T0 = 1767225600; // 2026-01-01T00:00:00Z

function harnessFail(string $message): never
{
    fwrite(STDERR, "harness: $message\n");
    exit(3);
}

function armName(array $argv): string
{
    $arm = $argv[1] ?? '';
    if ($arm !== 'memory' && $arm !== 'redis') {
        fwrite(STDERR, "usage: php {$argv[0]} memory|redis\n");
        exit(2);
    }

    return $arm;
}

/** @return array{store: FullCapabilityStoreInterface, redis: ?string, keys: ?Closure} redis is the server version and keys counts its keys (DBSIZE), both null for memory */
function armStore(string $arm, FixedClock $clock): array
{
    if ($arm === 'memory') {
        return ['store' => new FullCapabilityInMemoryStore($clock), 'redis' => null, 'keys' => null];
    }
    $host = getenv('REDIS_INTEGRATION_HOST');
    $port = getenv('REDIS_INTEGRATION_PORT');
    if ($host === false || $port === false) {
        harnessFail('REDIS_INTEGRATION_HOST/PORT are not set; run through scripts/ci/run-with-redis-service.sh');
    }
    $redis = new Redis();
    if (! $redis->connect($host, (int) $port, 5.0)) {
        harnessFail("cannot connect to Redis at $host:$port");
    }
    if ($redis->rawCommand('FLUSHALL') !== true || $redis->rawCommand('DBSIZE') !== 0) {
        harnessFail('FLUSHALL did not leave an empty Redis');
    }
    $version = $redis->info('server')['redis_version'] ?? harnessFail('no redis_version');
    $executor = new CallableRedisCommandExecutor(
        static fn (array $command): mixed => $redis->rawCommand((string) $command[0], ...array_slice($command, 1)),
    );

    return [
        'store' => new RedisFullCapabilityStore($executor, 'governance-closure-181', $clock),
        'redis' => $version,
        'keys' => static fn (): int => $redis->rawCommand('DBSIZE'),
    ];
}

function armBuilder(FullCapabilityStoreInterface $store, FixedClock $clock, BlockPolicyInterface $policy, RecordingFailureSignalEmitter $signals): RateLimiterBuilder
{
    return RateLimiterBuilder::fromFullCapabilityStore(
        new RateLimiterConfig('governance-closure-key-secret', 'governance-closure-fingerprint-secret', 'governance-closure'),
        $store,
        $signals,
    )->withClock($clock)->withPolicy($policy);
}

function emit(array $row): void
{
    echo json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
}
