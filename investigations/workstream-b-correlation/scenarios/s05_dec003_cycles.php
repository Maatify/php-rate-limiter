<?php

declare(strict_types=1);

// B-F04 — DEC-003 measured, not inferred: every persisted correlation hard block is routed through
// HardBlockCycleStoreInterface::blockWithCycleTracking(). The harness records each transition.
require dirname(__DIR__) . '/lib/bootstrap.php';

foreach (['login' => 'pre+out', 'api' => 'out'] as $policy => $flow) {
    $lab = new Lab($policy);
    $k2 = $lab->keys($lab->ctx(NAT, CHROME, null, fp('A')))['K2'];
    // Two independent legitimate clients behind one NAT alternate every 30 s for 2 hours.
    for ($t = 0, $n = 0; $t <= 7200; $t += 30, $n++) {
        $cl = $n % 2 === 1 ? 'B' : 'A';
        $lab->at($t);
        $lab->attempt($lab->ctx(NAT, CHROME, $policy === 'api' ? null : "acct$cl", fp($cl)), $flow);
    }
    $cycles = array_values(array_filter($lab->store->cycleLog, static fn(array $e): bool => $e['key'] === $k2));
    $new = array_values(array_filter($cycles, static fn(array $e): bool => $e['result']->newCycle));
    $paused = array_values(array_filter($cycles, static fn(array $e): bool => $e['result']->pauseActivated));
    $state = $lab->store->readDecayPauseState($k2, null, $lab->t0, $lab->t0 + 7200);
    emit('F04.dec003.' . $policy, [
        'hardBlockWritesOnK2' => count($cycles),
        'newCyclesRecorded' => count($new),
        'maxCycleCountSeen' => $cycles === [] ? 0 : max(array_map(static fn(array $e): int => $e['result']->cycleCount, $cycles)),
        'pauseActivations' => count($paused),
        'elapsedPausedSecondsOver7200s' => $state->elapsedPausedSeconds,
        'activePauseUntilOffset' => $state->activePauseUntil === 0 ? 0 : $state->activePauseUntil - $lab->t0,
        'otherKeysWithCycles' => count(array_unique(array_filter(array_column($lab->store->cycleLog, 'key'), static fn(string $k): bool => $k !== $k2))),
    ]);
}

// Controlled counter-example: a single genuine pair, then silence → exactly one cycle, no pause.
$lab = new Lab('login');
$k2 = $lab->keys($lab->ctx(NAT, CHROME, null, fp('A')))['K2'];
$lab->at(0);
$lab->attempt($lab->ctx(NAT, CHROME, 'acctA', fp('A')));
$lab->at(10);
$lab->attempt($lab->ctx(NAT, CHROME, 'acctB', fp('B')));
emit('F04.dec003.single-pair', ['cyclesOnK2' => count(array_filter($lab->store->cycleLog, static fn(array $e): bool => $e['key'] === $k2))]);
