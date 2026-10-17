<?php

declare(strict_types=1);

// G1 verification V06 — R3-H on REAL Redis (instance owned by run_verify.sh; G1_REDIS_PORT). The R-3 fixture of ../../scenarios/
// m06_redis.php with the hardened value-level check, on the real Lua store and on the partial-write mutant M-R3. Without a port
// every row is UNPROVEN, never PASS.

require dirname(__DIR__) . '/lib/verify.php';

$port = getenv('G1_REDIS_PORT');
if ($port === false || $port === '') {
    g1VEmit('v06', ['case' => 'R3-H Redis', 'status' => 'UNPROVEN', 'reason' => 'no Redis instance']);
    exit(0);
}
$r = new \Redis();
$r->connect('127.0.0.1', (int) $port, 2.0);
$now = 1767268800 + 60;
$small = ['U_src' => 5, 'U_acct' => 1, 'U_glob' => 0] + g1Params('R');
foreach (['real' => null, 'M-R3 partial-write store' => 'partial'] as $label => $mode) {
    $out = [];
    foreach (['new source' => ['198.51.100.210', '198.51.100.211'], 'same source' => ['198.51.100.212', '198.51.100.212']] as $case => [$s1, $s2]) {
        $r->flushDB();
        $st = $mode === null ? new G1RedisStore($r) : new G1VWrapStore(new G1RedisStore($r), $mode);
        $a = new G1Admission($st, $small);
        $a->reserve('U', $s1, 'victimAN', null, null, $now);
        $keys = $r->keys('{g1}*');
        sort($keys);
        $vals = g1VValues($st, $now);
        $res = $a->reserve('U', $s2, 'victimAN', null, null, $now);
        $after = $r->keys('{g1}*');
        sort($after);
        $out[$case] = ['refused' => $res['refused'], 'keysUnchanged' => $keys === $after, 'valuesUnchanged' => $vals === g1VValues($st, $now)];
    }
    $orig = array_reduce($out, static fn(bool $c, array $x): bool => $c && $x['refused'] === 'acct' && $x['keysUnchanged'], true);
    $hard = array_reduce($out, static fn(bool $c, array $x): bool => $c && $x['refused'] === 'acct' && $x['keysUnchanged'] && $x['valuesUnchanged'], true);
    g1VEmit('v06', ['case' => "R3-H Redis $label", 'cases' => $out, 'originalPass' => $orig, 'hardenedPass' => $hard]);
}
$r->flushDB();
