<?php

declare(strict_types=1);

// DEC-031 measurement (#185, F-185-18). Usage: measure-circuit.php <checkout> <port> <rounds>
// Each round: N processes (2, 4, 8) each report one backend failure, all reading the state before any saves it (a Redis
// barrier before the first save). Reported per N: the final status and failure count, and per process the fenced saves
// applied / missed; a process with three misses and no applied save dropped its failure (exhausted), one with no applied
// save and fewer misses returned because the circuit was already OPEN.
require __DIR__ . '/load.php';

use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

[, $root, $port, $rounds] = $argv;
$redis = new RespRedisCommandExecutor('127.0.0.1', (int) $port);
foreach ([2, 4, 8] as $n) {
    $statuses = []; $failureCounts = []; $applied = 0; $missed = 0; $exhausted = 0; $openReturn = 0;
    for ($round = 0; $round < (int) $rounds; $round++) {
        $redis->execute(['FLUSHDB']);
        $handles = [];
        for ($i = 0; $i < $n; $i++) {
            $handles[] = popen(implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, '-d', 'xdebug.mode=off', __DIR__ . '/circuit-worker.php', $root, $port, 'f185-18-measure:barrier', (string) $n])) . ' 2>&1', 'r');
        }
        foreach ($handles as $handle) {
            $reply = json_decode(trim((string) stream_get_contents($handle)), true);
            pclose($handle);
            if (! is_array($reply)) { fwrite(STDERR, "worker failed\n"); exit(2); }
            $applied += $reply['applied']; $missed += $reply['missed'];
            if ($reply['applied'] === 0 && $reply['missed'] === 3) { $exhausted++; }
            if ($reply['applied'] === 0 && $reply['missed'] > 0 && $reply['missed'] < 3) { $openReturn++; }
        }
        $state = (new RedisFullCapabilityStore($redis, 'f185-18-measure'))->load('login_protection');
        $statuses[$state?->status ?? 'none'] = ($statuses[$state?->status ?? 'none'] ?? 0) + 1;
        $count = count($state?->failures ?? []);
        $failureCounts[$count] = ($failureCounts[$count] ?? 0) + 1;
    }
    ksort($failureCounts);
    printf("N=%d rounds=%s  status=%s  failures recorded=%s  fenced applied=%d missed=%d  exhausted (dropped)=%d  returned on OPEN after a miss=%d\n",
        $n, $rounds, json_encode($statuses), json_encode($failureCounts), $applied, $missed, $exhausted, $openReturn);
}
