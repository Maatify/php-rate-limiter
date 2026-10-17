<?php

declare(strict_types=1);

// G1 M06 — the admission layer on REAL Redis (instance started and owned by run.sh; G1_REDIS_PORT). Without a port every case
// is emitted as UNPROVEN, never PASS. Failover is SIMULATED (state loss of acknowledged keys), not a real replicated failover.

require dirname(__DIR__) . '/lib/admission.php';

$port = getenv('G1_REDIS_PORT');
$cases = ['R-1 counter race', 'R-1m mutation control', 'R-2 device-set race', 'R-3 all-or-nothing', 'R-4 reference parity', 'R-5 simulated state loss',
    'R-6 unknown write', 'R-7 malformed state', 'R-8 T1 parity', 'R-9 Redis unavailable'];
if ($port === false || $port === '') {
    foreach ($cases as $c) {
        echo json_encode(['s' => 'm06', 'case' => $c, 'status' => 'UNPROVEN', 'reason' => 'no Redis instance']), "\n";
    }
    exit(0);
}
$emit = static fn(array $row) => print(json_encode(['s' => 'm06'] + $row, JSON_UNESCAPED_SLASHES) . "\n");
$r = new \Redis();
$r->connect('127.0.0.1', (int) $port, 2.0);
$now = 1767268800 + 60;
$small = ['U_src' => 5, 'U_acct' => 100, 'U_glob' => 0, 'N_P' => 5, 'P_acct' => 1000, 'P_acct_day' => 1000, 'P_proof' => 1000, 'P_proof_day' => 1000] + g1Params('R');

/** Runs 16 worker processes released at one instant; returns the number admitted. */
$race = static function (string $mode, string $case) use ($port): int {
    $barrier = microtime(true) + 1.5;
    $procs = [];
    for ($i = 0; $i < 16; $i++) {
        $cmd = 'php ' . escapeshellarg(__DIR__ . '/m06_worker.php') . " $port $barrier $mode $case $i";
        $procs[] = popen($cmd, 'r');
    }
    $n = 0;
    foreach ($procs as $p) {
        $n += (int) trim((string) stream_get_contents($p));
        pclose($p);
    }

    return $n;
};

// R-1 / R-1m — cap 5, 2 units pre-used, 16 racers: exactly 3 must be admitted (atomic); the non-atomic control may over-admit.
foreach (['atomic' => 'R-1 counter race', 'nonatomic' => 'R-1m mutation control'] as $mode => $label) {
    $rounds = [];
    for ($k = 0; $k < 3; $k++) {
        $r->flushDB();
        $a = new G1Admission(new G1RedisStore($r), $small);
        $a->reserve('U', '198.51.100.200', 'pre0', null, null, $now);
        $a->reserve('U', '198.51.100.200', 'pre1', null, null, $now);
        $rounds[] = $race($mode, 'counter');
    }
    if ($mode === 'atomic') {
        $emit(['case' => $label, 'expectedExact' => 3, 'admittedPerRound' => $rounds, 'pass' => $rounds === [3, 3, 3], 'role' => 'gate C1']);
    } else {
        // Racy by construction: only whether over-admission was observed is archived (raw counts vary run to run).
        $emit(['case' => $label, 'overAdmissionDetected' => max($rounds) > 3, 'pass' => max($rounds) > 3, 'role' => 'detector check']);
    }
}

// R-2 — P device set N_P = 5 with 4 live members, 16 racers with new devices: exactly 1 admitted.
$rounds = [];
for ($k = 0; $k < 3; $k++) {
    $r->flushDB();
    $a = new G1Admission(new G1RedisStore($r), $small);
    for ($i = 0; $i < 4; $i++) {
        $a->reserve('P', '198.51.100.201', 'acctRace', "sd-seed$i", "fp-seed$i", $now);
    }
    $rounds[] = $race('atomic', 'device');
}
$emit(['case' => 'R-2 device-set race', 'expectedExact' => 1, 'admittedPerRound' => $rounds, 'pass' => $rounds === [1, 1, 1]]);

// R-3 — all-or-nothing: the account is full, the source has room; the source counter must not move.
$r->flushDB();
$a = new G1Admission(new G1RedisStore($r), ['U_acct' => 1] + $small);
$a->reserve('U', '198.51.100.210', 'victimAN', null, null, $now);
$before = $r->keys('{g1}*');
sort($before);
$vals = array_map(static fn(string $k) => $r->get($k), $before);
$res = $a->reserve('U', '198.51.100.211', 'victimAN', null, null, $now);
$after = $r->keys('{g1}*');
sort($after);
$emit(['case' => 'R-3 all-or-nothing', 'refused' => $res['refused'], 'keysUnchanged' => $before === $after,
    'valuesUnchanged' => $vals === array_map(static fn(string $k) => $r->get($k), $after), 'pass' => $res['refused'] === 'acct' && $before === $after]);

// R-4 — reference store vs Redis on one deterministic 600-reservation sequence (lanes U / P / A, rotation of sources and accounts).
$r->flushDB();
$ref = new G1Admission(new G1RefStore(), g1Params('R'));
$red = new G1Admission(new G1RedisStore($r), g1Params('R'));
$state = 4242;
$diff = 0;
$admitted = 0;
for ($i = 0; $i < 600; $i++) {
    $state = ($state * 1103515245 + 12345) & 0x7fffffff;
    $lane = ['U', 'U', 'P', 'A'][$state % 4];
    $src = '198.51.100.' . (($state >> 3) % 6);
    $acct = 'acct' . (($state >> 7) % 9);
    $t = 1767268800 + 7 * $i;
    $x = $ref->reserve($lane, $src, $lane === 'A' ? null : $acct, "sd-$acct-" . (($state >> 11) % 3), "fp-$acct-" . (($state >> 11) % 3), $t);
    $y = $red->reserve($lane, $src, $lane === 'A' ? null : $acct, "sd-$acct-" . (($state >> 11) % 3), "fp-$acct-" . (($state >> 11) % 3), $t);
    $diff += ($x['ok'] === $y['ok'] && $x['refused'] === $y['refused']) ? 0 : 1;
    $admitted += $x['ok'] ? 1 : 0;
}
$emit(['case' => 'R-4 reference parity', 'reservations' => 600, 'admitted' => $admitted, 'decisionDifferences' => $diff, 'pass' => $diff === 0]);

// R-5 — SIMULATED failover with lost acknowledged writes: 5 / 5 admitted, the counter key is lost, 5 more are admitted.
$r->flushDB();
$a = new G1Admission(new G1RedisStore($r), ['U_acct' => 100] + $small);
$n1 = 0;
for ($i = 0; $i < 6; $i++) {
    $n1 += $a->reserve('U', '198.51.100.220', "lw$i", null, null, $now)['ok'] ? 1 : 0;
}
foreach ($r->keys('{g1}*:src:*') as $k) {
    $r->del($k);
}
$n2 = 0;
for ($i = 6; $i < 12; $i++) {
    $n2 += $a->reserve('U', '198.51.100.220', "lw$i", null, null, $now)['ok'] ? 1 : 0;
}
$emit(['case' => 'R-5 simulated state loss', 'admittedBefore' => $n1, 'admittedAfterLoss' => $n2, 'reissued' => $n2,
    'status' => 'DEGRADED (as F§6.9 predicts): the bound holds per window only modulo lost acknowledged writes; SIMULATED, not a real failover']);

// R-6 — unknown write: the script ran but the Host lost the reply and retried; two admissions are charged for one attempt.
$r->flushDB();
$a = new G1Admission(new G1RedisStore($r), $small);
$a->reserve('U', '198.51.100.230', 'uw', null, null, $now);
$a->reserve('U', '198.51.100.230', 'uw', null, null, $now);
$emit(['case' => 'R-6 unknown write', 'chargedForOneAttempt' => count($a->log), 'status' => 'as specified (F§2.3): fails toward over-charging']);

// R-7 — malformed state: a wrong-type counter key makes the script fail before its first write; nothing is written.
$r->flushDB();
$a = new G1Admission(new G1RedisStore($r), $small);
$a->reserve('U', '198.51.100.240', 'mf', null, null, $now);
$acctKey = array_values(array_filter($r->keys('{g1}*'), static fn(string $k) => str_contains($k, ':acct:')))[0];
$srcKey = array_values(array_filter($r->keys('{g1}*'), static fn(string $k) => str_contains($k, ':src:')))[0];
$r->del($acctKey);
$r->hSet($acctKey, 'x', '1');
$srcBefore = $r->get($srcKey);
$err = null;
try {
    $a->reserve('U', '198.51.100.240', 'mf', null, null, $now);
} catch (\Throwable $e) {
    $err = get_class($e);
}
$emit(['case' => 'R-7 malformed state', 'error' => $err, 'srcCounterUnchanged' => $srcBefore === $r->get($srcKey), 'pass' => $err !== null && $srcBefore === $r->get($srcKey)]);

// R-8 — T1 claim parity: the H-4 sequence of M05 on Redis.
$r->flushDB();
$st = new G1RedisStore($r);
$e = ['lane' => 'U', 'subject' => 'acctT', 'op' => 'login'];
$issue = static fn(string $id, int $t) => (new G1Admission($st, g1Params('R')))->reserve('U', '198.51.100.250', 'acctT', null, null, $t,
    ['id' => $id, 'lane' => 'U', 'subject' => 'acctT', 'op' => 'login', 'deadline' => $t + 30, 'grace' => 600]);
$base = 1767268800;
$issue('p1', $base);
$seq = [$st->claim('p1', $e, 'fail', $base + 5), $st->claim('p1', $e, 'fail', $base + 6), $st->claim('p1', $e, 'ok', $base + 7),
    $st->claim('p1', ['subject' => 'acctOther'] + $e, 'fail', $base + 8)];
$issue('p2', $base + 100);
$seq[] = $st->claim('p2', $e, 'fail', $base + 131);
$seq[] = $st->claim('p4-never-issued', $e, 'fail', $base + 300);
$emit(['case' => 'R-8 T1 parity', 'claims' => $seq, 'pass' => $seq === ['accepted', 'duplicate', 'conflict', 'rejected', 'late', 'unknown'],
    'note' => 'late / unknown are decided on the caller clock (deadline stored in the record); the record lifetime is a relative EXPIRE']);

// R-9 — Redis unavailable: no admission is possible; the study layer raises. Integration with the DEC-015 typed failure path is
// NOT implemented in this study (UNPROVEN).
$dead = new \Redis();
$err = null;
try {
    $dead->connect('127.0.0.1', 1, 0.2);
    (new G1Admission(new G1RedisStore($dead), $small))->reserve('U', '198.51.100.9', 'x', null, null, $now);
} catch (\Throwable $e) {
    $err = get_class($e);
}
$emit(['case' => 'R-9 Redis unavailable', 'error' => $err, 'status' => 'UNPROVEN: DEC-015 integration of the admission layer not built']);
$r->flushDB();
