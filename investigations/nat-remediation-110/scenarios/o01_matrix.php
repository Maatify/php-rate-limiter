<?php

declare(strict_types=1);

// O01 — the #110 protection / fairness matrix on the loaded runtime arm (S0 real, or an isolated OQC candidate arm).
// Every stream in the Host flows pre+out / pre (checkOnly only) / out (record-only) on Login (with account), plus the risk-based
// shape cells (OTP, Login-without-account, API_OVERUSE and the custom API policy). Same events, same controlled clock on every arm.
// Per-request attribution (persisted blocks before the request, blocks issued by it, correlation signals observed during it) comes
// from the PR #108 harness unchanged.
require dirname(__DIR__) . '/lib/oqc.php';

emit('O01.provenance', oqcProvenance());
$streams = oqcStreams();
foreach (oqcMatrixCells() as [$id, $shape, $flow]) {
    emit('O01.matrix', ['arm' => oqcArm(), 'level' => oqcLevel(), 'stream' => $id, 'class' => oqcStreamClasses()[$id], 'shape' => $shape, 'flow' => $flow] + oqcMeasure($streams[$id]['events'], $shape, $flow));
}
foreach (oqcStreamClasses() as $id => $class) {
    $s = $streams[$id];
    $times = array_column($s['events'], 't');
    emit('O01.stream', ['stream' => $id, 'title' => $s['title'], 'class' => $class, 'events' => count($s['events']), 'spanSeconds' => max($times) - min($times)]);
}

// Baseline invariants of the UNMODIFIED runtime (S0 only; hard-fail): the published numbers of PR #103 / #104 / #105 / #108 and the
// #100 Lead control (ABA_A 3/120, until now executor-attested only) must be reproduced on the real pipeline, otherwise this study is
// not measuring the same thing.
if (oqcArm() === 'S0') {
    $m = static fn(string $id, string $shape, string $flow): array => oqcMeasure($streams[$id]['events'], $shape, $flow);
    $d2 = $m('D2', 'login', 'pre+out');
    $d2o = $m('D2', 'login', 'out');
    OqcAsserts::check('O01', 'S0 D2 reproduces the published numbers: 117/121 legitimate requests denied (pre+out) and 115/121 (record-only)', $d2['legitDenied'] === 117 && $d2['legitAttempts'] === 121 && $d2o['legitDenied'] === 115, ['pre+out' => $d2['legitDenied'], 'out' => $d2o['legitDenied']]);
    $sp = ['SP1n' => 4, 'SP3' => 7, 'SP6' => 20];
    $got = [];
    foreach ($sp as $id => $want) {
        $got[$id] = $m($id, 'login', 'pre+out')['attackReachedVerification'];
    }
    OqcAsserts::check('O01', 'S0 SP1n/SP3/SP6 reproduce the published attacker reach 4/200, 7/1000, 20/1200 (pre+out)', $got === $sp, $got);
    $l1 = $m('L1', 'login', 'pre+out');
    $x1 = $m('X1', 'login', 'pre+out');
    $x2 = $m('X2', 'login', 'pre+out');
    $y9 = $m('Y9b', 'login', 'pre+out');
    OqcAsserts::check('O01', 'S0 L1 127/134 legit denied, X1 2, X2 6, Y9b 3 attacker attempts reach verification (published #103/#104 numbers)', $l1['legitDenied'] === 127 && $l1['legitAttempts'] === 134 && $x1['attackReachedVerification'] === 2 && $x2['attackReachedVerification'] === 6 && $y9['attackReachedVerification'] === 3, ['L1' => $l1['legitDenied'], 'X1' => $x1['attackReachedVerification'], 'X2' => $x2['attackReachedVerification'], 'Y9b' => $y9['attackReachedVerification']]);
    $aba = $m('ABA_A', 'login', 'pre+out');
    $abb = $m('ABA_B', 'login', 'pre+out');
    OqcAsserts::check('O01', 'S0 ABA_A the #100 Lead control is reproduced on the real pipeline: 3/120 alternating-fingerprint attacker attempts reach verification; ABA_B the innocent pair is denied 118/120', $aba['attackReachedVerification'] === 3 && $abb['legitDenied'] === 118 && $abb['legitAttempts'] === 120, ['ABA_A' => $aba['attackReachedVerification'], 'ABA_B' => $abb['legitDenied']]);
}
OqcAsserts::finish();
