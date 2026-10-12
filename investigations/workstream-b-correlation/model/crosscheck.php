<?php

declare(strict_types=1);

// Compares model S0 with the REAL pipeline on every fixture stream, per event and per step:
//   - decision of each command step (blocked / allowed), block level and retry-after (compared separately),
//   - reachedVerification,
//   - persisted K2 and K3 block remaining seconds for the event's keys (scope + TTL),
//   - total persisted hard-block issuances (= DEC-003 cycle count, read from the real HardBlockCycleStoreInterface log).
// NOT compared (opaque in the real stores / outside this model): WATCH counters, distinct-set contents, scores, K1/K4/K5 state.
// Therefore this establishes S0 equivalence for those observables on these fixtures ONLY; it says nothing about A1/A2/P/PW/P2/P0.
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/replay.php';
require dirname(__DIR__) . '/fixtures/streams.php';
require __DIR__ . '/PolicyModel.php';

foreach (['pre+out' => 'pre+out', 'out' => 'record-only', 'pre' => 'checkOnly-only'] as $flow => $flowLabel) {
    foreach (evidenceStreams() as $name => $events) {
        $lab = new Lab('login');
        $real = replayStream($lab, array_map(static fn(array $e): array => ['acct' => null] + $e, $events), $flow);
        $mr = modelReplay('S0', 'every', $events, $flow);
        $model = $mr['results'];
        $mis = ['blocked' => 0, 'reached' => 0, 'stepDecision' => 0, 'stepLevel' => 0, 'stepRetry' => 0, 'k2Remaining' => 0, 'k3Remaining' => 0];
        foreach ($real as $i => $r) {
            $m = $model[$i];
            $mis['blocked'] += $r['blocked'] !== $m['blocked'] ? 1 : 0;
            $mis['reached'] += $r['reachedVerification'] !== $m['reachedVerification'] ? 1 : 0;
            $mis['k2Remaining'] += $r['k2'] !== $m['k2'] ? 1 : 0;
            $mis['k3Remaining'] += $r['k3'] !== $m['k3'] ? 1 : 0;
            if (count($r['steps']) !== count($m['steps'])) {
                $mis['stepDecision']++;
                continue;
            }
            foreach ($r['steps'] as $j => $step) {
                $ms = $m['steps'][$j];
                $mis['stepDecision'] += ($step['blocked'] !== $ms['blocked'] || $step['cmd'] !== $ms['cmd']) ? 1 : 0;
                $mis['stepLevel'] += ($step['blocked'] && $step['level'] !== $ms['level']) ? 1 : 0;
                $mis['stepRetry'] += ($step['blocked'] && $step['retry'] !== $ms['retry']) ? 1 : 0;
            }
        }
        $realCycles = count($lab->store->cycleLog);
        emit('crosscheck.S0-vs-real', [
            'flow' => $flowLabel,
            'stream' => $name,
            'events' => count($events),
            'mismatches' => $mis,
            'cyclesReal' => $realCycles,
            'cyclesModel' => $mr['model']->cycles,
            'cycleMismatch' => $realCycles !== $mr['model']->cycles ? 1 : 0,
        ]);
    }
}
