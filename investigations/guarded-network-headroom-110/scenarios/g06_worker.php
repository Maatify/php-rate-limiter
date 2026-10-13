<?php

declare(strict_types=1);

// Child process of g06_concurrency.php: ONE concurrent checkOnly (Login) on real Redis, started at Redis time $startAt (simultaneity), then
// one INCR of the round's completion counter. Same ownership checks as the parent except emptiness. Never flushes; loopback only.
// LAB_ROOT / GNH_ARM select the runtime like every scenario (provenance enforced).
//   php g06_worker.php <namespace> <ip> <fp|-> <account> <uaIdx> <startAt> <counterKey>
require dirname(__DIR__, 2) . '/workstream-b-anti-evasion/lib/redis.php';
require dirname(__DIR__) . '/lib/gnh.php';

[, $ns, $ip, $fpArg, $acct, $uaIdx, $startAt, $counterKey] = $argv + array_fill(0, 8, null);
gnhProvenance();
$ex = wsbRedisConnectVerified(false);
$rt = wsbRedisPipeline($ex, (string) $ns);
$c = new \Maatify\RateLimiter\DTO\RateLimitContextDTO((string) $ip, k1Ua((int) $uaIdx), (string) $acct, $fpArg === '-' ? null : fp((string) $fpArg));
while ((int) $ex->execute(['TIME'])[0] < (int) $startAt) {
    usleep(500);
}
$check = wsbRedisRun($rt, $c, 'check');
$ex->execute(['INCR', (string) $counterKey]);
$ex->execute(['EXPIRE', (string) $counterKey, '120']);
echo $check, "\n";
