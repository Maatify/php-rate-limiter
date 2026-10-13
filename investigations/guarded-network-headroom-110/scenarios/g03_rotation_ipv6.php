<?php

declare(strict_types=1);

// G03 — Current/Previous rotation and IPv6 macro scopes on the loaded arm (pre+out unless stated, Login with account).
//   R1/R2  three rotation modes applied in the middle of a stream (outer secret + fingerprint bridged / outer secret only / fingerprint
//          secret only), each against the no-rotation reference on the SAME arm (the PR #111 O03 method)
//   R3     the GNH5 budget across an outer-secret rotation: budget spent under the old secret is read (previous, read-only) after rotation
//   R4     incidental S0 observation recorded only: a single stable device across an outer-key-only rotation
//   H*     IPv6: a /48 office (6 colleagues in 6 /64), the same office with typos, an attacker rotating /64s of one /48 and /48s of one /40
require dirname(__DIR__) . '/lib/gnh.php';

use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;

emit('G03.provenance', gnhProvenance());
$arm = gnhArm();
$cand = $arm !== 'S0';
$streams = gnhStreams();

/** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} */
$split = static function (array $events, int $at): array {
    $before = array_values(array_filter($events, static fn(array $e): bool => $e['t'] < $at));
    $after = array_values(array_filter($events, static fn(array $e): bool => $e['t'] >= $at));

    return [$before, $after];
};
/** Replays $events with a rotation of the given mode at $at; returns [aggregate of the whole stream, rows after rotation]. */
$rotate = static function (string $mode, array $events, int $at, string $flow = 'pre+out') use ($split): array {
    [$before, $after] = $split($events, $at);
    switch ($mode) {
        case 'outer+fp':
            $old = gnhLab('login', null, 'old-secret', null, false);
            $r1 = gnhReplay($old, $before, $flow, 'login', false);
            $new = gnhLab('login', 'old-secret', 'new-secret', $old, true);
            $r2 = gnhReplay($new, $after, $flow, 'login', false);
            break;
        case 'outer-only':
            $old = gnhLab('login', null, 'old-secret', null, false);
            $r1 = gnhReplay($old, $before, $flow, 'login', false);
            $new = gnhLab('login', 'old-secret', 'new-secret', $old, false);
            $r2 = gnhReplay($new, $after, $flow, 'login', false);
            break;
        case 'fp-only':
            $old = gnhLab('login', null, Lab::SECRET, null, false);
            $old->resolver = new DeviceIdentityResolver(new FingerprintHasher('fp-old'), null);
            $r1 = gnhReplay($old, $before, $flow, 'login', false);
            $new = gnhLab('login', null, Lab::SECRET, $old, false);
            $new->resolver = new DeviceIdentityResolver(new FingerprintHasher('fp-new'), new FingerprintHasher('fp-old'));
            $r2 = gnhReplay($new, $after, $flow, 'login', false);
            break;
        default:
            throw new RuntimeException($mode);
    }

    return [gnhAggregate(array_merge($r1, $r2)), $r2];
};
$ref = static function (array $events, string $flow = 'pre+out'): array {
    $a = gnhMeasure($events, 'login', $flow, false, false);

    return ['legitDenied' => $a['legitDenied'], 'attackReachedVerification' => $a['attackReachedVerification'], 'correlationIssuances' => $a['correlationIssuances'], 'headroomAdmissions' => $a['headroomAdmissions']];
};

$cases = [
    ['ABA_B', 600, 'benign'],
    ['D2', 1200, 'benign'],
    ['N12', 1200, 'benign'],
    ['ABA_A', 600, 'attack'],
    ['SP1n', 300, 'attack'],
    ['XN1', 900, 'attack'],
    ['TMSd', 300, 'attack'],
];
foreach ($cases as [$id, $at, $class]) {
    $reference = $ref($streams[$id]['events']);
    foreach (['outer+fp', 'outer-only', 'fp-only'] as $mode) {
        [$r, $after] = $rotate($mode, $streams[$id]['events'], $at);
        emit('G03.R.rotation', ['arm' => $arm, 'stream' => $id, 'mode' => $mode, 'rotationAt' => $at, 'legitDenied' => $r['legitDenied'], 'legitAttempts' => $r['legitAttempts'], 'attackReachedVerification' => $r['attackReachedVerification'], 'attackAttempts' => $r['attackAttempts'], 'correlationIssuances' => $r['correlationIssuances'], 'headroomAdmissions' => $r['headroomAdmissions'], 'afterRotationDenied' => count(array_filter($after, static fn(array $x): bool => $x['blocked'])), 'afterRotationReached' => count(array_filter($after, static fn(array $x): bool => $x['kind'] === 'attack' && $x['reached'])), 'reference' => $reference]);
        if ($class === 'benign') {
            GnhAsserts::check('G03', "R1 $id/$mode rotation does not re-block the benign stream on this arm: denied with rotation <= reference + 2", $r['legitDenied'] <= $reference['legitDenied'] + 2, ['rot' => $r['legitDenied'], 'ref' => $reference['legitDenied']]);
        } else {
            GnhAsserts::check('G03', "R2 $id/$mode rotation does not open a window for the attacker on this arm: reached with rotation <= reference + 3", $r['attackReachedVerification'] <= $reference['attackReachedVerification'] + 3, ['rot' => $r['attackReachedVerification'], 'ref' => $reference['attackReachedVerification']]);
        }
    }
}

// R3 — the K1 budget across an outer-secret rotation (checkOnly only, no fingerprint). Old secret: subjects s0..s4 at t=0..4 (the 5th opens
// the S0 zone) and s0 repeated at t=5..13 (GNH5: 10 headroom admissions in total). Rotation (new secret, old secret as previous) at t=20,
// then s0 repeated every second to t=59. GNH5 must count the old-generation budget: exactly B - 10 = 10 more admissions.
$ev = [];
for ($i = 0; $i < 5; $i++) {
    $ev[] = k1Ev($i, '198.51.100.160', null, "r3s$i", 'fail', 'attack', ['uaRaw' => k1Ua(98)]);
}
for ($t = 5; $t <= 13; $t++) {
    $ev[] = k1Ev($t, '198.51.100.160', null, 'r3s0', 'fail', 'attack', ['uaRaw' => k1Ua(98)]);
}
$afterEv = [];
for ($t = 20; $t <= 59; $t++) {
    $afterEv[] = k1Ev($t, '198.51.100.160', null, 'r3s0', 'fail', 'attack', ['uaRaw' => k1Ua(98)]);
}
$old = gnhLab('login', null, 'old-secret', null, false);
$r1 = gnhReplay($old, $ev, 'pre', 'login', false);
$new = gnhLab('login', 'old-secret', 'new-secret', $old, false);
$r2 = gnhReplay($new, $afterEv, 'pre', 'login', false);
$sprayLog = array_values(array_filter(gnhStore($new)->budgetLog, static fn(array $b): bool => $b[0] === 'spray'));
$r3 = ['beforeAllowed' => count(array_filter($r1, static fn(array $x): bool => ! $x['blocked'])), 'beforeHeadroom' => array_sum(array_column($r1, 'headroom')), 'afterSprayHeadroom' => count(array_filter(array_slice($sprayLog, 10), static fn(array $b): bool => $b[1] <= GNH_BUDGET_PER_RULE['spray'])), 'afterAllowed' => count(array_filter($r2, static fn(array $x): bool => ! $x['blocked'])), 'afterHeadroom' => array_sum(array_column($r2, 'headroom')), 'afterFirstDenyT' => ($d = array_values(array_filter($r2, static fn(array $x): bool => $x['blocked']))) === [] ? null : $d[0]['t']];
emit('G03.R3.budget-rotation', ['arm' => $arm] + $r3);
GnhAsserts::check('G03', 'R3 ' . match ($arm) {
    'S0' => 'S0: the 5th subject is denied before rotation and the previous-generation spray state keeps denying after rotation (0 of 40 allowed after)',
    'TH5' => 'TH5: the known subject is never stopped, before or after rotation (all 40 allowed after)',
    'GNH5' => 'GNH5: 10 headroom admissions before rotation; after rotation the previous-generation budget is read, so exactly 10 more pass and the 11th (t=30) is denied',
}, match ($arm) {
    'S0' => $r3['beforeAllowed'] === 4 && $r3['afterAllowed'] === 0,
    'TH5' => $r3['afterAllowed'] === 40,
    'GNH5' => $r3['beforeHeadroom'] === 10 && $r3['afterSprayHeadroom'] === 10 && $r3['afterAllowed'] === 10 && $r3['afterFirstDenyT'] === 30,
}, $r3);

// R4 — incidental S0 observation (OUT OF SCOPE, recorded only): one device with ONE stable fingerprint, two logins before and four after an
// outer-key-only rotation (previous key secret configured, no previous fingerprint secret: two independent public settings). The rotated
// K2 churn set counts the previous-generation and the current-generation member of the same device as two, so S0 denies the device itself
// on its 2nd request after rotation (N-1 WATCH); GNH5 admits it out of the churn headroom budget.
$r4 = [];
foreach (['client fingerprint' => 'r4dev', 'no client fingerprint (LOW)' => null] as $label => $fp) {
    $mk = static fn(int $t): array => k1Ev($t, '198.51.100.161', $fp, 'r4solo', 'ok', 'legit', ['uaRaw' => k1Ua(99), 'who' => 'solo']);
    $old = gnhLab('login', null, 'old-secret', null, false);
    $a = gnhReplay($old, [$mk(0), $mk(1)], 'pre', 'login', false);
    $new = gnhLab('login', 'old-secret', 'new-secret', $old, false);
    $b = gnhReplay($new, [$mk(20), $mk(21), $mk(22), $mk(23)], 'pre', 'login', false);
    $r4[$label] = array_map(static fn(array $r): string => $r['t'] . ':' . ($r['blocked'] ? 'B(' . $r['src'] . ')' : 'a'), array_merge($a, $b));
}
// control: the same device across an outer rotation WITH the fingerprint generation bridged (previous fingerprint secret configured)
$mk = static fn(int $t): array => k1Ev($t, '198.51.100.162', 'r4dev', 'r4solo', 'ok', 'legit', ['uaRaw' => k1Ua(99), 'who' => 'solo']);
$old = gnhLab('login', null, 'old-secret', null, false);
$a = gnhReplay($old, [$mk(0), $mk(1)], 'pre', 'login', false);
$new = gnhLab('login', 'old-secret', 'new-secret', $old, true);
$b = gnhReplay($new, [$mk(20), $mk(21), $mk(22), $mk(23)], 'pre', 'login', false);
$r4['control: outer + fingerprint bridged'] = array_map(static fn(array $r): string => $r['t'] . ':' . ($r['blocked'] ? 'B(' . $r['src'] . ')' : 'a'), array_merge($a, $b));
emit('G03.R4.outer-only-single-device', ['arm' => $arm, 'probes' => $r4]);
GnhAsserts::check('G03', 'R4 ' . ($arm === 'S0'
    ? 'S0 (incidental, out of scope): after an outer-key-only rotation the single stable device is denied from its 2nd request (K2 churn on its own two generations); with the fingerprint generation bridged it is never denied (control)'
    : "$arm: the single stable device is never denied across the outer-key-only rotation"), $arm === 'S0'
    ? $r4['client fingerprint'][3] === '21:B(issued:K2(churn))' && $r4['no client fingerprint (LOW)'][3] === '21:B(issued:K2(churn))' && ! str_contains(json_encode($r4['control: outer + fingerprint bridged']), ':B')
    : ! str_contains(json_encode($r4), ':B'), $r4);

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
$h = [];
foreach (['H1 office /48 (6 colleagues, 6 x /64, successes)' => $h1, 'H1f office /48 with 8% typos' => $h1f, 'H2 attacker over 6 /64 of one /48' => $h2, 'H2b attacker over 4 /48 of one /40' => $h2b] as $label => $events) {
    $out = [];
    foreach (['pre+out', 'pre', 'out'] as $flow) {
        $a = gnhMeasure($events, 'login', $flow, false, false);
        $out[$flow] = ['legitDenied' => $a['legitDenied'], 'legitAttempts' => $a['legitAttempts'], 'attackReachedVerification' => $a['attackReachedVerification'], 'attackDenied' => $a['attackDenied'], 'attackAttempts' => $a['attackAttempts'], 'correlationIssuances' => $a['correlationIssuances'], 'headroomAdmissions' => $a['headroomAdmissions'], 'legitDeniedBySource' => $a['legitDeniedBySource']];
    }
    emit('G03.H.ipv6', ['arm' => $arm, 'case' => $label, 'flows' => $out]);
    $h[strtok($label, ' ')] = $out['pre+out'];
}
GnhAsserts::check('G03', 'H1 ' . ($cand ? 'the all-success /48 office is denied no more than on S0 (S0: 25 of 31, pre+out)' : 'S0 denies the all-success /48 office 25 of 31 through the IPv6 MACRO spray scope (pre+out)'), $cand ? $h['H1']['legitDenied'] <= 25 : $h['H1']['legitDenied'] === 25, ['denied' => $h['H1']['legitDenied']]);
// The macro spray scopes are additional budget scopes on GNH5: the extra reach over S0 is bounded by B_spray per active scope per window
// (one 600 s window here: the canonical /64 K1 scopes of the attacker plus the /48 or /40 macro scope).
GnhAsserts::check('G03', 'H2 ' . ($cand ? 'the macro /48 spray scope still constrains an attacker rotating /64s: at most 4 + 20 per macro scope per 600 s window reach verification (pre+out)' : 'S0 reference: the macro /48 spray scope constrains an attacker rotating /64s (pre+out)'), $h['H2']['attackReachedVerification'] <= 24, ['reached' => $h['H2']['attackReachedVerification']]);
GnhAsserts::check('G03', 'H2b ' . ($cand ? 'the macro /40 spray scope still constrains an attacker rotating /48s: at most 4 + 20 per macro scope per 600 s window reach verification (pre+out)' : 'S0 reference: the macro /40 spray scope constrains an attacker rotating /48s (pre+out)'), $h['H2b']['attackReachedVerification'] <= 24, ['reached' => $h['H2b']['attackReachedVerification']]);
GnhAsserts::finish();
