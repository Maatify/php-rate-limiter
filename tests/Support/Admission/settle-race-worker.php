<?php

declare(strict_types=1);

// Race worker of the settlement gate (5b2.1 NM-7): one permit-bearing reservation of a distinct member over a set scope
// whose permit deadline is `now` (already due), released at a shared barrier.
//   settle-race-worker.php <host> <port> <barrier-unix-float> <index> <base64-json-spec>
// Spec: {namespace, now, mode: store|nonatomic, key, capacity}. Prints {"ok": bool}.

use Maatify\RateLimiter\DTO\AdmissionPermitDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Repository\Redis\RedisAdmissionOperations;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Admission\ReplyValue;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$raw = $_SERVER['argv'] ?? [];
$args = array_values(array_filter(is_array($raw) ? $raw : [], 'is_string'));
if (count($args) !== 6) {
    fwrite(STDERR, "usage: settle-race-worker.php <host> <port> <barrier> <index> <spec>\n");
    exit(2);
}
[, $host, $port, $barrier, $index, $encoded] = $args;
$json = base64_decode($encoded, true);
$spec = $json === false ? null : json_decode($json, true, 8, JSON_THROW_ON_ERROR);
if (! is_array($spec) || ! is_string($spec['namespace'] ?? null) || ! is_int($spec['now'] ?? null) || ! is_string($spec['mode'] ?? null)
    || ! is_string($spec['key'] ?? null) || ! is_int($spec['capacity'] ?? null)) {
    fwrite(STDERR, "malformed spec\n");
    exit(2);
}
$executor = new RespRedisCommandExecutor($host, intval($port));
$store = new RedisFullCapabilityStore($executor, $spec['namespace']);
$operations = new RedisAdmissionOperations($executor, $spec['namespace']);
$now = $spec['now'];
$scope = new AdmissionScopeDTO('fail_src', AdmissionScopeKindEnum::SET_SIZE, $spec['key'], $spec['capacity'], 1200, null, 'new-' . $index);
$request = new AdmissionReservationRequestDTO('login', [$scope], new AdmissionPermitDTO('permit-' . $index, 'U', 'acctT', 'login', $now, 600));

while (microtime(true) < (float) $barrier) {
    usleep(200);
}

if ($spec['mode'] === 'nonatomic') {
    // DETECTOR CONTROL: count in one round trip, write in another. Never production code.
    $set = $operations->scopeKey('login', AdmissionScopeKindEnum::SET_SIZE, $spec['key']);
    $pending = $operations->pendingKey('login', $spec['key']);
    $size = ReplyValue::integer($executor->execute(['SCARD', $set])) + ReplyValue::integer($executor->execute(['ZCOUNT', $pending, '-inf', (string) $now]));
    if ($size >= $spec['capacity']) {
        echo json_encode(['ok' => false], JSON_THROW_ON_ERROR);
        exit(0);
    }
    usleep(2000);
    $executor->execute(['ZADD', $pending, (string) $now, 'new-' . $index]);
    $executor->execute(['EXPIRE', $pending, '1200']);
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    exit(0);
}

echo json_encode(['ok' => $store->reserveAdmission($request, $now)->admitted], JSON_THROW_ON_ERROR);
