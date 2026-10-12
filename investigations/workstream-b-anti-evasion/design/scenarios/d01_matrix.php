<?php

declare(strict_types=1);

// D01 — attack-vs-legitimate matrix on the loaded runtime (S0 real, or an isolated candidate runtime).
// Primary: every stream on Login (with account id) in the three Host integration flows (pre+out, record-only, check-only).
// Policy-shape coverage (risk based, not Cartesian): selected streams on login-anon / otp / api / api_custom.
// Every row carries the paired "churn/dilution correlation disabled" control (counterfactual) so independent protections (K1 spray,
// K4, K5, score thresholds) are separated from the correlation effect.
require dirname(__DIR__) . '/lib/dsg.php';

emit('D01.provenance', dsgProvenance());
$streams = dsgAllStreams();
$controls = wsbControls() + dsgControls();
foreach (dsgMatrixCells() as [$id, $shape, $flow]) {
    emit('D01.matrix', ['variant' => wsbVariant(), 'level' => wsbLevel(), 'stream' => $id, 'class' => dsgClass()[$id] ?? wsbClass($id), 'shape' => $shape, 'flow' => $flow] + wsbMeasure($streams[$id]['events'], $shape, $flow));
}
foreach (dsgMatrixOrder() as $id) {
    $times = array_column($streams[$id]['events'], 't');
    emit('D01.controls', ['stream' => $id, 'title' => $streams[$id]['title'], 'class' => dsgClass()[$id] ?? wsbClass($id), 'spanSeconds' => max($times) - min($times), 'controls' => $controls[$id] ?? null]);
}

// Baseline invariants of the UNMODIFIED runtime (S0 only; hard-fail): they reproduce the numbers published by PR #103 / #104, so the
// S0 column of this proof cannot silently drift from the published baseline.
if (wsbVariant() === 'S0') {
    $m = static fn(string $id, string $shape, string $flow): array => wsbMeasure($streams[$id]['events'], $shape, $flow);
    $l1 = $m('L1', 'login', 'pre+out');
    $x1 = $m('X1', 'login', 'pre+out');
    $x2 = $m('X2', 'login', 'pre+out');
    $y9 = $m('Y9b', 'login', 'pre+out');
    WsbAsserts::check('D01', 'S0 L1 legit denied 127/134 (published #103/#104 number reproduced)', $l1['legitDenied'] === 127 && $l1['legitAttempts'] === 134, ['denied' => $l1['legitDenied']]);
    WsbAsserts::check('D01', 'S0 X1: only 2 attacker attempts reach verification', $x1['attackReachedVerification'] === 2, ['reach' => $x1['attackReachedVerification']]);
    WsbAsserts::check('D01', 'S0 X2: only 6 attacker attempts reach verification', $x2['attackReachedVerification'] === 6, ['reach' => $x2['attackReachedVerification']]);
    WsbAsserts::check('D01', 'S0 Y9b: only 3 attacker attempts reach verification', $y9['attackReachedVerification'] === 3, ['reach' => $y9['attackReachedVerification']]);
}

// Design properties of the recommended variant (EQL): benign populations are never denied BY CORRELATION on Login pre+out. For the other
// variants these are findings (recorded, not fatal) — EQW keeps the population collision of #97, EQN loses published protections.
if (wsbVariant() !== 'S0') {
    foreach (['L1', 'L2', 'L3', 'D1'] as $id) {
        $m = wsbMeasure($streams[$id]['events'], 'login', 'pre+out');
        WsbAsserts::check('D01', "$id benign stream: no legitimate request is denied by correlation (Login, pre+out)", $m['legitDeniedByCorrelation'] === 0, ['byCorrelation' => $m['legitDeniedByCorrelation'], 'denied' => $m['legitDenied'], 'attempts' => $m['legitAttempts']]);
    }
}
WsbAsserts::finish();
