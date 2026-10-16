<?php

declare(strict_types=1);

// C01 — the pre-registered matrix (VALIDATION_PLAN.md §1–§4): every stream × policy shape × arm × attacker strategy × flow, each on a
// FRESH Lab (fresh stores, controlled clock). Benign / both / owner cells also run a correlation-disabled counterfactual
// (ChurnDilutionDisabledStore of PR #103, same resolver) so a legitimate denial can be attributed to churn/dilution correlation.
// Deterministic: no wall clock, no randomness outside Lcg. One JSON line per cell.
require_once dirname(__DIR__) . '/lib/ci97.php';

$prov = ci97Provenance();
emit('c01', ['row' => 'provenance'] + $prov);

foreach (ci97Streams() as $stream => $def) {
    foreach ($def['shapes'] as $shape) {
        foreach (c01Arms($stream) as $arm) {
            foreach (ci97Strategies($stream, $def['cls'], $arm) as $strategy) {
                $events = ci97Materialise($def['events'], $arm, $strategy);
                foreach (c01Flows($shape) as $flow) {
                    $lab = ci97Lab($arm, $shape);
                    $res = ci97Replay(static fn(int $i): Lab => $lab, $events, $shape, $flow);
                    $row = [
                        'row' => 'cell', 'stream' => $stream, 'cls' => $def['cls'], 'shape' => $shape, 'arm' => $arm,
                        'strategy' => $strategy, 'flow' => $flow,
                    ] + ci97Metrics($res['check'], $res['record'], $res['kinds'], $res['who'], $flow) + $res;
                    if ($def['cls'] !== 'attack' && $arm !== 'CIRI-CHOFF') {
                        $ctlLab = ci97Lab($arm, $shape, null, null, true);
                        $ctl = ci97Replay(static fn(int $i): Lab => $ctlLab, $events, $shape, $flow);
                        $row['ctlCheck'] = $ctl['check'];
                        $row['ctlRecord'] = $ctl['record'];
                    }
                    if ($def['cls'] === 'owner') {
                        // G-S3 attribution counterfactual (F125-01): same events, same arm, K5 unable to produce a level.
                        $ablLab = ci97Lab($arm, $shape);
                        $ablLab->policy = new Ci97K5LevelAblatedLoginPolicy();
                        $abl = ci97Replay(static fn(int $i): Lab => $ablLab, $events, $shape, $flow);
                        $row['k5AblatedCheck'] = $abl['check'];
                        $row['k5AblatedRecord'] = $abl['record'];
                    }
                    emit('c01', $row);
                }
            }
        }
    }
}
