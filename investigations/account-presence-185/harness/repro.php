<?php

declare(strict_types=1);

/**
 * F-185-03 reproduction through the public API: `RateLimiterBuilder::fromFullCapabilityStore()` over
 * `RedisFullCapabilityStore` and phpredis, `login_protection`, a trusted session on its own device, 25 rounds of
 * `checkOnly` then `recordFailure` per account id, on a fresh Redis db per arm. Prints, per arm, the admitted count, the
 * first non-ALLOW decision, the exception if any, and which K4/K5 scopes the operational reader shows.
 *
 * php investigations/account-presence-185/harness/repro.php --host=127.0.0.1 --port=<port>
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;

$options = getopt('', ['host:', 'port:']);
if (! isset($options['host'], $options['port']) || ! is_string($options['host']) || ! is_string($options['port'])) {
    fwrite(STDERR, "--host and --port are required.\n");
    exit(2);
}
$redis = new Redis();
$redis->connect($options['host'], (int) $options['port'], 5.0);

$arms = ['owner' => 'owner', '00' => '00', '1' => '1', '0' => '0', '""' => '', 'null' => null];
foreach ($arms as $label => $accountId) {
    $redis->rawCommand('FLUSHDB');
    $store = new RedisFullCapabilityStore(
        new CallableRedisCommandExecutor(static fn (array $c): mixed => $redis->rawCommand((string) $c[0], ...array_slice($c, 1))),
        'f185-03',
    );
    $builder = RateLimiterBuilder::fromFullCapabilityStore(
        new RateLimiterConfig('f185-03-key', 'f185-03-fingerprint', 'prod'),
        $store,
        new RecordingFailureSignalEmitter(),
    )->withClock(new FixedClock('2026-11-04 12:00:00'));
    $limiter = $builder->build();
    $reader = $builder->buildOperationalReader();
    $context = new RateLimitContextDTO('198.51.100.7', 'Mozilla/5.0 Chrome/120.0', $accountId, null, 'session-device-1', true);
    $admitted = 0;
    $firstDenial = '-';
    $error = '-';
    try {
        for ($i = 0; $i < 25; $i++) {
            $result = $limiter->limit($context, RateLimitCommand::checkOnly('login_protection'));
            if ($result->decision === RateLimitResultDTO::DECISION_ALLOW) {
                $admitted++;
                $limiter->limit($context, RateLimitCommand::recordFailure('login_protection'));
            } elseif ($firstDenial === '-') {
                $firstDenial = $result->decision . '@' . ($i + 1);
            }
        }
        $snapshot = $reader->readScorePolicy($context, 'login_protection');
        $scopes = sprintf(
            'k4 %s, k5 %s',
            $snapshot->scopes->k4 === null ? 'absent' : 'score ' . ($snapshot->scopes->k4->score?->value ?? 0),
            $snapshot->scopes->k5 === null ? 'absent' : 'score ' . ($snapshot->scopes->k5->score?->value ?? 0),
        );
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
        $scopes = '-';
    }
    printf("accountId=%-7s | admitted %2d/25 | first denial %-16s | %s | %s\n", $label, $admitted, $firstDenial, $scopes, $error);
}
