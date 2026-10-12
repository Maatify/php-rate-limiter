<?php

declare(strict_types=1);

// A07 — DEC-003 verified by BEHAVIOUR on the loaded runtime (F104-03), not by inspecting the patch text. Assertions are derived from the
// ACTIVE decision (docs/decisions/DEC-003): cycle = transition from no active persisted L2+ block to an active one, cycle window 21600 s
// (inclusive boundary), 2 retained cycles activate a fixed 600 s pause when none is active, a cycle during an active pause is retained without
// extending it, Current/Previous are one logical history, L1 is not a cycle, every persisted L2+ block goes through the cycle capability.
// Intended policy differences (a candidate issuing fewer/more blocks) are separated from contract breakage: the reference model below is
// computed from the CYCLE TIMES THE RUNTIME ACTUALLY ISSUED, so a variant may issue a different number of cycles but never a different
// cycle/pause semantics.
require dirname(__DIR__) . '/lib/wsb.php';

emit('A07.provenance', wsbProvenance());

$ev = static fn(int $t, string $fp, string $acct, string $o = 'fail'): array => ['t' => $t, 'ip' => '198.51.100.88', 'ua' => 'chrome126', 'fp' => $fp, 'acct' => $acct, 'outcome' => $o, 'kind' => 'attack'];

/**
 * @param list<array{t: int, newCycle: bool, count: int, paused: bool, until: int}> $cycles
 * @return array{activations: list<int>, pausedSeconds: int}
 */
function a07Reference(array $cycles, int $from, int $to): array
{
    $retained = [];
    $pauses = [];
    foreach ($cycles as $c) {
        if (! $c['newCycle']) {
            continue;
        }
        $retained[] = $c['t'];
        $retained = array_values(array_filter($retained, static fn(int $t): bool => $t >= $c['t'] - 21600));
        $active = false;
        foreach ($pauses as [$s, $u]) {
            $active = $active || ($c['t'] >= $s && $c['t'] < $u);
        }
        if (! $active && count($retained) >= 2) {
            $pauses[] = [$c['t'], $c['t'] + 600];
        }
    }
    $paused = 0;
    foreach ($pauses as [$s, $u]) {
        $paused += max(0, min($u, $to) - max($s, $from));
    }

    return ['activations' => array_map(static fn(array $p): int => $p[0], $pauses), 'pausedSeconds' => $paused];
}

// WSB_SELFTEST_BLOCK_SHIFT=1 (run.sh self-test only): the pipeline clock is 1 s behind the store clock; the cycle/pause assertions must FAIL.
$a07Lab = static fn(): Lab => getenv('WSB_SELFTEST_BLOCK_SHIFT') === '1' ? wsbShiftedBlockLab('login') : new Lab('login');

// D1 — three triggers (3 FAILING fingerprints each, new account each) at t=0, 700, 1400, record-only (one observation per attempt).
// Each trigger starts after the previous block AND the correlation set have expired, so every trigger is a genuine transition.
$lab = $a07Lab();
$k2 = $lab->keys($lab->ctx('198.51.100.88', CHROME, null, fp('x')))['K2'];
$times = [];
$denied = [];
$log = 0;
foreach ([0, 700, 1400] as $base) {
    foreach ([0, 1, 2] as $i) {
        $lab->at($base + $i);
        $r = $lab->attempt($lab->ctx('198.51.100.88', CHROME, "a{$base}{$i}", fp("f{$base}{$i}")), 'out', 'fail');
        $denied[] = ['t' => $base + $i, 'blocked' => $r['blocked']];
        while ($log < count($lab->store->cycleLog)) {
            $e = $lab->store->cycleLog[$log++];
            $times[] = ['t' => $base + $i, 'newCycle' => $e['result']->newCycle, 'count' => $e['result']->cycleCount, 'paused' => $e['result']->pauseActivated, 'until' => $e['result']->pauseUntil, 'key' => $e['key']];
        }
    }
}
$k2Cycles = array_values(array_filter($times, static fn(array $c): bool => $c['key'] === $k2));
$end = 1500;
$ref = a07Reference($k2Cycles, 0, $end);
$state = $lab->store->readDecayPauseState($k2, null, $lab->t0, $lab->t0 + $end);
$runtimeActivations = array_map(static fn(array $c): int => $c['t'], array_values(array_filter($k2Cycles, static fn(array $c): bool => $c['paused'])));
emit('A07.D1.cycles', ['variant' => wsbVariant(), 'k2CycleTimes' => array_column($k2Cycles, 't'), 'cycleCounts' => array_column($k2Cycles, 'count'), 'pauseActivationsAt' => $runtimeActivations, 'referencePauseActivationsAt' => $ref['activations'], 'elapsedPausedSeconds' => $state->elapsedPausedSeconds, 'referencePausedSeconds' => $ref['pausedSeconds'], 'otherKeysWithCycles' => count(array_unique(array_filter(array_column($times, 'key'), static fn(string $k): bool => $k !== $k2)))]);
WsbAsserts::check('A07', 'D1 behavioural negative control: the runtime issued at least 3 K2 cycles in the scenario (so the checks below are not vacuous)', count($k2Cycles) >= 3, ['cycles' => count($k2Cycles)]);
WsbAsserts::check('A07', 'D1 every issued K2 block is a NEW cycle with a strictly increasing retained count (transition-only, DEC-003 §2)', $k2Cycles !== [] && array_reduce($k2Cycles, static fn(bool $ok, array $c): bool => $ok && $c['newCycle'], true) && array_column($k2Cycles, 'count') === range(1, count($k2Cycles)), ['counts' => array_column($k2Cycles, 'count')]);
WsbAsserts::check('A07', 'D1 pause activations equal the reference derived from the runtime cycle times (2 retained cycles in 21600 s, fixed 600 s, no renewal; §3)', $runtimeActivations === $ref['activations'] && $ref['activations'] !== [], ['runtime' => $runtimeActivations, 'reference' => $ref['activations']]);
WsbAsserts::check('A07', 'D1 elapsed paused decay seconds equal the reference (pause only affects decay, bounded to the window; §3, §5)', $state->elapsedPausedSeconds === $ref['pausedSeconds'], ['runtime' => $state->elapsedPausedSeconds, 'reference' => $ref['pausedSeconds']]);
WsbAsserts::check('A07', 'D1 independent histories per enforcement key (§1): only K2 recorded cycles in this scenario', count(array_unique(array_filter(array_column($times, 'key'), static fn(string $k): bool => $k !== $k2))) === 0, []);

// D2 — active-block ordering and L1/non-cycle: while a K2 block is active every request is denied BEFORE any state mutation:
// no cycle log growth and no correlation/witness store operation (distinct + WATCH counters unchanged).
$lab = $a07Lab();
for ($i = 0; $i < 3; $i++) {
    $lab->at($i);
    $lab->attempt($lab->ctx('198.51.100.88', CHROME, "o{$i}", fp("g{$i}")), 'out', 'fail');
}
$cyclesBefore = count($lab->store->cycleLog);
$mutations = 0;
$deniedProbes = 0;
for ($t = 3; $t <= 40; $t++) {
    $lab->at($t);
    $d0 = $lab->corr->distinctCalls;
    $w0 = $lab->corr->watchCalls;
    $c0 = count($lab->store->cycleLog);
    $r = $lab->run($lab->ctx('198.51.100.88', CHROME, "o{$t}", fp("h{$t}")), $t % 2 === 0 ? 'fail' : 'check');
    $deniedProbes += $r['d'] !== 'A' ? 1 : 0;
    $mutations += ($lab->corr->distinctCalls - $d0) + ($lab->corr->watchCalls - $w0) + (count($lab->store->cycleLog) - $c0);
}
emit('A07.D2.active-block-ordering', ['variant' => wsbVariant(), 'cyclesAtTrigger' => $cyclesBefore, 'probes' => 38, 'denied' => $deniedProbes, 'stateMutationsWhileDenied' => $mutations]);
WsbAsserts::check('A07', 'D2 negative control: the trigger issued a persisted block and every probe during it was denied', $cyclesBefore >= 1 && $deniedProbes === 38, ['cyclesAtTrigger' => $cyclesBefore, 'denied' => $deniedProbes]);
WsbAsserts::check('A07', 'D2 active persisted block short-circuits before ANY correlation/witness/cycle mutation (DEC-003 §2: refresh and reads create no cycle)', $mutations === 0, ['mutations' => $mutations]);

// D3 — Current/Previous continuity: cycle 1 under the OLD secret, cycle 2 after rotation (new secret + previous = old) must be ONE logical
// history: the second cycle reports count 2 and activates the pause. If the variant does not issue the second block at all (its policy
// requires evidence that rotation lost), the continuity claim is UNPROVEN for that variant — never PASS.
$old = new Lab('login', null, false, 'old-secret', null, false);
foreach ([0, 1, 2] as $i) {
    $old->at($i);
    $old->attempt($old->ctx('198.51.100.88', CHROME, "r{$i}", fp("r{$i}")), 'out', 'fail');
}
$firstIssued = count($old->store->cycleLog);
$new = new Lab('login', 'old-secret', false, 'new-secret', $old, false);
foreach ([700, 701, 702] as $i => $t) {
    $new->at($t);
    $new->attempt($new->ctx('198.51.100.88', CHROME, "s{$i}", fp("s{$i}")), 'out', 'fail');
}
$secondEntries = array_slice($old->store->cycleLog, $firstIssued);
$secondIssued = $secondEntries !== [];
$second = $secondIssued ? $secondEntries[0]['result'] : null;
emit('A07.D3.rotation-continuity', ['variant' => wsbVariant(), 'firstCycleIssued' => $firstIssued >= 1, 'secondCycleIssuedAfterRotation' => $secondIssued, 'secondCycleCount' => $second?->cycleCount, 'secondPauseActivated' => $second?->pauseActivated]);
WsbAsserts::check('A07', 'D3 negative control: the first (pre-rotation) cycle was issued', $firstIssued >= 1, ['issued' => $firstIssued]);
if ($secondIssued) {
    WsbAsserts::check('A07', 'D3 Current/Previous are one logical cycle history: the post-rotation cycle is the 2nd retained cycle and activates the pause (§7)', $second->cycleCount === 2 && $second->pauseActivated === true, ['count' => $second->cycleCount, 'pause' => $second->pauseActivated]);
} else {
    // Not asserted as ok:true: continuity could not be exercised. Recorded explicitly so the report maps it to UNPROVEN.
    emit('A07', ['variant' => wsbVariant(), 'assert' => 'D3 continuity NOT EXERCISED: the variant issued no block after rotation (its enforcement evidence did not survive rotation)', 'ok' => true, 'unproven' => true]);
}

// D4 — L1 is not a cycle and non-correlation blocks stay on their own keys: a single failing member never creates a cycle.
$lab = new Lab('login');
$lab->at(0);
$lab->attempt($lab->ctx('198.51.100.88', CHROME, 'one', fp('one')), 'out', 'fail');
WsbAsserts::check('A07', 'D4 a single failing attempt creates no hard-block cycle', count($lab->store->cycleLog) === 0, ['cycles' => count($lab->store->cycleLog)]);

WsbAsserts::finish();
