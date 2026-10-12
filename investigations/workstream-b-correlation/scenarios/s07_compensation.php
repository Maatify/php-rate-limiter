<?php

declare(strict_types=1);

// B-F05 — does anything else compensate if correlation enforcement were weakened? MEASURED on the real pipeline by
// replacing the correlation store with the package's NullCorrelationStore (correlation observes nothing).
// Real = unchanged correlation. Compared per policy and WITH / WITHOUT account identity.
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/replay.php';
require dirname(__DIR__) . '/fixtures/streams.php';

$streams = evidenceStreams();
$cases = [
    ['login+account', 'login', false],
    ['login anonymous (no account id)', 'login', true],
    ['api_overuse (no account identity)', 'api', true],
];
foreach ($streams as $name => $events) {
    foreach ($cases as [$label, $policy, $anon]) {
        $ev = $anon ? array_map(static fn(array $e): array => ['acct' => null] + $e, $events) : $events;
        $row = ['stream' => $name, 'policy' => $label];
        foreach (['correlationActive' => false, 'correlationDisabled' => true] as $mode => $disabled) {
            $lab = new Lab($policy, null, $disabled);
            $flow = $policy === 'api' ? 'out' : 'pre+out';
            $row[$mode] = summarize(replayStream($lab, $ev, $flow));
        }
        emit('F05.compensation', $row);
    }
}
