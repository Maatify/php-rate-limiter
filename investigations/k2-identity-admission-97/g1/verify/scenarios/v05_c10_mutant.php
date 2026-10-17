<?php

declare(strict_types=1);

// G1 verification V05 — C10-H detector control M-C10 (VERIFY_PLAN.md §2). MUTANT build (G1_ARM=CAND, G1_BUILD=MUTANT): the trace
// build with the D1 correlation candidate re-enabled. The same attribution and C10-H verdict as V01 must report correlation-origin
// benign denials on the benign streams that S0's correlation penalised.

require dirname(__DIR__) . '/lib/verify.php';

$prov = g1Provenance();
if ($prov['build'] !== 'MUTANT') {
    fwrite(STDERR, "v05 must run on the MUTANT build\n");
    exit(4);
}
foreach (['D1', 'L3', 'SESS', 'D2', 'ABA_B', 'N12V', 'X2L'] as $id) {
    $shape = g1Streams()[$id]['shapes'][0];
    $rows = g1Materialise($id, $shape, 'none');
    $cell = new G1Cell('CAND', $shape, g1Params('R'));
    $r = g1VReplay($cell, $rows, 'pre+out', ['trace' => true]);
    $m = g1Metrics($r['kinds'], $r['lanes'], $r['reach'], $r['dec'], array_map(static fn(array $x): int => $x['t'], $rows));
    g1VEmit('v05', ['case' => 'C10-H detector M-C10', 'stream' => $id, 'legitDenied' => $m['legitDenied'], 'legitAttempts' => $m['legitAttempts'],
        'benignOrigins' => $r['benignOrigins'], 'c10' => g1VC10($r['benignOrigins']), 'detected' => g1VC10($r['benignOrigins'])['forbidden'] > 0]);
}
