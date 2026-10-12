<?php

declare(strict_types=1);

// Child process of a05_redis.php: ONE concurrent recordFailure at a Redis-clock barrier. Same ownership checks as the parent
// (process_id + working dir) except for emptiness (the parent has already written state). Never flushes, loopback only.
require dirname(__DIR__) . '/lib/redis.php';

[, $ns, $fpId, $ipArg, $startAt] = $argv + [null, null, null, null, null];
$ex = wsbRedisConnectVerified(false);
$rt = wsbRedisPipeline($ex, (string) $ns);
$c = new \Maatify\RateLimiter\DTO\RateLimitContextDTO((string) $ipArg, CHROME, null, fp((string) $fpId));
while ((int) $ex->execute(['TIME'])[0] < (int) $startAt) {
    usleep(500);
}
echo wsbRedisRun($rt, $c, 'fail'), "\n";
