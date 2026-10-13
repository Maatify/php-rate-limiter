<?php

declare(strict_types=1);

// O05 — real Redis (instance owned and proven by run.sh; PR #103 F103-01 safety model) on the loaded arm. Redis owns time (DEC-016):
// the 60/600 s boundaries are proved on the controlled clock in O02/O04; here only SEQUENTIAL decisions (no waiting on expiry):
//   P1  A/B/A pair parity (reference store vs Redis): innocent successes, then the failing attacker
//   P2  spray parity: 7 distinct failing subjects in pre+out (5th observed at recordFailure on a candidate arm, 6th denied)
//   P3  record-only parity: 8 distinct failing subjects (candidate arms return a post-outcome K1 block from the 6th request)
//   P4  TTL hygiene: every key has a TTL; the two liveness markers of one scope exist on Redis with TTL in 1..600
require dirname(__DIR__, 2) . '/workstream-b-anti-evasion/lib/redis.php';
require dirname(__DIR__) . '/lib/oqc.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

emit('O05.provenance', oqcProvenance());
$arm = oqcArm();
$cand = $arm !== 'S0';
$ex = wsbRedisConnectVerified(true);

$norm = static fn(string $s): string => str_contains($s, ':H') ? 'B' : 'a';
/** Runs a pre+out (or out) logical attempt on Redis and returns 'a' (allowed throughout) or 'B' (denied at some step). */
$attempt = static function (array $rt, RateLimitContextDTO $c, string $outcome, string $flow) use ($norm): string {
    if ($flow !== 'out') {
        $r = wsbRedisRun($rt, $c, 'check');
        if ($norm($r) === 'B') {
            return 'B';
        }
    }
    $r = wsbRedisRun($rt, $c, $outcome);

    return $norm($r);
};
$refSteps = static function (array $events, string $flow): array {
    $rows = k1Replay(oqcLab(), $events, $flow, 'login');

    return array_map(static fn(array $r): string => $r['blocked'] ? 'B' : 'a', $rows);
};
$redisSteps = static function (string $ns, array $events, string $flow) use ($ex, $attempt): array {
    $rt = wsbRedisPipeline($ex, $ns);
    $out = [];
    foreach ($events as $e) {
        $c = k1Ctx($e, 'login');
        $out[] = $attempt($rt, $c, wsbShapeOutcome('login', $e['outcome']), $flow);
    }

    return $out;
};

// P1 — A/B/A: 6 innocent successes (A,B,A,B,A,B), then on a fresh namespace 6 failing attacker requests (A,B,A,B,A,B).
$ben = [];
$atk = [];
for ($i = 0; $i < 6; $i++) {
    $ben[] = k1Ev($i, '10.60.0.1', $i % 2 ? 'B' : 'A', 'col' . ($i % 2), 'ok', 'legit');
    $atk[] = k1Ev($i, '10.60.0.2', $i % 2 ? 'B' : 'A', "rv$i", 'fail', 'attack');
}
$rb = $redisSteps('oqc:p1b', $ben, 'pre+out');
$fb = $refSteps($ben, 'pre+out');
$ra = $redisSteps('oqc:p1a', $atk, 'pre+out');
$fa = $refSteps($atk, 'pre+out');
emit('O05.P1.aba-parity', ['arm' => $arm, 'benignRedis' => $rb, 'benignReference' => $fb, 'attackerRedis' => $ra, 'attackerReference' => $fa]);
OqcAsserts::check('O05', 'P1 A/B/A parity: the real Redis adapter and the reference store give identical decisions for the innocent pair and for the failing attacker on this arm', $rb === $fb && $ra === $fa, ['benignRedis' => $rb, 'benignRef' => $fb, 'attackerRedis' => $ra, 'attackerRef' => $fa]);
OqcAsserts::check('O05', 'P1b ' . ($cand ? 'on this arm the innocent successful pair is never denied and the failing attacker is denied from the 4th request (3 reach verification)' : 'S0 denies both pairs from the 3rd request'), $cand ? ($rb === ['a', 'a', 'a', 'a', 'a', 'a'] && $ra === ['a', 'a', 'B', 'B', 'B', 'B']) : ($rb[2] === 'B' && $ra[2] === 'B'), ['benign' => $rb, 'attacker' => $ra]);

// P2 — spray parity (pre+out): 7 distinct failing subjects from one IP, no fingerprint.
$sp = [];
for ($i = 0; $i < 7; $i++) {
    $sp[] = k1Ev($i, '10.60.0.3', null, "sv$i", 'fail', 'attack');
}
$rs = $redisSteps('oqc:p2', $sp, 'pre+out');
$fs = $refSteps($sp, 'pre+out');
emit('O05.P2.spray-parity', ['arm' => $arm, 'redis' => $rs, 'reference' => $fs]);
OqcAsserts::check('O05', 'P2 spray parity (pre+out): identical decisions on Redis and the reference store for 7 distinct failing subjects', $rs === $fs, ['redis' => $rs, 'reference' => $fs]);
OqcAsserts::check('O05', 'P2b ' . ($cand ? 'on this arm the 5th subject is denied only at recordFailure (after verification) and the 6th/7th at checkOnly' : 'S0 denies the 5th subject at checkOnly'), $cand ? $rs === ['a', 'a', 'a', 'a', 'B', 'B', 'B'] : $rs === ['a', 'a', 'a', 'a', 'B', 'B', 'B'], ['redis' => $rs]);

// P3 — record-only parity: 8 distinct failing subjects.
$ro = [];
for ($i = 0; $i < 8; $i++) {
    $ro[] = k1Ev($i, '10.60.0.4', null, "ov$i", 'fail', 'attack');
}
$rr = $redisSteps('oqc:p3', $ro, 'out');
$fr = $refSteps($ro, 'out');
emit('O05.P3.record-only-parity', ['arm' => $arm, 'redis' => $rr, 'reference' => $fr]);
OqcAsserts::check('O05', 'P3 record-only parity: identical decisions on Redis and the reference store for 8 distinct failing subjects', $rr === $fr, ['redis' => $rr, 'reference' => $fr]);
OqcAsserts::check('O05', 'P3b ' . ($cand ? 'record-only Host on this arm: the first failure only makes the scope live, failures 2..6 are observed, the 6th request (5th observed subject) returns the K1 block it issued and the 7th/8th are denied (K1 protection gained)' : 'S0 record-only Host: nothing is denied (no K1 observation)'), $cand ? $rr === ['a', 'a', 'a', 'a', 'a', 'B', 'B', 'B'] : $rr === array_fill(0, 8, 'a'), ['redis' => $rr]);

// P4 — TTL hygiene and the liveness markers on the real adapter (namespace oqc:p1b: one scope, successes only).
$keys = array_map('strval', (array) $ex->execute(['KEYS', '*']));
$noTtl = 0;
foreach ($keys as $k) {
    if ((int) $ex->execute(['TTL', $k]) < 0) {
        $noTtl++;
    }
}
$lab = new Lab('login');
$k = $lab->keys(new RateLimitContextDTO('10.60.0.1', CHROME, 'col0', fp('A')));
$churnScope = hash_hmac('sha256', 'login_protection:rate_limiter:correlation:churn:v1:prod:scope:' . $k['K2'], Lab::SECRET);
$markers = 0;
$markerTtlOk = true;
$now = (int) ((array) $ex->execute(['TIME']))[0];
$window = intdiv($now, 600);
foreach (['credential_spray:' . $k['K1'], $churnScope] as $scope) {
    foreach ([$window, $window - 1] as $w) { // the write happened a moment ago: this window, or the previous one at a boundary
        $logical = hash('sha256', 'oqc:live:v1:' . $scope . ':' . $w);
        $hits = array_map('strval', (array) $ex->execute(['KEYS', '*:' . hash('sha256', 'oqc:p1b') . ':watch:' . hash('sha256', $logical)]));
        foreach ($hits as $h) {
            $markers++;
            $ttl = (int) $ex->execute(['TTL', $h]);
            $markerTtlOk = $markerTtlOk && $ttl >= 1 && $ttl <= 1200; // raw TTLs depend on wall time and are NOT recorded
        }
    }
}
emit('O05.P4.ttl-hygiene', ['arm' => $arm, 'keys' => count($keys), 'keysWithoutTtl' => $noTtl, 'livenessMarkers' => $markers, 'markerTtlWithin1To1200' => $markers > 0 ? $markerTtlOk : null]);
OqcAsserts::check('O05', 'P4 every Redis key written on this arm has a TTL', $noTtl === 0 && count($keys) > 0, ['keys' => count($keys), 'noTtl' => $noTtl]);
OqcAsserts::check('O05', 'P4b ' . ($cand ? 'the two liveness markers of the innocent scope exist on Redis as WATCH keys with TTL in 1..1200 s' : 'S0 writes no liveness marker'), $cand ? ($markers === 2 && $markerTtlOk) : $markers === 0, ['markers' => $markers]);
OqcAsserts::finish();
