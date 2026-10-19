<?php

declare(strict_types=1);

// G5 pipeline-level fixtures (PLAN.md §4.2) on the loaded build (REF = G4, NOUA = G5, the four ablations), P and U lane.

require dirname(__DIR__) . '/lib/g5.php';

$arm = g5ArmOfBuild(g5Provenance());
foreach (['P', 'U'] as $lane) {
    foreach (g5PipeFixtures() as $fx) {
        g4Emit('pipe', ['arm' => $arm, 'lane' => $lane, 'fixture' => $fx] + g5Pipe($fx, $lane));
    }
}
