<?php

declare(strict_types=1);

// D07 — the LOW (UA-only) cross-IP dilution scope decision (OD-1), measured on ONE runtime with the three scope options selectable by the
// test-only EQ_MUTATE injectors of the patch, so every option is reproducible from the same pinned patched file:
//   advisory     : EQL as designed (no env)                         — LOW signal neither observed nor enforced
//   narrow       : EQL + EQ_MUTATE=low_narrow                        — LOW signal enforced only on the scored (evidence-bearing) request
//   wide         : EQL + EQ_MUTATE=low_enforce (== variant EQW rule) — LOW signal enforced population-wide while fresh evidence exists
// S0 (no patch) is the fourth column. The output rows are labelled with the option, never with a variant name that could be confused.
require dirname(__DIR__) . '/lib/dsg.php';

emit('D07.provenance', dsgProvenance());
$mutations = (string) getenv('EQ_MUTATE');
$option = wsbVariant() === 'S0' ? 's0' : (str_contains($mutations, 'low_narrow') ? 'narrow' : (str_contains($mutations, 'low_enforce') ? 'wide' : 'advisory'));
$streams = dsgAllStreams();
foreach (['L3', 'D1', 'D3', 'D4', 'X2', 'X7', 'X2L', 'X8', 'Y2c'] as $id) {
    $m = wsbMeasure($streams[$id]['events'], 'login', 'pre+out');
    emit('D07.option', ['option' => $option, 'stream' => $id, 'class' => dsgClass()[$id] ?? wsbClass($id), 'attackReach' => $m['attackReachedVerification'], 'attackAttempts' => $m['attackAttempts'], 'controlReach' => $m['controlAttackReachedVerification'], 'legitDenied' => $m['legitDenied'], 'legitDeniedByCorrelation' => $m['legitDeniedByCorrelation'], 'legitAttempts' => $m['legitAttempts']]);
}
WsbAsserts::finish();
