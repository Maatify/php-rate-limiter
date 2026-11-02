<?php

declare(strict_types=1);

// #181 S1 M-98 (PLAN §3, §10 LT-181-05): the #98 scenario through the public API.
//
//   php m98.php memory|redis
//
// new ApiHeavyProtectionPolicy(['k2' => 3]) (K1 600 and K3 300 as shipped); one subject (same IP and UA); successive
// recordSuccess at one fixed second (t0) until the first non-ALLOW decision, then AFTER_FIRST_BLOCK more; decision,
// blockLevel and retryAfter recorded per request, then the K2 score read through the operational reader.
// Exit 0 when the run is valid (the observations are the result), 3 when it is not.

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;

require dirname(__DIR__) . '/lib/arms.php';

const K2_THRESHOLD = 3;
const AFTER_FIRST_BLOCK = 10;
const MAX_REQUESTS = 50;

$arm = armName($argv);
$context = new RateLimitContextDTO(ip: '198.51.100.98', ua: 'Mozilla/5.0 (M-98) Chrome/130.0.0.0');
$clock = new FixedClock('@' . T0);
['store' => $store, 'redis' => $redisVersion, 'keys' => $keys] = armStore($arm, $clock);
$signals = new RecordingFailureSignalEmitter();
$builder = armBuilder($store, $clock, new ApiHeavyProtectionPolicy(['k2' => K2_THRESHOLD]), $signals);
$limiter = $builder->build();
$reader = $builder->buildOperationalReader();

$firstBlock = null;
for ($i = 1; $i <= MAX_REQUESTS && ($firstBlock === null || $i <= $firstBlock + AFTER_FIRST_BLOCK); $i++) {
    $result = $limiter->limit($context, RateLimitCommand::recordSuccess('api_heavy_protection'));
    if ($result->failureMode !== 'NORMAL') {
        harnessFail("request $i failure mode {$result->failureMode}");
    }
    if ($firstBlock === null && $result->decision !== RateLimitResultDTO::DECISION_ALLOW) {
        $firstBlock = $i;
    }
    emit(['arm' => $arm, 'request' => $i, 't' => 0, 'after_first_block' => $firstBlock === null ? null : $i - $firstBlock,
        'decision' => $result->decision, 'blockLevel' => $result->blockLevel, 'retryAfter' => $result->retryAfter]);
}
if ($firstBlock === null) {
    harnessFail('no block within ' . MAX_REQUESTS . ' requests');
}
$k2 = $reader->readScorePolicy($context, 'api_heavy_protection')->scopes->k2;
$row = ['arm' => $arm, 'redis' => $redisVersion, 'first_block' => $firstBlock, 'requests' => $i - 1,
    'k2_stored' => $k2->score?->value, 'k2_effective' => $k2->effectiveScore, 'signals' => count($signals->getEmitted()),
    'redis_keys' => $keys === null ? null : $keys()];
emit($row);
if ($signals->getEmitted() !== [] || $row['redis_keys'] === 0 || $k2->score?->value !== $i - 1) {
    harnessFail('signals, Redis keys or the K2 score do not match the requests sent');
}
exit(0);
