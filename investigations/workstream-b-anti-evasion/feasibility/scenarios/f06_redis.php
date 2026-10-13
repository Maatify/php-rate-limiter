<?php

declare(strict_types=1);

// F06 — real Redis (instance owned and proven by run.sh; safety model of PR #103 F103-01 / #104 / #105: loopback only, process-id + working-directory
// ownership proof, empty server, own key namespaces, no FLUSH*/CONFIG SET/DEBUG/SHUTDOWN/KEYS-on-foreign). Redis owns time (DEC-016): exact boundaries
// are proved on the controlled clock in F03; here only immediate sequences, structural invariants, CONCURRENCY, one LIVE recovery check and the
// backend-failure behaviour are asserted.
//   R1 semantics (3 failing fingerprints)       R1c no corroboration (4 successful fingerprints)   R2 TTL hygiene and bounded growth
//   R3 CONCURRENCY distinct failing members     R4 CONCURRENCY duplicate member                     R5 window/marker structure (cap, fixed TTL)
//   R6 LIVE recovery on the Redis clock         R7 Redis outage: the pipeline throws, never ALLOWs
require dirname(__DIR__, 2) . '/lib/redis.php';
require dirname(__DIR__) . '/lib/fsb.php';

use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

emit('F06.provenance', fsbProvenance());
$ex = wsbRedisConnectVerified(true);
$isS0 = wsbVariant() === 'S0';
$isPsc = in_array(wsbVariant(), ['PSC', 'PSCA'], true);
$redisPid = (int) (getenv('WSB_REDIS_PID') ?: '0');

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
$phys = new \ReflectionMethod(RedisFullCapabilityStore::class, 'key');
$policy = 'login_protection';

// R1 — record-only churn on one K2 (A, B, C failing): the third is denied.
$rt = wsbRedisPipeline($ex, 'fsb:r1');
$steps = [];
foreach (['A', 'B', 'C'] as $cl) {
    $steps[] = wsbRedisRun($rt, new \Maatify\RateLimiter\DTO\RateLimitContextDTO(NAT, CHROME, null, fp($cl)), 'fail');
}
emit('F06.R1.record-only-churn', ['variant' => wsbVariant(), 'steps' => $steps, 'thirdDenied' => str_contains($steps[2], 'H2')]);
WsbAsserts::check('F06', 'R1: three failing fingerprints on one K2 are denied at the third attempt on real Redis', str_contains($steps[2], 'H2'), ['steps' => $steps]);

// R1c — nobody failed: four SUCCESSFUL fingerprints on one K2 (S0 enforces from history; PSC needs corroborating failures).
$rt = wsbRedisPipeline($ex, 'fsb:r1c');
$steps = [];
foreach (['A', 'B', 'C', 'D'] as $cl) {
    $steps[] = wsbRedisRun($rt, new \Maatify\RateLimiter\DTO\RateLimitContextDTO(NAT, CHROME, null, fp($cl)), 'ok');
}
emit('F06.R1c.no-corroboration', ['variant' => wsbVariant(), 'steps' => $steps, 'anyDenied' => str_contains(implode(',', $steps), 'H')]);
if ($isPsc) {
    WsbAsserts::check('F06', 'R1c: four SUCCESSFUL fingerprints on one K2 are never denied on real Redis (no corroboration)', ! str_contains(implode(',', $steps), 'H'), ['steps' => $steps]);
}

// R2 — TTL hygiene and growth under anchor rotation: 400 failing requests, each a new IP + new fingerprint.
$before = $inventory($ex);
$rt = wsbRedisPipeline($ex, 'fsb:r2');
$denied = 0;
for ($i = 1; $i <= 400; $i++) {
    $r = wsbRedisRun($rt, new \Maatify\RateLimiter\DTO\RateLimitContextDTO('10.77.' . intdiv($i, 250) . '.' . ($i % 250 + 1), CHROME, null, fp("rot$i")), 'fail');
    $denied += str_contains($r, 'H') ? 1 : 0;
}
$after = $inventory($ex);
$grown = $after['keys'] - $before['keys'];
emit('F06.R2.anchor-rotation', ['variant' => wsbVariant(), 'requests' => 400, 'denied' => $denied, 'keysPerRequestRounded' => (int) round($grown / 400), 'keysWithoutTtl' => $after['keysWithoutTtl'], 'maxSetCardinality' => $after['maxSetCardinality'], 'maxTtlWithinTwoDays' => $after['maxTtlSeconds'] <= 172800]);
WsbAsserts::check('F06', 'R2: every key created has a TTL (no immortal state) on real Redis', $after['keysWithoutTtl'] === 0, ['keysWithoutTtl' => $after['keysWithoutTtl']]);
WsbAsserts::check('F06', 'R2 negative control: the rotation probes reached storage (no denial) and created keys', $denied === 0 && $grown > 0, ['denied' => $denied]);
WsbAsserts::check('F06', 'R2: largest set on real Redis stays within the largest declared cap (50)', $after['maxSetCardinality'] <= 50, ['max' => $after['maxSetCardinality']]);

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
$scopeOf = static fn(string $k2): string => hash_hmac('sha256', "{$policy}:rate_limiter:correlation:churn:v1:prod:scope:{$k2}", Lab::SECRET);
$windowKey = static fn(string $scope): string => hash_hmac('sha256', "psc:window:v1:prod:churn:scope:{$scope}", Lab::SECRET);
$markerKey = static fn(string $scope): string => hash_hmac('sha256', "psc:qualified:v1:prod:churn:{$scope}", Lab::SECRET);

// R3 — distinct-member concurrency: 8 workers with 8 DIFFERENT failing fingerprints on one K2 at one instant, 12 rounds.
$store3 = new RedisFullCapabilityStore($ex, 'fsb:r3');
$completed = 0;
$blockedRounds = 0;
$windowCapOk = 0;
$windowTtlOk = 0;
$markerPresent = 0;
for ($round = 1; $round <= 12; $round++) {
    $ip = '10.99.' . $round . '.1';
    $out = $spawnAll('fsb:r3', array_map(static fn(int $w): array => ["d{$round}x{$w}", $ip], range(0, 7)), (int) $ex->execute(['TIME'])[0] + 2);
    $completed += $ok($out);
    $blockedRounds += $store3->checkBlock($k2Of($ip)) !== null ? 1 : 0;
    if ($isPsc) {
        $wk = (string) $phys->invoke($store3, 'distinct', $windowKey($scopeOf($k2Of($ip))));
        $windowCapOk += (int) $ex->execute(['SCARD', $wk]) <= 3 ? 1 : 0;
        $ttl = (int) $ex->execute(['TTL', $wk]);
        $windowTtlOk += ($ttl > 0 && $ttl <= 60) ? 1 : 0;
        $markerPresent += $store3->getWatchFlag($markerKey($scopeOf($k2Of($ip)))) >= 1 ? 1 : 0;
    }
}
$inv = $inventory($ex);
emit('F06.R3.distinct-member-race', ['variant' => wsbVariant(), 'rounds' => 12, 'workersCompleted' => $completed, 'roundsWithK2Block' => $blockedRounds, 'maxSetCardinality' => $inv['maxSetCardinality']] + ($isPsc ? ['roundsWindowSetWithinCap3' => $windowCapOk, 'roundsWindowTtlWithin60' => $windowTtlOk, 'roundsWithMarker' => $markerPresent] : []));
WsbAsserts::check('F06', 'R3 negative control: all 96 concurrent workers completed', $completed === 96, ['completed' => $completed]);
WsbAsserts::check('F06', 'R3: concurrent distinct failing members never push a set above the largest declared cap (50)', $inv['maxSetCardinality'] <= 50, ['max' => $inv['maxSetCardinality']]);
WsbAsserts::check('F06', 'R3: in every round the concurrent failing members reached the threshold and a K2 block exists (no lost corroboration)', $blockedRounds === 12, ['roundsWithK2Block' => $blockedRounds]);
if ($isPsc) {
    WsbAsserts::check('F06', 'R3: the 60 s window set never exceeds its cap of 3 under concurrency (atomic bounded add)', $windowCapOk === 12, ['rounds' => $windowCapOk]);
    WsbAsserts::check('F06', 'R3: the window set has a fixed TTL <= 60 s in every round (never refreshed past its window)', $windowTtlOk === 12, ['rounds' => $windowTtlOk]);
    WsbAsserts::check('F06', 'R3: the qualification marker exists in every round that reached the cap (atomic first-writer, no lost marker)', $markerPresent === 12, ['rounds' => $markerPresent]);
}

// R4 — duplicate-member concurrency: 8 workers record the SAME failing fingerprint at one barrier, 12 rounds. One fingerprint is ONE member: it must
// never corroborate (no marker, no block) — the failure of PR #104's PW tally.
$store4 = new RedisFullCapabilityStore($ex, 'fsb:r4');
$completed = 0;
$blockedRounds = 0;
$markerRounds = 0;
for ($round = 1; $round <= 12; $round++) {
    $ip = '10.88.' . $round . '.1';
    $out = $spawnAll('fsb:r4', array_map(static fn(int $w): array => ["dup$round", $ip], range(0, 7)), (int) $ex->execute(['TIME'])[0] + 2);
    $completed += $ok($out);
    $blockedRounds += $store4->checkBlock($k2Of($ip)) !== null ? 1 : 0;
    $markerRounds += $isPsc && $store4->getWatchFlag($markerKey($scopeOf($k2Of($ip)))) >= 1 ? 1 : 0;
}
emit('F06.R4.duplicate-member-race', ['variant' => wsbVariant(), 'rounds' => 12, 'workersCompleted' => $completed, 'roundsWithK2Block' => $blockedRounds] + ($isPsc ? ['roundsWithMarker' => $markerRounds] : []));
WsbAsserts::check('F06', 'R4 negative control: all 96 concurrent workers completed', $completed === 96, ['completed' => $completed]);
WsbAsserts::check('F06', 'R4: 8 concurrent failures of ONE fingerprint never create a block (duplicates never inflate evidence)', $blockedRounds === 0, ['roundsWithK2Block' => $blockedRounds]);
if ($isPsc) {
    WsbAsserts::check('F06', 'R4: 8 concurrent failures of ONE fingerprint never create a corroboration marker', $markerRounds === 0, ['roundsWithMarker' => $markerRounds]);
}

// R6 — LIVE recovery on the Redis clock (S0 and PSC only; FSB_REDIS_LIVE=0 skips it => UNPROVEN, never PASS).
$live = getenv('FSB_REDIS_LIVE') !== '0' && in_array(wsbVariant(), ['S0', 'PSC'], true);
if ($live) {
    $rt = wsbRedisPipeline($ex, 'fsb:r6');
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
    emit('F06.R6.live-recovery', ['variant' => wsbVariant(), 'deniedWhileBlocked' => str_contains($immediate, 'H'), 'reblockedAfter63sFromHistory' => str_contains($afterBlock, 'H'), 'successAfter63s' => str_contains($afterOk, 'H') ? 'denied' : 'allowed', 'singleFreshFailureAfterExpiry' => str_contains($freshFail, 'H') ? 'enforced' : 'allowed', 'legitDeniedAfterFreshFailure' => str_contains($afterFresh, 'H')]);
    WsbAsserts::check('F06', 'R6: the 60 s block is enforced right after the trigger on the Redis clock', str_contains($immediate, 'H'), ['immediate' => $immediate]);
    if ($isS0) {
        WsbAsserts::check('F06', 'S0 R6: the stale set re-blocks a lone legitimate client 63 s after the trigger (history-only renewal)', str_contains($afterBlock, 'H'), ['afterBlock' => $afterBlock]);
    } else {
        WsbAsserts::check('F06', 'R6: a lone legitimate client is allowed 63 s after the trigger (block, window and marker all expired; no history-only re-block)', ! str_contains($afterBlock, 'H') && ! str_contains($afterOk, 'H'), ['afterBlock' => $afterBlock, 'afterOk' => $afterOk]);
        WsbAsserts::check('F06', 'R6: ONE fresh failing fingerprint after the expiry does not re-block the NAT (it is not corroboration), so a colleague typo costs nothing', ! str_contains($freshFail, 'H') && ! str_contains($afterFresh, 'H'), ['freshFail' => $freshFail, 'afterFresh' => $afterFresh]);
    }
} else {
    emit('F06.R6.live-recovery', ['variant' => wsbVariant(), 'applicable' => false, 'reason' => getenv('FSB_REDIS_LIVE') === '0' ? 'skipped by FSB_REDIS_LIVE=0 (UNPROVEN)' : 'only S0 and PSC run the 63 s live check']);
}

// R7 — Redis outage: terminate THIS verified instance (pid proven equal to the one run.sh started) and issue another command on the established
// connection. The pipeline must THROW a BackendFailureException; it must never return ALLOW or silently skip correlation (DEC-015: the engine, not the
// pipeline, owns fail-open / fail-closed policy).
$outcome = 'not-run';
if ($redisPid > 1 && (string) $redisPid === (getenv('WSB_REDIS_PID') ?: '')) {
    $rt = wsbRedisPipeline($ex, 'fsb:r7');
    wsbRedisRun($rt, new \Maatify\RateLimiter\DTO\RateLimitContextDTO(NAT, CHROME, null, fp('before-outage')), 'fail');
    exec('kill ' . $redisPid . ' >/dev/null 2>&1');
    for ($i = 0; $i < 50 && @posix_kill($redisPid, 0); $i++) {
        usleep(100000);
    }
    try {
        $r = wsbRedisRun($rt, new \Maatify\RateLimiter\DTO\RateLimitContextDTO(NAT, CHROME, null, fp('after-outage')), 'fail');
        $outcome = 'returned:' . $r;
    } catch (BackendFailureException) {
        $outcome = 'BackendFailureException';
    } catch (\Throwable $t) {
        $outcome = 'other:' . $t::class;
    }
}
emit('F06.R7.redis-outage', ['variant' => wsbVariant(), 'result' => $outcome]);
WsbAsserts::check('F06', 'R7: after the Redis instance dies the pipeline throws BackendFailureException (never ALLOW, never a silent skip)', $outcome === 'BackendFailureException', ['result' => $outcome]);
WsbAsserts::finish();
