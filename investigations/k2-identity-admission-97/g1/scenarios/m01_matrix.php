<?php

declare(strict_types=1);

// G1 M01 — stream matrix. Every selected merged stream x shape x strategy x flow, per arm allowed on this runtime, with the
// per-request decision / lane / reach / kind strings (so every metric is re-derivable) and the candidate's admission evidence.
// Env G1_ONLY=ID,ID restricts the streams (development only; run.sh never sets it).

require dirname(__DIR__) . '/lib/g1.php';

$prov = g1Provenance();
$only = getenv('G1_ONLY');
$only = $only === false || $only === '' ? null : explode(',', $only);
foreach (g1Streams() as $id => $s) {
    if ($only !== null && ! in_array($id, $only, true)) {
        continue;
    }
    foreach ($s['shapes'] as $shape) {
        if ($shape === 'api_custom') {
            continue; // custom API_OVERUSE keeps S0 semantics unless it opts in (DEC-008); not part of the candidate
        }
        $flows = $shape === 'api' ? ['out', 'pre+out'] : ['pre+out', 'pre', 'out'];
        foreach (g1Strategies($id) as $strategy) {
            $rows = g1Materialise($id, $shape, $strategy);
            $ts = array_map(static fn(array $r): int => $r['t'], $rows);
            foreach (g1Arms() as $arm) {
                $sets = $arm === 'CAND' ? ['R', 'R-src4', 'R-src40'] : ['R'];
                foreach ($sets as $set) {
                    $p = g1Params($set);
                    foreach ($flows as $flow) {
                        $cell = new G1Cell($arm, $shape, $p);
                        $r = g1Replay($cell, $rows, $flow);
                        $row = [
                            'stream' => $id, 'src' => $s['src'], 'cls' => $s['cls'], 'shape' => $shape, 'strategy' => $strategy,
                            'arm' => $arm, 'set' => $set, 'flow' => $flow,
                            'm' => g1Metrics($r['kinds'], $r['lanes'], $r['reach'], $r['dec'], $ts, $p['W']),
                            'legitDeniedByCause' => $r['legitDeniedByCause'],
                            'attackDeniedByCause' => $r['attackDeniedByCause'],
                            'pLaneForeignProof' => $r['pLaneForeignProof'],
                        ];
                        if ($cell->adm !== null) {
                            $refused = [];
                            foreach ($cell->adm->refusals as $x) {
                                $refused[$x['refused']] = ($refused[$x['refused']] ?? 0) + 1;
                            }
                            ksort($refused);
                            $row['recount'] = g1Recount($cell->adm->log, $p);
                            $row['refusedByScope'] = $refused;
                            $row['storage'] = g1StorageCheck($cell->adm->log, $p['W']);
                        }
                        if ($arm !== 'S0' && $shape !== 'api') {
                            $row['pStateLeaks'] = g1PStateLeaks($cell);
                        }
                        $row['dec'] = $r['dec'];
                        $row['lanes'] = $r['lanes'];
                        $row['reach'] = $r['reach'];
                        $row['kinds'] = $r['kinds'];
                        g1Emit('m01', $row);
                    }
                }
            }
        }
    }
}
