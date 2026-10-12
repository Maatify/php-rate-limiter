<?php

declare(strict_types=1);

// B-F05 — repeatable attack-vs-legitimate policy matrix (offline model; S0 validated against the real pipeline).
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/fixtures/streams.php';
require __DIR__ . '/PolicyModel.php';

foreach (evidenceStreams() as $name => $events) {
    // [label => [enforcement, observation mechanism]]. Alternatives use M2 (distinct-arrival WATCH) so they do not depend
    // on checkOnly being called; P0 is the earlier unpublished variant (observe only at checkOnly) kept for provenance.
    $policies = ['S0' => ['S0', 'every'], 'A1' => ['A1', 'distinct'], 'A2' => ['A2', 'distinct'], 'P' => ['P', 'distinct'], 'PW' => ['PW', 'distinct'], 'P2' => ['P2', 'distinct'], 'P0' => ['P', 'pre']];
    foreach ($policies as $label => [$enforce, $observe]) {
        emit('F05.matrix', ['stream' => $name, 'policy' => $label, 'flow' => 'pre+out'] + modelSummary(modelRun($enforce, $observe, $events, 'pre+out')));
    }
    foreach (['S0', 'PW', 'P0'] as $label) {
        [$enforce, $observe] = $policies[$label];
        emit('F05.matrix', ['stream' => $name, 'policy' => $label, 'flow' => 'recordOnly'] + modelSummary(modelRun($enforce, $observe, $events, 'out')));
    }
}
