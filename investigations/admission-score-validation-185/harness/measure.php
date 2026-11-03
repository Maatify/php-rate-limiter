<?php

declare(strict_types=1);

/**
 * F-185-01 cost: server-side time of the reservation script (Redis `INFO commandstats`, `cmdstat_eval` usec_per_call)
 * over fixtures shaped like LT-27 (a key rotation leaves a current and a previous generation of the device set), at the
 * largest sizes the sorted sets can reach, plus the pending set of a settling failure set. Every measured call writes
 * nothing (an existing member, or a refusal), so each iteration sees the same state; the harness asserts that.
 *
 * php investigations/admission-score-validation-185/harness/measure.php --src=<src dir> --host=127.0.0.1 --port=<port>
 *     [--iterations=2000]
 * Prints one JSON line per fixture.
 */

require __DIR__ . '/lib.php';

use Maatify\RateLimiter\DTO\AdmissionPermitDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum as K;
use Maatify\RateLimiter\Repository\Redis\RedisAdmissionOperations;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

$options = f185_options();
f185_boot($options['src']);
$redis = f185_redis($options['host'], (int) $options['port']);
$iterations = (int) ($options['iterations'] ?? 2000);

const NOW = 1767268800 + 900;
const CLS = 'login';
const DAY = 86400;

/** Seeds a zset with `$n` members `prefix-i` at `$score`, in batches. */
function seedZset(RespRedisCommandExecutor $redis, string $key, string $prefix, int $n, int $score, int $ttl): void
{
    for ($start = 0; $start < $n; $start += 500) {
        $command = ['ZADD', $key];
        for ($i = $start; $i < min($n, $start + 500); $i++) {
            $command[] = (string) $score;
            $command[] = $prefix . '-' . $i;
        }
        f185_raw($redis, ...$command);
    }
    f185_raw($redis, 'EXPIRE', $key, (string) $ttl);
}

function seedSet(RespRedisCommandExecutor $redis, string $key, string $prefix, int $n, int $ttl): void
{
    for ($start = 0; $start < $n; $start += 500) {
        $command = ['SADD', $key];
        for ($i = $start; $i < min($n, $start + 500); $i++) {
            $command[] = $prefix . '-' . $i;
        }
        f185_raw($redis, ...$command);
    }
    f185_raw($redis, 'EXPIRE', $key, (string) $ttl);
}

/**
 * @return array{request: AdmissionReservationRequestDTO, expectAdmitted: bool, members: int}
 */
function fixture(RespRedisCommandExecutor $redis, RedisAdmissionOperations $ops, string $name, int $size): array
{
    f185_raw($redis, 'FLUSHDB');
    $cur = $ops->scopeKey(CLS, K::DEVICE_MEMBER, 'pdev:cur');
    $prev = $ops->scopeKey(CLS, K::DEVICE_MEMBER, 'pdev:prev');
    switch ($name) {
        case 'device-existing':
            // Current: `size` live members; previous (read-only since the rotation): `size` members, expired.
            seedZset($redis, $cur, 'cur', $size, NOW + 3600, DAY);
            seedZset($redis, $prev, 'prev', $size, NOW - 60, DAY);
            $scope = new AdmissionScopeDTO('new_device_cap', K::DEVICE_MEMBER, 'pdev:cur', $size, DAY, 'pdev:prev', 'cur-0', 'prev-0');

            return ['request' => new AdmissionReservationRequestDTO(CLS, [$scope]), 'expectAdmitted' => true, 'members' => 2 * $size];
        case 'device-refused':
            // Current: `size` live members (the cap); previous: `size` members, expired; a new member is refused.
            seedZset($redis, $cur, 'cur', $size, NOW + 3600, DAY);
            seedZset($redis, $prev, 'prev', $size, NOW - 60, DAY);
            $scope = new AdmissionScopeDTO('new_device_cap', K::DEVICE_MEMBER, 'pdev:cur', $size, DAY, 'pdev:prev', 'new', 'new-prev');

            return ['request' => new AdmissionReservationRequestDTO(CLS, [$scope]), 'expectAdmitted' => false, 'members' => 2 * $size];
        case 'pending-refused':
            // A settling failure set at its cap of 10 settled members, `size` pending (not yet due) members; refused.
            $set = $ops->scopeKey(CLS, K::SET_SIZE, 'pfail:w');
            $pending = $ops->pendingKey(CLS, 'pfail:w');
            seedSet($redis, $set, 'acct', 10, 2400);
            seedZset($redis, $pending, 'inflight', $size, NOW + 30, 2400);
            $scope = new AdmissionScopeDTO('source_proof_failure_cap', K::SET_SIZE, 'pfail:w', 10, 2400, null, 'new-acct', null, false, true);
            $permit = new AdmissionPermitDTO('p-measure', 'U', 'new-acct', 'login', NOW + 30, 600);

            return ['request' => new AdmissionReservationRequestDTO(CLS, [$scope], $permit), 'expectAdmitted' => false, 'members' => $size];
    }
    throw new LogicException($name);
}

function evalUsecPerCall(RespRedisCommandExecutor $redis): array
{
    $info = (string) f185_raw($redis, 'INFO', 'commandstats');
    if (preg_match('/^cmdstat_eval:calls=(\d+),usec=(\d+),usec_per_call=([0-9.]+)/m', $info, $m) !== 1) {
        throw new RuntimeException('cmdstat_eval missing');
    }

    return ['calls' => (int) $m[1], 'usec' => (int) $m[2], 'usec_per_call' => (float) $m[3]];
}

$version = (string) f185_raw($redis, 'INFO', 'server');
preg_match('/^redis_version:(\S+)/m', $version, $vm);

foreach ([['device-existing', [10, 100, 1000]], ['device-refused', [10, 100, 1000]], ['pending-refused', [10, 100, 1000]]] as [$name, $sizes]) {
    foreach ($sizes as $size) {
        $ops = new RedisAdmissionOperations($redis, 'f185-measure');
        $f = fixture($redis, $ops, $name, $size);
        $before = f185_dump($redis);
        // Warm-up (script cache), then measure.
        $ops->reserve($f['request'], NOW);
        f185_raw($redis, 'CONFIG', 'RESETSTAT');
        $wall = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $result = $ops->reserve($f['request'], NOW);
            if ($result->admitted !== $f['expectAdmitted']) {
                throw new RuntimeException($name . ': unexpected verdict');
            }
        }
        $wallNs = hrtime(true) - $wall;
        $stats = evalUsecPerCall($redis);
        if ($stats['calls'] !== $iterations) {
            throw new RuntimeException($name . ': eval calls ' . $stats['calls'] . ' !== ' . $iterations);
        }
        $after = f185_dump($redis);
        $same = array_map(static fn (array $k): array => [$k['type'], $k['value']], $before) === array_map(static fn (array $k): array => [$k['type'], $k['value']], $after);
        if (! $same) {
            throw new RuntimeException($name . ': the measured call wrote to Redis');
        }
        echo json_encode([
            'redis' => $vm[1] ?? '?',
            'fixture' => $name,
            'size' => $size,
            'zset_members' => $f['members'],
            'iterations' => $iterations,
            'server_usec_per_call' => $stats['usec_per_call'],
            'client_usec_per_call' => round($wallNs / $iterations / 1000, 2),
        ]) . "\n";
    }
}
