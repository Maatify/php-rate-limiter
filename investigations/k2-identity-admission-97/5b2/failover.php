<?php

declare(strict_types=1);

// NM-7 failover measurement, store level (5b2/PLAN.md §3). EVIDENCE ONLY: a measurement script, not a test and not package code.
//
// Per round: a primary and a replica (redis 7.0.15, asynchronous replication) are started; one client reserves sequentially through
// `RedisFullCapabilityStore` on the primary; after 500 acknowledged reservations the primary is killed (SIGKILL) and the replica is
// promoted. L = acknowledged reservations - reservations present on the promoted replica (the lost writes). L is REPORTED, never
// bounded or claimed bounded (SF §6.9).
//   Variant `natural`: nothing is injected, the lag is whatever asynchronous replication gives on this host.
//   Variant `lagged`: the replica is cut off from the network (`docker network disconnect`) for the last 100 acknowledged reservations before the kill, so the
//                     replication lag is a known 100; this shows what L equals when the lag is known.
//   php 5b2/failover.php [rounds=5]

use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Repository\Redis\RedisAdmissionOperations;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

const IMAGE = 'redis:7.0.15-alpine';
const ACKS_BEFORE_KILL = 500;
const LAG_WINDOW = 100;
const NOW = 1767268800 + 900;

function sh(string ...$args): string
{
    $cmd = implode(' ', array_map('escapeshellarg', $args));
    $out = [];
    exec($cmd . ' 2>&1', $out, $status);
    if ($status !== 0) {
        throw new RuntimeException("command failed ($status): $cmd\n" . implode("\n", $out));
    }

    return trim(implode("\n", $out));
}

function hostPort(string $container): int
{
    return (int) substr(strrchr(sh('docker', 'port', $container, '6379/tcp'), ':'), 1);
}

function waitReady(int $port): RespRedisCommandExecutor
{
    for ($i = 0; $i < 100; $i++) {
        try {
            $client = new RespRedisCommandExecutor('127.0.0.1', $port);
            if ($client->execute(['PING']) === 'PONG') {
                return $client;
            }
        } catch (Throwable) {
        }
        usleep(100000);
    }
    throw new RuntimeException('redis did not start');
}

/** @return array{round: int, variant: string, acknowledged: int, onReplica: int, lost: int} */
function runRound(int $round, string $variant): array
{
    $tag = 'm5b2-' . getmypid() . "-$round-$variant";
    $net = $tag . '-net';
    $primary = $tag . '-p';
    $replica = $tag . '-r';
    sh('docker', 'network', 'create', $net);
    try {
        sh('docker', 'run', '-d', '--name', $primary, '--network', $net, '-p', '127.0.0.1::6379', IMAGE, 'redis-server', '--save', '', '--appendonly', 'no');
        sh('docker', 'run', '-d', '--name', $replica, '--network', $net, '-p', '127.0.0.1::6379', IMAGE, 'redis-server', '--save', '', '--appendonly', 'no', '--replicaof', $primary, '6379');
        $primaryPort = hostPort($primary);
        $replicaPort = hostPort($replica);
        $p = waitReady($primaryPort);
        $r = waitReady($replicaPort);
        for ($i = 0; $i < 100 && ! str_contains((string) $r->execute(['INFO', 'replication']), 'master_link_status:up'); $i++) {
            usleep(100000);
        }
        $store = new RedisFullCapabilityStore($p, 'failover');
        $request = new AdmissionReservationRequestDTO('login', [new AdmissionScopeDTO('src', AdmissionScopeKindEnum::COUNTER, 'failover:c', 1000000, 3600)]);
        $acknowledged = 0;
        $killed = false;
        $lagStart = $variant === 'lagged' ? ACKS_BEFORE_KILL - LAG_WINDOW : PHP_INT_MAX;
        while (true) {
            try {
                if ($store->reserveAdmission($request, NOW)->admitted) {
                    $acknowledged++;
                }
            } catch (Throwable) {
                break;
            }
            if ($acknowledged === $lagStart) {
                sh('docker', 'network', 'disconnect', $net, $replica);
            }
            if ($acknowledged === ACKS_BEFORE_KILL && ! $killed) {
                $killed = true;
                sh('docker', 'kill', '--signal', 'KILL', $primary);
            }
            if ($acknowledged > ACKS_BEFORE_KILL + 20000) {
                throw new RuntimeException('the primary did not die');
            }
        }
        // The replica is reached through `docker exec` so that a replica cut off from the network can still be promoted and read.
        sh('docker', 'exec', $replica, 'redis-cli', 'REPLICAOF', 'NO', 'ONE');
        $counterKey = (new RedisAdmissionOperations($p, 'failover'))->scopeKey('login', AdmissionScopeKindEnum::COUNTER, 'failover:c');
        $onReplica = (int) sh('docker', 'exec', $replica, 'redis-cli', 'GET', $counterKey);

        return ['round' => $round, 'variant' => $variant, 'acknowledged' => $acknowledged, 'onReplica' => $onReplica, 'lost' => $acknowledged - $onReplica];
    } finally {
        foreach ([$primary, $replica] as $name) {
            exec('docker rm -f ' . escapeshellarg($name) . ' >/dev/null 2>&1');
        }
        exec('docker network rm ' . escapeshellarg($net) . ' >/dev/null 2>&1');
    }
}

$rounds = (int) ($argv[1] ?? 5);
$results = [];
foreach (['natural', 'lagged'] as $variant) {
    for ($i = 1; $i <= $rounds; $i++) {
        $row = runRound($i, $variant);
        $results[$variant][] = $row['lost'];
        echo json_encode($row), "\n";
    }
}
foreach ($results as $variant => $lost) {
    sort($lost);
    echo json_encode(['summary' => $variant, 'rounds' => count($lost), 'min' => $lost[0], 'median' => $lost[intdiv(count($lost), 2)], 'max' => $lost[count($lost) - 1]]), "\n";
}
echo json_encode(['image' => IMAGE, 'acksBeforeKill' => ACKS_BEFORE_KILL, 'lagWindow' => LAG_WINDOW, 'note' => 'L reported, not bounded']), "\n";
