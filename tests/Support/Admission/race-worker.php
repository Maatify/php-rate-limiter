<?php

declare(strict_types=1);

// Race worker for the store gates (NM-6 R-1 / R-2, NM-7, PERMIT): one operation released at a shared barrier instant.
//   race-worker.php <host> <port> <barrier-unix-float> <index> <base64-json-spec>
// Prints one JSON object: {"ok": bool, "refused": ?string, "claim": ?string}.

use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\DTO\AdmissionPermitDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Repository\Redis\RedisAdmissionOperations;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Admission\NonAtomicAdmissionControl;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[$_, $host, $port, $barrier, $index, $encoded] = $argv;
$spec = json_decode((string) base64_decode($encoded, true), true, 512, JSON_THROW_ON_ERROR);
$executor = new RespRedisCommandExecutor($host, (int) $port);
$store = new RedisFullCapabilityStore($executor, (string) $spec['namespace']);
$operations = new RedisAdmissionOperations($executor, (string) $spec['namespace']);
$at = static fn(?string $value): ?string => $value === null ? null : str_replace('{i}', (string) $index, $value);

$scopes = [];
foreach ($spec['scopes'] ?? [] as $s) {
    $scopes[] = new AdmissionScopeDTO(
        $s['name'],
        AdmissionScopeKindEnum::from($s['kind']),
        (string) $at($s['key']),
        (int) $s['capacity'],
        (int) $s['ttl'],
        $at($s['previousKey'] ?? null),
        $at($s['member'] ?? null),
        $at($s['previousMember'] ?? null),
    );
}
$now = (int) $spec['now'];

while (microtime(true) < (float) $barrier) {
    usleep(200);
}

if ($spec['op'] === 'outcome') {
    $store->applyAdmissionOutcome(new AdmissionOutcomeDTO('login', AdmissionOutcomeKindEnum::from((string) $spec['outcome']), $scopes), $now);
    echo json_encode(['ok' => true, 'refused' => null, 'claim' => null]);
    exit(0);
}

if ($spec['op'] === 'claim') {
    if ($spec['mode'] === 'nonatomic') {
        echo json_encode(['ok' => (new NonAtomicAdmissionControl($executor, $operations))->claim($spec['permit']), 'refused' => null, 'claim' => null]);
        exit(0);
    }
    $claim = $store->claimAdmissionPermit($spec['permit'], 'U', 'acctT', 'login', AdmissionOutcomeKindEnum::FAILURE, $now);
    echo json_encode(['ok' => $claim->value === 'accepted', 'refused' => null, 'claim' => $claim->value]);
    exit(0);
}

$permit = isset($spec['issuePermit']) ? new AdmissionPermitDTO((string) $at($spec['issuePermit']), 'U', 'acctT', 'login', $now + 30, 600) : null;
$request = new AdmissionReservationRequestDTO('login', $scopes, $permit);
if ($spec['mode'] === 'nonatomic') {
    echo json_encode(['ok' => (new NonAtomicAdmissionControl($executor, $operations))->reserve($request, $now), 'refused' => null, 'claim' => null]);
    exit(0);
}
$result = $store->reserveAdmission($request, $now);
echo json_encode(['ok' => $result->admitted, 'refused' => $result->refusedScope, 'claim' => null]);
