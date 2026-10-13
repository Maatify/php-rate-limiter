<?php

declare(strict_types=1);

// Child process of o06_concurrency.php: ONE concurrent logical attempt (Login) on real Redis with a TWO-PHASE Redis-clock barrier:
// checkOnly at $startCheck, then (only if allowed, pre+out semantics) recordFailure at $startFail. The two barriers guarantee that every
// worker's checkOnly precedes every worker's recordFailure. Same ownership checks as the parent (process_id + working dir) except for
// emptiness (the parent has already written state). Never flushes; loopback only. LAB_ROOT / OQC_ARM select the runtime like every scenario.
require dirname(__DIR__, 2) . '/workstream-b-anti-evasion/lib/redis.php';
require dirname(__DIR__) . '/lib/oqc.php';

[, $ns, $ipArg, $fpArg, $acct, $uaIdx, $startCheck, $startFail] = $argv + array_fill(0, 8, null);
$ex = wsbRedisConnectVerified(false);
$rt = wsbRedisPipeline($ex, (string) $ns);
$c = new \Maatify\RateLimiter\DTO\RateLimitContextDTO((string) $ipArg, k1Ua((int) $uaIdx), (string) $acct, $fpArg === '-' ? null : fp((string) $fpArg));
$wait = static function (int $until) use ($ex): void {
    while ((int) $ex->execute(['TIME'])[0] < $until) {
        usleep(500);
    }
};
$wait((int) $startCheck);
$check = wsbRedisRun($rt, $c, 'check');
$out = [$check];
if (str_starts_with($check, 'check:A') && (int) $startFail > 0) {
    $wait((int) $startFail);
    $out[] = wsbRedisRun($rt, $c, 'fail');
}
echo implode('>', $out), "\n";
