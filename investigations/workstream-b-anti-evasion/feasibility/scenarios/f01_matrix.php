<?php

declare(strict_types=1);

// F01 — attack-vs-legitimate matrix on the loaded runtime (S0 real, or an isolated candidate runtime).
// Every cell carries the paired "churn/dilution correlation disabled" control (counterfactual), the winning decision scope of every denial
// (issued/active : K-family; correlation vs independent), the penalty scope of every persisted block, the store-operation count and the
// peak of live correlation entries. `reach` in the record-only (`out`) flow equals the attempts: the host verified before the library saw
// the outcome, so only pre+out / pre rows measure attacker verification.
require dirname(__DIR__) . '/lib/fsb.php';

emit('F01.provenance', fsbProvenance());
$streams = dsgAllStreams();
$controls = wsbControls() + dsgControls();
foreach (fsbMatrixCells() as [$id, $shape, $flow]) {
    emit('F01.matrix', ['variant' => wsbVariant(), 'level' => wsbLevel(), 'stream' => $id, 'class' => dsgClass()[$id] ?? wsbClass($id), 'shape' => $shape, 'flow' => $flow] + fsbMeasure($streams[$id]['events'], $shape, $flow));
}
foreach (array_merge(fsbCoreStreams(), fsbExtendedStreams()) as $id) {
    $times = array_column($streams[$id]['events'], 't');
    emit('F01.controls', ['stream' => $id, 'title' => $streams[$id]['title'], 'class' => dsgClass()[$id] ?? wsbClass($id), 'spanSeconds' => max($times) - min($times), 'controls' => $controls[$id] ?? null]);
}

// Baseline invariants of the UNMODIFIED runtime (S0 only; hard-fail): they reproduce the numbers published by PR #103/#104/#105.
if (wsbVariant() === 'S0') {
    $m = static fn(string $id, string $shape, string $flow): array => fsbMeasure($streams[$id]['events'], $shape, $flow);
    $l1 = $m('L1', 'login', 'pre+out');
    $d1 = $m('D1', 'login', 'pre+out');
    $x1 = $m('X1', 'login', 'pre+out');
    $x2 = $m('X2', 'login', 'pre+out');
    $y9 = $m('Y9b', 'login', 'pre+out');
    $d2 = $m('D2', 'login', 'pre+out');
    WsbAsserts::check('F01', 'S0 L1 legit denied 127/134 (published #103/#104/#105 number reproduced)', $l1['legitDenied'] === 127 && $l1['legitAttempts'] === 134, ['denied' => $l1['legitDenied']]);
    WsbAsserts::check('F01', 'S0 D1 legit denied 596/600 (published #105 number reproduced)', $d1['legitDenied'] === 596 && $d1['legitAttempts'] === 600, ['denied' => $d1['legitDenied']]);
    WsbAsserts::check('F01', 'S0 D2: 108 of the legitimate denials are independent of correlation (K1 spray, #106 — the control equals the runtime)', $d2['controlLegitDenied'] === 108, ['control' => $d2['controlLegitDenied']]);
    WsbAsserts::check('F01', 'S0 X1: only 2 attacker attempts reach verification', $x1['attackReachedVerification'] === 2, ['reach' => $x1['attackReachedVerification']]);
    WsbAsserts::check('F01', 'S0 X2: only 6 attacker attempts reach verification', $x2['attackReachedVerification'] === 6, ['reach' => $x2['attackReachedVerification']]);
    WsbAsserts::check('F01', 'S0 Y9b: only 3 attacker attempts reach verification', $y9['attackReachedVerification'] === 3, ['reach' => $y9['attackReachedVerification']]);
}
WsbAsserts::finish();
