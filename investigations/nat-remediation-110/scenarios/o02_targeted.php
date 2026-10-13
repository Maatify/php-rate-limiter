<?php

declare(strict_types=1);

// O02 — targeted probes of the OQC rule on the loaded arm (controlled clock, Login with account unless stated).
//   A1/A2  the #100 A/B/A pair: innocent colleagues (t=62 probe) and the alternating-fingerprint attacker (+1 per trigger rule)
//   C1/C2  command lifecycle: exactly ONE K1 observation per logical attempt (no double count at the silent->live transition, no drop)
//   M1     the liveness marker is written by the first outcome command only (existence, fixed TTL 600 s, not renewed)
//   P1     success / padding never erases failure evidence (5th distinct failing subject is still denied)
//   T1     the cost of typos on a 3-device NAT in an outcome-live scope (two typos per 600 s window)
//   G1     the WATCH gap after a 700 s silence (#110 G5)
require dirname(__DIR__) . '/lib/oqc.php';

emit('O02.provenance', oqcProvenance());
$arm = oqcArm();
$cand = $arm !== 'S0';
$streams = oqcStreams();

// A1 — innocent A/B/A (ABA_B): the t=62-equivalent probe is the 4th request (A@60) after A@0, B@10, A@20.
$m = oqcMeasure($streams['ABA_B']['events'], 'login', 'pre+out', true);
$first = array_slice($m['rows'], 0, 12);
emit('O02.A1.innocent-aba', ['arm' => $arm, 'legitDenied' => $m['legitDenied'], 'legitAttempts' => $m['legitAttempts'], 'firstSteps' => oqcSteps($first), 'src' => $m['legitDeniedBySource']]);
if ($cand) {
    OqcAsserts::check('O02', 'A1 innocent A/B/A colleagues (successful logins only) are never denied on this arm (pre+out): no stale-history re-block', $m['legitDenied'] === 0, ['denied' => $m['legitDenied']]);
} else {
    OqcAsserts::check('O02', 'A1 S0 denies the innocent A/B/A pair from the 3rd request on and re-blocks after every expiry (118/120)', $m['legitDenied'] === 118 && $m['rows'][2]['blocked'] === true, ['denied' => $m['legitDenied'], 'steps' => oqcSteps($first)]);
}
$mo = oqcMeasure($streams['ABA_B']['events'], 'login', 'out');
$mp = oqcMeasure($streams['ABA_B']['events'], 'login', 'pre');
emit('O02.A1.innocent-aba-flows', ['arm' => $arm, 'pre+out' => $m['legitDenied'], 'out' => $mo['legitDenied'], 'pre' => $mp['legitDenied']]);
OqcAsserts::check('O02', 'A1b checkOnly-only Hosts keep the S0 behaviour on the innocent pair (117/120 denied in flow pre)', $mp['legitDenied'] === 117, ['pre' => $mp['legitDenied']]);

// A2 — the attacker (ABA_A): attempts that reached verification vs the correlation blocks issued (triggers).
$a = oqcMeasure($streams['ABA_A']['events'], 'login', 'pre+out', true);
$timeline = [];
foreach ($a['rows'] as $r) {
    if ($r['reached']) {
        $timeline[] = $r['t'];
    }
}
emit('O02.A2.attacker-aba', ['arm' => $arm, 'reached' => $a['attackReachedVerification'], 'attempts' => $a['attackAttempts'], 'correlationIssuances' => $a['correlationIssuances'], 'issuancesByScope' => $a['blockIssuancesByScope'], 'reachedAt' => $timeline, 'retryAfter' => $a['denialRetryAfter']]);
if ($cand) {
    OqcAsserts::check('O02', 'A2 attacker A/B/A: every verification beyond the first two is paid by one correlation block (reached - 2 <= K1+K2 issuances)', $a['attackReachedVerification'] - 2 <= $a['correlationIssuances'], ['reached' => $a['attackReachedVerification'], 'issuances' => $a['correlationIssuances']]);
    // Bound: 2 verifications before the first block + one per 60 s block cycle + one per 600 s liveness-marker expiry (the first
    // command after the marker expires runs the S0 rule, so the attacker's next failure is observed only after its verification).
    $bound = 2 + intdiv(1200, 60) + intdiv(1200, 600);
    if (getenv('OQC_SELFTEST_BREAK') === '1') {
        $bound = 0; // run.sh self-test only: a violated candidate assertion must be fatal under OQC_STRICT=1
    }
    OqcAsserts::check('O02', 'A2b attacker A/B/A: at most one verification per 60 s block cycle plus one per 600 s marker expiry over 1200 s (<= 24) — the #110 bound made explicit', $a['attackReachedVerification'] <= $bound, ['reached' => $a['attackReachedVerification'], 'bound' => $bound]);
} else {
    OqcAsserts::check('O02', 'A2 S0 attacker A/B/A: 3/120 reach verification (the #100 Lead control, reproduced)', $a['attackReachedVerification'] === 3, ['reached' => $a['attackReachedVerification']]);
}

// C1 — exactly one K1 observation per logical attempt in pre+out: 8 distinct subjects from one IP, failing (spray).
$ev = [];
for ($i = 0; $i < 8; $i++) {
    $ev[] = k1Ev($i * 5, '198.51.100.90', null, "lc$i", 'fail', 'attack');
}
$lab = oqcLab();
$rows = k1Replay($lab, $ev, 'pre+out', 'login');
$ops = array_column($rows, 'sprayOps');
$denied = array_column($rows, 'blocked');
emit('O02.C1.one-observation-per-attempt', ['arm' => $arm, 'sprayOpsPerRequest' => $ops, 'blocked' => $denied, 'steps' => array_column($rows, 'steps')]);
$allowedOps = array_filter($ops, static fn(int $o, int $i): bool => ! $denied[$i], ARRAY_FILTER_USE_BOTH);
OqcAsserts::check('O02', 'C1 every ALLOWED logical attempt makes exactly ONE K1 spray observation in pre+out (no double count at the silent->live transition, no dropped observation)', $allowedOps !== [] && array_unique($allowedOps) === [1], ['ops' => $ops]);
if ($cand) {
    OqcAsserts::check('O02', 'C1b on this arm the 5th distinct failing subject is observed at recordFailure (after its verification) and the 6th is denied at checkOnly: exactly 5 reach verification', count(array_filter($rows, static fn(array $r): bool => $r['reached'])) === 5 && $denied[5] === true, ['blocked' => $denied]);
} else {
    OqcAsserts::check('O02', 'C1b S0 denies the 5th distinct subject at checkOnly: exactly 4 reach verification', count(array_filter($rows, static fn(array $r): bool => $r['reached'])) === 4 && $denied[4] === true, ['blocked' => $denied]);
}
// C2 — record-only: the first failure of a silent scope is not observed (S0 rule), every later failure is (K1 protection gained).
$lab = oqcLab();
$rows = k1Replay($lab, $ev, 'out', 'login');
$ops = array_column($rows, 'sprayOps');
emit('O02.C2.record-only-observation', ['arm' => $arm, 'sprayOpsPerRequest' => $ops, 'blocked' => array_column($rows, 'blocked')]);
if ($cand) {
    OqcAsserts::check('O02', 'C2 record-only Host on this arm: the first failure (silent scope) makes no K1 observation, every following failure makes one, the 6th request (5th observed subject) returns the K1 block it issued and the 7th is denied outright', $ops[0] === 0 && array_unique(array_slice($ops, 1, 5)) === [1] && $rows[5]['blocked'] === true && $rows[6]['blocked'] === true && $ops[6] === 0, ['ops' => $ops]);
} else {
    OqcAsserts::check('O02', 'C2 S0 record-only Host: zero K1 observations, nothing denied (the published record-only blind spot)', array_sum($ops) === 0 && ! in_array(true, array_column($rows, 'blocked'), true), ['ops' => $ops]);
}

// M1 — liveness marker: written by the first OUTCOME command, fixed TTL 600 s from that write, not renewed by later outcomes.
$lab = oqcLab();
$c = k1Ctx(k1Ev(0, '198.51.100.91', 'fpm', 'm1', 'ok', 'legit'), 'login');
$flags = static fn(): int => wsbStoreStats($lab)['flags'];
$lab->at(0);
$lab->run($c, 'check');
$afterCheck = $flags();
$lab->run($c, 'ok');
$afterOutcome = $flags();
$lab->at(500);
$lab->run($c, 'check');
$lab->run($c, 'ok');
$at500 = $flags();
$lab->at(600);
$at600 = $flags();
$lab->at(700);
$lab->run($c, 'check');
$lab->run($c, 'ok');
$at700 = $flags();
$lab->at(1199);
$at1199 = $flags();
$lab->at(1200);
$at1200 = $flags();
$lab->at(1900);
$at1900 = $flags();
emit('O02.M1.marker', ['arm' => $arm, 'flagsAfterCheck' => $afterCheck, 'flagsAfterFirstOutcome' => $afterOutcome, 'flagsAt500' => $at500, 'flagsAt600' => $at600, 'flagsAfterOutcomeAt700' => $at700, 'flagsAt1199' => $at1199, 'flagsAt1200' => $at1200, 'flagsAt1900' => $at1900]);
if ($cand) {
    // Arm OQCW also creates its K1 tally flag (TTL 600) at the first (silent, pre-check) K1 observation.
    $w = $arm === 'OQCW' ? 1 : 0;
    OqcAsserts::check('O02', 'M1 liveness markers: none with checkOnly; exactly 2 window keys (K1 scope + K2 scope) with the FIRST outcome; no new keys for a later outcome in the same 600 s window (t=500); still alive at t=600; an outcome in the next window (t=700) adds 2 keys; the first window\'s keys expire at t=1200 (TTL 1200 = this + previous window), the second\'s at t=1900', $afterCheck === $w && $afterOutcome === 2 + $w && $at500 === 2 + $w && $at600 === 2 && $at700 === 4 && $at1199 === 4 && $at1200 === 2 && $at1900 === 0, ['afterCheck' => $afterCheck, 'afterOutcome' => $afterOutcome, 'at500' => $at500, 'at600' => $at600, 'at700' => $at700, 'at1200' => $at1200, 'at1900' => $at1900, 'tallyFlags' => $w]);
} else {
    OqcAsserts::check('O02', 'M1 S0 writes no WATCH flag for a single successful login', $afterCheck === 0 && $afterOutcome === 0 && $at500 === 0, ['afterOutcome' => $afterOutcome]);
}

// P1 — success never erases failure evidence: 3 failing subjects, then 5 successful logins on the attacker's own account, then 2 more
// failing subjects: the 6th distinct failing subject must be denied on every arm (pre+out).
$ev = [];
for ($i = 0; $i < 3; $i++) {
    $ev[] = k1Ev($i * 5, '198.51.100.92', null, "p$i", 'fail', 'attack');
}
for ($i = 0; $i < 5; $i++) {
    $ev[] = k1Ev(20 + $i * 5, '198.51.100.92', null, 'ownacct', 'ok', 'attack');
}
for ($i = 3; $i < 6; $i++) {
    $ev[] = k1Ev(50 + ($i - 3) * 5, '198.51.100.92', null, "p$i", 'fail', 'attack');
}
$rows = k1Replay(oqcLab(), $ev, 'pre+out', 'login');
$steps = oqcSteps($rows);
emit('O02.P1.success-padding', ['arm' => $arm, 'steps' => $steps, 'sprayCount' => array_column($rows, 'sprayCount')]);
OqcAsserts::check('O02', 'P1 five successful own-account logins between the failures do not erase the K1 evidence: the 6th distinct failing subject (t=60) is denied on this arm', $rows[10]['blocked'] === true, ['steps' => $steps]);

// T1 — typo cost on a 3-device NAT: A/B/C succeed in turn every 10 s for 10 min; C mistypes at t=110 and t=200.
$ev = [];
for ($i = 0; $i <= 60; $i++) {
    $t = $i * 10;
    $u = $i % 3;
    $ev[] = k1Ev($t, NAT, ['A', 'B', 'C'][$u], "col$u", $u === 2 && in_array($i, [11, 20], true) ? 'fail' : 'ok', 'legit', ['who' => "col$u"]);
}
$t1 = oqcMeasure($ev, 'login', 'pre+out', true);
$deniedAt = array_column(array_filter($t1['rows'], static fn(array $r): bool => $r['blocked']), 't');
// Split the denials into the OFFICE collateral (colleagues A and B, who never failed) and colleague C's own account/device penalties
// (K4/K5 score of her two failures — an independent protection, identical on every arm).
$others = array_values(array_column(array_filter($t1['rows'], static fn(array $r): bool => $r['blocked'] && $r['who'] !== 'col2'), 't'));
$own = array_values(array_column(array_filter($t1['rows'], static fn(array $r): bool => $r['blocked'] && $r['who'] === 'col2'), 't'));
emit('O02.T1.typo-cost', ['arm' => $arm, 'legitDenied' => $t1['legitDenied'], 'legitAttempts' => $t1['legitAttempts'], 'deniedAt' => array_values($deniedAt), 'officeCollateralAt' => $others, 'typistOwnDeniedAt' => $own, 'src' => $t1['legitDeniedBySource']]);
if ($cand) {
    OqcAsserts::check('O02', 'T1 on this arm two typos by one colleague within 600 s on a 3-device NAT cost the OTHER colleagues one 60 s K2 churn block (the K2 WATCH-at-2 rule, unchanged): between 1 and 6 collateral denials, all in [200, 260]; the typist\'s own later denials are her K4/K5 score', count($others) >= 1 && count($others) <= 6 && min($others) >= 200 && max($others) <= 260, ['office' => $others, 'own' => $own]);
} else {
    OqcAsserts::check('O02', 'T1 S0 denies the 3-device NAT from the 3rd request on, typos or not', $t1['legitDenied'] >= 50, ['denied' => $t1['legitDenied']]);
}

// G1 — WATCH gap: GAP stream, the requests after the 700 s silence.
$g = oqcMeasure($streams['GAP']['events'], 'login', 'pre+out', true);
$late = array_values(array_filter($g['rows'], static fn(array $r): bool => $r['t'] >= 1000));
emit('O02.G1.watch-gap', ['arm' => $arm, 'legitDenied' => $g['legitDenied'], 'lateSteps' => oqcSteps(array_slice($late, 0, 6)), 'lateDenied' => count(array_filter($late, static fn(array $r): bool => $r['blocked']))]);
if ($cand) {
    OqcAsserts::check('O02', 'G1 after a 700 s silence (marker and set expired, WATCH 1800 s alive) the returning colleagues are NOT re-blocked on this arm: the first request runs the S0 rule (count 1), the scope is live again before the second fingerprint is observed', count(array_filter($late, static fn(array $r): bool => $r['blocked'])) === 0, ['lateSteps' => oqcSteps(array_slice($late, 0, 6))]);
} else {
    OqcAsserts::check('O02', 'G1 S0 re-blocks the returning colleagues at the second fingerprint (stale 1800 s WATCH + fresh 600 s set)', $late[1]['blocked'] === true, ['lateSteps' => oqcSteps(array_slice($late, 0, 6))]);
}
OqcAsserts::finish();
