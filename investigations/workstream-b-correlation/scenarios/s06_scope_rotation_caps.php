<?php

declare(strict_types=1);

// B-F04 — enforcement isolation, current+previous rotation bridging, caps, ephemeral overflow, bounded state.
require dirname(__DIR__) . '/lib/bootstrap.php';

// 1. Ephemeral overflow: account device cap (10) exceeded, then fingerprint rotation on one K2.
$lab = new Lab('login');
for ($i = 1; $i <= 12; $i++) {
    $lab->at($i * 20);
    $lab->attempt($lab->ctx("198.51.$i.10", CHROME, 'victimY', fp("dev$i")), 'pre+out', 'fail');
}
for ($j = 1; $j <= 4; $j++) {
    $lab->at(260 + $j * 5);
    $c = $lab->ctx('198.51.99.10', CHROME, 'victimY', fp("eph$j"));
    emit('F04.ephemeral.overflow-then-rotation', ['who' => "eph$j"] + $lab->attempt($c, 'pre+out', 'fail') + ['blocks' => $lab->blocks($c)]);
}

// 2. Enforcement isolation: K2 correlation block must not touch K4/K5 of the colleagues or any score.
$lab = new Lab('login');
$lab->at(0);
$lab->attempt($lab->ctx(NAT, CHROME, 'acctA', fp('A')));
$lab->at(10);
$lab->attempt($lab->ctx(NAT, CHROME, 'acctB', fp('B')));
foreach (['A', 'B'] as $cl) {
    $c = $lab->ctx(NAT, CHROME, "acct$cl", fp($cl));
    $b = $lab->blocks($c);
    emit('F04.isolation.k4-k5', ['client' => $cl, 'K2block' => $b['K2'] ?? null, 'K3block' => $b['K3'] ?? null, 'K4block' => $b['K4'] ?? null, 'K5block' => $b['K5'] ?? null, 'scores' => $lab->scores($c)]);
}

// 3. Policy isolation: login-policy churn block does not carry into OTP (state keys embed the policy name).
$login = new Lab('login');
$login->at(0);
$login->attempt($login->ctx(NAT, CHROME, 'acctA', fp('A')));
$login->at(10);
$login->attempt($login->ctx(NAT, CHROME, 'acctB', fp('B')));
$otp = new Lab('otp', null, false, Lab::SECRET, $login);
$otp->at(15);
emit('F04.isolation.policy', ['loginBlocked' => $login->attempt($login->ctx(NAT, CHROME, 'acctA', fp('A')))['blocked'], 'otpBlocked' => $otp->attempt($otp->ctx(NAT, CHROME, 'acctA', fp('A')))['blocked']]);

// 4. Rotation shapes. Baseline = no rotation; A then B on a shared NAT K2.
foreach ([
    'none' => [null, null],
    'outer-key-only' => ['old-secret', false],
    'fingerprint-only' => [null, true],
    'both-rotated' => ['old-secret', true],
] as $shape => [$prev, $fpRot]) {
    $lab = new Lab('login', $prev, false, Lab::SECRET, null, $fpRot);
    $res = [];
    foreach (['A', 'B', 'A'] as $i => $cl) {
        $lab->at($i * 20);
        $c = $lab->ctx(NAT, CHROME, "acct$cl", fp($cl));
        $res[] = $lab->attempt($c, 'pre+out')['steps'];
    }
    emit('F04.rotation.shapes', ['shape' => $shape, 'attempts' => $res, 'blocks' => $lab->blocks($lab->ctx(NAT, CHROME, 'acctA', fp('A')))]);
}

// 5. Cross-generation: client A observed BEFORE outer rotation (old secret), client B AFTER (new secret + previous=old).
$old = new Lab('login', null, false, 'old-secret', null, false);
$old->at(0);
$old->attempt($old->ctx(NAT, CHROME, 'acctA', fp('A')));
$new = new Lab('login', 'old-secret', false, 'new-secret', $old, false);
$new->at(10);
$r = $new->attempt($new->ctx(NAT, CHROME, 'acctB', fp('B')));
emit('F04.rotation.cross-generation-pair', $r + ['blocksUnderNewSecret' => $new->blocks($new->ctx(NAT, CHROME, 'acctB', fp('B')))]);
$sets = [];
foreach ($new->corr->distinctKeys() as $k) {
    $sets[] = $new->corr->distinctCount($k);
}
sort($sets);
emit('F04.rotation.cross-generation-state', ['distinctSetSizes' => $sets, 'maxSize' => max($sets)]);

// 6. No unbounded per-fingerprint state: 600 distinct fingerprints / IPs / accounts through one policy.
$lab = new Lab('login');
for ($i = 1; $i <= 600; $i++) {
    $lab->at($i);
    $lab->attempt($lab->ctx('10.' . intdiv($i, 250) . '.' . ($i % 250) . '.1', CHROME, "u$i", fp("d$i")), 'pre+out', 'fail');
}
$sizes = [];
foreach ($lab->corr->distinctKeys() as $k) {
    $sizes[] = $lab->corr->distinctCount($k);
}
rsort($sizes);
emit('F04.bounded-state.600-distinct', ['correlationSets' => count($sizes), 'largestSetSizes' => array_slice($sizes, 0, 5), 'caps' => ['churn' => 3, 'dilution' => 6, 'deviceAccount' => 10, 'deviceIp' => 50]]);

// 6b. Per-scope caps hold under pressure: one fingerprint across 600 IPs; one K2 with 600 fingerprints.
$lab = new Lab('login');
for ($i = 1; $i <= 600; $i++) {
    $lab->at($i);
    $lab->attempt($lab->ctx('10.' . intdiv($i, 250) . '.' . ($i % 250) . '.1', CHROME, null, fp('same')), 'pre');
}
$sizes = array_map(static fn(string $k): int => $lab->corr->distinctCount($k), $lab->corr->distinctKeys());
rsort($sizes);
emit('F04.bounded-state.one-fp-600-ips', ['largestSetSizes' => array_slice($sizes, 0, 3), 'cap' => 6]);
$lab = new Lab('login');
for ($i = 1; $i <= 600; $i++) {
    $lab->at($i * 61);
    $lab->attempt($lab->ctx(NAT, CHROME, null, fp("d$i")), 'pre');
}
$sizes = array_map(static fn(string $k): int => $lab->corr->distinctCount($k), $lab->corr->distinctKeys());
rsort($sizes);
emit('F04.bounded-state.one-k2-600-fps-spaced-61s', ['largestSetSizes' => array_slice($sizes, 0, 3), 'cap' => 3]);
