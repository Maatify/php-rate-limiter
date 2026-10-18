<?php

declare(strict_types=1);

// G4 STREAMS (PLAN.md §2) for every arm of the current build; on CAND, the S24 reference (V0).
// Attack rows run with two id policies (fresh per request, constant); best response is taken in report.php.
// `pre` runs for FULL, FULL-40, NO-BIND (PLAN.md §2) and for NO-TB, whose registered necessity expectation (§4) names the `pre`
// BRU reach.

require dirname(__DIR__) . '/lib/g4.php';

$build = g4Provenance();
if ($build === 'CAND') {
    foreach (G3_STREAMS as $id) {
        foreach (g1Strategies($id) as $strategy) {
            $rows = g4FromStream($id, $strategy, 'fresh', false);
            $cell = new G1Cell('CAND', 'login', g3Params(24));
            $r = g4Replay($cell, new G4Gate($cell->adm, []), new G4Host('off'), $rows, 'pre+out');
            g4Emit('v0', ['stream' => $id, 'strategy' => $strategy, 'arm' => 'S24', 'flow' => 'pre+out'] + g4Row($rows, $r));
        }
    }
    exit(0);
}
$hasAttack = static fn(string $id): bool => in_array(g1Streams()[$id]['cls'], ['attack', 'both'], true);
foreach (g4Arms() as $arm => [$b]) {
    if ($b !== $build) {
        continue;
    }
    foreach (in_array($arm, ['FULL', 'FULL-40', 'NO-BIND', 'NO-TB'], true) ? ['pre+out', 'pre'] : ['pre+out'] as $flow) {
        foreach (G3_STREAMS as $id) {
            foreach (g1Strategies($id) as $strategy) {
                foreach ($hasAttack($id) ? ['fresh', 'const'] : ['fresh'] as $policy) {
                    $rows = g4FromStream($id, $strategy, $policy);
                    [$cell, $gate, $host] = g4Cell($arm);
                    $r = g4Replay($cell, $gate, $host, $rows, $flow);
                    g4Emit('streams', ['stream' => $id, 'cls' => g1Streams()[$id]['cls'], 'strategy' => $strategy, 'ids' => $policy,
                        'arm' => $arm, 'flow' => $flow] + g4Row($rows, $r));
                }
            }
        }
    }
}
