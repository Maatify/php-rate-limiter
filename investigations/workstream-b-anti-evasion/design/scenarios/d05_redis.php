<?php

declare(strict_types=1);

// D05 — real Redis (instance owned and proven by run.sh; safety model of PR #103 F103-01 / PR #104). Redis owns time (DEC-016): the exact
// boundaries are proved on the controlled clock in D02; here only immediate sequences, structural invariants and CONCURRENCY are asserted,
// plus one LIVE recovery check on the Redis clock (R6, skipped when DSG_REDIS_LIVE=0, in which case the gate is UNPROVEN, never PASS).
//   R1  record-only semantics on the real adapter       R2  TTL hygiene and bounded growth (400 failing anchors)
//   R3  CONCURRENCY distinct fingerprints (cap race)     R4  CONCURRENCY one fingerprint (duplicate race: no inflated evidence)
//   R5  CONCURRENCY marker atomicity (no lost update)    R6  LIVE recovery on the Redis clock
require dirname(__DIR__, 2) . '/lib/redis.php';
require dirname(__DIR__) . '/lib/dsg.php';

use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

emit('D05.provenance', dsgProvenance());
$ex = wsbRedisConnectVerified(true);
$isS0 = wsbVariant() === 'S0';
$isEq = in_array(wsbVariant(), ['EQW', 'EQL'], true);

$inventory = static function (RespRedisCommandExecutor $ex): array {
    $keys = array_map('strval', (array) $ex->execute(['KEYS', '*']));
    $noTtl = 0;
    $maxSet = 0;
    $maxTtl = 0;
    foreach ($keys as $k) {
        $ttl = (int) $ex->execute(['TTL', $k]);
        if ($ttl < 0) {
            $noTtl++;
        }
        $maxTtl = max($maxTtl, $ttl);
        if ((string) $ex->execute(['TYPE', $k]) === 'set') {
            $maxSet = max($maxSet, (int) $ex->execute(['SCARD', $k]));
        }
    }

    return ['keys' => count($keys), 'keysWithoutTtl' => $noTtl, 'maxSetCardinality' => $maxSet, 'maxTtlSeconds' => $maxTtl];
};

$labKeys = new Lab('login');
$k2Of = static fn(string $ip): string => $labKeys->keys(new \Maatify\RateLimiter\DTO\RateLimitContextDTO($ip, CHROME, null, fp('x')))['K2'];
// R1 — record-only churn on one K2 (A, B, C failing): the third is denied.
$rt = wsbRedisPipeline($ex, 'dsg:r1');
$steps = [];
foreach (['A', 'B', 'C'] as $cl) {
    $steps[] = wsbRedisRun($rt, new \Maatify\RateLimiter\DTO\RateLimitContextDTO(NAT, CHROME, null, fp($cl)), 'fail');
}
emit('D05.R1.record-only-churn', ['variant' => wsbVariant(), 'steps' => $steps, 'thirdDenied' => str_contains($steps[2], 'H2')]);
WsbAsserts::check('D05', 'R1: three failing fingerprints on one K2 are denied at the third attempt on real Redis', str_contains($steps[2], 'H2'), ['steps' => $steps]);

// R1b — the third member SUCCEEDS: S0 enforces from history, the evidence-qualified variants must not.
$rt = wsbRedisPipeline($ex, 'dsg:r1b');
$steps = [];
foreach ([['A', 'fail'], ['B', 'fail'], ['C', 'ok']] as [$cl, $o]) {
    $steps[] = wsbRedisRun($rt, new \Maatify\RateLimiter\DTO\RateLimitContextDTO(NAT, CHROME, null, fp($cl)), $o);
}
emit('D05.R1b.third-member-success', ['variant' => wsbVariant(), 'steps' => $steps, 'thirdDenied' => str_contains($steps[2], 'H2')]);
if ($isEq) {
    // A and B failed seconds ago: their evidence markers are still fresh, so a trigger reached by a SUCCESSFUL third member IS enforced
    // (fresh evidence exists in the scope). The no-evidence case is R1c.
    WsbAsserts::check('D05', 'R1b: a successful third member is enforced only because fresh failing evidence exists in the same scope', str_contains($steps[2], 'H2'), ['steps' => $steps]);
}

// R1c — nobody failed: three SUCCESSFUL fingerprints on one K2.
$rt = wsbRedisPipeline($ex, 'dsg:r1c');
$steps = [];
foreach (['A', 'B', 'C', 'D'] as $cl) {
    $steps[] = wsbRedisRun($rt, new \Maatify\RateLimiter\DTO\RateLimitContextDTO(NAT, CHROME, null, fp($cl)), 'ok');
}
emit('D05.R1c.no-evidence', ['variant' => wsbVariant(), 'steps' => $steps, 'anyDenied' => str_contains(implode(',', $steps), 'H')]);
if ($isEq) {
    WsbAsserts::check('D05', 'R1c: four SUCCESSFUL fingerprints on one K2 are never denied on real Redis (no evidence)', ! str_contains(implode(',', $steps), 'H'), ['steps' => $steps]);
}

// R2 — TTL hygiene and growth under anchor rotation: 400 failing requests, each a new IP + new fingerprint.
$before = $inventory($ex);
$rt = wsbRedisPipeline($ex, 'dsg:r2');
$denied = 0;
for ($i = 1; $i <= 400; $i++) {
    $r = wsbRedisRun($rt, new \Maatify\RateLimiter\DTO\RateLimitContextDTO('10.77.' . intdiv($i, 250) . '.' . ($i % 250 + 1), CHROME, null, fp("rot$i")), 'fail');
    $denied += str_contains($r, 'H') ? 1 : 0;
}
$after = $inventory($ex);
$grown = $after['keys'] - $before['keys'];
emit('D05.R2.anchor-rotation', ['variant' => wsbVariant(), 'requests' => 400, 'denied' => $denied, 'keysPerRequestRounded' => (int) round($grown / 400), 'keysWithoutTtl' => $after['keysWithoutTtl'], 'maxSetCardinality' => $after['maxSetCardinality'], 'maxTtlWithinTwoDays' => $after['maxTtlSeconds'] <= 172800]);
WsbAsserts::check('D05', 'R2: every key created has a TTL (no immortal state) on real Redis', $after['keysWithoutTtl'] === 0, ['keysWithoutTtl' => $after['keysWithoutTtl']]);
WsbAsserts::check('D05', 'R2 negative control: the rotation probes reached storage (no denial) and created keys', $denied === 0 && $grown > 0, ['denied' => $denied]);
WsbAsserts::check('D05', 'R2: largest set on real Redis stays within the largest declared cap (50)', $after['maxSetCardinality'] <= 50, ['max' => $after['maxSetCardinality']]);

// Concurrency helpers: N workers record the same outcome at one Redis-clock barrier (PR #104 worker, unchanged).
$worker = dirname(__DIR__, 2) . '/scenarios/a05_redis_worker.php';
$spawnAll = static function (string $ns, array $jobs, int $startAt) use ($worker): array {
    $procs = [];
    foreach ($jobs as [$fpId, $ip]) {
        $env = [
            'WSB_REDIS_PORT' => getenv('WSB_REDIS_PORT'), 'WSB_REDIS_PID' => getenv('WSB_REDIS_PID'), 'WSB_REDIS_DIR' => getenv('WSB_REDIS_DIR'),
            'LAB_ROOT' => getenv('LAB_ROOT'), 'WSB_CANDIDATE' => getenv('WSB_CANDIDATE') ?: '', 'PATH' => getenv('PATH'),
        ];
        $p = proc_open([PHP_BINARY, $worker, $ns, $fpId, $ip, (string) $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $procs[] = [$p, $pipes];
    }
    $out = [];
    foreach ($procs as [$p, $pipes]) {
        $out[] = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
    }

    return $out;
};
$ok = static fn(array $out): int => count(array_filter($out, static fn(string $o): bool => str_starts_with($o, 'fail:')));

// R3 — distinct-member concurrency: 8 workers with 8 DIFFERENT fingerprints on one K2 at one instant, 12 rounds. The cap must hold and the
// threshold must be reached (a block exists) in every round.
$store3 = new RedisFullCapabilityStore($ex, 'dsg:r3');
$completed = 0;
$blockedRounds = 0;
for ($round = 1; $round <= 12; $round++) {
    $ip = '10.99.' . $round . '.1';
    $out = $spawnAll('dsg:r3', array_map(static fn(int $w): array => ["d{$round}x{$w}", $ip], range(0, 7)), (int) $ex->execute(['TIME'])[0] + 2);
    $completed += $ok($out);
    $blockedRounds += $store3->checkBlock($k2Of($ip)) !== null ? 1 : 0;
}
$inv = $inventory($ex);
emit('D05.R3.distinct-member-race', ['variant' => wsbVariant(), 'rounds' => 12, 'workersCompleted' => $completed, 'roundsWithK2Block' => $blockedRounds, 'maxSetCardinality' => $inv['maxSetCardinality']]);
WsbAsserts::check('D05', 'R3 negative control: all 96 concurrent workers completed', $completed === 96, ['completed' => $completed]);
WsbAsserts::check('D05', 'R3: concurrent distinct failing members never push a set above the largest declared cap (50)', $inv['maxSetCardinality'] <= 50, ['max' => $inv['maxSetCardinality']]);
WsbAsserts::check('D05', 'R3: in every round the concurrent failing members reached the threshold and a K2 block exists (no lost evidence)', $blockedRounds === 12, ['roundsWithK2Block' => $blockedRounds]);

// R4 — duplicate-member concurrency: 8 workers record the SAME fingerprint failure at one barrier, 12 rounds. One fingerprint is ONE member:
// the evidence must never reach the threshold from duplicates alone (the failure of PR #104's PW tally).
$store4 = new RedisFullCapabilityStore($ex, 'dsg:r4');
$completed = 0;
$blockedRounds = 0;
for ($round = 1; $round <= 12; $round++) {
    $ip = '10.88.' . $round . '.1';
    $out = $spawnAll('dsg:r4', array_map(static fn(int $w): array => ["dup$round", $ip], range(0, 7)), (int) $ex->execute(['TIME'])[0] + 2);
    $completed += $ok($out);
    $blockedRounds += $store4->checkBlock($k2Of($ip)) !== null ? 1 : 0;
}
emit('D05.R4.duplicate-member-race', ['variant' => wsbVariant(), 'rounds' => 12, 'workersCompleted' => $completed, 'roundsWithK2Block' => $blockedRounds]);
WsbAsserts::check('D05', 'R4 negative control: all 96 concurrent workers completed', $completed === 96, ['completed' => $completed]);
WsbAsserts::check('D05', 'R4: 8 concurrent failures of ONE fingerprint never create a block (duplicates never inflate evidence)', $blockedRounds === 0, ['roundsWithK2Block' => $blockedRounds]);

// R5 — marker atomicity: after the R4 rounds every worker wrote the marker for its K2 scope (same fingerprint, no block). The atomic WATCH
// counter must equal the number of workers that completed (no lost update, no duplicate).
if ($isEq) {
    $policy = 'login_protection';
    $exact = 0;
    for ($round = 1; $round <= 12; $round++) {
        $ip = '10.88.' . $round . '.1';
        $scope = hash_hmac('sha256', "{$policy}:rate_limiter:correlation:churn:v1:prod:scope:" . $k2Of($ip), Lab::SECRET);
        $marker = hash_hmac('sha256', "{$policy}:rate_limiter:eq:fresh:churn:v1:prod:{$scope}", Lab::SECRET);
        $exact += $store4->getWatchFlag($marker) === 8 ? 1 : 0;
    }
    emit('D05.R5.marker-atomicity', ['variant' => wsbVariant(), 'rounds' => 12, 'roundsWithExactCount8' => $exact]);
    WsbAsserts::check('D05', 'R5: the evidence marker counts exactly the 8 concurrent writers in every round (atomic increment, no lost update)', $exact === 12, ['roundsWithExactCount8' => $exact]);
} else {
    emit('D05.R5.marker-atomicity', ['variant' => wsbVariant(), 'applicable' => false, 'reason' => 'no evidence marker in this variant']);
}

// R6 — LIVE recovery on the Redis clock: three failing fingerprints block the K2; ~62 s later a lone success-only client probes.
$live = getenv('DSG_REDIS_LIVE') !== '0' && in_array(wsbVariant(), ['S0', 'EQL'], true);
if ($live) {
    $rt = wsbRedisPipeline($ex, 'dsg:r6');
    $store6 = new RedisFullCapabilityStore($ex, 'dsg:r6');
    foreach (['A', 'B', 'C'] as $cl) {
        wsbRedisRun($rt, new \Maatify\RateLimiter\DTO\RateLimitContextDTO(NAT, CHROME, null, fp($cl)), 'fail');
    }
    $c = new \Maatify\RateLimiter\DTO\RateLimitContextDTO(NAT, CHROME, null, fp('LEG'));
    $immediate = wsbRedisRun($rt, $c, 'check');
    $deadline = (int) $ex->execute(['TIME'])[0] + 63;
    while ((int) $ex->execute(['TIME'])[0] < $deadline) {
        usleep(250000);
    }
    $afterBlock = wsbRedisRun($rt, $c, 'check');
    $afterOk = wsbRedisRun($rt, $c, 'ok');
    $freshFail = wsbRedisRun($rt, new \Maatify\RateLimiter\DTO\RateLimitContextDTO(NAT, CHROME, null, fp('NEW')), 'fail');
    $afterFresh = wsbRedisRun($rt, $c, 'check');
    emit('D05.R6.live-recovery', ['variant' => wsbVariant(), 'deniedWhileBlocked' => str_contains($immediate, 'H'), 'reblockedAfter63sFromHistory' => str_contains($afterBlock, 'H'), 'successAfter63s' => str_contains($afterOk, 'H') ? 'denied' : 'allowed', 'freshFailureAfterExpiry' => str_contains($freshFail, 'H') ? 'enforced' : 'allowed', 'legitDeniedAfterFreshFailure' => str_contains($afterFresh, 'H')]);
    WsbAsserts::check('D05', 'R6: the 60 s block is enforced right after the trigger on the Redis clock', str_contains($immediate, 'H'), ['immediate' => $immediate]);
    if ($isS0) {
        WsbAsserts::check('D05', 'S0 R6: the stale set re-blocks a lone legitimate client 63 s after the trigger (history-only renewal)', str_contains($afterBlock, 'H'), ['afterBlock' => $afterBlock]);
    } else {
        WsbAsserts::check('D05', 'R6: a lone legitimate client is allowed 63 s after the trigger (block and evidence both expired; no history-only re-block)', ! str_contains($afterBlock, 'H') && ! str_contains($afterOk, 'H'), ['afterBlock' => $afterBlock, 'afterOk' => $afterOk]);
        WsbAsserts::check('D05', 'R6: a FRESH failing fingerprint after the expiry is fresh evidence and re-blocks the K2 on the Redis clock', str_contains($freshFail, 'H') || str_contains($afterFresh, 'H'), ['freshFail' => $freshFail, 'afterFresh' => $afterFresh]);
    }
} else {
    emit('D05.R6.live-recovery', ['variant' => wsbVariant(), 'applicable' => false, 'reason' => getenv('DSG_REDIS_LIVE') === '0' ? 'skipped by DSG_REDIS_LIVE=0 (UNPROVEN)' : 'only S0 and EQL run the 63 s live check']);
}

WsbAsserts::finish();
