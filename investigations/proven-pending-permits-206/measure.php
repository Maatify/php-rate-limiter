<?php

declare(strict_types=1);

// #206 measurement of the proven-lane pending-permit limit on an isolated Redis, through RateLimiterBuilder and the
// public runtime (the package's race worker builds the runtime and mints one credential per user).
//   REDIS_INTEGRATION_HOST=127.0.0.1 REDIS_INTEGRATION_PORT=6391 php investigations/proven-pending-permits-206/measure.php
// It runs FLUSHDB on that Redis: never point it at a shared instance.

use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRaceRunner;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRaceSpec;
use Maatify\RateLimiter\Tests\Support\Admission\PublicRedisAdmissionFixture;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$host = getenv('REDIS_INTEGRATION_HOST') ?: '127.0.0.1';
$port = (int) (getenv('REDIS_INTEGRATION_PORT') ?: 6391);
$executor = new RespRedisCommandExecutor($host, $port);
const NS = 'measure:pending-206';
const NOW = 2000000;
$limits = [1, 2, 4, 8, 16, 32, null];

/** @return array{admitted: int, refused: int, pendingCause: int} */
$run = static function (int $users, ?int $limit, string $op, string $ip, int $holdMs, int $jitterMs) use ($executor, $host, $port): array {
    $results = AdmissionRaceRunner::run($host, $port, $users, new AdmissionRaceSpec(NS, $op, 'store', NOW, pendingLimit: $limit, ip: $ip, holdMs: $holdMs, jitterMs: $jitterMs));
    $admitted = AdmissionRaceRunner::admitted($results);
    $pending = count(array_filter($results, static fn(array $r): bool => ! $r['ok'] && $r['refused'] === 'source_pending_permits'));

    return ['admitted' => $admitted, 'refused' => $users - $admitted, 'pendingCause' => $pending];
};
$open = static function () use ($executor): void {
    $executor->execute(['FLUSHDB']);
    $runtime = PublicRedisAdmissionFixture::build($executor, NS, NOW);
    $runtime->openAdmissionFence('login');
};
$label = static fn(?int $l): string => $l === null ? 'none' : (string) $l;

echo "## M1: legitimate users behind one source, verifier takes 100 ms, arrivals spread over J ms (3 repetitions each: admitted/users)\n\n";
echo "| users | J ms | " . implode(' | ', array_map(fn($l) => 'limit ' . $label($l), $limits)) . " |\n|---|---|" . str_repeat('---|', count($limits)) . "\n";
foreach ([[8, 0], [8, 400], [16, 0], [16, 400], [32, 400], [64, 400]] as [$users, $jitter]) {
    $cells = [];
    foreach ($limits as $limit) {
        $reps = [];
        for ($rep = 0; $rep < 3; $rep++) {
            $open();
            $r = $run($users, $limit, 'public_proven_session', '198.51.100.10', 100, $jitter);
            $reps[] = $r['admitted'];
            if ($r['refused'] !== $r['pendingCause']) {
                echo "UNEXPECTED refusal cause at users=$users limit=" . $label($limit) . "\n";
            }
        }
        $cells[] = implode('/', $reps) . " of $users";
    }
    echo "| $users | $jitter | " . implode(' | ', $cells) . " |\n";
}

echo "\n## M2: a flood on source A (64 independent proven credentials, no outcomes), then 16 legitimate users on source B\n\n";
echo "| limit | A admitted | A refused | B admitted of 16 | B refused |\n|---|---|---|---|---|\n";
foreach ($limits as $limit) {
    $open();
    $a = $run(64, $limit, 'public_proven', '198.51.100.20', 0, 0);
    $b = $run(16, $limit, 'public_proven_session', '198.51.100.21', 100, 400);
    echo '| ' . $label($limit) . " | {$a['admitted']} | {$a['refused']} | {$b['admitted']} | {$b['refused']} |\n";
}
$executor->execute(['FLUSHDB']);
