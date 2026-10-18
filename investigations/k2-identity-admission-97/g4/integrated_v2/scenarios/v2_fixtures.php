<?php

declare(strict_types=1);

// G4 v2 fixtures (PLAN.md §4) for every arm of the current build. The G4 fixtures are ported unchanged except for the BRU
// builder (F131-01 correction) and the replay (g4v2Replay, with the IN assertion). Part R emits the G4 row shapes so that V1 can
// compare them with the archive; Part N adds STEAL with the password-change event and the new K5U fixture.

require dirname(__DIR__) . '/lib/g4v2.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

$build = g4Provenance();

/** HAR (G1 v07), as the archived g4Har(). */
function g4v2Har(bool $bound): array
{
    $rows = $bound ? [g4v2Issue(0, 'h:owner', 'acct-har', 'i:owner')] : [];
    $owner = new RateLimitContextDTO('198.51.100.140', FIREFOX_130, 'acct-har', null, null, false, [], false, null);
    for ($t = 0; $t < 1800; $t += 120) {
        $rows[] = g4v2Row1($t, $owner, 'O', 'owner', 'ok', 'i:owner', 'h:owner');
    }
    for ($t = 5, $n = 0; $t < 1800; $t += 10, $n++) {
        $c = new RateLimitContextDTO('192.0.2.' . (150 + $n % 30), CHROME, 'acct-har', null, null, false, [], false, null);
        $rows[] = g4v2Row1($t, $c, 'X', 'atk', 'fail', 'x:' . $n, 'atk');
    }

    return g4v2Sort($rows);
}

/** STEAL up to 43,200 s, as the archived g4StealPre(). */
function g4v2StealPre(string $arm, RateLimitContextDTO $ownerCtx): array
{
    [$cell, $gate, $host] = g4v2Cell($arm);
    $pre = g4v2Sort(array_merge(
        [g4v2Issue(0, 'owner', 'acct-st', 'i:st'), ['t' => 600, 'event' => static fn(G4Host|G4V2Host $h, int $now) => $h->copyJar('owner', 'thief', 'i:st')]],
        array_map(static fn(int $t): array => g4v2Row1($t, $ownerCtx, 'O', 'owner', 'ok', 'i:st', 'owner'), range(60, 43199, 7200)),
        array_map(static fn(int $t): array => g4v2Row1($t, g3Attacker(intdiv($t, 15), 'acct-st'), 'X', 'thief', 'fail', 'i:st', 'thief'), range(615, 43199, 15)),
    ));
    $win = [];
    $day = 0;
    $ownerDen = 0;
    foreach ($pre as $row) {
        if (isset($row['event'])) {
            g4v2Replay($cell, $gate, $host, [$row], 'pre+out');
            continue;
        }
        $s = g4v2Step($cell, $gate, $host, $row);
        if ($row['holder'] === 'thief' && $s['lane'] === 'P' && $s['reach'] === '1') {
            $win[intdiv($row['t'], 600)] = ($win[intdiv($row['t'], 600)] ?? 0) + 1;
            $day++;
        }
        $ownerDen += $row['holder'] === 'owner' && $s['d'] !== 'A' ? 1 : 0;
    }

    return [$cell, $gate, $host, $win, $day, $ownerDen];
}

/** E2 / K5U owner rows (G4 E2 shape); $uFails = false drops the three attempts at 630, 750, 870 s. */
function g4v2OwnerRows(RateLimitContextDTO $oc, string $who, string $inst, string $holder, bool $uFails = true): array
{
    $rows = [];
    for ($t = 0; $t < 600; $t += 60) {
        $rows[] = g4v2Row1($t, $oc, 'O', $who, $t === 240 ? 'fail' : 'ok', $inst, $holder);
    }
    if ($uFails) {
        foreach ([630, 750, 870] as $t) {
            $rows[] = g4v2Row1($t, $oc, 'O', $who, 'fail', $inst, $holder);
        }
    }
    $rows[] = g4v2Row1(900, $oc, 'O', $who, 'ok', $inst, $holder);
    for ($t = 960; $t < 2400; $t += 60) {
        $rows[] = g4v2Row1($t, $oc, 'O', $who, in_array($t, [1200, 1800, 2280], true) ? 'fail' : 'ok', $inst, $holder);
    }

    return $rows;
}

/** Owner decisions / lanes / times of a replay, owner rows only. */
function g4v2Owner(array $rows, array $r): array
{
    $dec = $lanes = '';
    $ts = [];
    $j = 0;
    foreach ($rows as $row) {
        if (isset($row['event'])) {
            continue;
        }
        if ($row['kind'] === 'O') {
            $dec .= $r['dec'][$j];
            $lanes .= $r['lanes'][$j];
            $ts[] = $row['t'];
        }
        $j++;
    }

    return [$dec, $lanes, $ts];
}

foreach (g4v2ArmsOf($build) as $arm) {
    $part = g4v2Arms()[$arm][4];
    // HAR-B / HAR-N
    foreach (['HAR-B' => true, 'HAR-N' => false] as $fx => $bound) {
        [$cell, $gate, $host] = g4v2Cell($arm);
        $rows = g4v2Har($bound);
        $r = g4v2Replay($cell, $gate, $host, $rows, 'pre+out');
        g4Emit('fx', ['fixture' => $fx, 'arm' => $arm] + g4Row($rows, $r));
    }
    // BRU24 and BRU24-HV
    foreach (['BRU24' => false, 'BRU24-HV' => true] as $fx => $hv) {
        [$cell, $gate, $host] = g4v2Cell($arm);
        $rows = g4v2Bru($hv);
        $r = g4v2Replay($cell, $gate, $host, $rows, 'pre+out');
        $takes = count(array_filter($gate->u->tbLog, static fn(array $e): bool => $e['kind'] === 'take'));
        g4Emit('fx', ['fixture' => $fx, 'arm' => $arm, 'attackAdmitted' => substr_count($r['reach'], '1'), 'tbTakesRecount' => $takes,
            'pLane' => substr_count($r['lanes'], 'P'), 'attackDeniedByCause' => $r['attackDeniedByCause']]);
    }
    // REC
    $waits = [];
    foreach ([0, 60, 300, 900, 1800, 3600] as $delta) {
        [$cell, $gate, $host] = g4v2Cell($arm);
        g4v2Replay($cell, $gate, $host, g4v2Bru(false, 1800), 'pre+out');
        $owner = new RateLimitContextDTO('203.0.113.200', FIREFOX_130, 'acct-v', null, null, false, [], false, null);
        $wait = null;
        for ($t = 1785 + $delta; $t <= 1785 + $delta + 7200; $t += 60) {
            if (g4v2Step($cell, $gate, $host, g4v2Row1($t, $owner, 'O', 'owner', 'ok', 'i:rec-owner', 'h:rec-owner'))['d'] === 'A') {
                $wait = $t - 1785 - $delta;
                break;
            }
        }
        $waits[$delta] = $wait;
    }
    g4Emit('fx', ['fixture' => 'REC', 'arm' => $arm, 'waitFromArrival' => $waits]);
    // CROSS
    [$cell, $gate, $host] = g4v2Cell($arm);
    $rows = [g4v2Issue(0, 'atk', 'acct-atk', 'x:atk')];
    for ($i = 0; $i < 200; $i++) {
        $c = new RateLimitContextDTO('203.0.113.90', CHROME, 'acct-cv' . $i, null, null, false, [], false, null);
        $rows[] = g4v2Row1(3 * $i, $c, 'X', 'atk', 'fail', 'x:atk', 'atk');
    }
    $r = g4v2Replay($cell, $gate, $host, $rows, 'pre+out');
    $pAdm = 0;
    for ($i = 0, $n = strlen($r['lanes']); $i < $n; $i++) {
        $pAdm += $r['lanes'][$i] === 'P' && $r['reach'][$i] === '1' ? 1 : 0;
    }
    g4Emit('fx', ['fixture' => 'CROSS', 'arm' => $arm, 'victimReach' => substr_count($r['reach'], '1'), 'victimPAdmitted' => $pAdm]);
    // STEAL
    $ownerCtx = new RateLimitContextDTO('198.51.100.141', FIREFOX_130, 'acct-st', null, null, false, [], false, null);
    if ($part === 'R') {
        // As G4: revoke at 43,200 s; the owner retries on U from 43,260 s (and the G4 post-hoc +5 s variant, kept for V1).
        $after = [];
        foreach (['reg' => 43260, 'off5' => 43265] as $variant => $first) {
            [$cell, $gate, $host, $win, $day, $ownerDen] = g4v2StealPre($arm, $ownerCtx);
            $host->revoke('acct-st');
            $afterP = 0;
            $afterU = 0;
            $ownerWait = null;
            $next = $first;
            for ($t = 43215; $t < 86400; $t += 15) {
                if ($ownerWait === null && $next < $t) {
                    $s = g4v2Step($cell, $gate, $host, g4v2Row1($next, $ownerCtx, 'O', 'owner', 'ok', 'i:st', 'owner'));
                    $s['d'] === 'A' ? $ownerWait = $next - $first : $next += 60;
                }
                $s = g4v2Step($cell, $gate, $host, g4v2Row1($t, g3Attacker(intdiv($t, 15), 'acct-st'), 'X', 'thief', 'fail', 'i:st', 'thief'));
                $afterP += $s['lane'] === 'P' && $s['reach'] === '1' ? 1 : 0;
                $afterU += $s['lane'] === 'U' && $s['reach'] === '1' ? 1 : 0;
                if ($ownerWait === null && $next === $t) {
                    $s = g4v2Step($cell, $gate, $host, g4v2Row1($next, $ownerCtx, 'O', 'owner', 'ok', 'i:st', 'owner'));
                    $s['d'] === 'A' ? $ownerWait = $next - $first : $next += 60;
                }
            }
            $after[$variant] = [$afterP, $afterU, $ownerWait];
        }
        g4Emit('fx', ['fixture' => 'STEAL', 'arm' => $arm, 'thiefPMaxPerWindow' => $win === [] ? 0 : max($win), 'thiefPBeforeRevoke' => $day,
            'ownerDeniedBeforeRevoke' => $ownerDen, 'thiefPAfterRevoke' => $after['reg'][0], 'thiefUAfterRevoke' => $after['reg'][1],
            'ownerWaitAfterRevoke' => $after['reg'][2], 'ownerWaitOffset5' => $after['off5'][2]]);
    } else {
        // PLAN.md §4: the owner changes the password from the bound device at 43,200 s, then logs in from 43,260 s.
        [$cell, $gate, $host, $win, $day, $ownerDen] = g4v2StealPre($arm, $ownerCtx);
        g4v2Replay($cell, $gate, $host, [['t' => 43200, 'event' => static fn(G4V2Host $h, int $now) => $h->passwordChange('owner', 'acct-st', 'i:st', $now)]], 'pre+out');
        $afterP = 0;
        $afterU = 0;
        $ownerWait = null;
        $ownerLane = null;
        $ownerCauses = [];
        $next = 43260;
        for ($t = 43215; $t < 86400; $t += 15) {
            $s = g4v2Step($cell, $gate, $host, g4v2Row1($t, g3Attacker(intdiv($t, 15), 'acct-st'), 'X', 'thief', 'fail', 'i:st', 'thief'));
            if ($s['lane'] === 'P' && $s['reach'] === '1') {
                $afterP++;
                $win[intdiv($t, 600)] = ($win[intdiv($t, 600)] ?? 0) + 1;
            }
            $afterU += $s['lane'] === 'U' && $s['reach'] === '1' ? 1 : 0;
            if ($ownerWait === null && $next === $t) {
                $s = g4v2Step($cell, $gate, $host, g4v2Row1($next, $ownerCtx, 'O', 'owner', 'ok', 'i:st', 'owner'));
                if ($s['d'] === 'A') {
                    $ownerWait = $next - 43260;
                    $ownerLane = $s['lane'];
                } else {
                    $ownerCauses[$s['cause']] = ($ownerCauses[$s['cause']] ?? 0) + 1;
                    $next += 60;
                }
            }
        }
        ksort($ownerCauses);
        g4Emit('fx', ['fixture' => 'STEAL', 'arm' => $arm, 'thiefPMaxPerWindow' => $win === [] ? 0 : max($win), 'thiefPBeforeChange' => $day,
            'thiefPAfterChange' => $afterP, 'thiefUAfterChange' => $afterU, 'ownerDeniedBeforeChange' => $ownerDen,
            'ownerWaitAfterChange' => $ownerWait, 'ownerLaneAtAdmission' => $ownerLane, 'ownerDeniedAfterChangeByCause' => $ownerCauses,
            'pwBinds' => $host->pwBinds]);
    }
    // CLEAR
    [$cell, $gate, $host] = g4v2Cell($arm);
    $rows = [];
    for ($d = 0; $d < 5; $d++) {
        foreach ([32400, 46800, 61200] as $h) {
            $c = new RateLimitContextDTO('198.51.100.77', CHROME, 'acct-c', null, null, false, [], false, null);
            $rows[] = g4v2Row1($d * 86400 + $h, $c, 'L', 'c', 'ok', 'i:c' . $d, 'h:c' . $d);
        }
    }
    $r = g4v2Replay($cell, $gate, $host, $rows, 'pre+out');
    g4Emit('fx', ['fixture' => 'CLEAR', 'arm' => $arm] + g4Row($rows, $r));
    // E2 (G4 shape)
    $e2 = [];
    foreach (['pop' => true, 'ctl' => false] as $k => $pop) {
        [$cell, $gate, $host] = g4v2Cell($arm);
        $oc = new RateLimitContextDTO('198.51.100.150', FIREFOX_130, 'acct-e2', null, null, false, [], true, null);
        $rows = array_merge([g4v2Issue(0, 'h:e2', 'acct-e2', 'i:e2', 600)], g4v2OwnerRows($oc, 'e2', 'i:e2', 'h:e2'));
        if ($pop) {
            for ($t = 0, $n = 0; $t < 2400; $t += 15, $n++) {
                $rows[] = g4v2Row1($t, g3Attacker($n % 20, 'acct-e2'), 'X', 'pop', 'fail', 'x:p' . ($n % 20), 'pop');
            }
        }
        $rows = g4v2Sort($rows);
        $r = g4v2Replay($cell, $gate, $host, $rows, 'pre+out');
        [$dec, $lanes] = g4v2Owner($rows, $r);
        $e2[$k] = ['ownerDec' => $dec, 'ownerLanes' => $lanes, 'ownerDeniedByCause' => $r['legitDeniedByCause']];
    }
    g4Emit('fx', ['fixture' => 'E2', 'arm' => $arm, 'pop' => $e2['pop'], 'ctl' => $e2['ctl'],
        'identical' => $e2['pop']['ownerDec'] === $e2['ctl']['ownerDec'] && $e2['pop']['ownerLanes'] === $e2['ctl']['ownerLanes']]);
    // K5U (Part N only; PLAN.md §4): the token reaches its absolute limit at 600 s, the owner stays on the same instance.
    if ($part === 'N') {
        $k5 = [];
        foreach (['fail' => true, 'ctl' => false] as $k => $uFails) {
            [$cell, $gate, $host] = g4v2Cell($arm);
            $oc = new RateLimitContextDTO('198.51.100.151', FIREFOX_130, 'acct-k5', null, null, false, [], true, null);
            $rows = array_merge([g4v2Issue(0, 'h:k5', 'acct-k5', 'i:k5', G4_TOKEN_TTL, 600 - G4V2_MAX_LIFE)], g4v2OwnerRows($oc, 'k5', 'i:k5', 'h:k5', $uFails));
            $r = g4v2Replay($cell, $gate, $host, $rows, 'pre+out');
            [$dec, $lanes, $ts] = g4v2Owner($rows, $r);
            $from = (int) array_search(960, $ts, true);
            $k5[$k] = ['ownerDec' => $dec, 'ownerLanes' => $lanes, 'from960Dec' => substr($dec, $from), 'from960Lanes' => substr($lanes, $from),
                'ownerDeniedByCause' => $r['legitDeniedByCause']];
        }
        g4Emit('fx', ['fixture' => 'K5U', 'arm' => $arm, 'fail' => $k5['fail'], 'ctl' => $k5['ctl'],
            'identicalFrom960' => $k5['fail']['from960Dec'] === $k5['ctl']['from960Dec'] && $k5['fail']['from960Lanes'] === $k5['ctl']['from960Lanes']]);
    }
}

// BOOT / BOOT-X on the G4 build: R-FULL + R-NO-BIND (the G4 row, for V1), and FULL2 against R-NO-BIND (C5 detector).
if ($build === 'G4') {
    $boot = static function (bool $spray): array {
        mt_srand(4242);
        $rows = [];
        for ($k = 0; $k < 40; $k++) {
            for ($d = 0; $d < 3; $d++) {
                for ($j = 0; $j < 3; $j++) {
                    $t = $d * 86400 + 28800 + mt_rand(0, 36000);
                    $c = new RateLimitContextDTO('198.51.100.77', ci97RotUa($k), 'acct-b' . $k, null, null, false, [], false, null);
                    if (mt_rand(1, 100) <= 5) {
                        $rows[] = g4v2Row1($t, $c, 'L', 'b' . $k, 'fail', 'i:b' . $k, 'h:b' . $k);
                        $t += 30;
                    }
                    $rows[] = g4v2Row1($t, $c, 'L', 'b' . $k, 'ok', 'i:b' . $k, 'h:b' . $k);
                }
            }
        }
        if ($spray) {
            for ($t = 32400, $n = 0; $t < 61200; $t += 10, $n++) {
                $c = new RateLimitContextDTO('198.51.100.77', CHROME, 'acct-bx' . $n, null, null, false, [], false, null);
                $rows[] = g4v2Row1($t, $c, 'X', 'spray', 'fail', 'x:b' . $n, 'atk');
            }
        }

        return g4v2Sort($rows);
    };
    foreach (['BOOT' => false, 'BOOT-X' => true] as $fx => $spray) {
        $rows = $boot($spray);
        $res = [];
        foreach (['R-FULL', 'R-NO-BIND', 'FULL2'] as $arm) {
            [$cell, $gate, $host] = g4v2Cell($arm);
            $res[$arm] = g4v2Replay($cell, $gate, $host, $rows, 'pre+out');
        }
        foreach (['R-FULL', 'FULL2'] as $full) {
            $pair = [$full, 'R-NO-BIND'];
            $perDay = [];
            $boundRefused = 0;
            $detector = 0;
            foreach ($rows as $i => $row) {
                if ($row['kind'] === 'X') {
                    continue;
                }
                $d = intdiv($row['t'], 86400);
                foreach ($pair as $arm) {
                    $key = $arm !== $full ? 'NO-BIND' : ($full === 'R-FULL' ? 'FULL' : $full);
                    $perDay[$key][$d]['n'] = ($perDay[$key][$d]['n'] ?? 0) + 1;
                    $perDay[$key][$d]['refused'] = ($perDay[$key][$d]['refused'] ?? 0) + ($res[$arm]['dec'][$i] !== 'A' ? 1 : 0);
                }
                if ($res[$full]['lanes'][$i] === 'P' && $res[$full]['dec'][$i] !== 'A') {
                    $boundRefused++;
                }
                if ($d === 0 && $res[$full]['lanes'][$i] === 'P' && $res['R-NO-BIND']['dec'][$i] !== 'A') {
                    $detector++;
                }
            }
            g4Emit('fx', ['fixture' => $fx, 'arm' => $full . '+R-NO-BIND', 'perDay' => $perDay, 'fullBoundRefused' => $boundRefused,
                'noBindRefusedWhereFullBound' => $detector, 'fullIssued' => $res[$full]['issued'],
                'fullCauses' => $res[$full]['legitDeniedByCause'], 'noBindCauses' => $res['R-NO-BIND']['legitDeniedByCause'],
                'fullAttackReach' => substr_count(implode('', array_map(static fn(int $i): string => $rows[$i]['kind'] === 'X' ? $res[$full]['reach'][$i] : '', array_keys($rows))), '1')]);
        }
    }
}
