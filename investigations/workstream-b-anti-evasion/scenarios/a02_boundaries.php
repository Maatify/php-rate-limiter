<?php

declare(strict_types=1);

// A02 — exact time boundaries and recovery on the loaded runtime: 60 s block, 600 s correlation set, 1800 s WATCH, first-denial
// boundary, re-issue / recovery. Independent flows use fresh Labs; every probe is a host-realistic pre+out attempt.
require dirname(__DIR__) . '/lib/wsb.php';

emit('A02.provenance', wsbProvenance());

/** @param list<array<string, mixed>> $events @return array<string, mixed> */
function a02Run(array $events, string $flow = 'pre+out'): array
{
    // WSB_SELFTEST_BLOCK_SHIFT=1 (run.sh self-test only): persisted blocks last 1 s longer -> exact boundaries must move.
    $lab = getenv('WSB_SELFTEST_BLOCK_SHIFT') === '1' ? wsbShiftedBlockLab('login') : new Lab('login');
    $r = wsbReplay($lab, $events, $flow, 'login-anon');

    return ['rows' => $r['events'], 'issuances' => count($lab->store->cycleLog), 'lab' => $lab];
}

$ev = static fn(int $t, string $fp, string $acct, string $o, string $k = 'attack', string $ip = '198.51.100.88'): array => ['t' => $t, 'ip' => $ip, 'ua' => 'chrome126', 'fp' => $fp, 'acct' => $acct, 'outcome' => $o, 'kind' => $k];

// B1 — trigger with 3 failing fingerprints at t=0,1,2, then a lone LEGITIMATE client (own fp, success) probes every second.
$events = [$ev(0, 'f1', 'a1', 'fail'), $ev(1, 'f2', 'a2', 'fail'), $ev(2, 'f3', 'a3', 'fail')];
for ($t = 3; $t <= 720; $t++) {
    $events[] = $ev($t, 'LEG', 'legit', 'ok', 'legit');
}
$r = a02Run($events);
$legit = array_values(array_filter($r['rows'], static fn(array $x): bool => $x['kind'] === 'legit'));
$firstAllowed = null;
foreach ($legit as $x) {
    if (! $x['blocked']) {
        $firstAllowed = $x['t'];
        break;
    }
}
$atk = array_values(array_filter($r['rows'], static fn(array $x): bool => $x['kind'] === 'attack'));
$atkSteps = array_map(static fn(array $x): string => $x['steps'], $atk);
emit('A02.B1.lone-legit-after-trigger', [
    'variant' => wsbVariant(),
    'attackSteps' => array_map(static fn(array $x): string => $x['steps'], $atk),
    'legitRuns' => wsbRuns($legit),
    'legitFirstAllowedT' => $firstAllowed,
    'blockIssuances' => $r['issuances'],
]);
if (wsbVariant() === 'S0') {
    WsbAsserts::check('A02', 'S0 exact block boundary: the block issued at t=1 (second member, double-observed by pre+out) has exactly 58 s left at t=3 (persisted K2 expiry = issuance + 60 s)', ($legit[0]['k2'] ?? null) === 58, ['k2RemainingAtT3' => $legit[0]['k2'] ?? null]);
    WsbAsserts::check('A02', 'S0 exact recovery boundary: a lone legitimate client is first allowed at t=601 after a 3-member trigger (stale 600 s set; block 60 s)', $firstAllowed === 601, ['firstAllowed' => $firstAllowed]);
}
WsbAsserts::check('A02', 'B1: the trigger itself is enforced at the third logical member (S0) or suppressed (candidates need 3 FAILING members: here they exist)', $atk[2]['blocked'] === true, ['steps' => $atk[2]['steps']]);

// B2 — correlation-set TTL boundary: f1,f2 at t=0,1 (set created t=0, TTL 600); f3 at 599 vs 600. Run in the two integration flows:
// pre+out double-observes (published finding), record-only observes once per attempt (exact boundary).
foreach (['pre+out', 'out'] as $flow) {
    foreach ([599, 600] as $t3) {
        foreach (['fail', 'ok'] as $o3) {
            $r = a02Run([$ev(0, 'f1', 'a1', 'fail'), $ev(1, 'f2', 'a2', 'fail'), $ev($t3, 'f3', 'a3', $o3)], $flow);
            emit('A02.B2.set-ttl-boundary', ['variant' => wsbVariant(), 'flow' => $flow, 'f3At' => $t3, 'f3Outcome' => $o3, 'f3Blocked' => $r['rows'][2]['blocked'], 'step' => $r['rows'][2]['steps']]);
        }
    }
}

// B3 — WATCH (30 min) boundary: pair (f1,f2) at t=0,1 sets WATCH at t=1 (count 2 = N-1, TTL 1800 from creation => alive through 1800);
// a second pair whose 2nd member lands at 1800 (alive) vs 1801 (expired).
foreach (['pre+out', 'out'] as $flow) {
    foreach ([1800, 1801] as $t2) {
        $r = a02Run([$ev(0, 'f1', 'a1', 'fail'), $ev(1, 'f2', 'a2', 'fail'), $ev($t2 - 1, 'g1', 'a3', 'fail'), $ev($t2, 'g2', 'a4', 'fail')], $flow);
        emit('A02.B3.watch-boundary', ['variant' => wsbVariant(), 'flow' => $flow, 'secondPairEndsAt' => $t2, 'blockedAtSecondPairEnd' => $r['rows'][3]['blocked'], 'step' => $r['rows'][3]['steps']]);
    }
}

// B4 — first-denial / block-expiry boundary and RE-ISSUE: three failing members at 0,1,2 (block issued at 2, expires 62), then
// a failing request every second from 3 to 130 (a persistent attacker; denied requests must not be recorded).
$events = [$ev(0, 'f1', 'a1', 'fail'), $ev(1, 'f2', 'a2', 'fail'), $ev(2, 'f3', 'a3', 'fail')];
for ($t = 3; $t <= 130; $t++) {
    $events[] = $ev($t, 'P' . $t, 'p' . $t, 'fail');
}
$r = a02Run($events);
emit('A02.B4.persistent-attacker-reissue', [
    'variant' => wsbVariant(),
    'attackerRuns' => wsbRuns($r['rows']),
    'blockIssuances' => $r['issuances'],
    'attackerReached' => count(array_filter($r['rows'], static fn(array $x): bool => $x['reached'])),
]);

// B5 — recovery abuse on the real runtime: attacker seeds 3 FAILING fps once at t=0..2, then stops. A lone legitimate client probes
// every 5 s for 1500 s. Count the legit denials and the time of the last one (S0: stale set keeps re-issuing until it expires).
$events = [$ev(0, 'f1', 'a1', 'fail'), $ev(1, 'f2', 'a2', 'fail'), $ev(2, 'f3', 'a3', 'fail')];
for ($t = 5; $t <= 1500; $t += 5) {
    $events[] = $ev($t, 'LEG', 'legit', 'ok', 'legit');
}
$r = a02Run($events);
$legit = array_values(array_filter($r['rows'], static fn(array $x): bool => $x['kind'] === 'legit'));
$denied = array_values(array_filter($legit, static fn(array $x): bool => $x['blocked']));
$last = $denied === [] ? null : $denied[count($denied) - 1]['t'];
emit('A02.B5.single-seed-then-silence', ['variant' => wsbVariant(), 'legitDenied' => count($denied), 'legitAttempts' => count($legit), 'lastLegitDenialT' => $last, 'legitRuns' => wsbRuns($legit), 'blockIssuances' => $r['issuances']]);

if (in_array(wsbVariant(), ['PW', 'P2', 'RL'], true)) {
    WsbAsserts::check('A02', 'B5 freshness latch: after the seeded block expires a lone legitimate client is NOT re-blocked without a fresh failure (last denial <= 62 s)', $last !== null && $last <= 62, ['lastLegitDenialT' => $last]);
}
if (wsbVariant() === 'S0') {
    WsbAsserts::check('A02', 'S0 B5 recovery abuse: one seed burst denies a lone legitimate client until the 600 s set expires (last denial = 600)', $last === 600, ['lastLegitDenialT' => $last]);
}

// B6 — negative control: every probe really reached the pipeline (a denied-by-an-earlier-layer probe would hide a storage/evidence bug).
WsbAsserts::check('A02', 'B6: recording rows were produced for every probe (negative control: probes reached the pipeline)', count($legit) === 300, ['legitProbes' => count($legit)]);
WsbAsserts::finish();
