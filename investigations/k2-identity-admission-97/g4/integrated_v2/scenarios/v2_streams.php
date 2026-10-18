<?php

declare(strict_types=1);

// G4 v2 STREAMS (PLAN.md §2, §4) for every arm of the current build, through the corrected builder; on CAND, the S24 reference
// (V0, no instance ids by definition). Attack rows run with two id policies (fresh, const); best response is taken in report.php.

require dirname(__DIR__) . '/lib/g4v2.php';

$build = g4Provenance();
if ($build === 'CAND') {
    foreach (G3_STREAMS as $id) {
        foreach (g1Strategies($id) as $strategy) {
            $rows = g4v2FromStream($id, $strategy, 'fresh', false);
            $cell = new G1Cell('CAND', 'login', g3Params(24));
            $r = g4v2Replay($cell, new G4Gate($cell->adm, []), new G4Host('off'), $rows, 'pre+out');
            g4Emit('v0', ['stream' => $id, 'strategy' => $strategy, 'arm' => 'S24', 'flow' => 'pre+out'] + g4Row($rows, $r));
        }
    }
    exit(0);
}
$hasAttack = static fn(string $id): bool => in_array(g1Streams()[$id]['cls'], ['attack', 'both'], true);
$preArms = ['R-FULL', 'R-FULL-40', 'R-NO-BIND', 'R-NO-TB', 'FULL2', 'FULL2-40', 'N-PG1', 'P-NETF'];
foreach (g4v2ArmsOf($build) as $arm) {
    foreach (in_array($arm, $preArms, true) ? ['pre+out', 'pre'] : ['pre+out'] as $flow) {
        foreach (G3_STREAMS as $id) {
            foreach (g1Strategies($id) as $strategy) {
                foreach ($hasAttack($id) ? ['fresh', 'const'] : ['fresh'] as $policy) {
                    $rows = g4v2FromStream($id, $strategy, $policy);
                    [$cell, $gate, $host] = g4v2Cell($arm);
                    $r = g4v2Replay($cell, $gate, $host, $rows, $flow);
                    g4Emit('streams', ['stream' => $id, 'cls' => g1Streams()[$id]['cls'], 'strategy' => $strategy, 'ids' => $policy,
                        'arm' => $arm, 'flow' => $flow] + g4Row($rows, $r));
                }
            }
        }
    }
}
