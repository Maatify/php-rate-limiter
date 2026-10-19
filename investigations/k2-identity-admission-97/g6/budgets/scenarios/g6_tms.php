<?php

declare(strict_types=1);

// G6 arm 2 (PLAN.md §2.2, §4.3) on the assembled arms: build G4 = REF (`FULL2` of G4 v2, the archived G5 code path), build G5V2 = NOUA2 (`FULL2` + the G5 v2
// identity layer, the archived G5 v2 code path). Streams TMS, TMSd, TMX (from the G1 stream table) and the controls N12 and SP1n, through the corrected
// G4 v2 builder, attack id policies fresh and const, flows pre+out and pre, plus the no-id path. Control rows are emitted in the shape of the archive rows
// (V3); the three TMS cells carry the extra measures of g6TmsRow. Reporting only: no gate.

require dirname(__DIR__) . '/lib/g6.php';

$build = g6Provenance();
$arm = match ($build) {
    'G4' => 'REF',
    'G5V2' => 'NOUA2',
    default => exit(0),
};
$hasAttack = static fn(string $id): bool => in_array(g1Streams()[$id]['cls'], ['attack', 'both'], true);
foreach (['pre+out', 'pre'] as $flow) {
    foreach (array_merge(G6_TMS_STREAMS, G6_TMS_CONTROLS) as $id) {
        foreach (g1Strategies($id) as $strategy) {
            foreach (array_merge($hasAttack($id) ? ['fresh', 'const'] : ['fresh'], ['none']) as $policy) {
                $rows = g4v2FromStream($id, $strategy, $policy === 'none' ? 'fresh' : $policy, $policy !== 'none');
                if ($arm === 'REF') {
                    [$cell, $gate, $host] = g5Cell('REF');
                    $r = g5Replay($cell, $gate, $host, $rows, $flow, false);
                    $mat = [];
                } else {
                    [$cell, $gate, $host] = g5v2Cell('NOUA2');
                    $r = g5v2Replay($cell, $gate, $host, $rows, $flow, g5v2Spec('NOUA2'));
                    $mat = ['matVarying' => g5v2MatVarying($cell, $rows)];
                }
                $row = ['stream' => $id, 'cls' => g1Streams()[$id]['cls'], 'strategy' => $strategy, 'ids' => $policy, 'arm' => $arm, 'flow' => $flow]
                    + g4Row($rows, $r) + ['uaVarying' => g5UaVarying($rows)] + $mat;
                g4Emit(in_array($id, G6_TMS_STREAMS, true) ? 'tms' : 'streams', in_array($id, G6_TMS_STREAMS, true) ? $row + g6TmsRow($rows, $r) : $row);
            }
        }
    }
}
if ($arm === 'NOUA2') {
    g5v2NpEmit($arm, 'TMS');
}
