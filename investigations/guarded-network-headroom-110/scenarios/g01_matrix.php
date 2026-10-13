<?php

declare(strict_types=1);

// G01 — the protection / fairness matrix on the loaded runtime arm (S0 real, or the isolated TH5 / GNH5 candidate).
// Every stream in the Host flows pre+out / pre (checkOnly only) / out (record-only) on Login (with account), plus OTP, Login without
// account, API_OVERUSE and the DEC-008 custom API policy. Same events, same controlled clock on every arm. Per-request attribution is the
// PR #108 method (lib/gnh.php gnhReplay), extended with the task §6 fields.
require dirname(__DIR__) . '/lib/gnh.php';

emit('G01.provenance', gnhProvenance());
$streams = gnhStreams();
$classes = gnhStreamClasses();
foreach (gnhMatrixCells() as [$id, $shape, $flow]) {
    emit('G01.matrix', ['arm' => gnhArm(), 'level' => gnhLevel(), 'stream' => $id, 'class' => $classes[$id], 'shape' => $shape, 'flow' => $flow] + gnhMeasure($streams[$id]['events'], $shape, $flow));
}
foreach ($classes as $id => $class) {
    $s = $streams[$id];
    $times = array_column($s['events'], 't');
    emit('G01.stream', ['stream' => $id, 'title' => $s['title'], 'class' => $class, 'events' => count($s['events']), 'spanSeconds' => max($times) - min($times)]);
}

// Baseline invariants of the UNMODIFIED runtime (S0 only; hard-fail): the published numbers of PR #103 / #104 / #105 / #108 / #111 / #113
// must be reproduced on the real pipeline through this study's replay, otherwise this study is not measuring the same thing.
if (gnhArm() === 'S0') {
    $m = static fn(string $id, string $shape, string $flow): array => gnhMeasure($streams[$id]['events'], $shape, $flow, false, false);
    $d2 = $m('D2', 'login', 'pre+out');
    $d2o = $m('D2', 'login', 'out');
    GnhAsserts::check('G01', 'S0 D2 reproduces the published numbers: 117/121 legitimate requests denied (pre+out) and 115/121 (record-only)', $d2['legitDenied'] === 117 && $d2['legitAttempts'] === 121 && $d2o['legitDenied'] === 115, ['pre+out' => $d2['legitDenied'], 'out' => $d2o['legitDenied']]);
    $sp = ['SP1n' => 4, 'SP3' => 7, 'SP6' => 20];
    $got = [];
    foreach ($sp as $id => $want) {
        $got[$id] = $m($id, 'login', 'pre+out')['attackReachedVerification'];
    }
    GnhAsserts::check('G01', 'S0 SP1n/SP3/SP6 reproduce the published attacker reach 4/200, 7/1000, 20/1200 (pre+out)', $got === $sp, $got);
    $l1 = $m('L1', 'login', 'pre+out');
    $x1 = $m('X1', 'login', 'pre+out');
    $x2 = $m('X2', 'login', 'pre+out');
    $y9 = $m('Y9b', 'login', 'pre+out');
    $d1 = $m('D1', 'login', 'pre+out');
    $x7 = $m('X7', 'login', 'pre+out');
    GnhAsserts::check('G01', 'S0 L1 127/134 legit denied, D1 596/600, X1 2, X2 6, X7 6, Y9b 3 attacker attempts reach verification (published #103/#104/#105 numbers)', $l1['legitDenied'] === 127 && $l1['legitAttempts'] === 134 && $d1['legitDenied'] === 596 && $x1['attackReachedVerification'] === 2 && $x2['attackReachedVerification'] === 6 && $x7['attackReachedVerification'] === 6 && $y9['attackReachedVerification'] === 3, ['L1' => $l1['legitDenied'], 'D1' => $d1['legitDenied'], 'X1' => $x1['attackReachedVerification'], 'X2' => $x2['attackReachedVerification'], 'X7' => $x7['attackReachedVerification'], 'Y9b' => $y9['attackReachedVerification']]);
    $aba = $m('ABA_A', 'login', 'pre+out');
    $abb = $m('ABA_B', 'login', 'pre+out');
    GnhAsserts::check('G01', 'S0 ABA_A 3/120 alternating-fingerprint attacker attempts reach verification and ABA_B the innocent pair is denied 118/120 (PR #111 numbers)', $aba['attackReachedVerification'] === 3 && $abb['legitDenied'] === 118 && $abb['legitAttempts'] === 120, ['ABA_A' => $aba['attackReachedVerification'], 'ABA_B' => $abb['legitDenied']]);
    $tmsd = $m('TMSd', 'login', 'pre+out');
    $tmx = $m('TMX', 'login', 'pre+out');
    GnhAsserts::check('G01', 'S0 TMSd 2/200 and TMX 200/200 reach verification (PR #113 numbers)', $tmsd['attackReachedVerification'] === 2 && $tmx['attackReachedVerification'] === 200, ['TMSd' => $tmsd['attackReachedVerification'], 'TMX' => $tmx['attackReachedVerification']]);
}
GnhAsserts::finish();
