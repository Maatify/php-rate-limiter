<?php

declare(strict_types=1);

// Child process of k07_redis.php: ONE concurrent checkOnly (Login) at a Redis-clock barrier. Same ownership checks as the parent (process_id +
// working dir) except for emptiness (the parent has already written state). Never flushes; loopback only.
require dirname(__DIR__, 2) . '/workstream-b-anti-evasion/lib/redis.php';
require dirname(__DIR__) . '/lib/k1.php';

[, $ns, $acct, $ipArg, $uaIdx, $startAt] = $argv + [null, null, null, null, null, null];
$ex = wsbRedisConnectVerified(false);
$rt = wsbRedisPipeline($ex, (string) $ns);
$c = new \Maatify\RateLimiter\DTO\RateLimitContextDTO((string) $ipArg, k1Ua((int) $uaIdx), (string) $acct, fp('w' . $acct));
while ((int) $ex->execute(['TIME'])[0] < (int) $startAt) {
    usleep(500);
}
echo wsbRedisRun($rt, $c, 'check'), "\n";
