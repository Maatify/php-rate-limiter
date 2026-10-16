<?php

declare(strict_types=1);

// C03 — POST-HOC PROBE (VALIDATION_PLAN.md §7; NOT pre-registered, NOT eligible for the verdict). Runs arm CIRLI (the CIR derivation
// with an untrusted instance id kept at LOW confidence, lib/ci97.php Ci97LowConfidenceProbeResolver) over every C01 stream, shape,
// strategy and flow, so the pre-registered gate formulas can be applied to it for diagnosis only. Same Lab, same streams, same
// counterfactual as C01.
require_once dirname(__DIR__) . '/lib/ci97.php';

$prov = ci97Provenance();
emit('c03', ['row' => 'provenance', 'posthoc' => true] + $prov);

foreach (ci97Streams() as $stream => $def) {
    foreach ($def['shapes'] as $shape) {
        foreach (ci97Strategies($stream, $def['cls'], 'CIRLI') as $strategy) {
            $events = ci97Materialise($def['events'], 'CIRLI', $strategy);
            foreach (str_starts_with($shape, 'api') ? ['out', 'pre+out'] : ['pre+out', 'pre', 'out'] as $flow) {
                $lab = ci97Lab('CIRLI', $shape);
                $res = ci97Replay(static fn(int $i): Lab => $lab, $events, $shape, $flow);
                $row = [
                    'row' => 'cell', 'posthoc' => true, 'stream' => $stream, 'cls' => $def['cls'], 'shape' => $shape, 'arm' => 'CIRLI',
                    'strategy' => $strategy, 'flow' => $flow,
                ] + ci97Metrics($res['check'], $res['record'], $res['kinds'], $res['who'], $flow) + $res;
                if ($def['cls'] !== 'attack') {
                    $ctlLab = ci97Lab('CIRLI', $shape, null, null, true);
                    $ctl = ci97Replay(static fn(int $i): Lab => $ctlLab, $events, $shape, $flow);
                    $row['ctlCheck'] = $ctl['check'];
                    $row['ctlRecord'] = $ctl['record'];
                }
                emit('c03', $row);
            }
        }
    }
}
