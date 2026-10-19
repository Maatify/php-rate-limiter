<?php

declare(strict_types=1);

// G5 v2 STEAL family and N_P cost curve (PLAN.md §4.2). Build G4: arm REF. Build G5V2: NOUA2 (primary), NOUA2/B, NOUA2/C, D-NPID, D-NPID/B, D-NPID/C.
// STEAL is gated (R:STEAL, arm NOUA2, detector D-NPID); STEAL2, MULTITOK, MULTITOK-S and UAX-NP-K are reported. UAX-NP of the detector arm D-NPID is here too.

require dirname(__DIR__) . '/lib/g5v2.php';

$build = g5v2Provenance();
$arms = match ($build) {
    'G4' => ['REF'],
    'G5V2' => ['NOUA2', 'NOUA2/B', 'NOUA2/C', 'D-NPID', 'D-NPID/B', 'D-NPID/C'],
    default => [],
};
foreach ($arms as $arm) {
    g4Emit('fx', g5v2StealRun($arm, false));
    g5v2NpEmit($arm, 'STEAL');
    g4Emit('fx', g5v2StealRun($arm, true));
    g5v2NpEmit($arm, 'STEAL2');
    g4Emit('fx', g5v2Multitok($arm, false));
    g4Emit('fx', g5v2Multitok($arm, true));
    g5v2NpEmit($arm, 'MULTITOK');
    if (in_array($arm, ['REF', 'NOUA2', 'D-NPID'], true)) {
        foreach ([1, 2, 5, 10, 11, 12] as $k) {
            g4Emit('fx', g5v2NpK($arm, $k, 'UAX-NP-K'));
        }
        g5v2NpEmit($arm, 'UAX-NP-K');
    }
    if ($arm === 'D-NPID') {
        g4Emit('fx', g5v2NpK($arm, 12, 'UAX-NP'));
        g5v2NpEmit($arm, 'UAX-NP');
    }
}
