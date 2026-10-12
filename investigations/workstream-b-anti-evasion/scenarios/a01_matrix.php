<?php

declare(strict_types=1);

// A01 — attack-vs-legitimate matrix on the loaded runtime (S0 real, or an isolated candidate runtime).
// Primary: every stream on Login(with account) in the three Host integration flows (pre+out, record-only, check-only).
// Policy-shape coverage (risk based, not Cartesian): selected streams on login-anon / otp / api / api_custom.
require dirname(__DIR__) . '/lib/wsb.php';

emit('A01.provenance', wsbProvenance());
$streams = wsbStreams();
$controls = wsbControls();
foreach (wsbPrimaryOrder() as $id) {
    foreach (['pre+out', 'out', 'pre'] as $flow) {
        emit('A01.matrix', ['variant' => wsbVariant(), 'level' => wsbLevel(), 'stream' => $id, 'class' => wsbClass($id), 'shape' => 'login', 'flow' => $flow] + wsbMeasure($streams[$id]['events'], 'login', $flow));
    }
}
foreach (['login-anon', 'otp', 'api', 'api_custom'] as $shape) {
    foreach (['L1', 'L2', 'X1', 'X2', 'X3', 'X4', 'X6', 'X8', 'Y2a', 'Y4d', 'Y4e', 'Y4f'] as $id) {
        foreach (['pre+out', 'out'] as $flow) {
            emit('A01.matrix', ['variant' => wsbVariant(), 'level' => wsbLevel(), 'stream' => $id, 'class' => wsbClass($id), 'shape' => $shape, 'flow' => $flow] + wsbMeasure($streams[$id]['events'], $shape, $flow));
        }
    }
}
foreach (wsbPrimaryOrder() as $id) {
    emit('A01.controls', ['stream' => $id, 'title' => $streams[$id]['title'], 'class' => wsbClass($id), 'controls' => $controls[$id] ?? null]);
}

// Baseline invariants of the UNMODIFIED runtime (S0 only; hard-fail). They reproduce the numbers published in PR #103 on the real
// pipeline, so a regression that silently removes churn/dilution protection (see run.sh self-test) cannot pass.
if (wsbVariant() === 'S0') {
    $m = static fn(string $id, string $shape, string $flow): array => wsbMeasure($streams[$id]['events'], $shape, $flow);
    $l1 = $m('L1', 'login', 'pre+out');
    $x1 = $m('X1', 'login', 'pre+out');
    $x2 = $m('X2', 'login', 'pre+out');
    $y9 = $m('Y9b', 'login', 'pre+out');
    WsbAsserts::check('A01', 'S0 L1 legit denied 127/134 (published #103 number reproduced on the real pipeline)', $l1['legitDenied'] === 127 && $l1['legitAttempts'] === 134, ['denied' => $l1['legitDenied']]);
    WsbAsserts::check('A01', 'S0 X1 fingerprint churn: only 2 attacker attempts reach verification', $x1['attackReachedVerification'] === 2, ['reach' => $x1['attackReachedVerification']]);
    WsbAsserts::check('A01', 'S0 X2 distributed LOW: only 6 attacker attempts reach verification', $x2['attackReachedVerification'] === 6, ['reach' => $x2['attackReachedVerification']]);
    WsbAsserts::check('A01', 'S0 Y9b IPv6 /48 macro scope: only 3 attacker attempts reach verification', $y9['attackReachedVerification'] === 3, ['reach' => $y9['attackReachedVerification']]);
}
WsbAsserts::finish();
