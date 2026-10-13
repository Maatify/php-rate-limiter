<?php

declare(strict_types=1);

// F03 — exact time boundaries, recovery and renewal on the loaded runtime (controlled clock; Redis time is NOT claimed here — see F06).
//   R1 lone legitimate client after a 3-fingerprint failing trigger     R2 single seed then silence (history-only re-block)
//   R3 one colleague typo on a stale full set                           R4 history-only re-block over 2000 s (60 s block / 600 s set / 1800 s WATCH)
//   R5 sustained leak over 30 minutes (D7)                              R6 corroboration window is FIXED (0/30/59 vs 0/30/60/61), duplicates never corroborate
//   R7 renewal after expiry: a fresh window must be re-corroborated     R8 N-1 WATCH gaming (Y2a/Y2b): the measured loss
require dirname(__DIR__) . '/lib/fsb.php';

emit('F03.provenance', fsbProvenance());
$isS0 = wsbVariant() === 'S0';
$isPsc = in_array(wsbVariant(), ['PSC', 'PSCA'], true);

/** @param list<array<string, mixed>> $events @return array<string, mixed> */
function f03Run(array $events, string $flow = 'pre+out'): array
{
    $lab = new Lab('login');
    $r = wsbReplay($lab, $events, $flow, 'login-anon');

    return ['rows' => $r['events'], 'issuances' => count($lab->store->cycleLog)];
}

$ev = static fn(int $t, string $fp, string $acct, string $o, string $k = 'attack', string $ip = '198.51.100.88'): array => ['t' => $t, 'ip' => $ip, 'ua' => 'chrome126', 'fp' => $fp, 'acct' => $acct, 'outcome' => $o, 'kind' => $k];
$legitOf = static fn(array $rows): array => array_values(array_filter($rows, static fn(array $x): bool => $x['kind'] === 'legit'));
$lastDenial = static function (array $rows): ?int {
    $last = null;
    foreach ($rows as $x) {
        if ($x['blocked']) {
            $last = $x['t'];
        }
    }

    return $last;
};

// R1 — trigger with 3 failing fingerprints at t=0,1,2, then a lone LEGITIMATE client (own fp, success) probes every second to 720.
$events = [$ev(0, 'f1', 'a1', 'fail'), $ev(1, 'f2', 'a2', 'fail'), $ev(2, 'f3', 'a3', 'fail')];
for ($t = 3; $t <= 720; $t++) {
    $events[] = $ev($t, 'LEG', 'legit', 'ok', 'legit');
}
$r = f03Run($events);
$legit = $legitOf($r['rows']);
$first = null;
foreach ($legit as $x) {
    if (! $x['blocked']) {
        $first = $x['t'];
        break;
    }
}
$atk = array_values(array_filter($r['rows'], static fn(array $x): bool => $x['kind'] === 'attack'));
emit('F03.R1.lone-legit-after-trigger', ['variant' => wsbVariant(), 'attackSteps' => array_map(static fn(array $x): string => $x['steps'], $atk), 'legitRuns' => wsbRuns($legit), 'legitFirstAllowedT' => $first, 'blockIssuances' => $r['issuances']]);
if ($isS0) {
    WsbAsserts::check('F03', 'S0 R1 recovery: a lone legitimate client is first allowed at t=601 (stale 600 s set; block 60 s) — the #104/#105 boundary', $first === 601, ['firstAllowed' => $first]);
}
if ($isPsc) {
    WsbAsserts::check('F03', 'R1 recovery: a lone legitimate client is allowed no later than t=63 (block ends at 62; no history-only re-block)', $first !== null && $first <= 63, ['firstAllowed' => $first]);
    WsbAsserts::check('F03', 'R1 negative control: the trigger is enforced at the third failing member (corroborating evidence exists)', $atk[2]['blocked'] === true, ['steps' => $atk[2]['steps']]);
}

// R2 — single seed then silence: 3 failing fps at t=0..2; a lone legitimate client succeeds every 5 s for 1500 s.
$events = [$ev(0, 'f1', 'a1', 'fail'), $ev(1, 'f2', 'a2', 'fail'), $ev(2, 'f3', 'a3', 'fail')];
for ($t = 5; $t <= 1500; $t += 5) {
    $events[] = $ev($t, 'LEG', 'legit', 'ok', 'legit');
}
$r = f03Run($events);
$legit = $legitOf($r['rows']);
$last = $lastDenial($legit);
emit('F03.R2.single-seed-then-silence', ['variant' => wsbVariant(), 'legitDenied' => count(array_filter($legit, static fn(array $x): bool => $x['blocked'])), 'legitAttempts' => count($legit), 'lastLegitDenialT' => $last, 'legitRuns' => wsbRuns($legit), 'blockIssuances' => $r['issuances']]);
if ($isS0) {
    WsbAsserts::check('F03', 'S0 R2 history-only re-block: one seed burst denies a lone legitimate client until the 600 s set expires (last denial = 600)', $last === 600, ['lastLegitDenialT' => $last]);
}
if ($isPsc) {
    WsbAsserts::check('F03', 'R2 no history-only re-block: after the seeded block expires a lone legitimate client is never denied again (last denial <= 62)', $last !== null && $last <= 62, ['lastLegitDenialT' => $last]);
}

// R3 — shared NAT, stale full set of three SUCCESSFUL colleagues, ONE colleague typo at t=100, then successes every 5 s to 500.
$events = [$ev(0, 'f1', 'a1', 'ok', 'legit'), $ev(1, 'f2', 'a2', 'ok', 'legit'), $ev(2, 'f3', 'a3', 'ok', 'legit'), $ev(100, 'f4', 'a4', 'fail', 'legit')];
for ($t = 105; $t <= 500; $t += 5) {
    $events[] = $ev($t, 'f' . (($t / 5) % 3 + 1), 'a1', 'ok', 'legit');
}
$r = f03Run($events);
$denied = array_values(array_filter($r['rows'], static fn(array $x): bool => $x['blocked']));
emit('F03.R3.single-typo-on-stale-set', ['variant' => wsbVariant(), 'legitDenied' => count($denied), 'attempts' => count($r['rows']), 'firstDeniedT' => $denied[0]['t'] ?? null, 'lastDeniedT' => $lastDenial($r['rows']), 'runs' => wsbRuns($r['rows'])]);
if ($isPsc) {
    WsbAsserts::check('F03', 'R3: one colleague typo never re-arms the NAT cohort from old history (zero denials: a single failing member is not corroboration)', $denied === [], ['denied' => count($denied)]);
}

// R4 — history-only re-block across the 60 s block, 600 s set and 1800 s WATCH horizons: seed at 0..2, lone legitimate success every 10 s to 2000.
$events = [$ev(0, 'f1', 'a1', 'fail'), $ev(1, 'f2', 'a2', 'fail'), $ev(2, 'f3', 'a3', 'fail')];
for ($t = 10; $t <= 2000; $t += 10) {
    $events[] = $ev($t, 'LEG', 'legit', 'ok', 'legit');
}
$r = f03Run($events);
$legit = $legitOf($r['rows']);
emit('F03.R4.history-horizons-2000s', ['variant' => wsbVariant(), 'legitRuns' => wsbRuns($legit), 'lastLegitDenialT' => $lastDenial($legit), 'blockIssuances' => $r['issuances']]);
if ($isPsc) {
    WsbAsserts::check('F03', 'R4: over 2000 s (60 s block, 600 s set, 1800 s WATCH horizons) the lone legitimate client is denied only inside the seeded 60 s block', ($lastDenial($legit) ?? 0) <= 62 && $r['issuances'] === 1, ['last' => $lastDenial($legit), 'issuances' => $r['issuances']]);
}

// R5 — sustained leak over 30 minutes (D7: one K2, fresh fingerprint + account every second).
$r = f03Run(dsgStreams()['D7']['events']);
$reached = count(array_filter($r['rows'], static fn(array $x): bool => $x['reached']));
emit('F03.R5.sustained-leak-30min', ['variant' => wsbVariant(), 'attempts' => count($r['rows']), 'reachedVerification' => $reached, 'blockIssuances' => $r['issuances']]);
if ($isPsc) {
    // Derived, not fitted: each 60 s block cycle needs `cap` (3) corroborating failing members after expiry => <= 3 verifications per cycle.
    $bound = 3 * (1 + (int) ceil(1800 / 61));
    WsbAsserts::check('F03', "R5 sustained leak is bounded: verifications reaching the protected operation <= 3 per block cycle (<= $bound over 30 minutes)", $reached <= $bound, ['reached' => $reached, 'bound' => $bound]);
}

// R6 — the corroboration window is fixed from the first scored write and counts DISTINCT members. Record-only flow, three failing members.
$r6 = [];
foreach (['0/30/59' => [0, 30, 59], '0/30/60' => [0, 30, 60], '0/30/61' => [0, 30, 61]] as $label => $ts) {
    $r = f03Run([$ev($ts[0], 'w1', 'a1', 'fail'), $ev($ts[1], 'w2', 'a2', 'fail'), $ev($ts[2], 'w3', 'a3', 'fail')], 'out');
    $r6[$label] = $r['rows'][2]['blocked'];
}
$dup = f03Run([$ev(0, 'w1', 'a1', 'fail'), $ev(1, 'w1', 'a2', 'fail'), $ev(2, 'w1', 'a3', 'fail'), $ev(3, 'w1', 'a4', 'fail')], 'out');
$r6['same-fingerprint-x4'] = $dup['rows'][3]['blocked'];
emit('F03.R6.window-fixed-distinct', ['variant' => wsbVariant(), 'thirdFailingMemberEnforcedAt' => $r6]);
if ($isPsc) {
    WsbAsserts::check('F03', 'R6 fixed window: three distinct failing members enforce at +59 s and do NOT enforce when the third arrives at +60 / +61 s (the window is not sliding)', $r6['0/30/59'] === true && $r6['0/30/60'] === false && $r6['0/30/61'] === false, ['enforced' => $r6]);
    WsbAsserts::check('F03', 'R6 distinct: four failures of ONE fingerprint never corroborate', $r6['same-fingerprint-x4'] === false, ['enforced' => $r6]);
}

// R7 — renewal after expiry must be re-corroborated: seed (block to 62); at t=100,101,102 three failing members.
$r = f03Run([$ev(0, 'f1', 'a1', 'fail'), $ev(1, 'f2', 'a2', 'fail'), $ev(2, 'f3', 'a3', 'fail'), $ev(100, 'n1', 'b1', 'fail'), $ev(101, 'n2', 'b2', 'fail'), $ev(102, 'n3', 'b3', 'fail'), $ev(103, 'n4', 'b4', 'fail')]);
$steps = array_map(static fn(array $x): string => $x['t'] . ':' . $x['steps'], $r['rows']);
emit('F03.R7.renewal-needs-new-corroboration', ['variant' => wsbVariant(), 'steps' => $steps, 'issuances' => $r['issuances']]);
if ($isPsc) {
    WsbAsserts::check('F03', 'R7 renewal: after the first block expires the next three failing members are admitted until the third re-corroborates (1st and 2nd allowed, 3rd enforced)', ! $r['rows'][3]['blocked'] && ! $r['rows'][4]['blocked'] && $r['rows'][5]['blocked'] && $r['rows'][6]['blocked'], ['steps' => $steps]);
}
if ($isS0) {
    WsbAsserts::check('F03', 'S0 R7: history re-blocks the very first failing member after expiry', $r['rows'][3]['blocked'] === true, ['steps' => $steps]);
}

// R8 — N-1 WATCH gaming (Y2a/Y2b: two distinct fingerprints per cycle). S0 convicts the second cycle through the 1800 s WATCH; the window rule
// needs `cap` distinct failing members in 60 s, so two per cycle never corroborate: a measured, documented loss (not a regression of independent protections).
foreach (['Y2a', 'Y2b'] as $id) {
    $m = fsbMeasure(dsgAllStreams()[$id]['events'], 'login', 'pre+out');
    emit('F03.R8.n1-watch-gaming', ['variant' => wsbVariant(), 'stream' => $id, 'attackAttempts' => $m['attackAttempts'], 'attackReachedVerification' => $m['attackReachedVerification'], 'controlAttackReachedVerification' => $m['controlAttackReachedVerification']]);
}
WsbAsserts::finish();
