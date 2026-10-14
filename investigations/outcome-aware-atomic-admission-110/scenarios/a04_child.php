<?php

declare(strict_types=1);

// Child process of a04_failure_rollback_bounds.php: replays a slice of events on the runtime selected by LAB_ROOT / OAAA_ARM (provenance
// enforced) over the reference-store STATE handed over in a file, then writes the state back. This is how the REAL unpatched S0 code runs on
// state written by the candidate (rollback) and how old and new code alternate on one state (mixed version): two different
// EvaluationPipeline classes cannot be loaded in one PHP process.
// The state is [clock, rate-limit store, S0-family correlation sets, S0-family WATCH flags, OAAA state]. An S0 child rebuilds a plain K1Store
// from the S0-family arrays and carries the OAAA state through UNTOUCHED (the reference-store analogue of S0 code ignoring OAAA keys on a
// shared Redis); an OAAA child rebuilds an OaaaStore and re-imports it.
//   php a04_child.php <state-file> <events-json-file> <flow> <rows-out-file>
// An exception of the runtime is reported as {"error": "<class>: <message>"} in the rows file (exit 0); any harness problem exits 2.
require dirname(__DIR__) . '/lib/oaaa.php';

use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;

[, $stateFile, $eventsFile, $flow, $rowsFile] = $argv + array_fill(0, 5, null);
if (! is_string($stateFile) || ! is_string($eventsFile) || ! is_string($flow) || ! is_string($rowsFile)) {
    fwrite(STDERR, "usage: a04_child.php <state-file> <events-json-file> <flow> <rows-out-file>\n");
    exit(2);
}
$prov = oaaaProvenance();
$ref = new \ReflectionClass(StatefulInMemoryCorrelationStore::class);
$lab = oaaaLab();
$carried = null;
if (is_file($stateFile) && filesize($stateFile) > 0) {
    [$clock, $store, $sets, $flags, $oaaa] = unserialize((string) file_get_contents($stateFile));
    $lab->clock = $clock;
    $lab->store = $store;
    $lab->corr = oaaaNewStore($clock);
    $ref->getProperty('sets')->setValue($lab->corr, $sets);
    $ref->getProperty('flags')->setValue($lab->corr, $flags);
    if ($oaaa !== null && $lab->corr instanceof OaaaStore) {
        $lab->corr->importOaaa($oaaa);
    }
    $carried = $oaaa;
    gnhRewire($lab);
}
$events = json_decode((string) file_get_contents($eventsFile), true, 512, JSON_THROW_ON_ERROR);
try {
    $rows = oaaaReplay($lab, $events, $flow, 'login');
    $out = ['arm' => $prov['arm'], 'pipelineSha256' => $prov['pipelineSha256'], 'rows' => array_map(static fn(array $r): array => ['t' => $r['t'], 'kind' => $r['kind'], 'blocked' => $r['blocked'], 'reached' => $r['reached'], 'src' => $r['src'], 'granted' => $r['granted']], $rows), 'error' => null];
} catch (\Throwable $e) {
    $out = ['arm' => $prov['arm'], 'pipelineSha256' => $prov['pipelineSha256'], 'rows' => [], 'error' => get_class($e) . ': ' . $e->getMessage()];
}
$oaaaState = $lab->corr instanceof OaaaStore ? $lab->corr->exportOaaa() : $carried;
file_put_contents($stateFile, serialize([$lab->clock, $lab->store, $ref->getProperty('sets')->getValue($lab->corr), $ref->getProperty('flags')->getValue($lab->corr), $oaaaState]));
file_put_contents($rowsFile, json_encode($out, JSON_THROW_ON_ERROR));
