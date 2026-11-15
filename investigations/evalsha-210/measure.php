<?php

declare(strict_types=1);

// One measurement cell of the EVALSHA study (#210): a public-API workload, one script mode, one network.
//   php measure.php <host> <port> <mode> <workload> <requests> <warmup> <label> [--cold-first]
//   mode      eval | evalsha | evalsha-flush-each | evalsha-flush-commit
//   workload  read | newdevice | failure | admission-reserve | admission-outcome | recovery
//   --cold-first  SCRIPT FLUSH before the first measured request (with warmup 0: the cold-cache request is sample 0)
// Prints one JSON document on stdout: the environment and one record per measured request.

use Maatify\RateLimiter\Investigations\Evalsha210\Harness;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/lib/Harness.php';

$argv = $_SERVER['argv'] ?? [];
if (count($argv) < 8) {
    fwrite(STDERR, "usage: measure.php <host> <port> <mode> <workload> <requests> <warmup> <label> [--cold-first]\n");
    exit(2);
}
[, $host, $port, $mode, $workload, $requests, $warmup, $label] = $argv;
$coldFirst = in_array('--cold-first', $argv, true);
$namespace = 'study-' . $workload . '-' . $mode . '-' . getmypid();

$harness = Harness::connect($host, (int) $port, $mode, $namespace);
$raw = $harness->raw();
$raw->execute(['FLUSHALL']);
if ($workload === 'admission-reserve' || $workload === 'admission-outcome') {
    $harness->openFence();
}
if ($workload === 'read') {
    $harness->prepare('read', 0)();
}

$info = $raw->execute(['INFO', 'server']);
$redisVersion = is_string($info) && preg_match('/redis_version:(\S+)/', $info, $m) === 1 ? $m[1] : 'unknown';
$client = $harness->client();
$samples = [];
$total = (int) $warmup + (int) $requests;
for ($i = 0; $i < $total; $i++) {
    $call = $harness->prepare($workload, $i);
    if ($coldFirst && $i === (int) $warmup) {
        $raw->execute(['SCRIPT', 'FLUSH']);
    }
    $client->resetCounters();
    $harness->commits = $harness->verifies = 0;
    $started = hrtime(true);
    $error = null;
    try {
        $call();
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }
    $elapsed = (hrtime(true) - $started) / 1_000_000;
    if ($i < (int) $warmup) {
        continue;
    }
    $samples[] = [
        'ms' => round($elapsed, 4),
        'sent' => $client->bytesSent,
        'received' => $client->bytesReceived,
        'commands' => $client->commands,
        'scriptCalls' => $client->scriptCalls,
        'evalsha' => $client->evalshaCalls,
        'noscript' => $client->noscriptMisses,
        'fallbackEval' => $client->fallbackEvals,
        'commits' => $harness->commits,
        'verifies' => $harness->verifies,
        'error' => $error,
    ];
}

echo json_encode([
    'label' => $label,
    'workload' => $workload,
    'mode' => $mode,
    'requests' => (int) $requests,
    'warmup' => (int) $warmup,
    'coldFirst' => $coldFirst,
    'php' => PHP_VERSION,
    'phpIntSize' => PHP_INT_SIZE,
    'redis' => $redisVersion,
    'client' => 'tests/Support/Redis/RespRedisCommandExecutor (stream socket, no pipelining)',
    'endpoint' => $host . ':' . $port,
    'gitSha' => getenv('STUDY_GIT_SHA') ?: 'unknown',
    'samples' => $samples,
], JSON_THROW_ON_ERROR), "\n";
