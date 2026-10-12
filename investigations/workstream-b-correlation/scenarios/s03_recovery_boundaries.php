<?php

declare(strict_types=1);

// B-F04 — exact-boundary recovery proofs: 60 s block, 600 s distinct set, 1800 s WATCH (all fixed TTL from creation).
require dirname(__DIR__) . '/lib/bootstrap.php';

function seeded(): Lab
{
    $lab = new Lab('login');
    $lab->at(0);
    $lab->attempt($lab->ctx(NAT, CHROME, 'acctA', fp('A')), 'pre');
    $lab->at(10);
    $lab->attempt($lab->ctx(NAT, CHROME, 'acctB', fp('B')), 'pre');
    $lab->at(20);
    $lab->attempt($lab->ctx(NAT, CHROME, 'acctA', fp('A')), 'pre');

    return $lab;
}

// Reference: with checkOnly-only flow the third request (A,B,A) issues the 60 s block at t=20 → active until t=80.
foreach ([79, 80, 81] as $t) {
    $lab = seeded();
    $lab->at($t);
    $c = $lab->ctx(NAT, CHROME, 'acctA', fp('A'));
    emit('F04.block-expiry-boundary', ['probeAt' => $t] + $lab->attempt($c, 'pre') + ['blocks' => $lab->blocks($c)]);
}

// Distinct set (created t=0, TTL 600): single legit client A keeps asking.
foreach ([599, 600, 601] as $t) {
    $lab = seeded();
    $lab->at($t);
    $c = $lab->ctx(NAT, CHROME, 'acctA', fp('A'));
    emit('F04.distinct-600-boundary', ['probeAt' => $t] + $lab->attempt($c, 'pre') + ['blocks' => $lab->blocks($c)]);
}

// WATCH (first created t=10, TTL 1800 → expires t=1810): a NEW independent pair C,D one second apart.
foreach ([1807, 1808, 1809, 1810] as $t) {
    $lab = seeded();
    $lab->at($t - 1);
    $lab->attempt($lab->ctx(NAT, CHROME, 'acctC', fp('C')), 'pre');
    $lab->at($t);
    $c = $lab->ctx(NAT, CHROME, 'acctD', fp('D'));
    emit('F04.watch-1800-boundary', ['secondOfPairAt' => $t] + $lab->attempt($c, 'pre') + ['blocks' => $lab->blocks($c)]);
}

// Full-silence recovery bound: first time a legitimate single client is NOT re-blocked.
$lab = seeded();
$firstFree = null;
for ($t = 81; $t <= 2000 && $firstFree === null; $t++) {
    $lab->at($t);
    $r = $lab->attempt($lab->ctx(NAT, CHROME, 'acctA', fp('A')), 'pre');
    $firstFree = $r['blocked'] ? null : $t;
}
emit('F04.first-unblocked-second-for-single-client', ['t' => $firstFree]);

// Fresh independent pair at 700/710 after the first trigger (WATCH carry-over).
$lab = seeded();
foreach ([700, 710] as $t) {
    $lab->at($t);
    $cl = $t === 700 ? 'C' : 'D';
    $c = $lab->ctx(NAT, CHROME, "acct$cl", fp($cl));
    emit('F04.watch-carry-over-pair', ['who' => $cl] + $lab->attempt($c, 'pre') + ['blocks' => $lab->blocks($c)]);
}
