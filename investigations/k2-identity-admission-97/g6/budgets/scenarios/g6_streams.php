<?php

declare(strict_types=1);

// G6 arm 1 STREAMS (PLAN.md §4.1) on the G6UK build for the arms NOUA2 (V0: the rows equal the G5 v2 archive) and UKD: the 22 G3 streams through the
// corrected G4 v2 builder, attack id policies fresh and const, flows pre+out and pre, plus the same streams with no ids. As g5v2_streams.php, with g6Replay.
// UKD rows also record `routedUk`, the number of requests processed on the UK lane.

require dirname(__DIR__) . '/lib/g6.php';

if (g6Provenance() !== 'G6UK') {
    exit(0);
}
$hasAttack = static fn(string $id): bool => in_array(g1Streams()[$id]['cls'], ['attack', 'both'], true);
foreach (['NOUA2', 'UKD'] as $arm) {
    $spec = g6Spec($arm);
    foreach (['pre+out', 'pre'] as $flow) {
        foreach (G3_STREAMS as $id) {
            foreach (g1Strategies($id) as $strategy) {
                foreach (array_merge($hasAttack($id) ? ['fresh', 'const'] : ['fresh'], ['none']) as $policy) {
                    $rows = g4v2FromStream($id, $strategy, $policy === 'none' ? 'fresh' : $policy, $policy !== 'none');
                    [$cell, $gate, $host] = g6Cell($arm);
                    $r = g6Replay($cell, $gate, $host, $rows, $flow, $spec);
                    g4Emit('streams', ['stream' => $id, 'cls' => g1Streams()[$id]['cls'], 'strategy' => $strategy, 'ids' => $policy,
                        'arm' => $arm, 'flow' => $flow] + g4Row($rows, $r) + ['uaVarying' => g5UaVarying($rows), 'matVarying' => g5v2MatVarying($cell, $rows)]
                        + ($spec['uk'] ? ['routedUk' => $r['routedUk']] : []));
                }
            }
        }
    }
    g5v2NpEmit($arm, 'STREAMS');
}
