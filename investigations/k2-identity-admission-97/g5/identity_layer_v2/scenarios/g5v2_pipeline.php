<?php

declare(strict_types=1);

// G5 v2 pipeline-level fixtures (PLAN.md §4.3) on the loaded G5V2 build (NOUA2 and the four ablations), P and U lane, no K4P scope set.

require dirname(__DIR__) . '/lib/g5v2.php';

$arm = g5v2ArmOfBuild(g5v2Provenance());
foreach (['P', 'U'] as $lane) {
    foreach (g5PipeFixtures() as $fx) {
        g4Emit('pipe', ['arm' => $arm, 'lane' => $lane, 'fixture' => $fx] + g5Pipe($fx, $lane));
    }
}
