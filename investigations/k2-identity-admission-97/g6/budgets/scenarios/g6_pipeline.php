<?php

declare(strict_types=1);

// G6 pipeline-level fixtures on the G6UK build (PLAN.md §4.2). (1) V1: G5 v2's 14 fixtures on the U and the P lane, no K4P scope, labelled as the NOUA2 arm
// (the rows must equal the G5 v2 archive). (2) The UK lane (arm UKD): STABLE-600 / 300 / 60 and KDL on lane UK, reading the UK keys.

require dirname(__DIR__) . '/lib/g6.php';

if (g6Provenance() !== 'G6UK') {
    exit(0);
}
foreach (['P', 'U'] as $lane) {
    foreach (g5PipeFixtures() as $fx) {
        g4Emit('pipe', ['arm' => 'NOUA2', 'lane' => $lane, 'fixture' => $fx] + g5Pipe($fx, $lane));
    }
}
foreach (['STABLE-600', 'STABLE-300', 'STABLE-60', 'KDL'] as $fx) {
    g4Emit('pipe', ['arm' => 'UKD', 'lane' => 'UK', 'fixture' => $fx] + g6PipeUk($fx));
}
