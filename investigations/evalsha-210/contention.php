<?php

declare(strict_types=1);

// Hot-key contention cell of the EVALSHA study (#210): forked workers, one hot account, recordFailure, a barrier instant.
//   php contention.php <host> <port> <mode> <workers> <requests-per-worker> <label>
// Each worker has its own connection and script mode. Prints one JSON document: totals and one record per request.

use Maatify\RateLimiter\Investigations\Evalsha210\Harness;
use Maatify\RateLimiter\Command\RateLimitCommand;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/lib/Harness.php';

$argv = $_SERVER['argv'] ?? [];
if (count($argv) < 7) {
    fwrite(STDERR, "usage: contention.php <host> <port> <mode> <workers> <requests-per-worker> <label>\n");
    exit(2);
}
[, $host, $port, $mode, $workers, $perWorker, $label] = $argv;
$barrier = microtime(true) + 1.5;
(Harness::connect($host, (int) $port, 'eval', 'study-hot'))->raw()->execute(['FLUSHALL']);

$children = [];
for ($w = 0; $w < (int) $workers; $w++) {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose($pair[0]);
        $harness = Harness::connect($host, (int) $port, $mode, 'study-hot');
        $limiter = $harness->limiter();
        $context = $harness->context('hot-account', 'hot-device');
        $command = RateLimitCommand::recordFailure('login_protection');
        $client = $harness->client();
        while (microtime(true) < $barrier) {
            usleep(100);
        }
        $records = [];
        for ($i = 0; $i < (int) $perWorker; $i++) {
            $client->resetCounters();
            $started = hrtime(true);
            $cause = null;
            $error = null;
            try {
                $cause = $limiter->limit($context, $command)->metadata->cause ?? null;
            } catch (Throwable $e) {
                $error = get_class($e);
            }
            $records[] = [
                'ms' => round((hrtime(true) - $started) / 1_000_000, 4),
                'sent' => $client->bytesSent,
                'received' => $client->bytesReceived,
                'commands' => $client->commands,
                'noscript' => $client->noscriptMisses,
                'conflict' => $cause === 'k4_concurrency_conflict',
                'error' => $error,
            ];
        }
        fwrite($pair[1], json_encode($records, JSON_THROW_ON_ERROR));
        fclose($pair[1]);
        exit(0);
    }
    fclose($pair[1]);
    $children[] = [$pid, $pair[0]];
}
$samples = [];
foreach ($children as [$pid, $stream]) {
    $payload = stream_get_contents($stream);
    fclose($stream);
    pcntl_waitpid($pid, $status);
    $decoded = is_string($payload) && $payload !== '' ? json_decode($payload, true) : null;
    if (is_array($decoded)) {
        array_push($samples, ...$decoded);
    }
}
$info = (Harness::connect($host, (int) $port, 'eval', 'study-hot'))->raw()->execute(['INFO', 'server']);
echo json_encode([
    'label' => $label,
    'workload' => 'hot-contention',
    'mode' => $mode,
    'workers' => (int) $workers,
    'requestsPerWorker' => (int) $perWorker,
    'php' => PHP_VERSION,
    'phpIntSize' => PHP_INT_SIZE,
    'redis' => is_string($info) && preg_match('/redis_version:(\S+)/', $info, $m) === 1 ? $m[1] : 'unknown',
    'endpoint' => $host . ':' . $port,
    'gitSha' => getenv('STUDY_GIT_SHA') ?: 'unknown',
    'samples' => $samples,
], JSON_THROW_ON_ERROR), "\n";
