<?php

declare(strict_types=1);

// G3 v2 — net failure cap (PLAN.md §2, §4, §5 FCN-1 … FCN-4) on the CAND build. S12 / S24 / S40 and FC4-40 are v1 re-runs
// (V0 compares them with the v1 archive).

require dirname(__DIR__) . '/lib/g3v2.php';

$prov = g3Provenance();
if ($prov['build'] !== 'CAND') {
    fwrite(STDERR, "v2_fc must run on the CAND build\n");
    exit(4);
}
$arms = [
    'S12' => [12, []], 'S24' => [24, []], 'S40' => [40, []],
    'FCN4-24' => [24, ['fc' => 4, 'net' => true]], 'FCN8-24' => [24, ['fc' => 8, 'net' => true]],
    'FCN4-40' => [40, ['fc' => 4, 'net' => true]], 'FCN8-40' => [40, ['fc' => 8, 'net' => true]],
    'FC4-40' => [40, ['fc' => 4]],
];
$pre = ['S24', 'S40', 'FCN4-24', 'FCN4-40'];
foreach ($arms as $arm => [$uSrc, $opts]) {
    foreach (in_array($arm, $pre, true) ? ['pre+out', 'pre'] : ['pre+out'] as $flow) {
        foreach (G3_STREAMS as $id) {
            foreach (g1Strategies($id) as $strategy) {
                $rows = g1Materialise($id, 'login', $strategy);
                [$cell, $gate] = g3v2Cell(g3Params($uSrc), $opts);
                $r = g3v2Replay($cell, $gate, $rows, $flow);
                g3Emit('v2fc', ['stream' => $id, 'cls' => g1Streams()[$id]['cls'], 'strategy' => $strategy, 'arm' => $arm, 'flow' => $flow]
                    + g3Row($rows, $r));
            }
        }
    }
}
