<?php

declare(strict_types=1);

// One hot-key contention cell of the paired p99 measurement (#210, second round). Same workload as `../contention.php` (forked workers, one
// hot account, recordFailure, a barrier instant), with three additions the first round did not have:
//   - an unmeasured warm-up in the cell's own script mode, from a flushed script cache, so both modes start the measured phase warm;
//   - the commit and verify scripts each request sent (the retry attempts behind a latency);
//   - the server's own counters for the measured phase (CONFIG RESETSTAT before it, INFO commandstats after it).
//   php paired-contention.php <host> <port> <mode> <workers> <requests-per-worker> <label> [warm-up-requests=12]
// Prints one JSON document: totals and one record per request. `../contention.php` is not modified.

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Investigations\Evalsha210\Harness;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__) . '/lib/Harness.php';

$argv = $_SERVER['argv'] ?? [];
if (count($argv) < 7) {
    fwrite(STDERR, "usage: paired-contention.php <host> <port> <mode> <workers> <requests-per-worker> <label> [warm-up-requests]\n");
    exit(2);
}
[, $host, $port, $mode, $workers, $perWorker, $label] = $argv;
$warmUp = (int) ($argv[7] ?? 12);

$control = Harness::connect($host, (int) $port, 'eval', 'study-hot')->raw();
$control->execute(['SCRIPT', 'FLUSH']);
$control->execute(['FLUSHALL']);

// Warm-up: this mode, one process, no contention, the hot account's own request sequence (allow, soft block, hard block): every script
// the measured phase uses is loaded and the connection path is used. The state is flushed afterwards; the script cache is not.
$warm = Harness::connect($host, (int) $port, $mode, 'study-hot');
$warmLimiter = $warm->limiter();
$warmContext = $warm->context('hot-account', 'hot-device');
for ($i = 0; $i < $warmUp; $i++) {
    $warmLimiter->limit($warmContext, RateLimitCommand::recordFailure('login_protection'));
}
$control->execute(['FLUSHALL']);
$control->execute(['CONFIG', 'RESETSTAT']);

$barrier = microtime(true) + 1.5;
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
            $commitsBefore = $harness->commits;
            $verifiesBefore = $harness->verifies;
            $started = hrtime(true);
            $cause = null;
            $decision = null;
            $error = null;
            try {
                $result = $limiter->limit($context, $command);
                $cause = $result->metadata->cause ?? null;
                $decision = $result->decision;
            } catch (Throwable $e) {
                $error = get_class($e);
            }
            $records[] = [
                'ms' => round((hrtime(true) - $started) / 1_000_000, 4),
                'sent' => $client->bytesSent,
                'received' => $client->bytesReceived,
                'commands' => $client->commands,
                'noscript' => $client->noscriptMisses,
                'commits' => $harness->commits - $commitsBefore,
                'verifies' => $harness->verifies - $verifiesBefore,
                'decision' => $decision,
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
$info = $control->execute(['INFO', 'server']);
$stats = $control->execute(['INFO', 'commandstats']);
$commandCalls = [];
if (is_string($stats)) {
    foreach (['eval', 'evalsha', 'script|flush', 'script|load'] as $name) {
        $commandCalls[$name] = preg_match('/cmdstat_' . preg_quote($name, '/') . ':calls=(\d+)/', $stats, $m) === 1 ? (int) $m[1] : 0;
    }
}
echo json_encode([
    'label' => $label,
    'workload' => 'hot-contention',
    'mode' => $mode,
    'workers' => (int) $workers,
    'requestsPerWorker' => (int) $perWorker,
    'warmUpRequests' => $warmUp,
    'php' => PHP_VERSION,
    'phpIntSize' => PHP_INT_SIZE,
    'redis' => is_string($info) && preg_match('/redis_version:(\S+)/', $info, $m) === 1 ? $m[1] : 'unknown',
    'endpoint' => $host . ':' . $port,
    'gitSha' => getenv('STUDY_GIT_SHA') ?: 'unknown',
    'serverCommandCalls' => $commandCalls,
    'samples' => $samples,
], JSON_THROW_ON_ERROR), "\n";
