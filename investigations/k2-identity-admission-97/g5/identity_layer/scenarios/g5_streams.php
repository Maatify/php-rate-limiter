<?php

declare(strict_types=1);

// G5 STREAMS (PLAN.md §4.1) for the arm of the current build: REF on G4, NOUA on G5, through the corrected G4 v2 builder, attack id
// policies fresh and const, flows pre+out and pre, plus the same streams with no ids (ids = none). On CAND: the S24 reference (V0).

require dirname(__DIR__) . '/lib/g5.php';

$build = g5Provenance();
if ($build === 'CAND') {
    foreach (G3_STREAMS as $id) {
        foreach (g1Strategies($id) as $strategy) {
            $rows = g4v2FromStream($id, $strategy, 'fresh', false);
            $cell = new G1Cell('CAND', 'login', g3Params(24));
            $r = g5Replay($cell, new G4Gate($cell->adm, []), new G4Host('off'), $rows, 'pre+out', false);
            g4Emit('v0', ['stream' => $id, 'strategy' => $strategy, 'arm' => 'S24', 'flow' => 'pre+out'] + g4Row($rows, $r));
        }
    }
    exit(0);
}
$arm = g5ArmOfBuild($build);
if (! in_array($arm, ['REF', 'NOUA'], true)) {
    exit(0);
}
$hasAttack = static fn(string $id): bool => in_array(g1Streams()[$id]['cls'], ['attack', 'both'], true);
foreach (['pre+out', 'pre'] as $flow) {
    foreach (G3_STREAMS as $id) {
        foreach (g1Strategies($id) as $strategy) {
            foreach (array_merge($hasAttack($id) ? ['fresh', 'const'] : ['fresh'], ['none']) as $policy) {
                $rows = g4v2FromStream($id, $strategy, $policy === 'none' ? 'fresh' : $policy, $policy !== 'none');
                [$cell, $gate, $host] = g5Cell($arm);
                $r = g5Replay($cell, $gate, $host, $rows, $flow, $arm === 'NOUA');
                g4Emit('streams', ['stream' => $id, 'cls' => g1Streams()[$id]['cls'], 'strategy' => $strategy, 'ids' => $policy,
                    'arm' => $arm, 'flow' => $flow] + g4Row($rows, $r) + ['uaVarying' => g5UaVarying($rows)]);
            }
        }
    }
}
