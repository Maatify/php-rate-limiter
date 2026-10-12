<?php

declare(strict_types=1);

// B-F05 — attack-vs-legitimate policy matrix. OFFLINE predictions. S0 is the only row validated against the real pipeline
// (crosscheck.php); every other row is an unverified model output. P and P0 use IDEALIZED-UNBOUNDED timestamped witnesses;
// PW and P2 use the bounded window model (cap 3 per scope+window, 10 windows, failing-K1 cap 6) whose peak entry count is reported.
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/fixtures/streams.php';
require __DIR__ . '/PolicyModel.php';

// label => [enforcement, observation mechanism, state model]
$policies = [
    'S0' => ['S0', 'every', 'bounded-store(reference)'],
    'A1' => ['A1', 'distinct', 'bounded-store(reference)'],
    'A2' => ['A2', 'distinct', 'IDEALIZED-UNBOUNDED witnesses'],
    'P' => ['P', 'distinct', 'IDEALIZED-UNBOUNDED witnesses'],
    'PW' => ['PW', 'distinct', 'bounded window model'],
    'P2' => ['P2', 'distinct', 'bounded window model'],
    'P0' => ['P', 'pre', 'IDEALIZED-UNBOUNDED witnesses; observes only at checkOnly'],
];
foreach (evidenceStreams() as $name => $events) {
    foreach ($policies as $label => [$enforce, $observe, $state]) {
        $r = modelReplay($enforce, $observe, $events, 'pre+out');
        emit('F05.matrix', ['stream' => $name, 'policy' => $label, 'flow' => 'pre+out', 'state' => $state, 'peakBoundedStateEntries' => in_array($label, ['PW', 'P2'], true) ? $r['model']->peakBoundedEntries : null] + modelSummary($r['results']));
    }
    foreach (['S0', 'PW', 'P0'] as $label) {
        [$enforce, $observe, $state] = $policies[$label];
        $r = modelReplay($enforce, $observe, $events, 'out');
        emit('F05.matrix', ['stream' => $name, 'policy' => $label, 'flow' => 'recordOnly', 'state' => $state, 'peakBoundedStateEntries' => $label === 'PW' ? $r['model']->peakBoundedEntries : null] + modelSummary($r['results']));
    }
}
