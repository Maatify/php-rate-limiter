<?php

declare(strict_types=1);

// G1 M03 — admission-layer mechanics on the reference store (PA and I1 / I1′ / C5 / C6). G1-defined fixtures with explicit
// capacities. Concurrency and real Redis are in M06.

require dirname(__DIR__) . '/lib/g1.php';

g1Provenance();

/** @param array<string, int> $over */
function capParams(array $over): array
{
    return $over + g1Params('R');
}

$t0 = 1767268800; // 2026-01-01 12:00:00 UTC, aligned with the Lab clock

// PA-2 — the same proven device repeated 20x: one device member, 20 admissions (counters unaffected by membership).
$st = new G1RefStore();
$a = new G1Admission($st, capParams(['N_P' => 3, 'P_acct' => 100, 'P_proof' => 100, 'P_acct_day' => 1000, 'P_proof_day' => 1000]));
$ok = 0;
for ($i = 0; $i < 20; $i++) {
    $ok += $a->reserve('P', '203.0.113.9', 'acctA', 'sd-1', 'fp-dev1', $t0 + $i)['ok'] ? 1 : 0;
}
$members = array_sum(array_map('count', $st->s));
g1Emit('m03', ['pa' => 'PA-2', 'admitted' => $ok, 'deviceMembers' => $members, 'pass' => $ok === 20 && $members === 1]);

// PA-3 — TTL: N_P = 2, T_P = 100 s. d1 at 0, d2 at 10, d3 at 50 refused (full), d3 at 101 admitted (d1 expired).
$st = new G1RefStore();
$a = new G1Admission($st, capParams(['N_P' => 2, 'T_P' => 100, 'P_acct' => 100, 'P_proof' => 100]));
$seq = [];
foreach ([[0, 'd1'], [10, 'd2'], [50, 'd3'], [101, 'd3']] as [$t, $d]) {
    $r = $a->reserve('P', '203.0.113.9', 'acctA', "sd-$d", "fp-$d", $t0 + $t);
    $seq[] = $r['ok'] ? 'A' : (string) $r['refused'];
}
g1Emit('m03', ['pa' => 'PA-3', 'sequence' => $seq, 'pass' => $seq === ['A', 'A', 'p_device', 'A']]);

// PA-4 — rotation: generation gA admits d1..d4; after rotation (current gB, previous gA) d1 is an existing member, d5..d10 are
// new; d11 is refused at N_P = 10 (union counted once); a U source counter used 10 / 12 under gA admits only 2 more under gB.
$st = new G1RefStore();
$p4 = capParams(['N_P' => 10, 'P_acct' => 100, 'P_proof' => 100, 'P_acct_day' => 1000, 'P_proof_day' => 1000]);
$ga = new G1Admission($st, $p4, gen: 'gA');
foreach (['d1', 'd2', 'd3', 'd4'] as $i => $d) {
    $ga->reserve('P', '203.0.113.9', 'acctA', "sd-$d", "fp-$d", $t0 + $i);
}
$uA = 0;
for ($i = 0; $i < 10; $i++) {
    $uA += $ga->reserve('U', '198.51.100.7', "v$i", null, null, $t0 + 10 + $i)['ok'] ? 1 : 0;
}
$gb = new G1Admission($st, $p4, gen: 'gB', prevGen: 'gA');
$seq = [];
foreach (['d1', 'd5', 'd6', 'd7', 'd8', 'd9', 'd10', 'd11'] as $i => $d) {
    $r = $gb->reserve('P', '203.0.113.9', 'acctA', "sd-$d", "fp-$d", $t0 + 30 + $i);
    $seq[] = $r['ok'] ? 'A' : (string) $r['refused'];
}
$uB = 0;
for ($i = 0; $i < 5; $i++) {
    $uB += $gb->reserve('U', '198.51.100.7', "w$i", null, null, $t0 + 40 + $i)['ok'] ? 1 : 0;
}
$sizes = array_map('count', $st->s);
sort($sizes);
// Two rotations inside one window (current gC, previous gB): gA's counters are no longer read -> re-issue (F§6.4 predicts it).
$gc = new G1Admission($st, $p4, gen: 'gC', prevGen: 'gB');
$uC = 0;
for ($i = 0; $i < 12; $i++) {
    $uC += $gc->reserve('U', '198.51.100.7', "x$i", null, null, $t0 + 50 + $i)['ok'] ? 1 : 0;
}
g1Emit('m03', ['pa' => 'PA-4', 'pDevicesAfterRotation' => $seq, 'setSizes' => $sizes, 'uSrcBeforeRotation' => $uA,
    'uSrcAfterOneRotation' => $uB, 'uSrcAfterSecondRotationSameWindow' => $uC,
    'pass' => $seq === ['A', 'A', 'A', 'A', 'A', 'A', 'A', 'p_device'] && $sizes === [4, 6] && $uA === 10 && $uB === 2,
    'secondRotationReissue' => $uC]);

// PA-5 — fixed-window edge (I1′): U_src = 12; 12 attempts at t = 599 and 12 at t = 600 are all admitted (24 within 2 s).
$st = new G1RefStore();
$a = new G1Admission($st, capParams([]));
$n = 0;
for ($i = 0; $i < 13; $i++) {
    $n += $a->reserve('U', '198.51.100.8', "e$i", null, null, $t0 - ($t0 % 600) + 599)['ok'] ? 1 : 0;
}
$m = 0;
for ($i = 0; $i < 13; $i++) {
    $m += $a->reserve('U', '198.51.100.8', "f$i", null, null, $t0 - ($t0 % 600) + 600)['ok'] ? 1 : 0;
}
g1Emit('m03', ['pa' => 'PA-5', 'admittedLastSecondOfWindow' => $n, 'admittedFirstSecondOfNext' => $m, 'pass' => $n === 12 && $m === 12,
    'note' => 'I1 holds per fixed window; I1′ 2·c in an interval of length W is observed, as stated']);

// PA-6 — all-or-nothing: the source has headroom, the account does not; nothing is written. Refused requests create no key.
$st = new G1RefStore();
$a = new G1Admission($st, capParams([]));
for ($i = 0; $i < 4; $i++) {
    $a->reserve('U', "198.51.100.2$i", 'victim', null, null, $t0 + $i);
}
$keys = $st->liveKeys($t0 + 5);
$w = $st->writes;
$r = $a->reserve('U', '198.51.100.99', 'victim', null, null, $t0 + 5);
g1Emit('m03', ['pa' => 'PA-6', 'refused' => $r['refused'], 'writesUnchanged' => $w === $st->writes,
    'keysUnchanged' => $keys === $st->liveKeys($t0 + 5), 'pass' => $r['refused'] === 'acct' && $w === $st->writes && $keys === $st->liveKeys($t0 + 5)]);

// PA-7 — no P -> U: a refused P request is not retried on U by the layer; the lane of the refusal is P.
$st = new G1RefStore();
$a = new G1Admission($st, capParams(['N_P' => 1, 'P_acct' => 100, 'P_proof' => 100]));
$a->reserve('P', '203.0.113.9', 'acctA', 'sd-1', 'fp-1', $t0);
$r = $a->reserve('P', '203.0.113.9', 'acctA', 'sd-2', 'fp-2', $t0 + 1);
$uAdmissions = count(array_filter($a->log, static fn(array $e): bool => $e['lane'] === 'U'));
g1Emit('m03', ['pa' => 'PA-7', 'refused' => $r['refused'], 'refusalLane' => end($a->refusals)['lane'], 'uAdmissions' => $uAdmissions,
    'pass' => $r['refused'] === 'p_device' && $uAdmissions === 0]);
