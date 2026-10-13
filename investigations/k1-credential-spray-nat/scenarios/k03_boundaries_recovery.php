<?php

declare(strict_types=1);

// K03 — exact K1 boundaries and recovery on the controlled clock (Login, checkOnly = the only spray-observing command).
//   B1 4/5 and 5/5            B2 the 4/5 WATCH counts REQUESTS at cardinality 4 (repeat of a member)   B3 repeats at 3/5 stay silent
//   B4 recovery after a 5/5 block: 60 s block vs the fixed 600 s set; members, new subjects, trusted probe
//   B5 600 s fixed-window boundary (not refreshed by later members)         B6 1800 s WATCH boundary (second 4/5)
//   B7 negative control: arm C (K1 spray off) shows none of the above
// Every subject has its own browser major, so K2 churn / dilution cannot fire: only K1 (and independent score protections) is observed.
require dirname(__DIR__) . '/lib/k1.php';

k1Emit('K03.provenance', k1Provenance());
$IP = '203.0.113.77';
$sub = static fn(int $u, int $t, string $o = 'ok', array $x = []): array => k1Ev($t, $IP, "fp$u", "s$u", $o, 'legit', $x + ['uaRaw' => k1Ua($u)]);
$run = static function (string $arm, array $events, string $flow = 'pre'): array {
    $lab = k1Lab($arm);

    return ['rows' => k1Replay($lab, $events, $flow, 'login'), 'lab' => $lab];
};
$steps = static fn(array $rows): array => array_map(static fn(array $r): string => $r['t'] . ':' . ($r['blocked'] ? 'B(' . $r['retry'] . ')' : 'a') . '#' . $r['sprayCount'], $rows);

// B1 — 5 distinct subjects, then a 6th.
$ev = [];
for ($u = 1; $u <= 6; $u++) {
    $ev[] = $sub($u, $u - 1);
}
$r = $run('A', $ev);
k1Emit('K03.B1.four-five-boundary', ['steps' => $steps($r['rows']), 'src5' => $r['rows'][4]['src'], 'src6' => $r['rows'][5]['src']]);
K1Asserts::check('K03', 'B1 subjects 1-4 are allowed, the 5th distinct subject is denied (HARD_BLOCK L2, Retry-After 60) — 5/5', ! $r['rows'][3]['blocked'] && $r['rows'][4]['blocked'] && $r['rows'][4]['retry'] === 60 && $r['rows'][4]['sprayCount'] === 5, ['steps' => $steps($r['rows'])]);
K1Asserts::check('K03', 'B1 the 5th denial is a K1 block issued by the spray rule', $r['rows'][4]['src'] === 'issued:K1(spray)', ['src' => $r['rows'][4]['src']]);

// B2 — 4 distinct subjects (WATCH armed by the 4th request), then ONE more request: a repeat of an existing member, a legit second login.
foreach (['repeat of the 4th member' => 4, 'repeat of the 1st member' => 1] as $label => $u) {
    $ev = [$sub(1, 0), $sub(2, 1), $sub(3, 2), $sub(4, 3), $sub($u, 4)];
    $r = $run('A', $ev);
    k1Emit('K03.B2.watch-counts-requests', ['case' => $label, 'steps' => $steps($r['rows']), 'src' => $r['rows'][4]['src']]);
    K1Asserts::check('K03', "B2 [$label]: 4 distinct subjects + one repeat request is denied by K1 although no 5th subject appeared (the 4/5 WATCH counts requests at cardinality 4, not distinct arrivals)", $r['rows'][4]['blocked'] && $r['rows'][4]['sprayCount'] === 4 && $r['rows'][3]['blocked'] === false, ['steps' => $steps($r['rows'])]);
}

// B3 — three subjects, many repeats: silent (cardinality 3).
$ev = [];
for ($i = 0; $i < 12; $i++) {
    $ev[] = $sub(1 + $i % 3, $i * 5);
}
$r = $run('A', $ev);
k1Emit('K03.B3.three-subjects-repeats', ['denied' => count(array_filter($r['rows'], static fn(array $x): bool => $x['blocked'])), 'maxSprayCount' => max(array_column($r['rows'], 'sprayCount'))]);
K1Asserts::check('K03', 'B3 three distinct subjects with repeated requests are never denied by K1 (cardinality 3 < 4)', count(array_filter($r['rows'], static fn(array $x): bool => $x['blocked'])) === 0, []);

// B4 — recovery after 5/5: subjects 1..5 at t=0..4 (block issued at t=4, 60 s), then probes every second to t=700. The TRUSTED probe has its
// own browser major (K2 sees one fingerprint) so only K1 acts on it.
foreach (['member subject s1' => [1, false], 'NEW subject s9' => [9, false], 'member subject s1, TRUSTED session' => [1, true]] as $label => [$u, $tr]) {
    $ev = [];
    for ($i = 1; $i <= 5; $i++) {
        $ev[] = $sub($i, $i - 1, 'ok', ['who' => 'seed']);
    }
    for ($t = 5; $t <= 700; $t++) {
        $ev[] = $sub($u, $t, 'ok', ($tr ? ['trusted' => true, 'sess' => 'sess-s1', 'uaRaw' => k1Ua(30)] : []) + ['who' => 'probe']);
    }
    $r = $run('A', $ev);
    $probe = array_values(array_filter($r['rows'], static fn(array $x): bool => $x['who'] === 'probe'));
    $firstAllowed = null;
    foreach ($probe as $x) {
        if (! $x['blocked']) {
            $firstAllowed = $x['t'];
            break;
        }
    }
    $log = $r['lab']->store->cycleLog;
    $issuedAt = [];
    foreach ($r['rows'] as $x) {
        if ($x['issued'] !== []) {
            $issuedAt[] = $x['t'];
        }
    }
    $lastIssued = $issuedAt === [] ? null : max($issuedAt);
    k1Emit('K03.B4.recovery', ['probe' => $label, 'runs' => k1Runs($probe), 'firstAllowedT' => $firstAllowed, 'blockIssuances' => count($log), 'issuedAt' => $issuedAt]);
    if (! $tr) {
        K1Asserts::check('K03', "B4 [$label] stays denied while the fixed 600 s spray set is alive: K1 L2 blocks (60 s) are re-issued every 60 s (t=4,64,...,544) and the last one outlives the set, so recovery is at 604 — the set, not the 60 s block, defines the denial window", $firstAllowed === 604 && $lastIssued === 544 && count($log) === 10, ['runs' => k1Runs($probe), 'firstAllowed' => $firstAllowed, 'issuedAt' => $issuedAt]);
    } else {
        K1Asserts::check('K03', 'B4 a TRUSTED session probe (own K2) is allowed at every second while the untrusted traffic of the same IP is denied: K1 is advisory for it (spray state is observed, no K1 enforcement)', k1Runs($probe) === 'a5-700', ['runs' => k1Runs($probe)]);
        K1Asserts::check('K03', 'B4 a trusted request that meets the spray threshold still persists the K1 block (it is advisory only for trusted devices, active for untrusted traffic) — block issued at the trusted request t=5', in_array(5, $issuedAt, true), ['issuedAt' => $issuedAt]);
    }
}

// B5 — the 600 s window is fixed (created by the first member, not refreshed): 4 subjects at t=0..3, 5th subject at T.
foreach ([598, 599, 600, 601] as $T) {
    $r = $run('A', [$sub(1, 0), $sub(2, 1), $sub(3, 2), $sub(4, 3), $sub(5, $T)]);
    $blocked = $r['rows'][4]['blocked'];
    k1Emit('K03.B5.fixed-window-boundary', ['fifthAt' => $T, 'blocked' => $blocked]);
    K1Asserts::check('K03', "B5 5th distinct subject at t=$T is " . ($T <= 599 ? 'denied (inside the window)' : 'allowed (window expired at 600)') . ' — fixed, not refreshed', $blocked === ($T <= 599), ['blocked' => $blocked]);
}

// B6 — the 1800 s WATCH (N-1) boundary. Window 1: 4 subjects at t=0..3 (WATCH flag created by the 4th request at t=3, TTL fixed 1800 -> 1803).
// Window 2: 4 NEW subjects at T-3..T (all after the first 600 s set expired). The second 4/5 inside the WATCH TTL is enforced.
$probe6 = [];
foreach ([1801, 1802, 1803, 1804] as $T) {
    $ev = [$sub(1, 0), $sub(2, 1), $sub(3, 2), $sub(4, 3), $sub(11, $T - 3), $sub(12, $T - 2), $sub(13, $T - 1), $sub(14, $T)];
    $r = $run('A', $ev);
    $blocked = $r['rows'][7]['blocked'];
    $probe6[$T] = $blocked;
    k1Emit('K03.B6.watch-1800-boundary', ['fourthOfSecondWindowAt' => $T, 'blocked' => $blocked, 'src' => $r['rows'][7]['src']]);
}
K1Asserts::check('K03', 'B6 N-1 gaming: a second 4/5 window whose 4th subject arrives inside the 1800 s WATCH TTL (t<=1802) is denied by the spray rule; outside it (t>=1803) it is not — exact fixed-TTL boundary', $probe6[1801] === true && $probe6[1802] === true && $probe6[1803] === false && $probe6[1804] === false, ['probe' => $probe6]);

// B7 — negative control: the same B1 + B2 + B4 sequences on arm C (K1 spray off) must show NO K1 denial. If they did, the control would be broken.
$ev = [];
for ($u = 1; $u <= 6; $u++) {
    $ev[] = $sub($u, $u - 1);
}
$c = $run('C', $ev);
k1Emit('K03.B7.control-arm-C', ['steps' => $steps($c['rows'])]);
K1Asserts::check('K03', 'B7 CONTROL: with K1 spray disabled (arm C) six distinct subjects are all allowed — the 5/5 denial above is the spray rule and nothing else', count(array_filter($c['rows'], static fn(array $x): bool => $x['blocked'])) === 0, []);
$c = $run('C', [$sub(1, 0), $sub(2, 1), $sub(3, 2), $sub(4, 3), $sub(4, 4)]);
K1Asserts::check('K03', 'B7 CONTROL: 4 subjects + a repeat is allowed on arm C (the WATCH denial is K1 spray)', $c['rows'][4]['blocked'] === false, []);

// B8 — the same 5/5 sequence in the OTP policy.
$lab = k1Lab('A', 'otp');
$rows = k1Replay($lab, [$sub(1, 0), $sub(2, 1), $sub(3, 2), $sub(4, 3), $sub(5, 4)], 'pre', 'otp');
k1Emit('K03.B8.otp-five-of-five', ['steps' => $steps($rows)]);
K1Asserts::check('K03', 'B8 OTP applies the same 5/5 K1 rule (5th distinct subject denied)', $rows[4]['blocked'] === true && $rows[3]['blocked'] === false, ['steps' => $steps($rows)]);
K1Asserts::finish();
