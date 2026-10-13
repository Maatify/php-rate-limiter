<?php

declare(strict_types=1);

// Child process of g04_bounds_rollback.php: replays a slice of events on the runtime selected by LAB_ROOT / GNH_ARM (provenance enforced)
// over the reference-store STATE handed over in a file (serialized clock + rate-limit store + correlation store), then writes the state
// back. This is how the REAL unpatched S0 code runs on state written by a candidate (rollback) and how old and new code alternate on one
// state (mixed version): two different EvaluationPipeline classes cannot be loaded in one PHP process.
//   php g04_child.php <state-file> <events-json-file> <flow> <rows-out-file>
// An exception of the runtime is reported as {"error": "<class>: <message>"} in the rows file (exit 0); any harness problem exits 2.
require dirname(__DIR__) . '/lib/gnh.php';

[, $stateFile, $eventsFile, $flow, $rowsFile] = $argv + array_fill(0, 5, null);
if (! is_string($stateFile) || ! is_string($eventsFile) || ! is_string($flow) || ! is_string($rowsFile)) {
    fwrite(STDERR, "usage: g04_child.php <state-file> <events-json-file> <flow> <rows-out-file>\n");
    exit(2);
}
$prov = gnhProvenance();
$lab = gnhLab();
if (is_file($stateFile) && filesize($stateFile) > 0) {
    [$clock, $store, $corr] = unserialize((string) file_get_contents($stateFile));
    $lab->clock = $clock;
    $lab->store = $store;
    $lab->corr = $corr;
    gnhRewire($lab);
}
$events = json_decode((string) file_get_contents($eventsFile), true, 512, JSON_THROW_ON_ERROR);
try {
    $rows = gnhReplay($lab, $events, $flow, 'login', false);
    $out = ['arm' => $prov['arm'], 'pipelineSha256' => $prov['pipelineSha256'], 'rows' => array_map(static fn(array $r): array => ['t' => $r['t'], 'kind' => $r['kind'], 'blocked' => $r['blocked'], 'reached' => $r['reached'], 'steps' => $r['steps'], 'src' => $r['src']], $rows), 'error' => null];
} catch (\Throwable $e) {
    $out = ['arm' => $prov['arm'], 'pipelineSha256' => $prov['pipelineSha256'], 'rows' => [], 'error' => get_class($e) . ': ' . $e->getMessage()];
}
file_put_contents($stateFile, serialize([$lab->clock, $lab->store, $lab->corr]));
file_put_contents($rowsFile, json_encode($out, JSON_THROW_ON_ERROR));
