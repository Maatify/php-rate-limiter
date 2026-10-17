<?php

declare(strict_types=1);

// G3 S01 — V0 reproduction, MID and FC on the G1 candidate build (PLAN.md §2.1–§2.3, §4.1, §5).
//   v0   : S12 / S40 replayed with the G1 verification replay (g1VReplay), exactly as the archived v07 rows were produced;
//   arms : S12, S24, S40 (gate without FC / TB: must equal v0 for S12 / S40), FC4, FC8, FCALL8, through g3Replay.

require dirname(__DIR__) . '/lib/g3.php';

$prov = g3Provenance();
if ($prov['build'] !== 'CAND') {
    fwrite(STDERR, "s01 must run on the CAND build\n");
    exit(4);
}
foreach (['S12' => 12, 'S40' => 40] as $set => $uSrc) {
    $p = g3Params($uSrc);
    foreach (G3_STREAMS as $id) {
        foreach (g1Strategies($id) as $strategy) {
            $rows = g1Materialise($id, 'login', $strategy);
            $r = g1VReplay(new G1Cell('CAND', 'login', $p), $rows, 'pre+out');
            g3Emit('v0', ['stream' => $id, 'strategy' => $strategy, 'set' => $set, 'dec' => $r['dec'], 'reach' => $r['reach'],
                'kinds' => $r['kinds'], 'lanes' => $r['lanes'], 'legitDeniedByCause' => $r['legitDeniedByCause']]);
        }
    }
}
$arms = [
    'S12' => [12, []], 'S24' => [24, []], 'S40' => [40, []],
    'FC4' => [40, ['fc' => 4]], 'FC8' => [40, ['fc' => 8]], 'FCALL8' => [40, ['fc' => 8, 'fcAll' => true]],
];
$flows = ['S12' => ['pre+out'], 'S24' => ['pre+out'], 'S40' => ['pre+out', 'pre', 'out'], 'FC4' => ['pre+out', 'pre'],
    'FC8' => ['pre+out', 'pre', 'out'], 'FCALL8' => ['pre+out']];
foreach ($arms as $arm => [$uSrc, $opts]) {
    foreach ($flows[$arm] as $flow) {
        foreach (G3_STREAMS as $id) {
            foreach (g1Strategies($id) as $strategy) {
                $rows = g1Materialise($id, 'login', $strategy);
                [$cell, $gate] = g3Cell(g3Params($uSrc), $opts);
                $r = g3Replay($cell, $gate, $rows, $flow);
                g3Emit('s01', ['stream' => $id, 'cls' => g1Streams()[$id]['cls'], 'strategy' => $strategy, 'arm' => $arm, 'flow' => $flow]
                    + g3Row($rows, $r));
            }
        }
    }
}
