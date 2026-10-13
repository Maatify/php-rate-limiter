<?php

declare(strict_types=1);

// K05 — IPv6 aggregation, Current/Previous key rotation and the quality of the Host-supplied correlation subject (checkOnly flow).
//   H1/H2  IPv6 /64 hosts of one /48 (legit office vs rotating attacker), /40 variant   -> macro spray scopes act through the /64 K1
//   R1-R4  key rotation: previous + bridge cardinality, no current-only reset, previous block continuity
//   S1-S5  correlation subject: absent / opaque id / constant / per-request unique
require dirname(__DIR__) . '/lib/k1.php';

k1Emit('K05.provenance', k1Provenance());
$v6 = static fn(int $p48, int $p64, int $host = 1): string => sprintf('2001:db8:%x:%x::%x', $p48, $p64, $host);

// H1 — 6 colleagues of one corporate /48, each in its own /64 (typical SLAAC/DHCPv6-PD per host), each a different browser major,
// 1 login / 20 s for 10 minutes, all successful. Base K1 (per /64) sees ONE subject; only the macro /48 spray scope sees all.
$h1 = static function () use ($v6): array {
    $ev = [];
    for ($t = 0, $i = 0; $t <= 600; $t += 20, $i++) {
        $u = $i % 6;
        $ev[] = k1Ev($t, $v6(0xabcd, $u + 1), "stable$u", "col$u", 'ok', 'legit', ['uaRaw' => k1Ua($u), 'who' => "col$u"]);
    }

    return $ev;
};
// H2 — a spray attacker who rotates across 6 /64s of one /48 (200 victims, 1 / 3 s, no fingerprint, own UA).
$h2 = static function () use ($v6): array {
    $ev = [];
    for ($i = 0; $i < 200; $i++) {
        $ev[] = k1Ev($i * 3, $v6(0xabcd, ($i % 6) + 1), null, "v6victim$i", 'fail', 'attack', ['uaRaw' => k1Ua(70)]);
    }

    return $ev;
};
// H2b — the same attacker spread over 4 /48s of one /40 (2 /64s each).
$h2b = static function () use ($v6): array {
    $ev = [];
    for ($i = 0; $i < 200; $i++) {
        $ev[] = k1Ev($i * 3, $v6(0xab00 + ($i % 4), (intdiv($i, 4) % 2) + 1), null, "v6bvictim$i", 'fail', 'attack', ['uaRaw' => k1Ua(70)]);
    }

    return $ev;
};
$agg = [];
foreach (['H1 office /48 (6 colleagues, 6 x /64)' => $h1(), 'H2 attacker over 6 /64 of one /48' => $h2(), 'H2b attacker over 4 /48 of one /40' => $h2b()] as $label => $events) {
    $byArm = [];
    foreach (K1_ARMS as $arm) {
        $lab = k1Lab($arm);
        $rows = k1Replay($lab, $events, 'pre', 'login');
        $byArm[$arm] = k1Aggregate($rows) + ['sprayThresholdRequests' => count(array_filter($rows, static fn(array $r): bool => $r['sprayMet']))];
    }
    $agg[$label] = $byArm;
    k1Emit('K05.H.ipv6', ['case' => $label, 'arms' => $byArm]);
}
$h1a = $agg['H1 office /48 (6 colleagues, 6 x /64)'];
K1Asserts::check('K05', 'H1 benign office whose hosts sit in different /64s of one /48 is denied by the IPv6 MACRO spray scope (base /64 K1 sees 1 subject): arm A denies requests, arm C (K1 spray off) none, and every denial is a K1 block', $h1a['A']['legitDenied'] > 0 && $h1a['C']['legitDenied'] === 0 && array_keys($h1a['A']['legitDeniedBySource']) === ['issued:K1(spray)'], ['A' => $h1a['A']['legitDenied'], 'C' => $h1a['C']['legitDenied'], 'src' => $h1a['A']['legitDeniedBySource']]);
$h2a = $agg['H2 attacker over 6 /64 of one /48'];
// Arms B (K1 spray on, churn/dilution off) vs D (both off) isolate K1: the attacker's single browser major also trips device dilution in A/C.
K1Asserts::check('K05', 'H2 an attacker rotating /64s inside a /48 is stopped by the macro spray scope (arm B: <=10 of 200 reach verification) and unconstrained when K1 spray is also off (arm D 200/200) — a per-/64 K1 alone would be evaded', $h2a['B']['attackReachedVerification'] <= 10 && $h2a['D']['attackReachedVerification'] === 200, ['B' => $h2a['B']['attackReachedVerification'], 'D' => $h2a['D']['attackReachedVerification']]);
$h2ba = $agg['H2b attacker over 4 /48 of one /40'];
K1Asserts::check('K05', 'H2b the /40 macro scope (4/4 active /48s) also constrains the attacker (arm B) versus both off (arm D 200/200)', $h2ba['B']['attackReachedVerification'] <= 20 && $h2ba['D']['attackReachedVerification'] === 200, ['B' => $h2ba['B']['attackReachedVerification'], 'D' => $h2ba['D']['attackReachedVerification']]);

// R — key rotation (Current/Previous). Subjects use their own browser major (K2 inert).
$IP = '203.0.113.99';
$sub = static fn(int $u, int $t, array $x = []): array => k1Ev($t, $IP, "fp$u", "s$u", 'ok', 'legit', $x + ['uaRaw' => k1Ua($u)]);
$rot = static function (string $arm, array $before, array $after, string $flow = 'pre'): array {
    $old = k1Lab($arm, 'login', null, 'old-secret', null, false);
    $r1 = k1Replay($old, $before, $flow, 'login');
    $new = k1Lab($arm, 'login', 'old-secret', 'new-secret', $old, false);
    $r2 = k1Replay($new, $after, $flow, 'login');

    return array_merge($r1, $r2);
};
$fmt = static fn(array $rows): array => array_map(static fn(array $r): string => $r['t'] . ':' . ($r['blocked'] ? 'B' : 'a') . '#' . $r['sprayCount'], $rows);

// R1 — 3 subjects before the rotation, 2 after: the 5th distinct subject is denied (cardinality = previous + bridge).
$rows = $rot('A', [$sub(1, 0), $sub(2, 1), $sub(3, 2)], [$sub(4, 3), $sub(5, 4)]);
k1Emit('K05.R1.split-across-rotation', ['steps' => $fmt($rows)]);
K1Asserts::check('K05', 'R1 3 subjects under the previous secret + 2 under the new one: the 5th distinct subject is denied (the previous set is READ, never reset)', $rows[3]['blocked'] === false && $rows[4]['blocked'] === true && $rows[4]['sprayCount'] === 5, ['steps' => $fmt($rows)]);
// R2 — a subject already in the previous set is not double counted: 4 before, one of them repeats after: still cardinality 4 (WATCH, B2 semantics).
$rows = $rot('A', [$sub(1, 0), $sub(2, 1), $sub(3, 2), $sub(4, 3)], [$sub(2, 4)]);
$noRot = k1Replay(k1Lab('A'), [$sub(1, 0), $sub(2, 1), $sub(3, 2), $sub(4, 3), $sub(2, 4)], 'pre', 'login');
k1Emit('K05.R2.repeat-across-rotation', ['steps' => $fmt($rows), 'noRotationReference' => $fmt($noRot)]);
K1Asserts::check('K05', 'R2 a subject already counted under the previous secret is not added to the bridge: count stays 4 and the outcome equals the no-rotation reference', $rows[4]['sprayCount'] === 4 && $rows[4]['blocked'] === $noRot[4]['blocked'], ['rot' => $fmt($rows), 'ref' => $fmt($noRot)]);
// R3 — attacker tries to use the rotation as a reset: 4 subjects before, 4 NEW subjects after.
$rows = $rot('A', [$sub(1, 0), $sub(2, 1), $sub(3, 2), $sub(4, 3)], [$sub(5, 4), $sub(6, 5), $sub(7, 6), $sub(8, 7)]);
$reached = count(array_filter($rows, static fn(array $r): bool => ! $r['blocked']));
k1Emit('K05.R3.rotation-as-reset', ['steps' => $fmt($rows), 'reachedVerification' => $reached]);
K1Asserts::check('K05', 'R3 rotation is not a reset: of 4+4 distinct subjects only 4 reach verification (a current-only fallback would let 8 through)', $reached === 4, ['steps' => $fmt($rows)]);
// R4 — a K1 block issued before the rotation is still enforced after it, and a TRUSTED session still passes.
$rows = $rot('A', [$sub(1, 0), $sub(2, 1), $sub(3, 2), $sub(4, 3), $sub(5, 4)], [$sub(1, 10), $sub(9, 11, ['trusted' => true, 'sess' => 'sess-9', 'uaRaw' => k1Ua(31)])]);
// (no per-request source label here: the attribution reads canonical CURRENT keys only, so a block on the previous-generation key shows as unpersisted)
k1Emit('K05.R4.block-continuity', ['steps' => $fmt($rows)]);
K1Asserts::check('K05', 'R4 the pre-rotation K1 block is enforced after the rotation for an untrusted member; the trusted session device is allowed (advisory)', $rows[5]['blocked'] === true && $rows[6]['blocked'] === false, ['steps' => $fmt($rows)]);
// R5 — negative control: the same R1 on arm C shows no denial.
$rows = $rot('C', [$sub(1, 0), $sub(2, 1), $sub(3, 2)], [$sub(4, 3), $sub(5, 4), $sub(6, 5)]);
K1Asserts::check('K05', 'R5 CONTROL: R1 on arm C (K1 spray off) is never denied', count(array_filter($rows, static fn(array $r): bool => $r['blocked'])) === 0, ['steps' => $fmt($rows)]);

// S — the correlation subject is Host-supplied: correlationId ?? accountId. 200 attacker victims vs one legit user with 6 typos in 5 minutes.
$atk = static function (callable $mk): array {
    $ev = [];
    for ($i = 0; $i < 200; $i++) {
        $ev[] = $mk($i);
    }

    return $ev;
};
$legit = static function (callable $mk): array {
    $ev = [];
    for ($i = 0; $i < 6; $i++) {
        $ev[] = $mk($i);
    }

    return $ev;
};
$cases = [
    'S1 login without account id and without correlation id' => [$atk(fn(int $i): array => k1Ev($i * 3, '198.51.100.7', null, null, 'fail', 'attack', ['uaRaw' => k1Ua(70)])), $legit(fn(int $i): array => k1Ev($i * 40, '203.0.113.5', 'fpL', null, 'fail', 'legit', ['uaRaw' => k1Ua(71)])), 'login-anon'],
    'S2 login without account id, opaque correlation id per submitted username' => [$atk(fn(int $i): array => k1Ev($i * 3, '198.51.100.7', null, null, 'fail', 'attack', ['uaRaw' => k1Ua(70), 'corr' => "user$i"])), $legit(fn(int $i): array => k1Ev($i * 40, '203.0.113.5', 'fpL', null, 'fail', 'legit', ['uaRaw' => k1Ua(71), 'corr' => 'userL'])), 'login-anon'],
    'S3 correlation id CONSTANT (Host passes a tenant/site id)' => [$atk(fn(int $i): array => k1Ev($i * 3, '198.51.100.7', null, "victim$i", 'fail', 'attack', ['uaRaw' => k1Ua(70), 'corr' => 'tenant-1'])), $legit(fn(int $i): array => k1Ev($i * 40, '203.0.113.5', 'fpL', 'userL', 'fail', 'legit', ['uaRaw' => k1Ua(71), 'corr' => 'tenant-1'])), 'login'],
    'S4 correlation id UNIQUE PER REQUEST (Host passes a request id)' => [$atk(fn(int $i): array => k1Ev($i * 3, '198.51.100.7', null, "victim$i", 'fail', 'attack', ['uaRaw' => k1Ua(70), 'corr' => "req-a$i"])), $legit(fn(int $i): array => k1Ev($i * 40, '203.0.113.5', 'fpL', 'userL', 'fail', 'legit', ['uaRaw' => k1Ua(71), 'corr' => "req-l$i"])), 'login'],
    'S5 correlation id = the targeted account (correct use)' => [$atk(fn(int $i): array => k1Ev($i * 3, '198.51.100.7', null, "victim$i", 'fail', 'attack', ['uaRaw' => k1Ua(70), 'corr' => "victim$i"])), $legit(fn(int $i): array => k1Ev($i * 40, '203.0.113.5', 'fpL', 'userL', 'fail', 'legit', ['uaRaw' => k1Ua(71), 'corr' => 'userL'])), 'login'],
];
$sres = [];
foreach ($cases as $label => [$attack, $legitEv, $shape]) {
    foreach (['A', 'C'] as $arm) {
        $ra = k1Replay(k1Lab($arm), $attack, 'pre', $shape);
        $rl = k1Replay(k1Lab($arm), $legitEv, 'pre', $shape);
        $sres[$label][$arm] = ['attackReachedVerification' => count(array_filter($ra, static fn(array $r): bool => $r['reached'])), 'attackAttempts' => 200, 'legitDenied' => count(array_filter($rl, static fn(array $r): bool => $r['blocked'])), 'legitAttempts' => 6, 'sprayObservations' => array_sum(array_column($ra, 'sprayOps'))];
    }
    k1Emit('K05.S.subject-quality', ['case' => $label, 'arms' => $sres[$label]]);
}
$s = static fn(string $k): array => $sres[array_keys($cases)[$k]];
K1Asserts::check('K05', 'S1 with NO subject the K1 spray rule cannot observe anything: zero observations, all 200 victims reach verification', $s('0')['A']['sprayObservations'] === 0 && $s('0')['A']['attackReachedVerification'] === 200, $s('0')['A']);
K1Asserts::check('K05', 'S2 an opaque correlation id restores the rule (attacker stopped, <=10 of 200)', $s('1')['A']['attackReachedVerification'] <= 10, $s('1')['A']);
K1Asserts::check('K05', 'S3 a constant correlation id makes every request the SAME subject: the attacker is not stopped (200/200) — Host misuse silently disables the rule', $s('2')['A']['attackReachedVerification'] === 200, $s('2')['A']);
K1Asserts::check('K05', 'S4 a per-request-unique correlation id makes every request a new subject: the legitimate user with 6 retries is denied from the 5th request on, and the attacker is stopped', $s('3')['A']['legitDenied'] >= 1 && $s('3')['A']['attackReachedVerification'] <= 10, $s('3')['A']);
K1Asserts::check('K05', 'S5 correct use: the single legitimate user (1 subject, 6 retries) is not denied while the attacker is stopped', $s('4')['A']['legitDenied'] === 0 && $s('4')['A']['attackReachedVerification'] <= 10, $s('4')['A']);
K1Asserts::finish();
