<?php

declare(strict_types=1);

// G7 STREAMS (PLAN.md §4) on the archived G5V2 build. (1) The 22 G3 streams for NOUA2 (V0: the rows equal the G5 v2 archive), PX4, PX8, as g5v2_streams.php with g7Replay;
// D-PXALL on N12 only. (2) The office and multi-session cells N12T, TPAIRT, TMS, TMSd, TMX (G1 stream table) for NOUA2, PX4, PX8; D-PXALL on N12T. Attack id policies fresh
// and const and the no-id path; flows pre+out and pre. The TMS family rows carry the G6 extra measures (V1: the NOUA2 rows equal the G6 archive).

require dirname(__DIR__) . '/lib/g7.php';

if (g6Provenance() !== 'G5V2') {
    exit(0);
}
const G7_EXTRA = ['N12T', 'TPAIRT', 'TMS', 'TMSd', 'TMX'];
$hasAttack = static fn(string $id): bool => in_array(g1Streams()[$id]['cls'], ['attack', 'both'], true);
$run = static function (string $arm, string $id, string $flow, bool $extra) use ($hasAttack): void {
    $spec = g7Spec($arm);
    foreach (g1Strategies($id) as $strategy) {
        foreach (array_merge($hasAttack($id) ? ['fresh', 'const'] : ['fresh'], ['none']) as $policy) {
            $rows = g4v2FromStream($id, $strategy, $policy === 'none' ? 'fresh' : $policy, $policy !== 'none');
            [$cell, $gate, $host] = g7Cell($arm);
            $px = g7Px($arm);
            $r = g7Replay($cell, $gate, $host, $rows, $flow, $spec, $px);
            $row = ['stream' => $id, 'cls' => g1Streams()[$id]['cls'], 'strategy' => $strategy, 'ids' => $policy, 'arm' => $arm, 'flow' => $flow]
                + g4Row($rows, $r) + ['uaVarying' => g5UaVarying($rows), 'matVarying' => g5v2MatVarying($cell, $rows)];
            if ($extra && in_array($id, G6_TMS_STREAMS, true)) {
                $row += g6TmsRow($rows, $r);
            }
            if ($spec['f'] !== null) {
                $row += ['refusedPx' => $r['refusedPx']];
            }
            g4Emit($extra ? ($id === 'N12T' || $id === 'TPAIRT' ? 'xs' : 'tms') : 'streams', $row);
        }
    }
};
foreach (['NOUA2', 'PX4', 'PX8', 'D-PXALL'] as $arm) {
    foreach (['pre+out', 'pre'] as $flow) {
        foreach (G3_STREAMS as $id) {
            if ($arm === 'D-PXALL' && $id !== 'N12') {
                continue;
            }
            $run($arm, $id, $flow, false);
        }
        foreach (G7_EXTRA as $id) {
            if ($arm === 'D-PXALL' && $id !== 'N12T') {
                continue;
            }
            $run($arm, $id, $flow, true);
        }
    }
    g5v2NpEmit($arm, 'STREAMS');
}
