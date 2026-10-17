<?php

declare(strict_types=1);

// G1 targeted step X02 — proven-owner effect isolation per DA arm (DA_T1_PLAN.md §1.2, N3). Runs on G1_BUILD=TRACE (CAND),
// DAM (CAND-DA-M) or DAO (CAND-no-DA-punishment). MX: the addendum's mixed-account fixture (same code as v02, incl. the secret
// rotation) with its U-family decomposition. MXE: proof expiry on the SAME device (P 0–600 s, U 600–1500 s, P again 1500–2400 s)
// while other unknown devices fail on the account; control without the other devices. Owner denials carry trace origins.

require dirname(__DIR__) . '/lib/verify.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

$prov = g1Provenance();
if (! in_array($prov['build'], ['TRACE', 'DAM', 'DAO'], true)) {
    fwrite(STDERR, "x02 must run on the TRACE, DAM or DAO build\n");
    exit(4);
}
$arm = ['TRACE' => 'CAND', 'DAM' => 'CAND-DA-M', 'DAO' => 'CAND-no-DA-punishment'][$prov['build']];
$p = g1Params('R');
$row = static fn(int $t, RateLimitContextDTO $c, ?string $proof, string $kind, string $out): array =>
    ['t' => $t, 'ctx' => $c, 'proof' => $proof, 'kind' => $kind, 'own' => false, 'who' => $kind, 'outcome' => $out];
$ownerOf = static function (array ...$rs): array {
    $o = '';
    $origins = [];
    foreach ($rs as $x) {
        for ($i = 0, $n = strlen($x['kinds']); $i < $n; $i++) {
            $o .= $x['kinds'][$i] === 'O' ? $x['dec'][$i] : '';
        }
        foreach ($x['benignOrigins'] as $k => $v) {
            $origins[$k] = ($origins[$k] ?? 0) + $v;
        }
    }
    ksort($origins);

    return [$o, $origins];
};
$population = static fn(array $origins): int => array_sum(array_filter($origins, static fn(string $k): bool =>
    str_contains($k, 'distributed_account_attack') || str_contains($k, 'flood'), ARRAY_FILTER_USE_KEY));

// ---- MX (as v02) ----
$acct = 'acct-mx';
$ctx = static fn(string $ip, string $ua, ?string $sid, bool $trusted, bool $verified): RateLimitContextDTO =>
    new RateLimitContextDTO($ip, $ua, $acct, null, $sid, $trusted, [], $verified, null);
$owner = $ctx('198.51.100.150', FIREFOX_130, 'sd-mx-owner', true, false);
$phase = static function (int $from, int $to, bool $withU, array $variants) use ($ctx, $owner, $acct, $row): array {
    $rows = [];
    $n = 0;
    for ($t = $from; $t < $to; $t += 20, $n++) {
        if ($t % 120 === 0) {
            $rows[] = $row($t, $owner, $acct, 'O', intdiv($t, 120) % 3 === 2 ? 'ok' : 'fail');
        }
        if (! $withU || $t < $from + 20 || ! in_array($n % 4, $variants, true)) {
            continue;
        }
        $u = match ($n % 4) {
            0 => $ctx('192.0.2.' . (10 + $n % 20), k1Ua(100 + $n % 20), null, false, false),
            1 => $ctx('198.51.100.150', FIREFOX_130, null, false, true),
            2 => $ctx('203.0.113.' . (10 + $n % 7), FIREFOX_130, 'sd-mx-owner', false, false),
            default => $ctx('198.18.0.' . (1 + $n % 9), CHROME, 'sess-other', false, false),
        };
        $rows[] = $row($t + 7, $u, null, 'X', 'fail');
    }

    return $rows;
};
$runMx = static function (bool $withU, array $variants) use ($p, $phase): array {
    $cell = new G1Cell('CAND', 'login', $p);
    $r1 = g1VReplay($cell, $phase(0, 1800, $withU, $variants), 'pre+out', ['trace' => true, 'c9' => true]);
    $file = (string) tempnam(sys_get_temp_dir(), 'g1x');
    g1SaveState($cell, $file);
    $rot = new G1Cell('CAND', 'login', $p, gen: 'g1-secret-2', prevGen: 'g1-secret-current', state: g1LoadState($file),
        previousSecret: Lab::SECRET, secret: 'lab-secret-2');
    @unlink($file);
    $rot->pAccounts = $cell->pAccounts;
    $rot->accounts = $cell->accounts;
    $r2 = g1VReplay($rot, $phase(1800, 3000, $withU, $variants), 'pre+out', ['trace' => true, 'c9' => true, 'secrets' => g1VSecrets(Lab::SECRET, 'lab-secret-2')]);

    return [$r1, $r2];
};
[$cr1, $cr2] = $runMx(false, []);
[$ctl, $ctlOrigins] = $ownerOf($cr1, $cr2);
foreach ([[0], [1], [2], [3], [0, 1, 3], [0, 2], [1, 2], [2, 3], [0, 1, 2, 3]] as $vs) {
    [$r1, $r2] = $runMx(true, $vs);
    [$o, $origins] = $ownerOf($r1, $r2);
    g1VEmit('x02', ['arm' => $arm, 'fixture' => 'MX', 'variants' => implode('', $vs), 'presentsOwnerSecret' => in_array(2, $vs, true),
        'ownerDecisions' => $o, 'controlDecisions' => $ctl, 'identicalToControl' => $o === $ctl, 'ownerHard' => substr_count($o, 'H'),
        'controlHard' => substr_count($ctl, 'H'), 'ownerOrigins' => $origins, 'controlOrigins' => $ctlOrigins,
        'populationOriginDenials' => $population($origins) - $population($ctlOrigins),
        'c9Violations' => count($r1['c9']['violations']) + count($r2['c9']['violations'])]);
}

// ---- MXE: proof expiry on the same device ----
$acct = 'acct-mxe';
$dev = static fn(bool $proven): RateLimitContextDTO => new RateLimitContextDTO('198.51.100.160', FIREFOX_130, 'acct-mxe', null, 'sd-mxe-owner', $proven, [], false, null);
$mxe = static function (bool $others) use ($dev, $row): array {
    $rows = [];
    for ($t = 0, $n = 0; $t < 2400; $t += 20, $n++) {
        if ($t % 120 === 0) {
            $proven = $t < 600 || $t >= 1500;
            $rows[] = $row($t, $dev($proven), $proven ? 'acct-mxe' : null, 'O', $n % 3 === 0 ? 'fail' : 'ok');
        }
        if ($others && $t > 0 && $t % 20 === 0) {
            $rows[] = $row($t + 7, new RateLimitContextDTO('192.0.2.' . (60 + $n % 25), k1Ua(100 + $n % 25), 'acct-mxe', null, null, false, [], false, null),
                null, 'X', 'fail');
        }
    }

    return $rows;
};
$res = [];
foreach (['with other devices' => true, 'control' => false] as $label => $others) {
    $rows = $mxe($others);
    $cell = new G1Cell('CAND', 'login', $p);
    $r = g1VReplay($cell, $rows, 'pre+out', ['trace' => true, 'c9' => true]);
    $phases = ['A (P)' => '', 'B (same device, U)' => '', 'C (P again)' => ''];
    $lanes = '';
    foreach ($rows as $i => $x) {
        if ($x['kind'] !== 'O') {
            continue;
        }
        $ph = $x['t'] < 600 ? 'A (P)' : ($x['t'] < 1500 ? 'B (same device, U)' : 'C (P again)');
        $phases[$ph] .= $r['dec'][$i];
        $lanes .= $r['lanes'][$i];
    }
    $res[$label] = ['phases' => $phases, 'origins' => $r['benignOrigins'], 'ownerLanes' => $lanes, 'c9' => count($r['c9']['violations']),
        'attackerReach' => substr_count(implode('', array_map(static fn(int $i): string => $r['kinds'][$i] === 'X' ? $r['reach'][$i] : '', array_keys($rows))), '1')];
}
g1VEmit('x02', ['arm' => $arm, 'fixture' => 'MXE', 'withOthers' => $res['with other devices'], 'control' => $res['control'],
    'phaseCIdenticalToControl' => $res['with other devices']['phases']['C (P again)'] === $res['control']['phases']['C (P again)'],
    'phaseBIdenticalToControl' => $res['with other devices']['phases']['B (same device, U)'] === $res['control']['phases']['B (same device, U)'],
    'populationOriginDenials' => $population($res['with other devices']['origins']) - $population($res['control']['origins'])]);
