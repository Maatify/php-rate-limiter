<?php

declare(strict_types=1);

// G6 arm 2 reference: the TMS family on the repository's own unpatched src/ (arm S0, G1_ARM unset, own process), at THIS baseline. The rows have the encoding of
// the G1 archive's m01 S0 rows (V4: the rows must equal them) plus the extra measures of the TMS rows.

require dirname(__DIR__) . '/lib/g6.php';

g5ProvenanceS0();
foreach (G6_TMS_STREAMS as $id) {
    $s = g1Streams()[$id];
    $rows = g1Materialise($id, 'login', 'none');
    $ts = array_map(static fn(array $r): int => $r['t'], $rows);
    foreach (['pre+out', 'pre'] as $flow) {
        $p = g1Params('R');
        $cell = new G1Cell('S0', 'login', $p);
        $r = g1Replay($cell, $rows, $flow);
        $first600 = 0;
        $acct = [];
        for ($i = 0, $n = strlen($r['reach']); $i < $n; $i++) {
            if ($r['reach'][$i] === '1') {
                $first600 += $rows[$i]['t'] < 600 ? 1 : 0;
                $a = (string) $rows[$i]['ctx']->accountId;
                $acct[$a] = ($acct[$a] ?? 0) + 1;
            }
        }
        g1Emit('tms_s0', ['stream' => $id, 'shape' => 'login', 'strategy' => 'none', 'arm' => 'S0', 'flow' => $flow,
            'm' => g1Metrics($r['kinds'], $r['lanes'], $r['reach'], $r['dec'], $ts, $p['W']),
            'legitDeniedByCause' => $r['legitDeniedByCause'], 'attackDeniedByCause' => $r['attackDeniedByCause'], 'pLaneForeignProof' => $r['pLaneForeignProof'],
            'dec' => $r['dec'], 'lanes' => $r['lanes'], 'reach' => $r['reach'], 'kinds' => $r['kinds'],
            'reachFirst600' => $first600, 'reachPerAccountMax' => $acct === [] ? 0 : max($acct), 'accountsReached' => count($acct)]);
    }
}
