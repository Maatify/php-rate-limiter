<?php

declare(strict_types=1);

// G5 DEC-021 reference (PLAN.md §2.1, arm S0): the pipeline-level fixtures on the repository's own unpatched src/ (G1_ARM unset),
// which has no lanes.

require dirname(__DIR__) . '/lib/g5.php';

g5ProvenanceS0();
foreach (g5PipeFixtures() as $fx) {
    g4Emit('pipe', ['arm' => 'S0', 'lane' => null, 'fixture' => $fx] + g5Pipe($fx, null));
}
