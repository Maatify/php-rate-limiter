<?php

declare(strict_types=1);

// A01 — the protection / fairness matrix on the loaded runtime arm (S0 real, or the isolated OAAA candidate).
// Every stream in the Host flows pre+out / pre (checkOnly only) / out (record-only) on Login (with account), plus OTP, Login without account,
// a DEC-008 custom authentication policy, API_OVERUSE and the DEC-008 custom API policy. Same events, same controlled clock on both arms.
// Per-request attribution is the PR #108 / #115 method (lib/oaaa.php oaaaReplay) extended with the OAAA reservation signals and with
// request-for-request bit strings so report.php can compute exact S0-vs-OAAA deltas.
require dirname(__DIR__) . '/lib/oaaa.php';

emit('A01.provenance', oaaaProvenance());
$streams = oaaaStreams();
$classes = oaaaStreamClasses();
foreach (oaaaMatrixCells() as [$id, $shape, $flow]) {
    emit('A01.matrix', ['arm' => oaaaArm(), 'level' => oaaaLevel(), 'stream' => $id, 'class' => $classes[$id], 'shape' => $shape, 'flow' => $flow] + oaaaMeasure($streams[$id]['events'], $shape, $flow));
}
foreach ($classes as $id => $class) {
    $s = $streams[$id];
    $times = array_column($s['events'], 't');
    $ips = array_values(array_unique(array_column(array_filter($s['events'], static fn(array $e): bool => $e['kind'] === 'attack'), 'ip')));
    emit('A01.stream', ['stream' => $id, 'title' => $s['title'], 'class' => $class, 'events' => count($s['events']), 'spanSeconds' => max($times) - min($times), 'attackIps' => count($ips)]);
}

// Baseline invariants of the UNMODIFIED runtime (S0 only; hard-fail): the published numbers of PR #103 / #104 / #105 / #108 / #111 / #113
// must be reproduced on the real pipeline through this study's replay, otherwise this study is not measuring the same thing. report.php
// additionally cross-checks every common cell against the merged #115 S0 golden.
if (oaaaArm() === 'S0') {
    $m = static fn(string $id, string $shape, string $flow): array => oaaaMeasure($streams[$id]['events'], $shape, $flow);
    $d2 = $m('D2', 'login', 'pre+out');
    $d2o = $m('D2', 'login', 'out');
    OaaaAsserts::check('A01', 'S0 D2 reproduces the published numbers: 117/121 legitimate requests denied (pre+out) and 115/121 (record-only)', $d2['legitDenied'] === 117 && $d2['legitAttempts'] === 121 && $d2o['legitDenied'] === 115, ['pre+out' => $d2['legitDenied'], 'out' => $d2o['legitDenied']]);
    $sp = ['SP1n' => 4, 'SP3' => 7, 'SP6' => 20];
    $got = [];
    foreach ($sp as $id => $want) {
        $got[$id] = $m($id, 'login', 'pre+out')['attackReachedVerification'];
    }
    OaaaAsserts::check('A01', 'S0 SP1n/SP3/SP6 reproduce the published attacker reach 4/200, 7/1000, 20/1200 (pre+out)', $got === $sp, $got);
    $l1 = $m('L1', 'login', 'pre+out');
    $x1 = $m('X1', 'login', 'pre+out');
    $x2 = $m('X2', 'login', 'pre+out');
    $y9 = $m('Y9b', 'login', 'pre+out');
    $d1 = $m('D1', 'login', 'pre+out');
    $x7 = $m('X7', 'login', 'pre+out');
    OaaaAsserts::check('A01', 'S0 L1 127/134 legit denied, D1 596/600, X1 2, X2 6, X7 6, Y9b 3 attacker attempts reach verification (published #103/#104/#105 numbers)', $l1['legitDenied'] === 127 && $l1['legitAttempts'] === 134 && $d1['legitDenied'] === 596 && $x1['attackReachedVerification'] === 2 && $x2['attackReachedVerification'] === 6 && $x7['attackReachedVerification'] === 6 && $y9['attackReachedVerification'] === 3, ['L1' => $l1['legitDenied'], 'D1' => $d1['legitDenied'], 'X1' => $x1['attackReachedVerification'], 'X2' => $x2['attackReachedVerification'], 'X7' => $x7['attackReachedVerification'], 'Y9b' => $y9['attackReachedVerification']]);
    $aba = $m('ABA_A', 'login', 'pre+out');
    $abb = $m('ABA_B', 'login', 'pre+out');
    OaaaAsserts::check('A01', 'S0 ABA_A 3/120 alternating-fingerprint attacker attempts reach verification and ABA_B the innocent pair is denied 118/120 (PR #111 numbers)', $aba['attackReachedVerification'] === 3 && $abb['legitDenied'] === 118 && $abb['legitAttempts'] === 120, ['ABA_A' => $aba['attackReachedVerification'], 'ABA_B' => $abb['legitDenied']]);
    $tmsd = $m('TMSd', 'login', 'pre+out');
    $tmx = $m('TMX', 'login', 'pre+out');
    OaaaAsserts::check('A01', 'S0 TMSd 2/200 and TMX 200/200 reach verification (PR #113 numbers)', $tmsd['attackReachedVerification'] === 2 && $tmx['attackReachedVerification'] === 200, ['TMSd' => $tmsd['attackReachedVerification'], 'TMX' => $tmx['attackReachedVerification']]);
    $nu4 = $m('Nu4', 'login', 'pre+out');
    $sp2 = $m('SP2', 'login', 'pre+out');
    OaaaAsserts::check('A01', 'S0 Nu4 47/61 legitimate denied (K1 isolation, PR #108) and SP2 60/72 low-and-slow attempts reach verification (PR #115)', $nu4['legitDenied'] === 47 && $sp2['attackReachedVerification'] === 60, ['Nu4' => $nu4['legitDenied'], 'SP2' => $sp2['attackReachedVerification']]);
}
OaaaAsserts::finish();
