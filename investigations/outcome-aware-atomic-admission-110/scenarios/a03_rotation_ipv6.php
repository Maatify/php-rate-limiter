<?php

declare(strict_types=1);

// A03 — Current/Previous rotation and IPv6 macro scopes on the loaded arm (Login with account).
//   R1  three rotation modes in the middle of a stream (outer secret + fingerprint bridged / outer secret only / fingerprint secret only),
//       each against the no-rotation reference on the SAME arm (the PR #111 O03 / #115 G03 method), pre+out
//   R2  units across an outer-secret rotation: units spent under the old secret are read (previous generation, read-only) after it — no
//       reset; an old-generation reservation is never refunded into, and never writes, the previous generation; global tiers unchanged
//   R3  credits across rotation: credit earned under the old secret still gates the new generation (read-only)
//   R4  the #115 incidental S0 observation (single stable device across an outer-key-only rotation), recorded on both arms
//   H*  IPv6: a /48 office (6 colleagues in 6 /64), the same with typos, an attacker over 6 /64 of one /48, over 4 /48 of one /40, and an
//       attacker inside the office /48 (co-located on the macro scope)
require dirname(__DIR__) . '/lib/oaaa.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;

emit('A03.provenance', oaaaProvenance());
$arm = oaaaArm();
$cand = $arm === 'OAAA';
$streams = oaaaStreams();

$split = static function (array $events, int $at): array {
    return [array_values(array_filter($events, static fn(array $e): bool => $e['t'] < $at)), array_values(array_filter($events, static fn(array $e): bool => $e['t'] >= $at))];
};
$rotate = static function (string $mode, array $events, int $at, string $flow = 'pre+out') use ($split): array {
    [$before, $after] = $split($events, $at);
    switch ($mode) {
        case 'outer+fp':
            $old = oaaaLab('login', null, 'old-secret', null, false);
            $r1 = oaaaReplay($old, $before, $flow, 'login');
            $new = oaaaLab('login', 'old-secret', 'new-secret', $old, true);
            break;
        case 'outer-only':
            $old = oaaaLab('login', null, 'old-secret', null, false);
            $r1 = oaaaReplay($old, $before, $flow, 'login');
            $new = oaaaLab('login', 'old-secret', 'new-secret', $old, false);
            break;
        case 'fp-only':
            $old = oaaaLab('login', null, Lab::SECRET, null, false);
            $old->resolver = new DeviceIdentityResolver(new FingerprintHasher('fp-old'), null);
            $r1 = oaaaReplay($old, $before, $flow, 'login');
            $new = oaaaLab('login', null, Lab::SECRET, $old, false);
            $new->resolver = new DeviceIdentityResolver(new FingerprintHasher('fp-new'), new FingerprintHasher('fp-old'));
            break;
        default:
            throw new RuntimeException($mode);
    }
    $r2 = oaaaReplay($new, $after, $flow, 'login');

    return [oaaaAggregate(array_merge($r1, $r2)), $r2];
};

// R1
$cases = [['ABA_B', 600, 'benign'], ['D2', 1200, 'benign'], ['Nu12', 1200, 'benign'], ['N12', 1200, 'benign'], ['MIX', 600, 'both'], ['ABA_A', 600, 'attack'], ['SP1n', 300, 'attack'], ['OWN1', 300, 'attack'], ['TMSd', 300, 'attack']];
foreach ($cases as [$id, $at, $class]) {
    $ref = oaaaMeasure($streams[$id]['events'], 'login', 'pre+out');
    foreach (['outer+fp', 'outer-only', 'fp-only'] as $mode) {
        [$r, $after] = $rotate($mode, $streams[$id]['events'], $at);
        emit('A03.R1.rotation', ['arm' => $arm, 'stream' => $id, 'mode' => $mode, 'rotationAt' => $at, 'legitDenied' => $r['legitDenied'], 'legitAttempts' => $r['legitAttempts'], 'attackReachedVerification' => $r['attackReachedVerification'], 'attackAttempts' => $r['attackAttempts'], 'reservationsGranted' => $r['reservationsGranted'], 'reference' => ['legitDenied' => $ref['legitDenied'], 'attackReachedVerification' => $ref['attackReachedVerification'], 'reservationsGranted' => $ref['reservationsGranted']]]);
        // the arm comparison (rotation delta on OAAA vs rotation delta on S0) is gate O5 in report.php; S0 itself moves under some modes (#115 R4)
    }
}

// R2 / R3 — tiers and credits across an outer-secret rotation (fingerprint bridged), K1 spray scope only (LOW subjects, own UA).
$ctx = static fn(string $acct, ?string $fp = null, int $ua = 197): RateLimitContextDTO => new RateLimitContextDTO('198.51.100.170', k1Ua($ua - 100), $acct, $fp === null ? null : fp($fp));
$attempt = static function (Lab $lab, RateLimitContextDTO $c, string $outcome): string {
    $p = $lab->run($c, 'check')['d'] === 'A' ? 'a' : 'B';

    return $p === 'B' ? 'B-' : $p . ($lab->run($c, $outcome)['d'] === 'A' ? 'a' : 'B');
};
$tierKey = static fn(Lab $lab, string $family): string => match ($family) {
    'spray' => 'oaaa:v1:e:spray:credential_spray:' . $lab->keys($ctx('x'))['K1'],
    'global' => 'oaaa:v1:g:e:' . hash('sha256', 'login_protection:prod'),
};
$tier = static function (Lab $lab, string $key): ?array {
    if (! $lab->corr instanceof OaaaStore) {
        return null;
    }
    $t = $lab->corr->tiers[$key] ?? null;

    return $t === null || $t['exp'] <= $lab->clock->now()->getTimestamp() ? null : ['e' => $t['e'], 'u' => $t['u']];
};
$old = oaaaLab('login', null, 'old-secret', null, true);
$seq = '';
for ($i = 0; $i < 5; $i++) {
    $seq .= $attempt($old, $ctx("r2s$i"), 'ok');                     // 5 successes: credits 2, the 5th is a returned reservation
}
$old->at(10);
$seq .= $attempt($old, $ctx('r2v0', 'r2a'), 'fail') . $attempt($old, $ctx('r2v1', 'r2b'), 'fail');   // 2 units spent under the old secret
$oldSpray = $tierKey($old, 'spray');
$oldTierBefore = $tier($old, $oldSpray);
$globalBefore = $tier($old, $tierKey($old, 'global'));
$old->at(20);
$pending = $old->run($ctx('r2p', 'r2p'), 'check')['d'];             // refused: the scope is spent (2/2)
$new = oaaaLab('login', 'old-secret', 'new-secret', $old, true);
$new->at(30);
$after = $attempt($new, $ctx('r2v2', 'r2c'), 'fail');               // new generation: current 0 + previous 2 >= 2 -> refused (no reset)
$globalAfter = $tier($new, $tierKey($new, 'global'));
$new->at(700);                                                       // old epoch (created t=0) expired; old credit windows expired too
$reseat = '';
for ($i = 0; $i < 5; $i++) {
    $reseat .= $attempt($new, $ctx("r2n$i"), 'ok');                  // new-generation credits and a new epoch
}
$late = $attempt($new, $ctx('r2v3', 'r2d'), 'fail');
$r2 = ['before' => $seq, 'refusedBeforeRotation' => $pending, 'afterRotation' => $after, 'oldTierBefore' => $oldTierBefore, 'oldTierAfterRotationAttempt' => $tier($new, $oldSpray) ?? $oldTierBefore, 'globalBefore' => $globalBefore, 'globalAfterRotation' => $globalAfter, 'reseat' => $reseat, 'afterOldEpoch' => $late];
emit('A03.R2.units-across-rotation', ['arm' => $arm] + $r2);
OaaaAsserts::check('A03', 'R2 ' . ($cand ? 'units spent under the old secret are counted after rotation (the new generation is refused while the old epoch lives), the old tier is neither written nor refunded, the global tier is not reset, and a new epoch admits again after the old one expired' : 'S0 reference sequence recorded'), $cand
    ? $seq === str_repeat('aa', 7) && $pending === 'H' && $after === 'B-' && $oldTierBefore === ['e' => $oldTierBefore['e'], 'u' => 2] && $r2['oldTierAfterRotationAttempt'] === $oldTierBefore && $globalAfter === $globalBefore && $late === 'aa'
    : $after === 'B-', $r2);

// R3 — credit earned under the old secret gates the new generation (read-only previous credit sets).
$old = oaaaLab('login', null, 'old-secret', null, true);
$mk = static fn(string $a): RateLimitContextDTO => new RateLimitContextDTO('198.51.100.171', k1Ua(98), $a, null);
$seq = $attempt($old, $mk('r3own0'), 'ok') . $attempt($old, $mk('r3own1'), 'ok');   // 2 credits (and 2 spray subjects) under the old secret
$new = oaaaLab('login', 'old-secret', 'new-secret', $old, true);
$new->at(5);
$seq3 = '';
for ($i = 0; $i < 3; $i++) {
    $seq3 .= $attempt($new, $mk("r3s$i"), 'fail');                   // subjects 3..5 across the rotation (spray set bridged)
}
$probe = $attempt($new, $mk('r3s3'), 'fail') . $attempt($new, $mk('r3s4'), 'fail');
emit('A03.R3.credit-across-rotation', ['arm' => $arm, 'credits' => $seq, 'subjects' => $seq3, 'probe' => $probe]);
OaaaAsserts::check('A03', 'R3 ' . ($cand ? 'credit earned under the old secret (2 accounts) still lends exactly 2 units after the rotation: the 5th and 6th subjects are admitted, the 7th refused' : 'S0: the 5th subject (first after the bridged spray window reaches 5) is denied'), $cand ? $seq3 . $probe === 'aaaaaaaaB-' : str_contains($seq3 . $probe, 'B-'), ['credits' => $seq, 'subjects' => $seq3, 'probe' => $probe]);

// R4 — #115 incidental S0 observation, both arms.
$r4 = [];
foreach (['client fingerprint' => 'r4dev', 'no client fingerprint (LOW)' => null] as $label => $fpv) {
    $mk = static fn(int $t): array => k1Ev($t, '198.51.100.161', $fpv, 'r4solo', 'ok', 'legit', ['uaRaw' => k1Ua(99), 'who' => 'solo']);
    $old = oaaaLab('login', null, 'old-secret', null, false);
    $a = oaaaReplay($old, [$mk(0), $mk(1)], 'pre+out', 'login');
    $new = oaaaLab('login', 'old-secret', 'new-secret', $old, false);
    $b = oaaaReplay($new, [$mk(20), $mk(21), $mk(22), $mk(23)], 'pre+out', 'login');
    $r4[$label] = array_map(static fn(array $r): string => $r['t'] . ':' . ($r['blocked'] ? 'B(' . $r['src'] . ')' : 'a'), array_merge($a, $b));
}
emit('A03.R4.outer-only-single-device', ['arm' => $arm, 'probes' => $r4]);

// IPv6 — macro scopes.
$v6 = static fn(int $p48, int $p64, int $host = 1): string => sprintf('2001:db8:%x:%x::%x', $p48, $p64, $host);
$h1 = [];
$h1f = [];
$rnd = new Lcg(4806);
for ($t = 0, $i = 0; $t <= 600; $t += 20, $i++) {
    $u = $i % 6;
    $h1[] = k1Ev($t, $v6(0xabcd, $u + 1), "stable$u", "col$u", 'ok', 'legit', ['uaRaw' => k1Ua($u), 'who' => "col$u"]);
    $h1f[] = k1Ev($t, $v6(0xabcd, $u + 1), "stable$u", "col$u", $rnd->pct() <= 8 ? 'fail' : 'ok', 'legit', ['uaRaw' => k1Ua($u), 'who' => "col$u"]);
}
$h2 = [];
$h2b = [];
for ($i = 0; $i < 200; $i++) {
    $h2[] = k1Ev($i * 3, $v6(0xabcd, ($i % 6) + 1), null, "v6victim$i", 'fail', 'attack', ['uaRaw' => k1Ua(70)]);
    $h2b[] = k1Ev($i * 3, $v6(0xab00 + ($i % 4), (intdiv($i, 4) % 2) + 1), null, "v6bvictim$i", 'fail', 'attack', ['uaRaw' => k1Ua(70)]);
}
// H3 — the H1 office plus an attacker in a 7th /64 of the SAME /48 (own UA, no fingerprint), 1 / 10 s from t=100: co-located on the macro scope.
$h3 = $h1;
for ($t = 100, $i = 0; $t <= 600; $t += 10, $i++) {
    $h3[] = k1Ev($t, $v6(0xabcd, 7), null, "v6cvictim$i", 'fail', 'attack', ['uaRaw' => k1Ua(71)]);
}
$h3 = k1Sort($h3);
$h = [];
foreach (['H1 office /48 (6 colleagues, 6 x /64, successes)' => $h1, 'H1f office /48 with 8% typos' => $h1f, 'H2 attacker over 6 /64 of one /48' => $h2, 'H2b attacker over 4 /48 of one /40' => $h2b, 'H3 office /48 + attacker in a 7th /64 of the same /48' => $h3] as $label => $events) {
    $out = [];
    foreach (['pre+out', 'pre', 'out'] as $flow) {
        $lab = oaaaLab();
        $rows = oaaaReplay($lab, $events, $flow, 'login');
        $a = oaaaAggregate($rows);
        $macroInReservation = 0;
        if ($lab->corr instanceof OaaaStore) {
            foreach ($lab->corr->oaaaLog as $l) {
                if ($l['op'] === 'reserve' && count(array_filter($l['tiers'], static fn(string $k): bool => str_starts_with($k, 'oaaa:v1:e:spray:') || str_starts_with($k, 'oaaa:v1:e:churn:'))) > 1) {
                    $macroInReservation++;
                }
            }
        }
        $out[$flow] = ['legitDenied' => $a['legitDenied'], 'legitAttempts' => $a['legitAttempts'], 'attackReachedVerification' => $a['attackReachedVerification'], 'attackAttempts' => $a['attackAttempts'], 'reservationsGranted' => $a['reservationsGranted'], 'reservationsRefused' => $a['reservationsRefused'], 'multiScopeReservations' => $macroInReservation, 'legitDeniedBits' => md5($a['legitDeniedBits']), 'attackReachedBits' => md5($a['attackReachedBits'])];
    }
    emit('A03.H.ipv6', ['arm' => $arm, 'case' => $label, 'flows' => $out]);
    $h[strtok($label, ' ')] = $out;
}
OaaaAsserts::check('A03', 'H1 ' . ($cand ? 'the all-success /48 office, stopped in S0 by the MACRO spray scope only, is admitted through credited macro-scope reservations (pre+out)' : 'S0 denies the all-success /48 office 25 of 31 through the IPv6 MACRO spray scope (pre+out)'), $cand ? $h['H1']['pre+out']['legitDenied'] === 0 && $h['H1']['pre+out']['reservationsGranted'] > 0 : $h['H1']['pre+out']['legitDenied'] === 25, ['h1' => $h['H1']['pre+out']]);
OaaaAsserts::check('A03', 'H2 ' . ($cand ? 'an attacker over 6 /64 (and over 4 /48) fires the canonical AND the macro spray scope in the same requests: they are reserved in ONE atomic multi-scope reservation, which (no credit) is refused' : 'S0 reference: 5 of 200 reach verification over 6 /64 and over 4 /48'), $cand ? $h['H2']['pre+out']['multiScopeReservations'] > 0 && $h['H2b']['pre+out']['multiScopeReservations'] > 0 && $h['H2']['pre+out']['reservationsGranted'] === 0 && $h['H2b']['pre+out']['reservationsGranted'] === 0 : $h['H2']['pre+out']['attackReachedVerification'] === 5 && $h['H2b']['pre+out']['attackReachedVerification'] === 5, ['h2' => $h['H2']['pre+out'], 'h2b' => $h['H2b']['pre+out']]);
OaaaAsserts::finish();
