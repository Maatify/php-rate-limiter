<?php

declare(strict_types=1);

// Validates the offline model: S0 (current §5.3) must equal the REAL pipeline event-by-event on every fixture stream.
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/replay.php';
require dirname(__DIR__) . '/fixtures/streams.php';
require __DIR__ . '/PolicyModel.php';

foreach (evidenceStreams() as $name => $events) {
    $real = replayStream(new Lab('login'), array_map(static fn(array $e): array => ['acct' => null] + $e, $events), 'pre+out');
    $model = modelRun('S0', 'every', $events, 'pre+out');
    $mismatch = 0;
    foreach ($real as $i => $r) {
        if ($r['blocked'] !== $model[$i]['blocked']) {
            $mismatch++;
        }
    }
    emit('crosscheck.S0-vs-real', ['stream' => $name, 'events' => count($events), 'mismatches' => $mismatch]);
}
