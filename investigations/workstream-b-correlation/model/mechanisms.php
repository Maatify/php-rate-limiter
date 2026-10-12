<?php

declare(strict_types=1);

// B-F02 — candidate "once per logical attempt" mechanisms evaluated on the three independent host flows.
// Exact idempotence would need a request identity shared by checkOnly and record*; none exists today, so every
// mechanism below is an approximation whose limits are measured here (and reported as a contract decision).
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/fixtures/streams.php';
require __DIR__ . '/PolicyModel.php';

$streams = evidenceStreams();
$use = [
    'L1 NAT: 2 legit MEDIUM clients, valid logins',
    'L2 LOW population 3 new users/min, valid',
    'X1 attacker: fresh FP per request, same IP/UA, failing',
    'X4 FP-rotating attacker sharing a K2 with 2 legit clients',
];
foreach ($use as $name) {
    foreach (['every' => 'current: every command observes', 'pre' => 'M1 observe only at checkOnly', 'distinct' => 'M2 WATCH counts distinct arrivals only', 'debounce' => 'M3 2 s debounce per (scope,member)'] as $mech => $label) {
        foreach (['pre+out' => 'checkOnly+record', 'out' => 'record-only', 'pre' => 'checkOnly-only'] as $flow => $flowLabel) {
            $res = modelRun('S0', $mech, $streams[$name], $flow);
            emit('F02.mechanism', ['stream' => $name, 'mechanism' => $label, 'flow' => $flowLabel] + modelSummary($res));
        }
    }
}
