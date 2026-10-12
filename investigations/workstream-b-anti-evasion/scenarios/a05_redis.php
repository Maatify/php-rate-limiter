<?php

declare(strict_types=1);

// A05 — real Redis (instance owned and proven by run.sh). Redis owns time (DEC-016), so only immediate sequences are asserted here;
// every time-boundary claim lives in A02 on the controlled clock and is NOT claimed for Redis.
//   R1 semantics (record-only churn + candidate evidence), R2 TTL hygiene on every key, R3 key growth under anchor rotation,
//   R4 CONCURRENCY: duplicate-member race on the evidence tally, R5 CONCURRENCY: distinct-member cap race.
require dirname(__DIR__) . '/lib/redis.php';

use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

emit('A05.provenance', wsbProvenance());
$ex = wsbRedisConnectVerified(true);

$inventory = static function (RespRedisCommandExecutor $ex): array {
    $keys = array_map('strval', (array) $ex->execute(['KEYS', '*']));
    $ttlClasses = [];
    $noTtl = 0;
    $maxSet = 0;
    foreach ($keys as $k) {
        $ttl = (int) $ex->execute(['TTL', $k]);
        if ($ttl < 0) {
            $noTtl++;
        }
        // Redis counts down in real time: snap each remaining TTL to the smallest known class >= ttl within 30 s (classes are the
        // TTLs the code uses: 60 block, 600/660/700/900/1200/1800 correlation, 21600/86400 scores) so the output is stable.
        foreach ([60, 600, 660, 700, 900, 1200, 1800, 21600, 86400] as $known) {
            if ($ttl > 0 && $ttl <= $known && $known - $ttl <= 30) {
                $ttl = $known;
                break;
            }
        }
        $ttlClasses[$ttl] = ($ttlClasses[$ttl] ?? 0) + 1;
        if ((string) $ex->execute(['TYPE', $k]) === 'set') {
            $maxSet = max($maxSet, (int) $ex->execute(['SCARD', $k]));
        }
    }
    ksort($ttlClasses);

    // counts per TTL class vary with the wall-clock minute (window-keyed state), so the golden keeps the SET of classes only.
    return ['keys' => count($keys), 'keysWithoutTtl' => $noTtl, 'maxSetCardinality' => $maxSet, 'ttlClassesSeen' => array_keys($ttlClasses)];
};

// R1 — record-only churn on one K2 (A, B, C failing): which attempt is the first denied?
$rt = wsbRedisPipeline($ex, 'wsb:r1');
$steps = [];
foreach (['A', 'B', 'C'] as $cl) {
    $steps[] = wsbRedisRun($rt, new \Maatify\RateLimiter\DTO\RateLimitContextDTO(NAT, CHROME, null, fp($cl)), 'fail');
}
emit('A05.R1.record-only-churn', ['variant' => wsbVariant(), 'steps' => $steps, 'thirdDenied' => str_contains($steps[2], 'H2')]);
WsbAsserts::check('A05', 'R1: three failing fingerprints on one K2 are denied at the third attempt on real Redis', str_contains($steps[2], 'H2'), ['steps' => $steps]);

// R1b — a record-only success-only third member (zero failures): S0 blocks, evidence candidates must not.
$rt = wsbRedisPipeline($ex, 'wsb:r1b');
$steps = [];
foreach ([['A', 'fail'], ['B', 'fail'], ['C', 'ok']] as [$cl, $o]) {
    $steps[] = wsbRedisRun($rt, new \Maatify\RateLimiter\DTO\RateLimitContextDTO(NAT, CHROME, null, fp($cl)), $o);
}
emit('A05.R1b.third-member-success', ['variant' => wsbVariant(), 'steps' => $steps, 'thirdDenied' => str_contains($steps[2], 'H2')]);

// R2/R3 — TTL hygiene and growth under anchor rotation (400 requests, each a new IP + new fingerprint).
$before = $inventory($ex);
$rt = wsbRedisPipeline($ex, 'wsb:r3');
$denied = 0;
for ($i = 1; $i <= 400; $i++) {
    $r = wsbRedisRun($rt, new \Maatify\RateLimiter\DTO\RateLimitContextDTO('10.77.' . intdiv($i, 250) . '.' . ($i % 250 + 1), CHROME, null, fp("rot$i")), 'fail');
    $denied += str_contains($r, 'H') ? 1 : 0;
}
$after = $inventory($ex);
$grown = $after['keys'] - $before['keys'];
emit('A05.R3.anchor-rotation', ['variant' => wsbVariant(), 'requests' => 400, 'denied' => $denied, 'keysPerRequestRounded' => (int) round($grown / 400), 'ttlClassesSeen' => $after['ttlClassesSeen'], 'keysWithoutTtl' => $after['keysWithoutTtl'], 'maxSetCardinality' => $after['maxSetCardinality']]);
WsbAsserts::check('A05', 'R2: every key created has a TTL (no immortal state) on real Redis', $after['keysWithoutTtl'] === 0, ['keysWithoutTtl' => $after['keysWithoutTtl']]);
WsbAsserts::check('A05', 'R3 negative control: the rotation probes reached storage (no denial) and created keys', $denied === 0 && $grown > 0, ['denied' => $denied]);
WsbAsserts::check('A05', 'R3: largest set on real Redis stays within the largest declared cap (50)', $after['maxSetCardinality'] <= 50, ['max' => $after['maxSetCardinality']]);

// R4 — duplicate-member concurrency: 8 workers record the SAME fingerprint failure at the same Redis-clock second; run 12 rounds.
// A correct distinct-evidence count equals SCARD of the evidence set (== 1). The candidate tally is a read-then-increment pair
// (two store calls): inflation shows up as tally > 1. S0 has no tally (NOT_APPLICABLE).
$worker = __DIR__ . '/a05_redis_worker.php';
$spawn = static function (string $ns, string $fpId, string $ip, int $startAt, int $workers) use ($worker): array {
    $procs = [];
    for ($w = 0; $w < $workers; $w++) {
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

if (in_array(wsbVariant(), ['PW', 'P2'], true)) {
    $keyFn = new \ReflectionMethod(RedisFullCapabilityStore::class, 'key');
    $inflated = 0;
    $maxTally = 0;
    $rounds = 20;
    $storeR4 = new RedisFullCapabilityStore($ex, 'wsb:r4');
    $resolver = new DeviceIdentityResolver(new FingerprintHasher(Lab::SECRET));
    $labKeys = new Lab('login');
    for ($round = 1; $round <= $rounds; $round++) {
        $ip = '10.88.' . $round . '.1';
        $startAt = (int) $ex->execute(['TIME'])[0] + 2;
        $out = $spawn('wsb:r4', "dup$round", $ip, $startAt, 8);
        $c = new \Maatify\RateLimiter\DTO\RateLimitContextDTO($ip, CHROME, null, fp("dup$round"));
        $k2 = $labKeys->keys($c)['K2'];
        // tally key = HMAC of the candidate's logical key; recompute exactly like EvaluationPipeline::wsbKey()
        $tallyKey = hash_hmac('sha256', "login_protection:rate_limiter:wsb:wit:v1:prod:k2:{$k2}:", Lab::SECRET);
        $tally = $storeR4->getWatchFlag($tallyKey);
        $maxTally = max($maxTally, $tally);
        $inflated += $tally > 1 ? 1 : 0;
        $workersOk = count(array_filter($out, static fn(string $o): bool => str_starts_with($o, 'fail:')));
        if ($workersOk !== 8) {
            WsbAsserts::check('A05', "R4 negative control: all 8 workers completed round $round", false, ['out' => $out]);
        }
    }
    // The exact counts are scheduling-dependent, so only the invariant-relevant booleans are recorded in the golden output.
    emit('A05.R4.duplicate-member-race', ['variant' => wsbVariant(), 'rounds' => $rounds, 'workersPerRound' => 8, 'inflationObserved' => $inflated > 0, 'oneFingerprintReachedThreshold' => $maxTally >= 3, 'correctValue' => 1]);
    WsbAsserts::check('A05', 'R4: 8 concurrent failures of ONE fingerprint never inflate the distinct-evidence tally above 1', $inflated === 0, ['inflationObserved' => $inflated > 0]);
} else {
    emit('A05.R4.duplicate-member-race', ['variant' => wsbVariant(), 'applicable' => false, 'reason' => 'no read-then-increment evidence tally in this variant']);
}

// R5 — distinct-member concurrency: 8 workers with 8 DIFFERENT fingerprints on one K2 at the same instant, 12 rounds.
// The store-side cap must hold (SCARD <= declared cap) for every variant; this exercises the existing atomic primitive.
$maxCard = 0;
$rounds = 12;
$completed = 0;
for ($round = 1; $round <= $rounds; $round++) {
    $ip = '10.99.' . $round . '.1';
    $startAt = (int) $ex->execute(['TIME'])[0] + 2;
    $procs = [];
    for ($w = 0; $w < 8; $w++) {
        $env = [
            'WSB_REDIS_PORT' => getenv('WSB_REDIS_PORT'), 'WSB_REDIS_PID' => getenv('WSB_REDIS_PID'), 'WSB_REDIS_DIR' => getenv('WSB_REDIS_DIR'),
            'LAB_ROOT' => getenv('LAB_ROOT'), 'WSB_CANDIDATE' => getenv('WSB_CANDIDATE') ?: '', 'PATH' => getenv('PATH'),
        ];
        $p = proc_open([PHP_BINARY, $worker, 'wsb:r5', "d{$round}x{$w}", $ip, (string) $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $procs[] = [$p, $pipes];
    }
    foreach ($procs as [$p, $pipes]) {
        $o = trim((string) stream_get_contents($pipes[1]));
        $completed += str_starts_with($o, 'fail:') ? 1 : 0;
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
    }
}
$inv = $inventory($ex);
emit('A05.R5.distinct-member-race', ['variant' => wsbVariant(), 'rounds' => $rounds, 'workersCompleted' => $completed, 'maxSetCardinality' => $inv['maxSetCardinality']]);
WsbAsserts::check('A05', 'R5 negative control: all 96 concurrent workers completed', $completed === 96, ['completed' => $completed]);
WsbAsserts::check('A05', 'R5: concurrent distinct members never push any set above the largest declared cap (50)', $inv['maxSetCardinality'] <= 50, ['max' => $inv['maxSetCardinality']]);

WsbAsserts::finish();
