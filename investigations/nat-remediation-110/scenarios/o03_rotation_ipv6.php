<?php

declare(strict_types=1);

// O03 — Current/Previous rotation and IPv6 macro scopes on the loaded arm (pre+out, Login with account).
//   R*  three rotation modes applied in the middle of a stream (outer secret + fingerprint bridged / outer secret only, fingerprint
//       not bridged / fingerprint secret only), each against the no-rotation reference on the SAME arm
//   H*  IPv6: a /48 office (6 colleagues in 6 /64), the same office with typos, an attacker rotating /64s of one /48 and /48s of one /40
require dirname(__DIR__) . '/lib/oqc.php';

use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;

emit('O03.provenance', oqcProvenance());
$arm = oqcArm();
$cand = $arm !== 'S0';
$streams = oqcStreams();

/** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} */
$split = static function (array $events, int $at): array {
    $before = array_values(array_filter($events, static fn(array $e): bool => $e['t'] < $at));
    $after = array_values(array_filter($events, static fn(array $e): bool => $e['t'] >= $at));

    return [$before, $after];
};
/** Replays $events with a rotation of the given mode at $at; returns the aggregate of the whole stream. */
$rotate = static function (string $mode, array $events, int $at, string $flow = 'pre+out') use ($split): array {
    [$before, $after] = $split($events, $at);
    switch ($mode) {
        case 'outer+fp':
            $old = oqcLab('login', null, 'old-secret', null, false);
            $r1 = k1Replay($old, $before, $flow, 'login');
            $new = oqcLab('login', 'old-secret', 'new-secret', $old, true);
            $r2 = k1Replay($new, $after, $flow, 'login');
            break;
        case 'outer-only':
            $old = oqcLab('login', null, 'old-secret', null, false);
            $r1 = k1Replay($old, $before, $flow, 'login');
            $new = oqcLab('login', 'old-secret', 'new-secret', $old, false);
            $r2 = k1Replay($new, $after, $flow, 'login');
            break;
        case 'fp-only':
            $old = oqcLab('login', null, Lab::SECRET, null, false);
            $old->resolver = new DeviceIdentityResolver(new FingerprintHasher('fp-old'), null);
            $r1 = k1Replay($old, $before, $flow, 'login');
            $new = oqcLab('login', null, Lab::SECRET, $old, false);
            $new->resolver = new DeviceIdentityResolver(new FingerprintHasher('fp-new'), new FingerprintHasher('fp-old'));
            $r2 = k1Replay($new, $after, $flow, 'login');
            break;
        default:
            throw new RuntimeException($mode);
    }
    $rows = array_merge($r1, $r2);
    $agg = k1Aggregate($rows);
    $agg['correlationIssuances'] = ($agg['blockIssuancesByScope']['K1'] ?? 0) + ($agg['blockIssuancesByScope']['K2'] ?? 0);
    $agg['afterRotationDenied'] = count(array_filter($r2, static fn(array $r): bool => $r['blocked']));
    $agg['afterRotationReached'] = count(array_filter($r2, static fn(array $r): bool => $r['kind'] === 'attack' && $r['reached']));

    return $agg;
};
$ref = static function (array $events, string $flow = 'pre+out'): array {
    $a = oqcMeasure($events, 'login', $flow);

    return ['legitDenied' => $a['legitDenied'], 'attackReachedVerification' => $a['attackReachedVerification'], 'correlationIssuances' => $a['correlationIssuances']];
};

$cases = [
    ['ABA_B', 600, 'benign'],
    ['ABA_B1', 600, 'benign'],
    ['ABA_A', 600, 'attack'],
    ['SP1n', 300, 'attack'],
    ['D2', 1200, 'benign'],
    ['N12', 1200, 'benign'],
];
foreach ($cases as [$id, $at, $class]) {
    $reference = $ref($streams[$id]['events']);
    foreach (['outer+fp', 'outer-only', 'fp-only'] as $mode) {
        $r = $rotate($mode, $streams[$id]['events'], $at);
        emit('O03.R.rotation', ['arm' => $arm, 'stream' => $id, 'mode' => $mode, 'rotationAt' => $at, 'legitDenied' => $r['legitDenied'], 'legitAttempts' => $r['legitAttempts'], 'attackReachedVerification' => $r['attackReachedVerification'], 'attackAttempts' => $r['attackAttempts'], 'correlationIssuances' => $r['correlationIssuances'], 'afterRotationDenied' => $r['afterRotationDenied'], 'afterRotationReached' => $r['afterRotationReached'], 'reference' => $reference]);
        if ($cand) {
            if ($class === 'benign') {
                OqcAsserts::check('O03', "R1 $id/$mode rotation does not re-block the benign stream on this arm: denied with rotation <= reference + 2", $r['legitDenied'] <= $reference['legitDenied'] + 2, ['rot' => $r['legitDenied'], 'ref' => $reference['legitDenied']]);
            } else {
                OqcAsserts::check('O03', "R2 $id/$mode rotation does not open a window for the attacker on this arm: reached with rotation <= reference + 3", $r['attackReachedVerification'] <= $reference['attackReachedVerification'] + 3, ['rot' => $r['attackReachedVerification'], 'ref' => $reference['attackReachedVerification']]);
            }
        } elseif ($class === 'attack') {
            OqcAsserts::check('O03', "R2 S0 $id/$mode rotation: the previous-generation state is read (not reset): reached with rotation <= reference + 3", $r['attackReachedVerification'] <= $reference['attackReachedVerification'] + 3, ['rot' => $r['attackReachedVerification'], 'ref' => $reference['attackReachedVerification']]);
        } else {
            emit('O03.R.rotation-s0-note', ['stream' => $id, 'mode' => $mode, 'note' => 'S0 benign numbers under rotation are reference only']);
        }
    }
}

// IPv6 — macro scopes (/48, /40). Colleagues each in their own /64 with their own browser major (base K1/K2 see one subject / one fingerprint).
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
foreach (['H1 office /48 (6 colleagues, 6 x /64, successes)' => $h1, 'H1f office /48 with 8% typos' => $h1f, 'H2 attacker over 6 /64 of one /48' => $h2, 'H2b attacker over 4 /48 of one /40' => $h2b] as $label => $events) {
    $out = [];
    foreach (['pre+out', 'pre', 'out'] as $flow) {
        $a = oqcMeasure($events, 'login', $flow);
        $out[$flow] = ['legitDenied' => $a['legitDenied'], 'legitAttempts' => $a['legitAttempts'], 'attackReachedVerification' => $a['attackReachedVerification'], 'attackDenied' => $a['attackDenied'], 'attackAttempts' => $a['attackAttempts'], 'correlationIssuances' => $a['correlationIssuances'], 'sprayThresholdRequests' => $a['sprayThresholdRequests'], 'legitDeniedBySource' => $a['legitDeniedBySource']];
    }
    emit('O03.H.ipv6', ['arm' => $arm, 'case' => $label, 'flows' => $out]);
    if (str_starts_with($label, 'H1 ')) {
        OqcAsserts::check('O03', 'H1 ' . ($cand ? 'on this arm the all-success /48 office is never denied (pre+out): no macro spray evidence without failures' : 'S0 denies the all-success /48 office through the IPv6 MACRO spray scope (pre+out)'), $cand ? $out['pre+out']['legitDenied'] === 0 : $out['pre+out']['legitDenied'] > 0, ['denied' => $out['pre+out']['legitDenied']]);
    }
    if (str_starts_with($label, 'H2 ')) {
        OqcAsserts::check('O03', 'H2 the macro /48 spray scope still constrains an attacker rotating /64s on this arm (pre+out): at most 40 of 200 reach verification', $out['pre+out']['attackReachedVerification'] <= 40, ['reached' => $out['pre+out']['attackReachedVerification']]);
    }
    if (str_starts_with($label, 'H2b')) {
        OqcAsserts::check('O03', 'H2b the macro /40 spray scope still constrains an attacker rotating /48s on this arm (pre+out): at most 60 of 200 reach verification', $out['pre+out']['attackReachedVerification'] <= 60, ['reached' => $out['pre+out']['attackReachedVerification']]);
    }
}
OqcAsserts::finish();
