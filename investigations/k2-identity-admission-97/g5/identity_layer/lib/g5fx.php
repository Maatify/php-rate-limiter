<?php

declare(strict_types=1);

// G5 assembled-fixture builders shared by g5_fixtures.php and the post-hoc scenario (the G4 v2 builders, ported unchanged, and the
// STEAL row of PLAN.md §4.1).

require_once __DIR__ . '/g5.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

/** HAR (G1 v07), as the archived g4v2Har(). */
function g5Har(bool $bound): array
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

/** STEAL up to 43,200 s, as the archived g4v2StealPre(). */
function g5StealPre(string $arm, RateLimitContextDTO $ownerCtx): array
{
    [$cell, $gate, $host] = g5Cell($arm);
    $idm = $arm !== 'REF';
    $pre = g4v2Sort(array_merge(
        [g4v2Issue(0, 'owner', 'acct-st', 'i:st'), ['t' => 600, 'event' => static fn(G4V2Host $h, int $now) => $h->copyJar('owner', 'thief', 'i:st')]],
        array_map(static fn(int $t): array => g4v2Row1($t, $ownerCtx, 'O', 'owner', 'ok', 'i:st', 'owner'), range(60, 43199, 7200)),
        array_map(static fn(int $t): array => g4v2Row1($t, g3Attacker(intdiv($t, 15), 'acct-st'), 'X', 'thief', 'fail', 'i:st', 'thief'), range(615, 43199, 15)),
    ));
    $win = [];
    $day = 0;
    $ownerDen = 0;
    foreach ($pre as $row) {
        if (isset($row['event'])) {
            g5Replay($cell, $gate, $host, [$row], 'pre+out', $idm);
            continue;
        }
        $s = g5Step($cell, $gate, $host, $row, $idm);
        if ($row['holder'] === 'thief' && $s['lane'] === 'P' && $s['reach'] === '1') {
            $win[intdiv($row['t'], 600)] = ($win[intdiv($row['t'], 600)] ?? 0) + 1;
            $day++;
        }
        $ownerDen += $row['holder'] === 'owner' && $s['d'] !== 'A' ? 1 : 0;
    }

    return [$cell, $gate, $host, $win, $day, $ownerDen];
}

/** E2 / K5U owner rows, as the archived g4v2OwnerRows(). */
function g5OwnerRows(RateLimitContextDTO $oc, string $who, string $inst, string $holder, bool $uFails = true): array
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

/** Owner decisions / lanes / times of a replay, owner rows only (as the archived g4v2Owner()). */
function g5Owner(array $rows, array $r): array
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

/** BOOT / BOOT-X rows as the archived $boot closure. */
function g5Boot(bool $spray): array
{
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
}

/** The STEAL row (password-change variant) for an arm, as the archived `fx` row of FULL2. @return array<string, mixed> */
function g5Steal(string $arm): array
{
    $idm = $arm !== 'REF';
    $ownerCtx = new RateLimitContextDTO('198.51.100.141', FIREFOX_130, 'acct-st', null, null, false, [], false, null);
    [$cell, $gate, $host, $win, $day, $ownerDen] = g5StealPre($arm, $ownerCtx);
    g5Replay($cell, $gate, $host, [['t' => 43200, 'event' => static fn(G4V2Host $h, int $now) => $h->passwordChange('owner', 'acct-st', 'i:st', $now)]], 'pre+out', $idm);
    $afterP = 0;
    $afterU = 0;
    $ownerWait = null;
    $ownerLane = null;
    $ownerCauses = [];
    $next = 43260;
    for ($t = 43215; $t < 86400; $t += 15) {
        $s = g5Step($cell, $gate, $host, g4v2Row1($t, g3Attacker(intdiv($t, 15), 'acct-st'), 'X', 'thief', 'fail', 'i:st', 'thief'), $idm);
        if ($s['lane'] === 'P' && $s['reach'] === '1') {
            $afterP++;
            $win[intdiv($t, 600)] = ($win[intdiv($t, 600)] ?? 0) + 1;
        }
        $afterU += $s['lane'] === 'U' && $s['reach'] === '1' ? 1 : 0;
        if ($ownerWait === null && $next === $t) {
            $s = g5Step($cell, $gate, $host, g4v2Row1($next, $ownerCtx, 'O', 'owner', 'ok', 'i:st', 'owner'), $idm);
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

    return ['fixture' => 'STEAL', 'arm' => $arm, 'thiefPMaxPerWindow' => $win === [] ? 0 : max($win), 'thiefPBeforeChange' => $day,
        'thiefPAfterChange' => $afterP, 'thiefUAfterChange' => $afterU, 'ownerDeniedBeforeChange' => $ownerDen,
        'ownerWaitAfterChange' => $ownerWait, 'ownerLaneAtAdmission' => $ownerLane, 'ownerDeniedAfterChangeByCause' => $ownerCauses,
        'pwBinds' => $host->pwBinds];
}
