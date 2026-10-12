<?php

declare(strict_types=1);

// D02 — exact time boundaries, recovery and renewal on the loaded runtime (controlled clock; Redis time is NOT claimed here — see D05).
//   B1  lone legitimate client after a 3-fingerprint FAILING trigger           B2  600 s set boundary, success-only vs failing third member
//   B3  1800 s WATCH (N-1) boundary                                          B4  persistent attacker: first denial, re-issue rate
//   B5  single seed then silence (history-only re-block)                      B6  marker TTL boundary (fresh evidence is exactly 60 s)
//   B7  shared NAT: stale full set + ONE colleague typo, then successes only  B8  sustained leak bound over 30 minutes (D7)
require dirname(__DIR__) . '/lib/dsg.php';

emit('D02.provenance', dsgProvenance());

/** @param list<array<string, mixed>> $events @return array<string, mixed> */
function d02Run(array $events, string $flow = 'pre+out'): array
{
    $lab = new Lab('login');
    $r = wsbReplay($lab, $events, $flow, 'login-anon');

    return ['rows' => $r['events'], 'issuances' => count($lab->store->cycleLog), 'lab' => $lab];
}

$isS0 = wsbVariant() === 'S0';
$ev = static fn(int $t, string $fp, string $acct, string $o, string $k = 'attack', string $ip = '198.51.100.88'): array => ['t' => $t, 'ip' => $ip, 'ua' => 'chrome126', 'fp' => $fp, 'acct' => $acct, 'outcome' => $o, 'kind' => $k];

// B1 — trigger with 3 failing fingerprints at t=0,1,2, then a lone LEGITIMATE client (own fp, success) probes every second.
$events = [$ev(0, 'f1', 'a1', 'fail'), $ev(1, 'f2', 'a2', 'fail'), $ev(2, 'f3', 'a3', 'fail')];
for ($t = 3; $t <= 720; $t++) {
    $events[] = $ev($t, 'LEG', 'legit', 'ok', 'legit');
}
$r = d02Run($events);
$legit = array_values(array_filter($r['rows'], static fn(array $x): bool => $x['kind'] === 'legit'));
$firstAllowed = null;
foreach ($legit as $x) {
    if (! $x['blocked']) {
        $firstAllowed = $x['t'];
        break;
    }
}
$atk = array_values(array_filter($r['rows'], static fn(array $x): bool => $x['kind'] === 'attack'));
emit('D02.B1.lone-legit-after-trigger', ['variant' => wsbVariant(), 'attackSteps' => array_map(static fn(array $x): string => $x['steps'], $atk), 'legitRuns' => wsbRuns($legit), 'legitFirstAllowedT' => $firstAllowed, 'blockIssuances' => $r['issuances']]);
if ($isS0) {
    WsbAsserts::check('D02', 'S0 B1 recovery: a lone legitimate client is first allowed at t=601 (stale 600 s set; block 60 s) — the #104 published boundary', $firstAllowed === 601, ['firstAllowed' => $firstAllowed]);
} else {
    WsbAsserts::check('D02', 'B1 recovery: a lone legitimate client is allowed no later than t=63 (block ends at 62; no history-only re-block)', $firstAllowed !== null && $firstAllowed <= 63, ['firstAllowed' => $firstAllowed]);
}
WsbAsserts::check('D02', 'B1 the trigger is enforced at the third failing member (negative control: failing evidence exists)', $atk[2]['blocked'] === true, ['steps' => $atk[2]['steps']]);

// B2 — correlation-set TTL boundary: f1,f2 at t=0,1 (set TTL 600); f3 at 599 vs 600; third member failing vs succeeding; two flows.
foreach (['pre+out', 'out'] as $flow) {
    foreach ([599, 600] as $t3) {
        foreach (['fail', 'ok'] as $o3) {
            $r = d02Run([$ev(0, 'f1', 'a1', 'fail'), $ev(1, 'f2', 'a2', 'fail'), $ev($t3, 'f3', 'a3', $o3)], $flow);
            $blocked = $r['rows'][2]['blocked'];
            emit('D02.B2.set-ttl-boundary', ['variant' => wsbVariant(), 'flow' => $flow, 'f3At' => $t3, 'f3Outcome' => $o3, 'f3Blocked' => $blocked, 'step' => $r['rows'][2]['steps']]);
            if (! $isS0) {
                // f1/f2 failed at t<=1: their evidence marker (60 s) is long gone at 599/600, so even a failing third member must NOT be a
                // history-only enforcement unless it is itself fresh evidence: a failing f3 IS fresh evidence, a succeeding f3 is not.
                $expected = $o3 === 'fail' && $t3 === 599;
                WsbAsserts::check('D02', "B2 $flow f3@{$t3}/{$o3}: enforced only for a FAILING third member inside the 600 s set (set boundary exact)", $blocked === $expected, ['blocked' => $blocked, 'expected' => $expected]);
            }
        }
    }
}

// B3 — WATCH (30 min) boundary: pair (f1,f2) at t=0,1 sets WATCH (count 2 = N-1, TTL 1800 from creation); a second pair whose 2nd member
// lands at 1800 (alive) vs 1801 (expired). All members fail, so the evidence rule is satisfied and the N-1 rule itself is what is measured.
foreach (['pre+out', 'out'] as $flow) {
    foreach ([1800, 1801] as $t2) {
        $r = d02Run([$ev(0, 'f1', 'a1', 'fail'), $ev(1, 'f2', 'a2', 'fail'), $ev($t2 - 1, 'g1', 'a3', 'fail'), $ev($t2, 'g2', 'a4', 'fail')], $flow);
        $blocked = $r['rows'][3]['blocked'];
        emit('D02.B3.watch-boundary', ['variant' => wsbVariant(), 'flow' => $flow, 'secondPairEndsAt' => $t2, 'blockedAtSecondPairEnd' => $blocked, 'step' => $r['rows'][3]['steps']]);
        // Expected values are the S0 behaviour: record-only observes once per attempt (exact 1800 s boundary); pre+out observes every
        // attempt twice, which is the published double-observation effect (both pairs enforced) — a property of the flow, not of a variant.
        $expected = $flow === 'pre+out' ? true : $t2 === 1800;
        WsbAsserts::check('D02', "B3 $flow: the N-1 WATCH boundary equals the S0 behaviour (second pair ending at $t2 " . ($expected ? 'enforced' : 'not enforced') . ')', $blocked === $expected, ['blocked' => $blocked]);
    }
}

// B4 — persistent attacker: three failing members at 0,1,2 (block issued, expires 62), then a failing request every second to 130.
$events = [$ev(0, 'f1', 'a1', 'fail'), $ev(1, 'f2', 'a2', 'fail'), $ev(2, 'f3', 'a3', 'fail')];
for ($t = 3; $t <= 130; $t++) {
    $events[] = $ev($t, 'P' . $t, 'p' . $t, 'fail');
}
$r = d02Run($events);
$reached = count(array_filter($r['rows'], static fn(array $x): bool => $x['reached']));
emit('D02.B4.persistent-attacker-reissue', ['variant' => wsbVariant(), 'attackerRuns' => wsbRuns($r['rows']), 'blockIssuances' => $r['issuances'], 'attackerReached' => $reached]);

// B5 — single seed then silence: 3 failing fps once at t=0..2; a lone legitimate client succeeds every 5 s for 1500 s.
$events = [$ev(0, 'f1', 'a1', 'fail'), $ev(1, 'f2', 'a2', 'fail'), $ev(2, 'f3', 'a3', 'fail')];
for ($t = 5; $t <= 1500; $t += 5) {
    $events[] = $ev($t, 'LEG', 'legit', 'ok', 'legit');
}
$r = d02Run($events);
$legit = array_values(array_filter($r['rows'], static fn(array $x): bool => $x['kind'] === 'legit'));
$denied = array_values(array_filter($legit, static fn(array $x): bool => $x['blocked']));
$last = $denied === [] ? null : $denied[count($denied) - 1]['t'];
$b5Probes = count($legit);
emit('D02.B5.single-seed-then-silence', ['variant' => wsbVariant(), 'legitDenied' => count($denied), 'legitAttempts' => count($legit), 'lastLegitDenialT' => $last, 'legitRuns' => wsbRuns($legit), 'blockIssuances' => $r['issuances']]);
if ($isS0) {
    WsbAsserts::check('D02', 'S0 B5 history-only re-block: one seed burst denies a lone legitimate client until the 600 s set expires (last denial = 600)', $last === 600, ['lastLegitDenialT' => $last]);
} else {
    WsbAsserts::check('D02', 'B5 no history-only re-block: after the seeded block expires a lone legitimate client is never denied again (last denial <= 62)', $last !== null && $last <= 62, ['lastLegitDenialT' => $last]);
}

// B6 — marker TTL boundary. Three SUCCESSFUL fingerprints at t=0..2 fill the churn set (no evidence). A FAILING 4th fingerprint at t=100 is the
// only evidence. A lone legitimate client probes at t=100+k. Evidence is fixed 60 s: the block (60 s) ends exactly when the evidence does.
$events = [$ev(0, 'f1', 'a1', 'ok'), $ev(1, 'f2', 'a2', 'ok'), $ev(2, 'f3', 'a3', 'ok'), $ev(100, 'f4', 'a4', 'fail')];
foreach ([1, 30, 59, 60, 61, 120] as $k) {
    $events[] = $ev(100 + $k, 'LEG', 'legit', 'ok', 'legit');
}
usort($events, static fn(array $a, array $b): int => $a['t'] <=> $b['t']);
$r = d02Run($events);
$probes = [];
foreach ($r['rows'] as $x) {
    if ($x['kind'] === 'legit') {
        $probes[$x['t'] - 100] = $x['blocked'];
    }
}
$failedMember = array_values(array_filter($r['rows'], static fn(array $x): bool => $x['t'] === 100))[0];
emit('D02.B6.evidence-boundary', ['variant' => wsbVariant(), 'failingMemberStep' => $failedMember['steps'], 'probeBlockedAtPlusK' => $probes]);
if ($isS0) {
    WsbAsserts::check('D02', 'S0 B6: the success-only fourth member trigger is enforced by S0 history (every probe up to +120 is denied or the set expired)', $probes[1] === true && $probes[59] === true, ['probes' => $probes]);
} else {
    WsbAsserts::check('D02', 'B6 evidence boundary: the single failing member is enforced (+1,+30,+59 denied) and the fixed 60 s evidence ends together with the 60 s block (+60 allowed, +61 allowed, +120 allowed)', $probes[1] === true && $probes[30] === true && $probes[59] === true && $probes[60] === false && $probes[61] === false && $probes[120] === false, ['probes' => $probes]);
}

// B7 — shared NAT with a stale full set made only of SUCCESSFUL fingerprints, ONE colleague typo at t=100, then successes every 5 s.
$events = [$ev(0, 'f1', 'a1', 'ok', 'legit'), $ev(1, 'f2', 'a2', 'ok', 'legit'), $ev(2, 'f3', 'a3', 'ok', 'legit'), $ev(100, 'f4', 'a4', 'fail', 'legit')];
for ($t = 105; $t <= 500; $t += 5) {
    $events[] = $ev($t, 'f' . (($t / 5) % 3 + 1), 'a1', 'ok', 'legit');
}
$r = d02Run($events);
$denied = array_values(array_filter($r['rows'], static fn(array $x): bool => $x['blocked']));
$last = $denied === [] ? null : $denied[count($denied) - 1]['t'];
emit('D02.B7.single-typo-on-stale-set', ['variant' => wsbVariant(), 'legitDenied' => count($denied), 'attempts' => count($r['rows']), 'firstDeniedT' => $denied[0]['t'] ?? null, 'lastDeniedT' => $last, 'runs' => wsbRuns($r['rows'])]);
if (! $isS0) {
    WsbAsserts::check('D02', 'B7: one colleague typo costs the NAT at most one 60 s block; the following successes are never re-blocked (last denial <= t=160)', $last !== null && $last <= 160, ['lastDeniedT' => $last]);
}

// B8 — sustained leak over 30 minutes (D7 stream, pre+out, login-anon so K4 cannot hide the correlation signal).
$d7 = dsgStreams()['D7']['events'];
$r = d02Run($d7);
$reached = count(array_filter($r['rows'], static fn(array $x): bool => $x['reached']));
emit('D02.B8.sustained-leak-30min', ['variant' => wsbVariant(), 'attempts' => count($r['rows']), 'reachedVerification' => $reached, 'blockIssuances' => $r['issuances']]);
if (! $isS0) {
    // Bound (derived from the design, not fitted): after detection every block cycle admits at most ONE verification (the fresh evidence), so
    // reach <= members needed for the first trigger (3) + one per 60 s cycle. S0 re-blocks from history and admits only the first 2.
    WsbAsserts::check('D02', 'B8 sustained leak is bounded: verifications reaching the protected operation <= 3 + ceil(1800/62) over 30 minutes', $reached <= 3 + (int) ceil(1800 / 62), ['reached' => $reached]);
}

// B9 — negative control: probes really reached the pipeline.
WsbAsserts::check('D02', 'B9: every B5 probe produced a row (negative control: probes reached the pipeline)', $b5Probes === 300, ['probes' => $b5Probes]);
WsbAsserts::finish();
