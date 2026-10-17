<?php

declare(strict_types=1);

// G1 targeted step — T1 negative fixtures (DA_T1_PLAN.md §2, F126-G1-R2-02) on the ORIGINAL stores (expected to expose the
// defects; kept as failing fixtures) and on the FIXED pair G1RefStoreV2 / G1RedisStoreV2. Real Redis owned by run_da_t1.sh
// (G1_REDIS_PORT); without it the Redis rows are UNPROVEN.
// T1-N4b (refusal by the DEVICE cap with expired members) is an addition to the pre-declared T1-N4 (refusal by a counter): the
// pre-declared wording did not cover the path the review names (pruning before the device-cap decision).

require dirname(__DIR__) . '/lib/t1v2.php';

$emit = static fn(array $row) => print(json_encode(['s' => 't1'] + $row, JSON_UNESCAPED_SLASHES) . "\n");
$now = 1767268800 + 900; // mid-window, so the device-set fixtures' earlier reservations share the counters' window
$p = ['U_src' => 5, 'U_acct' => 100, 'U_glob' => 0, 'N_P' => 2, 'T_P' => 86400, 'P_acct' => 1000, 'P_acct_day' => 1000, 'P_proof' => 1000,
    'P_proof_day' => 1000] + g1Params('R');
$permit = static fn(string $id, int $t, int $deadline = 30): array => ['id' => $id, 'lane' => 'U', 'subject' => 'acctT', 'op' => 'login', 'deadline' => $t + $deadline, 'grace' => 600];
$e = ['lane' => 'U', 'subject' => 'acctT', 'op' => 'login'];
$keyOf = static fn(G1Admission $a, string $scope, string $value, string $bucket, string $gen): string => (new \ReflectionMethod(G1Admission::class, 'key'))->invoke($a, $scope, $value, $bucket, $gen);

$port = getenv('G1_REDIS_PORT');
$redis = null;
if ($port !== false && $port !== '') {
    $redis = new \Redis();
    $redis->connect('127.0.0.1', (int) $port, 2.0);
}
$stores = [
    'original/reference' => static fn() => new G1RefStore(),
    'fixed/reference' => static fn() => new G1RefStoreV2(),
    'original/redis' => static fn() => $redis === null ? null : new G1RedisStore($redis),
    'fixed/redis' => static fn() => $redis === null ? null : new G1RedisStoreV2($redis),
];
$state = static function (G1Store $st, ?string $permitId = null) use ($redis): array {
    if ($st instanceof G1RedisStore || $st instanceof G1RedisStoreV2) {
        $d = g1VRedisDump($redis);

        return $permitId === null ? $d : ['permit' => $d['{g1}:permit:' . $permitId] ?? null, 'all' => $d];
    }
    $c = array_map(static fn(array $x): int => $x[0], $st->c);
    ksort($c);
    $s = $st->s;
    ksort($s);
    $pp = $st->p;

    return $permitId === null ? ['c' => $c, 's' => $s, 'p' => $pp] : ['permit' => $pp[$permitId] ?? null, 'all' => ['c' => $c, 's' => $s, 'p' => $pp]];
};

foreach ($stores as $label => $make) {
    if (str_ends_with($label, 'redis') && $redis === null) {
        $emit(['store' => $label, 'status' => 'UNPROVEN', 'reason' => 'no Redis instance']);
        continue;
    }
    $isRedis = str_ends_with($label, 'redis');
    $fresh = static function () use ($make, $redis): G1Store {
        $redis?->flushDB();

        return $make();
    };

    // T1-N1 — the permit key exists with a wrong type (Redis only: the reference store has no key types).
    if ($isRedis) {
        $st = $fresh();
        $redis->set('{g1}:permit:pX', 'junk');
        $before = $state($st);
        $err = null;
        try {
            $res = (new G1Admission($st, $p))->reserve('U', '198.51.100.30', 'acctT', null, null, $now, $permit('pX', $now));
        } catch (\Throwable $t) {
            $res = null;
            $err = get_class($t);
        }
        $after = $state($st);
        $emit(['store' => $label, 'fixture' => 'T1-N1 wrong-type permit key', 'result' => $res['refused'] ?? ($res === null ? "error:$err" : 'admitted'),
            'stateUnchanged' => $before === $after, 'pass' => $before === $after && ($res === null || ! $res['ok'])]);
    }

    // T1-N2 — re-issuing an already CLAIMED permit id.
    $st = $fresh();
    $a = new G1Admission($st, $p);
    $a->reserve('U', '198.51.100.31', 'acctT', null, null, $now, $permit('p1', $now));
    $c1 = $st->claim('p1', $e, 'fail', $now + 5);
    $re = $a->reserve('U', '198.51.100.31', 'acctT', null, null, $now + 6, $permit('p1', $now + 6));
    $c2 = $st->claim('p1', $e, 'fail', $now + 7);
    $emit(['store' => $label, 'fixture' => 'T1-N2 re-issue a CLAIMED id', 'firstClaim' => $c1, 'reissue' => $re['ok'] ? 'admitted' : $re['refused'],
        'secondClaim' => $c2, 'secondAccepted' => $c2 === 'accepted', 'pass' => $c1 === 'accepted' && ! $re['ok'] && $c2 !== 'accepted']);

    // T1-N3 — re-issuing an ISSUED id (different deadline): the record must not change.
    $st = $fresh();
    $a = new G1Admission($st, $p);
    $a->reserve('U', '198.51.100.32', 'acctT', null, null, $now, $permit('p2', $now));
    $before = $state($st, 'p2')['permit'];
    $re = $a->reserve('U', '198.51.100.32', 'acctT', null, null, $now + 1, $permit('p2', $now + 1, 500));
    $emit(['store' => $label, 'fixture' => 'T1-N3 re-issue an ISSUED id', 'reissue' => $re['ok'] ? 'admitted' : $re['refused'],
        'recordUnchanged' => $before === $state($st, 'p2')['permit'], 'pass' => ! $re['ok'] && $before === $state($st, 'p2')['permit']]);

    // Device-set fixtures: generation g-old holds d1, d2 (live); the new admission reads g-new (current) + g-old (previous). An
    // EXPIRED member is inserted into both sets. N4a: refusal by a counter; N4b: refusal by the device cap; N5: admitted.
    $setup = static function (array $pp) use ($fresh, $now, $keyOf, $redis, $isRedis): array {
        $st = $fresh();
        $old = new G1Admission($st, $pp, 'login', 'g-old');
        $old->reserve('P', '198.51.100.40', 'acctD', 'sd-1', 'fp-1', $now - 100);
        $old->reserve('P', '198.51.100.40', 'acctD', 'sd-2', 'fp-2', $now - 90);
        $new = new G1Admission($st, $pp, 'login', 'g-new', 'g-old');
        $cur = $keyOf($new, 'pdev', 'acctD', 'set', 'g-new');
        $prev = $keyOf($new, 'pdev', 'acctD', 'set', 'g-old');
        if ($isRedis) {
            $redis->zAdd($cur, $now - 1, 'expired-cur');
            $redis->zAdd($prev, $now - 1, 'expired-prev');
        } else {
            $st->s[$cur]['expired-cur'] = $now - 1;
            $st->s[$prev]['expired-prev'] = $now - 1;
        }

        return [$st, $new, $cur, $prev];
    };
    $sets = static function (G1Store $st, string $cur, string $prev) use ($redis, $isRedis): array {
        return $isRedis ? [$redis->zRange($cur, 0, -1, true), $redis->zRange($prev, 0, -1, true)] : [$st->s[$cur] ?? null, $st->s[$prev] ?? null];
    };
    foreach (['T1-N4a refused by a counter' => ['P_acct' => 2], 'T1-N4b refused by the device cap' => [], 'T1-N5 admitted, previous set read-only' => ['N_P' => 3]] as $fx => $over) {
        [$st, $new, $cur, $prev] = $setup($over + $p);
        [$c0, $p0] = $sets($st, $cur, $prev);
        $res = $new->reserve('P', '198.51.100.40', 'acctD', 'sd-3', 'fp-3', $now);
        [$c1, $p1] = $sets($st, $cur, $prev);
        $admitted = $res['ok'];
        $row = ['store' => $label, 'fixture' => $fx, 'result' => $admitted ? 'admitted' : $res['refused'],
            'currentSetUnchanged' => $c0 === $c1, 'previousSetUnchanged' => $p0 === $p1];
        $row['pass'] = $admitted ? $p0 === $p1 : ($c0 === $c1 && $p0 === $p1);
        $emit($row);
    }
}

// T1-N6 — regression on the FIXED stores: R-1 counter race, R-2 device-set race (16 processes at one barrier), R-3 value-level
// all-or-nothing, and the H-4 / R-8 claim series (reference and Redis).
$series = static function (G1Store $st, int $t0) use ($p, $permit, $e): array {
    $a = new G1Admission($st, $p);
    $a->reserve('U', '198.51.100.250', 'acctT', null, null, $t0, $permit('p1', $t0));
    $seq = [$st->claim('p1', $e, 'fail', $t0 + 5), $st->claim('p1', $e, 'fail', $t0 + 6), $st->claim('p1', $e, 'ok', $t0 + 7),
        $st->claim('p1', ['subject' => 'acctOther'] + $e, 'fail', $t0 + 8)];
    $a->reserve('U', '198.51.100.250', 'acctT', null, null, $t0 + 100, $permit('p2', $t0 + 100));
    $seq[] = $st->claim('p2', $e, 'fail', $t0 + 131);
    $seq[] = $st->claim('p4-never-issued', $e, 'fail', $t0 + 300);

    return $seq;
};
$want = ['accepted', 'duplicate', 'conflict', 'rejected', 'late', 'unknown'];
$seq = $series(new G1RefStoreV2(), $now);
$emit(['store' => 'fixed/reference', 'fixture' => 'T1-N6 claim series', 'claims' => $seq, 'pass' => $seq === $want]);
if ($redis !== null) {
    $redis->flushDB();
    $seq = $series(new G1RedisStoreV2($redis), $now);
    $emit(['store' => 'fixed/redis', 'fixture' => 'T1-N6 claim series', 'claims' => $seq, 'pass' => $seq === $want]);
    $race = static function (string $case) use ($port): int {
        $barrier = microtime(true) + 1.5;
        $procs = [];
        for ($i = 0; $i < 16; $i++) {
            $procs[] = popen('php ' . escapeshellarg(__DIR__ . '/t1_worker.php') . " $port $barrier $case $i", 'r');
        }
        $n = 0;
        foreach ($procs as $pr) {
            $n += (int) trim((string) stream_get_contents($pr));
            pclose($pr);
        }

        return $n;
    };
    $small = ['U_src' => 5, 'U_acct' => 100, 'U_glob' => 0, 'N_P' => 5, 'P_acct' => 1000, 'P_acct_day' => 1000, 'P_proof' => 1000, 'P_proof_day' => 1000] + g1Params('R');
    $t = 1767268800 + 60;
    foreach (['counter' => 3, 'device' => 1] as $case => $exact) {
        $rounds = [];
        for ($k = 0; $k < 3; $k++) {
            $redis->flushDB();
            $a = new G1Admission(new G1RedisStoreV2($redis), $small);
            if ($case === 'counter') {
                $a->reserve('U', '198.51.100.200', 'pre0', null, null, $t);
                $a->reserve('U', '198.51.100.200', 'pre1', null, null, $t);
            } else {
                for ($i = 0; $i < 4; $i++) {
                    $a->reserve('P', '198.51.100.201', 'acctRace', "sd-seed$i", "fp-seed$i", $t);
                }
            }
            $rounds[] = $race($case);
        }
        $emit(['store' => 'fixed/redis', 'fixture' => "T1-N6 $case race", 'expectedExact' => $exact, 'admittedPerRound' => $rounds, 'pass' => $rounds === [$exact, $exact, $exact]]);
    }
    foreach (['new source' => ['198.51.100.210', '198.51.100.211'], 'same source' => ['198.51.100.212', '198.51.100.212']] as $case => [$s1, $s2]) {
        $redis->flushDB();
        $st = new G1RedisStoreV2($redis);
        $a = new G1Admission($st, ['U_acct' => 1] + $small);
        $a->reserve('U', $s1, 'victimAN', null, null, $t);
        $before = g1VRedisDump($redis);
        $res = $a->reserve('U', $s2, 'victimAN', null, null, $t);
        $emit(['store' => 'fixed/redis', 'fixture' => "T1-N6 R-3 value-level ($case)", 'refused' => $res['refused'], 'stateUnchanged' => $before === g1VRedisDump($redis),
            'pass' => $res['refused'] === 'acct' && $before === g1VRedisDump($redis)]);
    }
    $redis->flushDB();
}
