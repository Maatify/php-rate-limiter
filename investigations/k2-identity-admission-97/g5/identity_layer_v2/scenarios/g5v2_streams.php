<?php

declare(strict_types=1);

// G5 v2 STREAMS (PLAN.md §4.1) for the primary arm NOUA2 on G5V2 (the reference arm is the G5 scenario run unchanged): the 22 G3 streams through
// the corrected G4 v2 builder, attack id policies fresh and const, flows pre+out and pre, plus the same streams with no ids. Each row also records
// uaVarying (G5's rule) and matVarying (PLAN.md §5.3, the widened rule).

require dirname(__DIR__) . '/lib/g5v2.php';

if (g5v2Provenance() !== 'G5V2') {
    exit(0);
}
$arm = 'NOUA2';
$spec = g5v2Spec($arm);
$hasAttack = static fn(string $id): bool => in_array(g1Streams()[$id]['cls'], ['attack', 'both'], true);
foreach (['pre+out', 'pre'] as $flow) {
    foreach (G3_STREAMS as $id) {
        foreach (g1Strategies($id) as $strategy) {
            foreach (array_merge($hasAttack($id) ? ['fresh', 'const'] : ['fresh'], ['none']) as $policy) {
                $rows = g4v2FromStream($id, $strategy, $policy === 'none' ? 'fresh' : $policy, $policy !== 'none');
                [$cell, $gate, $host] = g5v2Cell($arm);
                $r = g5v2Replay($cell, $gate, $host, $rows, $flow, $spec);
                g4Emit('streams', ['stream' => $id, 'cls' => g1Streams()[$id]['cls'], 'strategy' => $strategy, 'ids' => $policy,
                    'arm' => $arm, 'flow' => $flow] + g4Row($rows, $r) + ['uaVarying' => g5UaVarying($rows), 'matVarying' => g5v2MatVarying($cell, $rows)]);
            }
        }
    }
}
g5v2NpEmit($arm, 'STREAMS');
