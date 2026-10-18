<?php

declare(strict_types=1);

// G4 fixtures (PLAN.md §2): HAR-B, HAR-N, BRU24, BRU24-HV, REC, CROSS, STEAL, BOOT, BOOT-X, CLEAR, E2 — for every arm of the
// current build. BOOT-X also evaluates the C5 detector (FULL against NO-BIND, both on the G4 build).

require dirname(__DIR__) . '/lib/g4.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

$build = g4Provenance();

function g4Row1(int $t, RateLimitContextDTO $c, string $kind, string $who, string $outcome, ?string $inst, string $holder): array
{
    return ['t' => $t, 'ctx' => g4Ctx($c, $inst), 'proof' => null, 'kind' => $kind, 'own' => false, 'who' => $who,
        'outcome' => $outcome, 'inst' => $inst, 'holder' => $holder];
}

function g4Issue(int $t, string $holder, string $acct, string $inst, int $ttl = G4_TOKEN_TTL): array
{
    return ['t' => $t, 'event' => static fn(G4Host $h, int $now) => $h->issue($holder, $acct, $inst, $now, $ttl)];
}

function g4Sort(array $rows): array
{
    usort($rows, static fn(array $a, array $b): int => [$a['t'], isset($a['event']) ? 0 : 1, ($a['kind'] ?? '') === 'X' ? 0 : 1]
        <=> [$b['t'], isset($b['event']) ? 0 : 1, ($b['kind'] ?? '') === 'X' ? 0 : 1]);

    return $rows;
}

/** HAR (G1 v07): owner logs in every 120 s; the attacker fails every 10 s from 30 rotating sources, fresh id each time. */
function g4Har(bool $bound): array
{
    $rows = $bound ? [g4Issue(0, 'h:owner', 'acct-har', 'i:owner')] : [];
    $owner = new RateLimitContextDTO('198.51.100.140', FIREFOX_130, 'acct-har', null, null, false, [], false, null);
    for ($t = 0; $t < 1800; $t += 120) {
        $rows[] = g4Row1($t, $owner, 'O', 'owner', 'ok', 'i:owner', 'h:owner');
    }
    for ($t = 5, $n = 0; $t < 1800; $t += 10, $n++) {
        $c = new RateLimitContextDTO('192.0.2.' . (150 + $n % 30), CHROME, 'acct-har', null, null, false, [], false, null);
        $rows[] = g4Row1($t, $c, 'X', 'atk', 'fail', 'x:' . $n, 'atk');
    }

    return g4Sort($rows);
}

function g4Bru(bool $hv, int $until = 86400): array
{
    $rows = [];
    foreach (g3v2Bru24($hv) as $n => $r) {
        if ($r['t'] < $until) {
            $rows[] = $r + ['inst' => 'x:' . $n, 'holder' => 'atk', 'ctx' => g4Ctx($r['ctx'], 'x:' . $n)];
        }
    }

    return $rows;
}

/** One row through the replay; returns its decision and lane. */
function g4Step(G1Cell $cell, G4Gate $gate, G4Host $host, array $row): array
{
    $r = g4Replay($cell, $gate, $host, [$row], 'pre+out');

    return ['d' => $r['dec'], 'lane' => $r['lanes'], 'reach' => $r['reach']];
}

/** STEAL up to the password change (43,200 s): owner bound at 0, thief copies the jar at 600 s and fails every 15 s. */
function g4StealPre(string $arm, RateLimitContextDTO $ownerCtx): array
{
    [$cell, $gate, $host] = g4Cell($arm);
    $pre = g4Sort(array_merge(
        [g4Issue(0, 'owner', 'acct-st', 'i:st'), ['t' => 600, 'event' => static fn(G4Host $h, int $now) => $h->copyJar('owner', 'thief', 'i:st')]],
        array_map(static fn(int $t): array => g4Row1($t, $ownerCtx, 'O', 'owner', 'ok', 'i:st', 'owner'), range(60, 43199, 7200)),
        array_map(static fn(int $t): array => g4Row1($t, g3Attacker(intdiv($t, 15), 'acct-st'), 'X', 'thief', 'fail', 'i:st', 'thief'), range(615, 43199, 15)),
    ));
    $win = [];
    $day = 0;
    $ownerDen = 0;
    foreach ($pre as $row) {
        if (isset($row['event'])) {
            g4Replay($cell, $gate, $host, [$row], 'pre+out');
            continue;
        }
        $s = g4Step($cell, $gate, $host, $row);
        if ($row['holder'] === 'thief' && $s['lane'] === 'P' && $s['reach'] === '1') {
            $win[intdiv($row['t'], 600)] = ($win[intdiv($row['t'], 600)] ?? 0) + 1;
            $day++;
        }
        $ownerDen += $row['holder'] === 'owner' && $s['d'] !== 'A' ? 1 : 0;
    }

    return [$cell, $gate, $host, $win, $day, $ownerDen];
}

foreach (g4Arms() as $arm => [$b]) {
    if ($b !== $build) {
        continue;
    }
    // HAR-B / HAR-N
    foreach (['HAR-B' => true, 'HAR-N' => false] as $fx => $bound) {
        [$cell, $gate, $host] = g4Cell($arm);
        $rows = g4Har($bound);
        $r = g4Replay($cell, $gate, $host, $rows, 'pre+out');
        g4Emit('fx', ['fixture' => $fx, 'arm' => $arm] + g4Row($rows, $r));
    }
    // BRU24 and BRU24-HV
    foreach (['BRU24' => false, 'BRU24-HV' => true] as $fx => $hv) {
        [$cell, $gate, $host] = g4Cell($arm);
        $rows = g4Bru($hv);
        $r = g4Replay($cell, $gate, $host, $rows, 'pre+out');
        $takes = count(array_filter($gate->u->tbLog, static fn(array $e): bool => $e['kind'] === 'take'));
        g4Emit('fx', ['fixture' => $fx, 'arm' => $arm, 'attackAdmitted' => substr_count($r['reach'], '1'), 'tbTakesRecount' => $takes,
            'pLane' => substr_count($r['lanes'], 'P'), 'attackDeniedByCause' => $r['attackDeniedByCause']]);
    }
    // REC
    $waits = [];
    foreach ([0, 60, 300, 900, 1800, 3600] as $delta) {
        [$cell, $gate, $host] = g4Cell($arm);
        g4Replay($cell, $gate, $host, g4Bru(false, 1800), 'pre+out');
        $owner = new RateLimitContextDTO('203.0.113.200', FIREFOX_130, 'acct-v', null, null, false, [], false, null);
        $wait = null;
        for ($t = 1785 + $delta; $t <= 1785 + $delta + 7200; $t += 60) {
            if (g4Step($cell, $gate, $host, g4Row1($t, $owner, 'O', 'owner', 'ok', 'i:rec-owner', 'h:rec-owner'))['d'] === 'A') {
                $wait = $t - 1785 - $delta;
                break;
            }
        }
        $waits[$delta] = $wait;
    }
    g4Emit('fx', ['fixture' => 'REC', 'arm' => $arm, 'waitFromArrival' => $waits]);
    // CROSS
    [$cell, $gate, $host] = g4Cell($arm);
    $rows = [g4Issue(0, 'atk', 'acct-atk', 'x:atk')];
    for ($i = 0; $i < 200; $i++) {
        $c = new RateLimitContextDTO('203.0.113.90', CHROME, 'acct-cv' . $i, null, null, false, [], false, null);
        $rows[] = g4Row1(3 * $i, $c, 'X', 'atk', 'fail', 'x:atk', 'atk');
    }
    $r = g4Replay($cell, $gate, $host, $rows, 'pre+out');
    $pAdm = 0;
    for ($i = 0, $n = strlen($r['lanes']); $i < $n; $i++) {
        $pAdm += $r['lanes'][$i] === 'P' && $r['reach'][$i] === '1' ? 1 : 0;
    }
    g4Emit('fx', ['fixture' => 'CROSS', 'arm' => $arm, 'victimReach' => substr_count($r['reach'], '1'), 'victimPAdmitted' => $pAdm]);
    // STEAL (before the bump: g4StealPre)
    $ownerCtx = new RateLimitContextDTO('198.51.100.141', FIREFOX_130, 'acct-st', null, null, false, [], false, null);
    // After the bump the thief keeps failing every 15 s; the owner logs in at 43,260 s and retries every 60 s until admitted.
    // 'ownerWaitOffset5' (reported only, added after the first results): the same with the owner 5 s after a thief slot, to show
    // whether the registered tie-break (attacker first at equal times) decides the owner's wait.
    $after = [];
    foreach (['reg' => 43260, 'off5' => 43265] as $variant => $first) {
        [$cell, $gate, $host, $win, $day, $ownerDen] = g4StealPre($arm, $ownerCtx);
        $host->revoke('acct-st');
        $afterP = 0;
        $afterU = 0;
        $ownerWait = null;
        $next = $first;
        for ($t = 43215; $t < 86400; $t += 15) {
            if ($ownerWait === null && $next < $t) {
                $s = g4Step($cell, $gate, $host, g4Row1($next, $ownerCtx, 'O', 'owner', 'ok', 'i:st', 'owner'));
                $s['d'] === 'A' ? $ownerWait = $next - $first : $next += 60;
            }
            $s = g4Step($cell, $gate, $host, g4Row1($t, g3Attacker(intdiv($t, 15), 'acct-st'), 'X', 'thief', 'fail', 'i:st', 'thief'));
            $afterP += $s['lane'] === 'P' && $s['reach'] === '1' ? 1 : 0;
            $afterU += $s['lane'] === 'U' && $s['reach'] === '1' ? 1 : 0;
            if ($ownerWait === null && $next === $t) {
                $s = g4Step($cell, $gate, $host, g4Row1($next, $ownerCtx, 'O', 'owner', 'ok', 'i:st', 'owner'));
                $s['d'] === 'A' ? $ownerWait = $next - $first : $next += 60;
            }
        }
        $after[$variant] = [$afterP, $afterU, $ownerWait];
    }
    g4Emit('fx', ['fixture' => 'STEAL', 'arm' => $arm, 'thiefPMaxPerWindow' => $win === [] ? 0 : max($win), 'thiefPBeforeRevoke' => $day,
        'ownerDeniedBeforeRevoke' => $ownerDen, 'thiefPAfterRevoke' => $after['reg'][0], 'thiefUAfterRevoke' => $after['reg'][1],
        'ownerWaitAfterRevoke' => $after['reg'][2], 'ownerWaitOffset5' => $after['off5'][2]]);
    // CLEAR
    [$cell, $gate, $host] = g4Cell($arm);
    $rows = [];
    for ($d = 0; $d < 5; $d++) {
        foreach ([32400, 46800, 61200] as $h) {
            $c = new RateLimitContextDTO('198.51.100.77', CHROME, 'acct-c', null, null, false, [], false, null);
            $rows[] = g4Row1($d * 86400 + $h, $c, 'L', 'c', 'ok', 'i:c' . $d, 'h:c' . $d);
        }
    }
    $r = g4Replay($cell, $gate, $host, $rows, 'pre+out');
    g4Emit('fx', ['fixture' => 'CLEAR', 'arm' => $arm] + g4Row($rows, $r));
    // E2 (PLAN.md §2): owner rows identical in both runs; the population (20 rotating devices failing on the account every 15 s
    // from 0 to 2,400 s) only in 'pop'. Compared: the owner's decisions and lanes on every owner row.
    $e2 = [];
    foreach (['pop' => true, 'ctl' => false] as $k => $pop) {
        [$cell, $gate, $host] = g4Cell($arm);
        $oc = new RateLimitContextDTO('198.51.100.150', FIREFOX_130, 'acct-e2', null, null, false, [], true, null);
        $rows = [g4Issue(0, 'h:e2', 'acct-e2', 'i:e2', 600)];
        for ($t = 0; $t < 600; $t += 60) {
            $rows[] = g4Row1($t, $oc, 'O', 'e2', $t === 240 ? 'fail' : 'ok', 'i:e2', 'h:e2');
        }
        foreach ([630, 750, 870] as $t) {
            $rows[] = g4Row1($t, $oc, 'O', 'e2', 'fail', 'i:e2', 'h:e2');
        }
        $rows[] = g4Row1(900, $oc, 'O', 'e2', 'ok', 'i:e2', 'h:e2');
        for ($t = 960; $t < 2400; $t += 60) {
            $rows[] = g4Row1($t, $oc, 'O', 'e2', in_array($t, [1200, 1800, 2280], true) ? 'fail' : 'ok', 'i:e2', 'h:e2');
        }
        if ($pop) {
            for ($t = 0, $n = 0; $t < 2400; $t += 15, $n++) {
                $rows[] = g4Row1($t, g3Attacker($n % 20, 'acct-e2'), 'X', 'pop', 'fail', 'x:p' . ($n % 20), 'pop');
            }
        }
        $rows = g4Sort($rows);
        $r = g4Replay($cell, $gate, $host, $rows, 'pre+out');
        $dec = $lanes = '';
        $j = 0;
        foreach ($rows as $row) {
            if (isset($row['event'])) {
                continue;
            }
            if ($row['kind'] === 'O') {
                $dec .= $r['dec'][$j];
                $lanes .= $r['lanes'][$j];
            }
            $j++;
        }
        $e2[$k] = ['ownerDec' => $dec, 'ownerLanes' => $lanes, 'ownerDeniedByCause' => $r['legitDeniedByCause']];
    }
    g4Emit('fx', ['fixture' => 'E2', 'arm' => $arm, 'pop' => $e2['pop'], 'ctl' => $e2['ctl'],
        'identical' => $e2['pop']['ownerDec'] === $e2['ctl']['ownerDec'] && $e2['pop']['ownerLanes'] === $e2['ctl']['ownerLanes']]);
}

// BOOT / BOOT-X (FULL and NO-BIND on the G4 build; the C5 detector compares them request by request).
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
                        $rows[] = g4Row1($t, $c, 'L', 'b' . $k, 'fail', 'i:b' . $k, 'h:b' . $k);
                        $t += 30;
                    }
                    $rows[] = g4Row1($t, $c, 'L', 'b' . $k, 'ok', 'i:b' . $k, 'h:b' . $k);
                }
            }
        }
        if ($spray) {
            for ($t = 32400, $n = 0; $t < 61200; $t += 10, $n++) {
                $c = new RateLimitContextDTO('198.51.100.77', CHROME, 'acct-bx' . $n, null, null, false, [], false, null);
                $rows[] = g4Row1($t, $c, 'X', 'spray', 'fail', 'x:b' . $n, 'atk');
            }
        }

        return g4Sort($rows);
    };
    foreach (['BOOT' => false, 'BOOT-X' => true] as $fx => $spray) {
        $rows = $boot($spray);
        $res = [];
        foreach (['FULL', 'NO-BIND'] as $arm) {
            [$cell, $gate, $host] = g4Cell($arm);
            $res[$arm] = g4Replay($cell, $gate, $host, $rows, 'pre+out');
        }
        $perDay = [];
        $boundRefused = 0;
        $detector = 0;
        foreach ($rows as $i => $row) {
            if ($row['kind'] === 'X') {
                continue;
            }
            $d = intdiv($row['t'], 86400);
            foreach (['FULL', 'NO-BIND'] as $arm) {
                $perDay[$arm][$d]['n'] = ($perDay[$arm][$d]['n'] ?? 0) + 1;
                $perDay[$arm][$d]['refused'] = ($perDay[$arm][$d]['refused'] ?? 0) + ($res[$arm]['dec'][$i] !== 'A' ? 1 : 0);
            }
            if ($res['FULL']['lanes'][$i] === 'P' && $res['FULL']['dec'][$i] !== 'A') {
                $boundRefused++;
            }
            if ($d === 0 && $res['FULL']['lanes'][$i] === 'P' && $res['NO-BIND']['dec'][$i] !== 'A') {
                $detector++;
            }
        }
        g4Emit('fx', ['fixture' => $fx, 'arm' => 'FULL+NO-BIND', 'perDay' => $perDay, 'fullBoundRefused' => $boundRefused,
            'noBindRefusedWhereFullBound' => $detector, 'fullIssued' => $res['FULL']['issued'],
            'fullCauses' => $res['FULL']['legitDeniedByCause'], 'noBindCauses' => $res['NO-BIND']['legitDeniedByCause'],
            'fullAttackReach' => substr_count(implode('', array_map(static fn(int $i): string => $rows[$i]['kind'] === 'X' ? $res['FULL']['reach'][$i] : '', array_keys($rows))), '1')]);
    }
}
