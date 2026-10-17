<?php

declare(strict_types=1);

// G3 S00 — S0 reference reproduction (PLAN.md §5 V0): the unpatched repository runtime replays the §4.1 streams in pre+out;
// report.php compares every row with the archived G1 S0 rows (g1/expected/m01_matrix.S0.jsonl).

require dirname(__DIR__) . '/lib/g3.php';

$prov = g1Provenance();
if ($prov['build'] !== 'S0') {
    fwrite(STDERR, "s00 must run on S0\n");
    exit(4);
}
foreach (G3_STREAMS as $id) {
    foreach (g1Strategies($id) as $strategy) {
        $rows = g1Materialise($id, 'login', $strategy);
        $r = g1Replay(new G1Cell('S0', 'login', g1Params('R')), $rows, 'pre+out');
        g3Emit('s00', ['stream' => $id, 'cls' => g1Streams()[$id]['cls'], 'strategy' => $strategy, 'arm' => 'S0', 'flow' => 'pre+out']
            + g3Row($rows, $r + ['attackDeniedByCause' => $r['attackDeniedByCause'] ?? []]));
    }
}
