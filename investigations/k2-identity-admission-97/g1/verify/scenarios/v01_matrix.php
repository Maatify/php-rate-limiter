<?php

declare(strict_types=1);

// G1 verification V01 — the M01 matrix replayed on the TRACE build (G1_ARM=CAND, G1_BUILD=TRACE), every CAND-runtime cell.
// Per cell: (1) equivalence of dec / lanes / reach with the archived ../../expected/m01_matrix.CAND.jsonl (precondition of the
// trace build); (2) C10-H origins of every denial; (3) C9-H P-state delta around every U command and U admission, mixed
// accounts included; (4) C1-H recount from the admission log AND independently from the replayed events.
// Env G1_ONLY=ID,ID restricts the streams (development only; run_verify.sh never sets it).

require dirname(__DIR__) . '/lib/verify.php';

$prov = g1Provenance();
if ($prov['build'] !== 'TRACE') {
    fwrite(STDERR, "v01 must run on the TRACE build\n");
    exit(4);
}
$archived = [];
foreach (file(dirname(__DIR__, 2) . '/expected/m01_matrix.CAND.jsonl') as $line) {
    $x = json_decode($line, true);
    $archived["{$x['stream']}|{$x['shape']}|{$x['strategy']}|{$x['arm']}|{$x['set']}|{$x['flow']}"] = $x;
}
$only = getenv('G1_ONLY');
$only = $only === false || $only === '' ? null : explode(',', $only);
foreach (g1Streams() as $id => $s) {
    if ($only !== null && ! in_array($id, $only, true)) {
        continue;
    }
    foreach ($s['shapes'] as $shape) {
        if ($shape === 'api_custom') {
            continue;
        }
        $flows = $shape === 'api' ? ['out', 'pre+out'] : ['pre+out', 'pre', 'out'];
        foreach (g1Strategies($id) as $strategy) {
            $rows = g1Materialise($id, $shape, $strategy);
            foreach (g1Arms() as $arm) {
                foreach ($arm === 'CAND' ? ['R', 'R-src4', 'R-src40'] : ['R'] as $set) {
                    $p = g1Params($set);
                    foreach ($flows as $flow) {
                        $cell = new G1Cell($arm, $shape, $p);
                        $r = g1VReplay($cell, $rows, $flow, ['trace' => true, 'c9' => $shape !== 'api' && $arm !== 'ALLU']);
                        $a = $archived["$id|$shape|$strategy|$arm|$set|$flow"] ?? null;
                        $row = [
                            'stream' => $id, 'cls' => $s['cls'], 'shape' => $shape, 'strategy' => $strategy, 'arm' => $arm, 'set' => $set, 'flow' => $flow,
                            'equivalent' => $a !== null && $a['dec'] === $r['dec'] && $a['lanes'] === $r['lanes'] && $a['reach'] === $r['reach'] && $a['kinds'] === $r['kinds'],
                            'archivedCause' => $a['legitDeniedByCause'] ?? null,
                            'benignOrigins' => $r['benignOrigins'],
                            'attackOrigins' => $r['attackOrigins'],
                            'c10' => g1VC10($r['benignOrigins']),
                            'c9' => ['checked' => $r['c9']['checked'], 'mixedChecked' => $r['c9']['mixedChecked'], 'violations' => count($r['c9']['violations']),
                                'first' => array_slice($r['c9']['violations'], 0, 3)],
                        ];
                        if ($cell->adm !== null) {
                            $log = g1VOverCap(g1Recount($cell->adm->log, $p));
                            $ev = g1VEventRecount($cell, $rows, $r['lanes'], $r['reach'], $flow, $p);
                            $row['c1'] = ['logOver' => $log, 'eventOver' => g1VOverCap($ev), 'eventRecount' => $ev,
                                'logAdmissions' => count($cell->adm->log), 'eventAdmissions' => $flow === 'out' && $shape !== 'api' ? null : substr_count($r['reach'], '1')];
                        }
                        g1VEmit('v01', $row);
                    }
                }
            }
        }
    }
}
