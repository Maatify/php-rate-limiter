<?php

declare(strict_types=1);

// DEC-030 second measurement (#185, F-185-08). Usage: measure.php <checkout> <port> <rounds>
// Each round: one failure at 11:35:00 writes K5 (+2) and K1 (+5); K5 is set to 5; then N processes (2, 4, 8), released at
// one instant, each fail once at 12:00:00 (1500 s later, both scores fully decayed). The decay credit applies once, to the
// first writer, so with W requests that wrote K5 (and V that wrote K1) the sequential result is K5 = 2W and K1 = 5V; a
// request refused by an active block (K5 crosses its thresholds) writes nothing. Reported: rounds with a negative value,
// rounds whose K5 / K1 differ from 2W / 5V (the sum of the deltas lost), the writers, and the fenced writes applied /
// missed / exhausted.
require __DIR__ . '/load.php';

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

[, $root, $port, $rounds] = $argv;
$redis = new RespRedisCommandExecutor('127.0.0.1', (int) $port);
$increment = (string) (new ReflectionClassConstant(RedisFullCapabilityStore::class, 'SCORE_INCREMENT'))->getValue();

/** @return array{k5: string, k1: string} */
function seed(RespRedisCommandExecutor $redis, string $increment): array
{
    $redis->execute(['FLUSHDB']);
    $recorder = new class ($redis, $increment) implements RedisCommandExecutorInterface {
        /** @var array<string, string> */
        public array $keys = [];

        public function __construct(private RedisCommandExecutorInterface $inner, private string $increment) {}

        public function execute(array $command): mixed
        {
            if (($command[0] ?? '') === 'EVAL' && $command[1] === $this->increment) {
                $this->keys[(string) $command[5]] = (string) $command[3];
            }

            return $this->inner->execute($command);
        }
    };
    RateLimiterBuilder::fromFullCapabilityStore(
        new RateLimiterConfig('f185-08-key', 'f185-08-fingerprint', 'prod'),
        new RedisFullCapabilityStore($recorder, 'f185-08'),
        new RecordingFailureSignalEmitter(),
    )->withClock(new FixedClock('2026-11-04 11:35:00'))->build()
        ->limit(new RateLimitContextDTO('198.51.100.60', 'Mozilla/5.0 Chrome/120.0', 'account-1', null, 'session-device-1', true), RateLimitCommand::recordFailure('login_protection'));
    $redis->execute(['HSET', $recorder->keys['2'], 'value', '5']);

    return ['k5' => $recorder->keys['2'], 'k1' => $recorder->keys['5']];
}

foreach ([2, 4, 8] as $n) {
    $negative = 0; $k5Off = 0; $k1Off = 0; $applied = 0; $misses = 0; $exhausted = 0; $k5Values = []; $writers = [];
    for ($round = 0; $round < (int) $rounds; $round++) {
        $keys = seed($redis, $increment);
        $startAt = (int) (microtime(true) * 1e6) + 300_000;
        $handles = [];
        $w5 = 0;
        $w1 = 0;
        for ($i = 0; $i < $n; $i++) {
            $handles[] = popen(implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, '-d', 'xdebug.mode=off', __DIR__ . '/worker.php', $root, $port, (string) $startAt, $keys['k5'], $keys['k1']])) . ' 2>&1', 'r');
        }
        foreach ($handles as $handle) {
            $reply = json_decode(trim((string) stream_get_contents($handle)), true);
            pclose($handle);
            if (! is_array($reply)) {
                fwrite(STDERR, "worker failed\n");
                exit(2);
            }
            $applied += $reply['applied']; $misses += $reply['misses']; $exhausted += $reply['exhausted'];
            $w5 += $reply['wroteK5']; $w1 += $reply['wroteK1'];
        }
        $k5 = (int) $redis->execute(['HGET', $keys['k5'], 'value']);
        $k1 = (int) $redis->execute(['HGET', $keys['k1'], 'value']);
        $negative += ($k5 < 0 || $k1 < 0) ? 1 : 0;
        $k5Off += $k5 !== 2 * $w5 ? 1 : 0;
        $k1Off += $k1 !== 5 * $w1 ? 1 : 0;
        $writers[$w5] = ($writers[$w5] ?? 0) + 1;
        $k5Values[$k5] = ($k5Values[$k5] ?? 0) + 1;
    }
    ksort($k5Values);
    ksort($writers);
    printf("N=%d rounds=%s  negative=%d  K5!=2W=%d  K1!=5V=%d  K5 values=%s  K5 writers per round=%s  fenced applied=%d missed=%d exhausted=%d\n",
        $n, $rounds, $negative, $k5Off, $k1Off, json_encode($k5Values), json_encode($writers), $applied, $misses, $exhausted);
}
