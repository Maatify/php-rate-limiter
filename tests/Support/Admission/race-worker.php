<?php

declare(strict_types=1);

// Race worker for the store gates (NM-6 R-1 / R-2, NM-7, PERMIT): one operation released at a shared barrier instant.
//   race-worker.php <host> <port> <barrier-unix-float> <index> <base64-json-spec>
// Prints one JSON object: {"ok": bool, "refused": ?string, "claim": ?string}.

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionPermitDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Repository\Redis\RedisAdmissionOperations;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRaceSpec;
use Maatify\RateLimiter\Tests\Support\Admission\NonAtomicAdmissionControl;
use Maatify\RateLimiter\Tests\Support\Admission\PublicRedisAdmissionFixture;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$args = [];
$raw = $_SERVER['argv'] ?? [];
foreach (is_array($raw) ? $raw : [] as $argument) {
    if (is_string($argument)) {
        $args[] = $argument;
    }
}
if (count($args) !== 6) {
    fwrite(STDERR, "usage: race-worker.php <host> <port> <barrier> <index> <spec>\n");
    exit(2);
}
[, $host, $port, $barrier, $index, $encoded] = $args;
$json = base64_decode($encoded, true);
if ($json === false) {
    fwrite(STDERR, "malformed spec\n");
    exit(2);
}
$spec = AdmissionRaceSpec::fromJson($json);
$executor = new RespRedisCommandExecutor($host, intval($port));
// These suites assert exactly-once effects under overlap, not latency: a CPU-starved runner must not turn a slow retry into a concurrency block.
$store = new RedisFullCapabilityStore($executor, $spec->namespace, null, 60_000);
$operations = new RedisAdmissionOperations($executor, $spec->namespace);
$fill = static fn(string $value): string => str_replace('{i}', $index, $value);
$at = static fn(?string $value): ?string => $value === null ? null : $fill($value);
$reply = static fn(bool $ok, ?string $refused = null, ?string $claim = null): string => json_encode(['ok' => $ok, 'refused' => $refused, 'claim' => $claim], JSON_THROW_ON_ERROR);

$scopes = [];
foreach ($spec->scopes as $s) {
    $scopes[] = new AdmissionScopeDTO(
        $s['name'],
        AdmissionScopeKindEnum::from($s['kind']),
        $fill($s['key']),
        $s['capacity'],
        $s['ttl'],
        $at($s['previousKey'] ?? null),
        $at($s['member'] ?? null),
        $at($s['previousMember'] ?? null),
    );
}

$runtime = $spec->op === 'public_check' ? PublicRedisAdmissionFixture::build($executor, $spec->namespace, $spec->now, 60_000) : null;

if ($runtime !== null && microtime(true) >= (float) $barrier) {
    fwrite(STDERR, "Public runtime worker missed the start barrier.\n");
    exit(2);
}
while (microtime(true) < (float) $barrier) {
    usleep(200);
}

if ($runtime !== null) {
    $result = $runtime->limit(
        new RateLimitContextDTO(ip: '192.0.2.10', ua: 'redis-gate', accountId: 'redis-gate-' . $index),
        RateLimitCommand::checkOnly('login_protection'),
    );
    echo $reply($result->isAllowed() && $result->metadata?->permit !== null, $result->metadata?->cause);
    exit(0);
}

if ($spec->op === 'outcome') {
    if ($spec->outcome === null) {
        fwrite(STDERR, "outcome op without an outcome\n");
        exit(2);
    }
    $store->applyAdmissionOutcome(new AdmissionOutcomeDTO('login', AdmissionOutcomeKindEnum::from($spec->outcome), $scopes), $spec->now);
    echo $reply(true);
    exit(0);
}

if ($spec->op === 'claim') {
    $permitId = $spec->permit;
    if ($permitId === null) {
        fwrite(STDERR, "claim op without a permit\n");
        exit(2);
    }
    if ($spec->mode === 'nonatomic') {
        echo $reply((new NonAtomicAdmissionControl($executor, $operations))->claim($permitId));
        exit(0);
    }
    $claim = $store->claimAdmissionPermit($permitId, 'U', 'acctT', 'login', AdmissionOutcomeKindEnum::FAILURE, $spec->now);
    echo $reply($claim->value === 'accepted', null, $claim->value);
    exit(0);
}

if ($spec->op === 'claim_with_outcome') {
    $permitId = $spec->permit;
    if ($permitId === null) {
        fwrite(STDERR, "claim_with_outcome op without a permit\n");
        exit(2);
    }
    $outcome = AdmissionOutcomeKindEnum::from($spec->outcome ?? 'failure');
    $outcomeDTO = new AdmissionOutcomeDTO('login', $outcome, $scopes, $permitId);
    $claim = $store->claimAdmissionPermitWithOutcome('U', 'acctT', 'login', $outcomeDTO, $spec->now);
    echo $reply($claim->value === 'accepted', null, $claim->value);
    exit(0);
}

$issue = $at($spec->issuePermit);
$permit = $issue === null ? null : new AdmissionPermitDTO($issue, 'U', 'acctT', 'login', $spec->now + 30, 600);
$request = new AdmissionReservationRequestDTO('login', $scopes, $permit);
if ($spec->mode === 'nonatomic') {
    echo $reply((new NonAtomicAdmissionControl($executor, $operations))->reserve($request, $spec->now));
    exit(0);
}
$result = $store->reserveAdmission($request, $spec->now);
echo $reply($result->admitted, $result->refusedScope);
