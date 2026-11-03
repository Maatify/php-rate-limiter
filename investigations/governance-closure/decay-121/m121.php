<?php

declare(strict_types=1);

// #181 S1 M-121 (PLAN §3, §10 LT-181-04): the #121 decay scenario as a red test, through the public API.
//
//   php m121.php memory|redis
//
// ApiHeavyProtectionPolicy with thresholds no request reaches; one subject (same IP and UA); six recordSuccess, then
// the K1 (IP, 180 s) score read at t0 + 500 through buildOperationalReader()->readScorePolicy().
//   control  six requests at t0
//   tested   one request at t0, t0 + 100, ..., t0 + 500
// The documented contract ("IP score: -1 every 3 minutes", docs/POLICIES.md L405) gives 6 - floor(500 / 180) = 4 in
// both cases. Exit 0 if both are 4 (green), 1 if not (red), 3 if the run itself is not valid.

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;

require dirname(__DIR__) . '/lib/arms.php';

const CONTRACT_SCORE = 4;

$arm = armName($argv);
$context = new RateLimitContextDTO(ip: '198.51.100.121', ua: 'Mozilla/5.0 (M-121) Firefox/131.0');
$cases = ['control' => [0, 0, 0, 0, 0, 0], 'tested' => [0, 100, 200, 300, 400, 500]];
$red = false;
foreach ($cases as $case => $offsets) {
    $clock = new FixedClock('@' . T0);
    ['store' => $store, 'redis' => $redisVersion, 'keys' => $keys] = armStore($arm, $clock);
    $signals = new RecordingFailureSignalEmitter();
    $builder = armBuilder($store, $clock, new ApiHeavyProtectionPolicy(['k1' => 1000, 'k2' => 1000, 'k3' => 1000]), $signals);
    $limiter = $builder->build();
    $reader = $builder->buildOperationalReader();
    foreach ($offsets as $i => $offset) {
        $clock->setNow(new DateTimeImmutable('@' . (T0 + $offset)));
        $result = $limiter->limit($context, RateLimitCommand::recordSuccess('api_heavy_protection'));
        emit(['arm' => $arm, 'case' => $case, 'request' => $i + 1, 't' => $offset, 'decision' => $result->decision,
            'retryAfter' => $result->retryAfter, 'failureMode' => $result->failureMode]);
        if ($result->decision !== RateLimitResultDTO::DECISION_ALLOW || $result->failureMode !== 'NORMAL') {
            harnessFail("$case request " . ($i + 1) . " was {$result->decision}/{$result->failureMode}, not ALLOW/NORMAL");
        }
    }
    $clock->setNow(new DateTimeImmutable('@' . (T0 + 500)));
    $snapshot = $reader->readScorePolicy($context, 'api_heavy_protection');
    $k1 = $snapshot->scopes->k1;
    $row = ['arm' => $arm, 'case' => $case, 'read_at' => 500, 'redis' => $redisVersion, 'backendHealthy' => $snapshot->backendHealthy,
        'k1_stored' => $k1->score?->value, 'k1_updatedAt' => $k1->score === null ? null : $k1->score->updatedAt - T0,
        'k1_effective' => $k1->effectiveScore, 'k2_stored' => $snapshot->scopes->k2->score?->value,
        'k2_effective' => $snapshot->scopes->k2->effectiveScore, 'signals' => count($signals->getEmitted()), 'redis_keys' => $keys === null ? null : $keys(),
        'contract' => CONTRACT_SCORE];
    $row['green'] = $k1->effectiveScore === CONTRACT_SCORE;
    emit($row);
    if ($k1->score?->value !== 6 || ! $snapshot->backendHealthy || $signals->getEmitted() !== [] || $row['redis_keys'] === 0) {
        harnessFail("$case: stored K1 is " . var_export($k1->score?->value, true) . ', or backend, signals or Redis keys not clean');
    }
    $red = $red || ! $row['green'];
}
exit($red ? 1 : 0);
