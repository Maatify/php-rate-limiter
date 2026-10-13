<?php

declare(strict_types=1);

// K02 — benign-NAT vs credential-spray matrix. Every stream, on the four arms A/B/C/D (TEST-ONLY controls in the correlation store; the
// pipeline is the unpatched S0), in the Host flows pre+out / pre (checkOnly only) / out (record-only), plus Login-without-account and OTP
// shapes. Same events, same controlled clock, same scopes on every arm. Winning source / enforcement key come from the per-request attribution
// of lib/k1.php (persisted blocks before the request, blocks issued by it, correlation signals observed during it).
require dirname(__DIR__) . '/lib/k1.php';
require dirname(__DIR__) . '/lib/cells.php';

k1Emit('K02.provenance', k1Provenance());
$streams = k1Streams();
foreach (k1MatrixCells() as [$id, $shape, $flow]) {
    $cell = k1Cell($streams[$id]['events'], $shape, $flow);
    unset($cell['rows']);
    k1Emit('K02.matrix', ['stream' => $id, 'class' => k1StreamClasses()[$id], 'shape' => $shape, 'flow' => $flow] + $cell);
}
foreach ($streams as $id => $s) {
    $times = array_column($s['events'], 't');
    k1Emit('K02.stream', ['stream' => $id, 'title' => $s['title'], 'class' => $s['class'], 'events' => count($s['events']), 'spanSeconds' => max($times) - min($times)]);
}

// Baseline invariants (hard-fail): the arms must reproduce the PUBLISHED S0 numbers of PR #104/#105 for D2, otherwise this study is not
// measuring the same thing: A = published S0, B = published "no-correlation control" (108), plus the control-effectiveness checks.
$d2 = fn(string $flow): array => k1Cell($streams['D2']['events'], 'login', $flow);
$pre = $d2('pre+out');
$out = $d2('out');
K1Asserts::check('K02', 'D2 arm A reproduces the published S0 number: 117/121 legitimate requests denied (Login, pre+out)', $pre['arms']['A']['legitDenied'] === 117 && $pre['arms']['A']['legitAttempts'] === 121, ['denied' => $pre['arms']['A']['legitDenied']]);
K1Asserts::check('K02', 'D2 arm B (churn/dilution off) reproduces the published no-correlation control: 108/121 denied (Login, pre+out)', $pre['arms']['B']['legitDenied'] === 108, ['denied' => $pre['arms']['B']['legitDenied']]);
K1Asserts::check('K02', 'D2 record-only: A 115 and B 7 reproduce the published numbers', $out['arms']['A']['legitDenied'] === 115 && $out['arms']['B']['legitDenied'] === 7, ['A' => $out['arms']['A']['legitDenied'], 'B' => $out['arms']['B']['legitDenied']]);
K1Asserts::check('K02', 'CONTROL: arm C never lets a K1 spray threshold be reached while arm A does (the isolated control really disables K1 spray)', $pre['arms']['C']['sprayThresholdRequests'] === 0 && $pre['arms']['A']['sprayThresholdRequests'] > 0, ['A' => $pre['arms']['A']['sprayThresholdRequests'], 'C' => $pre['arms']['C']['sprayThresholdRequests']]);
K1Asserts::check('K02', 'CONTROL: arm B keeps K1 spray alive (spray threshold still reached) and arm D disables it', $pre['arms']['B']['sprayThresholdRequests'] > 0 && $pre['arms']['D']['sprayThresholdRequests'] === 0, ['B' => $pre['arms']['B']['sprayThresholdRequests'], 'D' => $pre['arms']['D']['sprayThresholdRequests']]);
$sp = k1Cell($streams['SP1n']['events'], 'login', 'pre+out');
K1Asserts::check('K02', 'CONTROL: the spray stream is stopped by K1 in arm A (<=10 of 200 attempts reach verification) and unconstrained when K1 spray is off (arm C 200/200)', $sp['arms']['A']['attackReachedVerification'] <= 10 && $sp['arms']['C']['attackReachedVerification'] === 200, ['A' => $sp['arms']['A']['attackReachedVerification'], 'C' => $sp['arms']['C']['attackReachedVerification']]);
K1Asserts::check('K02', 'DEC: spray observation is precheck-only: the record-only flow makes ZERO K1 spray observations on every arm', $out['arms']['A']['sprayObservations'] === 0 && $out['arms']['C']['sprayObservations'] === 0, ['A' => $out['arms']['A']['sprayObservations']]);
K1Asserts::finish();
