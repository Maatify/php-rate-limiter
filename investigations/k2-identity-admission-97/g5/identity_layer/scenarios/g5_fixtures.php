<?php

declare(strict_types=1);

// G5 assembled fixtures (PLAN.md §4.1) for the arm of the current build: REF on G4, NOUA (and D-IDMINT, BRU24 only) on G5. The G4 v2
// fixtures are ported unchanged (same shapes, same row encoding; the arm label aside) so that V1 can compare REF with the archive;
// the new fixtures UAX-NP, FORGE, MINT, LOST, TOKEN, UAX-ASM and MIXPRES follow.

require dirname(__DIR__) . '/lib/g5fx.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

$build = g5Provenance();
$main = g5ArmOfBuild($build);
if (! in_array($main, ['REF', 'NOUA'], true)) {
    exit(0);
}

$ua = static fn(int $major): string => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/$major.0.0.0 Safari/537.36";
$known = static fn(string $ip, string $acct, string $agent): RateLimitContextDTO => new RateLimitContextDTO($ip, $agent, $acct, null, null, false, [], true, null);

// D-IDMINT: BRU24 and BRU24-HV only (the detector of G2).
if ($main === 'NOUA') {
    foreach (['BRU24' => false, 'BRU24-HV' => true] as $fx => $hv) {
        [$cell, $gate, $host] = g5Cell('D-IDMINT');
        $r = g5Replay($cell, $gate, $host, g4v2Bru($hv), 'pre+out', true);
        g4Emit('fx', ['fixture' => $fx, 'arm' => 'D-IDMINT', 'attackAdmitted' => substr_count($r['reach'], '1')]);
    }
}

$arm = $main;
$idm = $arm !== 'REF';
// HAR-B / HAR-N
foreach (['HAR-B' => true, 'HAR-N' => false] as $fx => $bound) {
    [$cell, $gate, $host] = g5Cell($arm);
    $rows = g5Har($bound);
    $r = g5Replay($cell, $gate, $host, $rows, 'pre+out', $idm);
    g4Emit('fx', ['fixture' => $fx, 'arm' => $arm] + g4Row($rows, $r));
}
// BRU24 and BRU24-HV
foreach (['BRU24' => false, 'BRU24-HV' => true] as $fx => $hv) {
    [$cell, $gate, $host] = g5Cell($arm);
    $r = g5Replay($cell, $gate, $host, g4v2Bru($hv), 'pre+out', $idm);
    $takes = count(array_filter($gate->u->tbLog, static fn(array $e): bool => $e['kind'] === 'take'));
    g4Emit('fx', ['fixture' => $fx, 'arm' => $arm, 'attackAdmitted' => substr_count($r['reach'], '1'), 'tbTakesRecount' => $takes,
        'pLane' => substr_count($r['lanes'], 'P'), 'attackDeniedByCause' => $r['attackDeniedByCause']]);
}
// REC
$waits = [];
foreach ([0, 60, 300, 900, 1800, 3600] as $delta) {
    [$cell, $gate, $host] = g5Cell($arm);
    g5Replay($cell, $gate, $host, g4v2Bru(false, 1800), 'pre+out', $idm);
    $owner = new RateLimitContextDTO('203.0.113.200', FIREFOX_130, 'acct-v', null, null, false, [], false, null);
    $wait = null;
    for ($t = 1785 + $delta; $t <= 1785 + $delta + 7200; $t += 60) {
        if (g5Step($cell, $gate, $host, g4v2Row1($t, $owner, 'O', 'owner', 'ok', 'i:rec-owner', 'h:rec-owner'), $idm)['d'] === 'A') {
            $wait = $t - 1785 - $delta;
            break;
        }
    }
    $waits[$delta] = $wait;
}
g4Emit('fx', ['fixture' => 'REC', 'arm' => $arm, 'waitFromArrival' => $waits]);
// CROSS
[$cell, $gate, $host] = g5Cell($arm);
$rows = [g4v2Issue(0, 'atk', 'acct-atk', 'x:atk')];
for ($i = 0; $i < 200; $i++) {
    $c = new RateLimitContextDTO('203.0.113.90', CHROME, 'acct-cv' . $i, null, null, false, [], false, null);
    $rows[] = g4v2Row1(3 * $i, $c, 'X', 'atk', 'fail', 'x:atk', 'atk');
}
$r = g5Replay($cell, $gate, $host, $rows, 'pre+out', $idm);
$pAdm = 0;
for ($i = 0, $n = strlen($r['lanes']); $i < $n; $i++) {
    $pAdm += $r['lanes'][$i] === 'P' && $r['reach'][$i] === '1' ? 1 : 0;
}
g4Emit('fx', ['fixture' => 'CROSS', 'arm' => $arm, 'victimReach' => substr_count($r['reach'], '1'), 'victimPAdmitted' => $pAdm]);
// STEAL (password-change variant)
g4Emit('fx', g5Steal($arm));
// CLEAR
[$cell, $gate, $host] = g5Cell($arm);
$rows = [];
for ($d = 0; $d < 5; $d++) {
    foreach ([32400, 46800, 61200] as $h) {
        $c = new RateLimitContextDTO('198.51.100.77', CHROME, 'acct-c', null, null, false, [], false, null);
        $rows[] = g4v2Row1($d * 86400 + $h, $c, 'L', 'c', 'ok', 'i:c' . $d, 'h:c' . $d);
    }
}
$r = g5Replay($cell, $gate, $host, $rows, 'pre+out', $idm);
g4Emit('fx', ['fixture' => 'CLEAR', 'arm' => $arm] + g4Row($rows, $r));
// E2
$e2 = [];
foreach (['pop' => true, 'ctl' => false] as $k => $pop) {
    [$cell, $gate, $host] = g5Cell($arm);
    $oc = new RateLimitContextDTO('198.51.100.150', FIREFOX_130, 'acct-e2', null, null, false, [], true, null);
    $rows = array_merge([g4v2Issue(0, 'h:e2', 'acct-e2', 'i:e2', 600)], g5OwnerRows($oc, 'e2', 'i:e2', 'h:e2'));
    if ($pop) {
        for ($t = 0, $n = 0; $t < 2400; $t += 15, $n++) {
            $rows[] = g4v2Row1($t, g3Attacker($n % 20, 'acct-e2'), 'X', 'pop', 'fail', 'x:p' . ($n % 20), 'pop');
        }
    }
    $rows = g4v2Sort($rows);
    $r = g5Replay($cell, $gate, $host, $rows, 'pre+out', $idm);
    [$dec, $lanes] = g5Owner($rows, $r);
    $e2[$k] = ['ownerDec' => $dec, 'ownerLanes' => $lanes, 'ownerDeniedByCause' => $r['legitDeniedByCause']];
}
g4Emit('fx', ['fixture' => 'E2', 'arm' => $arm, 'pop' => $e2['pop'], 'ctl' => $e2['ctl'],
    'identical' => $e2['pop']['ownerDec'] === $e2['ctl']['ownerDec'] && $e2['pop']['ownerLanes'] === $e2['ctl']['ownerLanes']]);
// K5U
$k5 = [];
foreach (['fail' => true, 'ctl' => false] as $k => $uFails) {
    [$cell, $gate, $host] = g5Cell($arm);
    $oc = new RateLimitContextDTO('198.51.100.151', FIREFOX_130, 'acct-k5', null, null, false, [], true, null);
    $rows = array_merge([g4v2Issue(0, 'h:k5', 'acct-k5', 'i:k5', G4_TOKEN_TTL, 600 - G4V2_MAX_LIFE)], g5OwnerRows($oc, 'k5', 'i:k5', 'h:k5', $uFails));
    $r = g5Replay($cell, $gate, $host, $rows, 'pre+out', $idm);
    [$dec, $lanes, $ts] = g5Owner($rows, $r);
    $from = (int) array_search(960, $ts, true);
    $k5[$k] = ['ownerDec' => $dec, 'ownerLanes' => $lanes, 'from960Dec' => substr($dec, $from), 'from960Lanes' => substr($lanes, $from),
        'ownerDeniedByCause' => $r['legitDeniedByCause']];
}
g4Emit('fx', ['fixture' => 'K5U', 'arm' => $arm, 'fail' => $k5['fail'], 'ctl' => $k5['ctl'],
    'identicalFrom960' => $k5['fail']['from960Dec'] === $k5['ctl']['from960Dec'] && $k5['fail']['from960Lanes'] === $k5['ctl']['from960Lanes']]);
// BOOT / BOOT-X: the arm against R-NO-BIND (the C5 detector), as the archived row for FULL2
foreach (['BOOT' => false, 'BOOT-X' => true] as $fx => $spray) {
    $rows = g5Boot($spray);
    [$cell, $gate, $host] = g5Cell($arm);
    $res = [$arm => g5Replay($cell, $gate, $host, $rows, 'pre+out', $idm)];
    [$cell, $gate, $host] = g4v2Cell('R-NO-BIND');
    $res['R-NO-BIND'] = g5Replay($cell, $gate, $host, $rows, 'pre+out', false);
    $perDay = [];
    $boundRefused = 0;
    $detector = 0;
    foreach ($rows as $i => $row) {
        if ($row['kind'] === 'X') {
            continue;
        }
        $d = intdiv($row['t'], 86400);
        foreach ([$arm, 'R-NO-BIND'] as $a) {
            $key = $a === $arm ? 'FULL2' : 'NO-BIND';
            $perDay[$key][$d]['n'] = ($perDay[$key][$d]['n'] ?? 0) + 1;
            $perDay[$key][$d]['refused'] = ($perDay[$key][$d]['refused'] ?? 0) + ($res[$a]['dec'][$i] !== 'A' ? 1 : 0);
        }
        if ($res[$arm]['lanes'][$i] === 'P' && $res[$arm]['dec'][$i] !== 'A') {
            $boundRefused++;
        }
        if ($d === 0 && $res[$arm]['lanes'][$i] === 'P' && $res['R-NO-BIND']['dec'][$i] !== 'A') {
            $detector++;
        }
    }
    g4Emit('fx', ['fixture' => $fx, 'arm' => $arm . '+R-NO-BIND', 'perDay' => $perDay, 'fullBoundRefused' => $boundRefused,
        'noBindRefusedWhereFullBound' => $detector, 'fullIssued' => $res[$arm]['issued'],
        'fullCauses' => $res[$arm]['legitDeniedByCause'], 'noBindCauses' => $res['R-NO-BIND']['legitDeniedByCause'],
        'fullAttackReach' => substr_count(implode('', array_map(static fn(int $i): string => $rows[$i]['kind'] === 'X' ? $res[$arm]['reach'][$i] : '', array_keys($rows))), '1')]);
}

// ---- new fixtures (PLAN.md §4.1) ---------------------------------------------------------------------------------------------

// UAX-NP: twelve successful logins, one id and token, a different browser major each time (the daily new-device allowance).
[$cell, $gate, $host] = g5Cell($arm);
$rows = [g4v2Issue(0, 'h:np', 'acct-np', 'i:np')];
for ($i = 0; $i < 12; $i++) {
    $rows[] = g4v2Row1(3600 * $i + 60, $known('198.51.100.60', 'acct-np', $ua(120 + $i)), 'O', 'np', 'ok', 'i:np', 'h:np');
}
$r = g5Replay($cell, $gate, $host, g4v2Sort($rows), 'pre+out', $idm);
g4Emit('fx', ['fixture' => 'UAX-NP', 'arm' => $arm, 'lanes' => $r['lanes'], 'dec' => $r['dec'], 'refused' => strlen($r['dec']) - substr_count($r['dec'], 'A'),
    'causes' => $r['legitDeniedByCause']]);

// FORGE: the thief holds the owner's token and presents it with a fresh id each attempt, failing every 15 s to 43,200 s.
[$cell, $gate, $host] = g5Cell($arm);
$rows = [g4v2Issue(0, 'owner', 'acct-fg', 'i:fg')];
for ($t = 600, $n = 0; $t < 43200; $t += 15, $n++) {
    $inst = 'x:fg' . $n;
    $rows[] = ['t' => $t - 1, 'event' => static function (G4V2Host $h, int $now) use ($inst): void {
        $h->jar['thief|' . $inst] = $h->jar['owner|i:fg'] ?? [];
    }];
    $rows[] = g4v2Row1($t, g3Attacker($n, 'acct-fg'), 'X', 'thief', 'fail', $inst, 'thief');
}
$r = g5Replay($cell, $gate, $host, g4v2Sort($rows), 'pre+out', $idm);
$pAdm = $uReach = 0;
for ($i = 0, $n = strlen($r['lanes']); $i < $n; $i++) {
    $pAdm += $r['lanes'][$i] === 'P' && $r['reach'][$i] === '1' ? 1 : 0;
    $uReach += $r['lanes'][$i] === 'U' && $r['reach'][$i] === '1' ? 1 : 0;
}
g4Emit('fx', ['fixture' => 'FORGE', 'arm' => $arm, 'attempts' => strlen($r['lanes']), 'thiefPAdmitted' => $pAdm, 'thiefUReach' => $uReach]);

// MINT: a Host-known device, no token, a fresh id per attempt, failing every 60 s for 24 h (U lane).
[$cell, $gate, $host] = g5Cell($arm);
$rows = [];
for ($t = 0, $n = 0; $t < 86400; $t += 60, $n++) {
    $rows[] = g4v2Row1($t, $known('198.51.100.61', 'acct-mint', CHROME), 'X', 'm', 'fail', 'm:' . $n, 'm');
}
$r = g5Replay($cell, $gate, $host, $rows, 'pre+out', $idm);
g4Emit('fx', ['fixture' => 'MINT', 'arm' => $arm, 'attempts' => strlen($r['lanes']), 'reach' => substr_count($r['reach'], '1'), 'pLane' => substr_count($r['lanes'], 'P')]);

// LOST / TOKEN: a Host-known device, constant id and UA, failing every i seconds for 24 h; token lost (U) or valid (P).
foreach (['LOST' => false, 'TOKEN' => true] as $fx => $token) {
    $out = [];
    foreach ([600, 300, 60] as $iv) {
        [$cell, $gate, $host] = g5Cell($arm);
        $rows = $token ? [g4v2Issue(0, 'h:kd', 'acct-kd', 'i:kd')] : [];
        for ($t = 0; $t < 86400; $t += $iv) {
            $rows[] = g4v2Row1($t, $known('198.51.100.20', 'acct-kd', CHROME), 'O', 'kd', 'fail', 'i:kd', 'h:kd');
        }
        $r = g5Replay($cell, $gate, $host, g4v2Sort($rows), 'pre+out', $idm);
        $out[$iv] = ['reach' => substr_count($r['reach'], '1'), 'pLane' => substr_count($r['lanes'], 'P')];
    }
    g4Emit('fx', ['fixture' => $fx, 'arm' => $arm, 'byInterval' => $out]);
}

// UAX-ASM: an owner with a token, twelve failures 60 s apart, the browser major changing from the seventh; control without it.
$asm = [];
foreach (['flip' => true, 'ctl' => false] as $k => $flip) {
    [$cell, $gate, $host] = g5Cell($arm);
    $rows = [g4v2Issue(0, 'h:ua', 'acct-ua', 'i:ua')];
    for ($i = 0; $i < 12; $i++) {
        $rows[] = g4v2Row1($i * 60, $known('198.51.100.62', 'acct-ua', $flip && $i >= 6 ? $ua(127) : $ua(126)), 'O', 'ua', 'fail', 'i:ua', 'h:ua');
    }
    $r = g5Replay($cell, $gate, $host, g4v2Sort($rows), 'pre+out', $idm);
    $asm[$k] = ['dec' => $r['dec'], 'lanes' => $r['lanes']];
}
g4Emit('fx', ['fixture' => 'UAX-ASM', 'arm' => $arm, 'flip' => $asm['flip'], 'ctl' => $asm['ctl'], 'identical' => $asm['flip'] === $asm['ctl']]);

// MIXPRES-ASM (reported, not gated; PLAN.md §10 A1): one account, two devices of the owner; B sends no id and fails four times, then A (an id) fails twice; control without B.
$mix = [];
foreach (['both' => true, 'ctl' => false] as $k => $withB) {
    [$cell, $gate, $host] = g5Cell($arm);
    $rows = [];
    if ($withB) {
        foreach ([0, 30, 60, 90] as $t) {
            $rows[] = g4v2Row1($t, $known('198.51.100.63', 'acct-mx', CHROME), 'O', 'b', 'fail', null, 'h:b');
        }
    }
    foreach ([120, 150] as $t) {
        $rows[] = g4v2Row1($t, $known('198.51.100.63', 'acct-mx', CHROME), 'O', 'a', 'fail', 'i:a', 'h:a');
    }
    $rows = g4v2Sort($rows);
    $r = g5Replay($cell, $gate, $host, $rows, 'pre+out', $idm);
    $aDec = '';
    foreach ($rows as $j => $row) {
        $aDec .= $row['who'] === 'a' ? $r['dec'][$j] : '';
    }
    $mix[$k] = ['aDec' => $aDec, 'allDec' => $r['dec']];
}
[$cell] = g5Cell($arm);
$keyA = substr(g5K5Key($cell->lab, new RateLimitContextDTO('198.51.100.63', CHROME, 'acct-mx', null, 'i:a', false, [], true, null), 'U'), 0, 12);
$keyB = substr(g5K5Key($cell->lab, new RateLimitContextDTO('198.51.100.63', CHROME, 'acct-mx', null, null, false, [], true, null), 'U'), 0, 12);
g4Emit('fx', ['fixture' => 'MIXPRES-ASM', 'arm' => $arm, 'both' => $mix['both'], 'ctl' => $mix['ctl'], 'k5KeyA' => $keyA, 'k5KeyB' => $keyB,
    'keysDiffer' => $keyA !== $keyB, 'aIdentical' => $mix['both']['aDec'] === $mix['ctl']['aDec']]);
