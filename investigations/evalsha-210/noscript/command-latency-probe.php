<?php

declare(strict_types=1);

// Per-command latency of EVAL against EVALSHA with the request logic taken out (#210, second round): `connections` forked workers, each
// sending `commands` sequential script calls of a padded script (the size of a store script) through the same endpoint, in the order given.
//   php command-latency-probe.php <host> <port> <connections> <commands> <padding-bytes> <mode> [mode...]
// For each mode in turn (EVALSHA is preloaded, so there is no cold miss): p50, p95, p99, max in ms over every call of every worker.
// The point is to ask whether the extra latency of EVALSHA seen at a 7 ms round trip belongs to the command or to the request logic.

use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $host, $port, $connections, $commands, $padding] = array_pad($_SERVER['argv'] ?? [], 6, '');
$modes = array_slice($_SERVER['argv'] ?? [], 6);
$script = 'return 1 --' . str_repeat('x', (int) $padding);
$control = new RespRedisCommandExecutor($host, (int) $port, 'eval');
$control->execute(['SCRIPT', 'LOAD', $script]);
$report = [];
foreach ($modes as $mode) {
    $children = [];
    $barrier = microtime(true) + 1.0;
    for ($w = 0; $w < (int) $connections; $w++) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($pair[0]);
            $client = new RespRedisCommandExecutor($host, (int) $port, $mode);
            $client->execute(['EVAL', $script, '0']);
            while (microtime(true) < $barrier) {
                usleep(100);
            }
            $ms = [];
            for ($i = 0; $i < (int) $commands; $i++) {
                $started = hrtime(true);
                $client->execute(['EVAL', $script, '0']);
                $ms[] = (hrtime(true) - $started) / 1_000_000;
            }
            fwrite($pair[1], json_encode($ms, JSON_THROW_ON_ERROR));
            exit(0);
        }
        fclose($pair[1]);
        $children[] = [$pid, $pair[0]];
    }
    $all = [];
    foreach ($children as [$pid, $stream]) {
        $decoded = json_decode((string) stream_get_contents($stream), true);
        pcntl_waitpid($pid, $status);
        if (is_array($decoded)) {
            array_push($all, ...$decoded);
        }
    }
    sort($all);
    $rank = static fn(float $p): float => round($all[max(0, (int) ceil($p / 100 * count($all)) - 1)], 3);
    $report[] = ['mode' => $mode, 'connections' => (int) $connections, 'commands' => count($all), 'padding' => (int) $padding, 'p50Ms' => $rank(50), 'p95Ms' => $rank(95), 'p99Ms' => $rank(99), 'maxMs' => round(max($all), 3)];
    usleep(500_000);
}
foreach ($report as $row) {
    echo json_encode($row, JSON_THROW_ON_ERROR), "\n";
}
