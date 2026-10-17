<?php

declare(strict_types=1);

// G1 targeted step X01 — the M01 matrix per DA arm (DA_T1_PLAN.md §1.2, N1 / N2 / N4). Runs on G1_BUILD=TRACE (CAND, must reproduce
// the archived strings), DAM (CAND-DA-M) or DAO (CAND-no-DA-punishment). Cells: CAND arm on R, R-src4, R-src40 and ALLU on R, every
// stream x strategy x flow; trace origins, C9-H event checks, both C1-H recounts, per-target reach.

require dirname(__DIR__) . '/lib/verify.php';

$prov = g1Provenance();
if (! in_array($prov['build'], ['TRACE', 'DAM', 'DAO', 'DAOF'], true)) {
    fwrite(STDERR, "x01 must run on the TRACE, DAM or DAO build\n");
    exit(4);
}
$variant = ['TRACE' => 'CAND', 'DAM' => 'CAND-DA-M', 'DAO' => 'CAND-no-DA-punishment', 'DAOF' => 'CAND-no-DA-no-flood-punishment'][$prov['build']];
$archived = [];
foreach (file(dirname(__DIR__, 2) . '/expected/m01_matrix.CAND.jsonl') as $line) {
    $x = json_decode($line, true);
    $archived["{$x['stream']}|{$x['shape']}|{$x['strategy']}|{$x['arm']}|{$x['set']}|{$x['flow']}"] = $x;
}
foreach (g1Streams() as $id => $s) {
    foreach ($s['shapes'] as $shape) {
        if ($shape === 'api_custom') {
            continue;
        }
        $flows = $shape === 'api' ? ['out', 'pre+out'] : ['pre+out', 'pre', 'out'];
        foreach (g1Strategies($id) as $strategy) {
            $rows = g1Materialise($id, $shape, $strategy);
            $ts = array_map(static fn(array $r): int => $r['t'], $rows);
            foreach (['CAND' => ['R', 'R-src4', 'R-src40'], 'ALLU' => ['R']] as $arm => $sets) {
                foreach ($sets as $set) {
                    $p = g1Params($set);
                    foreach ($flows as $flow) {
                        $cell = new G1Cell($arm, $shape, $p);
                        $r = g1VReplay($cell, $rows, $flow, ['trace' => true, 'c9' => $shape !== 'api' && $arm === 'CAND']);
                        $a = $archived["$id|$shape|$strategy|$arm|$set|$flow"] ?? null;
                        $da = 0;
                        $flood = 0;
                        foreach ($r['benignOrigins'] as $k => $n) {
                            $da += str_contains($k, 'distributed_account_attack') ? $n : 0;
                            $flood += str_contains($k, 'flood') ? $n : 0;
                        }
                        $ev = g1VEventRecount($cell, $rows, $r['lanes'], $r['reach'], $flow, $p);
                        g1VEmit('x01', [
                            'variant' => $variant, 'stream' => $id, 'cls' => $s['cls'], 'shape' => $shape, 'strategy' => $strategy, 'arm' => $arm, 'set' => $set, 'flow' => $flow,
                            'equivalentToArchivedCand' => $a !== null && $a['dec'] === $r['dec'] && $a['lanes'] === $r['lanes'] && $a['reach'] === $r['reach'],
                            'm' => g1Metrics($r['kinds'], $r['lanes'], $r['reach'], $r['dec'], $ts, $p['W']),
                            'target' => g1VTargetReach($rows, $r['kinds'], $r['reach'], $p['W']),
                            'benignOrigins' => $r['benignOrigins'], 'attackOrigins' => $r['attackOrigins'],
                            'c10' => g1VC10($r['benignOrigins']), 'benignDA' => $da, 'benignFlood' => $flood,
                            'c9' => ['checked' => $r['c9']['checked'], 'mixedChecked' => $r['c9']['mixedChecked'], 'violations' => count($r['c9']['violations'])],
                            'c1' => ['logOver' => g1VOverCap(g1Recount($cell->adm->log, $p)), 'eventOver' => g1VOverCap($ev)],
                            'dec' => $r['dec'], 'lanes' => $r['lanes'], 'reach' => $r['reach'], 'kinds' => $r['kinds'],
                        ]);
                    }
                }
            }
        }
    }
}
