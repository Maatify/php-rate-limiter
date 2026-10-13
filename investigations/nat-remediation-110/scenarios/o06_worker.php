<?php

declare(strict_types=1);

// Child process of o06_concurrency.php: ONE concurrent logical attempt (Login) on real Redis with a TWO-PHASE barrier:
//   phase 1  checkOnly at Redis time $startCheck (simultaneity), then INCR of the round's completion counter $barrierKey;
//   phase 2  (only if allowed, pre+out semantics) recordFailure ONLY after the completion counter reached $workers, i.e. after EVERY
//            worker's checkOnly has finished (F111-06: ordering is proven by the counter, not assumed from a time gap). Waiting longer
//            than 30 s for the counter aborts with exit 7 and a message that is not a decision (the parent counts it as not completed).
// Same ownership checks as the parent (process_id + working dir) except for emptiness (the parent has already written state). Never
// flushes; loopback only. LAB_ROOT / OQC_ARM select the runtime like every scenario.
require dirname(__DIR__, 2) . '/workstream-b-anti-evasion/lib/redis.php';
require dirname(__DIR__) . '/lib/oqc.php';

[, $ns, $ipArg, $fpArg, $acct, $uaIdx, $startCheck, $barrierKey, $workers] = $argv + array_fill(0, 9, null);
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
$ex->execute(['INCR', (string) $barrierKey]);
$ex->execute(['EXPIRE', (string) $barrierKey, '120']);
$out = [$check];
if (str_starts_with($check, 'check:A') && (int) $workers > 0) {
    $deadline = (int) $ex->execute(['TIME'])[0] + 30;
    while ((int) $ex->execute(['GET', (string) $barrierKey]) < (int) $workers) {
        if ((int) $ex->execute(['TIME'])[0] > $deadline) {
            fwrite(STDERR, "barrier timeout: not every checkOnly completed within 30 s\n");
            exit(7);
        }
        usleep(500);
    }
    $out[] = wsbRedisRun($rt, $c, 'fail');
}
echo implode('>', $out), "\n";
