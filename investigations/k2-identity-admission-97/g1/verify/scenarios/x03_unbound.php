<?php

declare(strict_types=1);

// G1 targeted step X03 — DIAGNOSTIC, added after the pre-declared X01 (disclosed in G1_DA_T1.md): with R, U_acct = 4 binds before
// the distributed-account rule can act, so X01 cannot show what the DA punishment protects when the account cap does not bind.
// Same comparison (TRACE = CAND vs DAO = CAND-no-DA-punishment) on the sweep sets Ua16-s40 and Uaoff-s40 (and R as anchor), for
// single-target multi-device attacks and the affected benign streams, plus HAR / HARp of the sweep.

require dirname(__DIR__) . '/lib/verify.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

$prov = g1Provenance();
if (! in_array($prov['build'], ['TRACE', 'DAO'], true)) {
    fwrite(STDERR, "x03 must run on the TRACE or DAO build\n");
    exit(4);
}
$variant = $prov['build'] === 'TRACE' ? 'CAND' : 'CAND-no-DA-punishment';
$har = static function (bool $proven): array {
    $rows = [];
    $owner = new RateLimitContextDTO('198.51.100.140', FIREFOX_130, 'acct-har', null, $proven ? 'sd-har-owner' : null, $proven, [], false, null);
    for ($t = 0; $t < 1800; $t += 120) {
        $rows[] = ['t' => $t, 'ctx' => $owner, 'proof' => $proven ? 'acct-har' : null, 'kind' => 'O', 'own' => false, 'who' => 'owner', 'outcome' => 'ok'];
    }
    for ($t = 5, $n = 0; $t < 1800; $t += 10, $n++) {
        $rows[] = ['t' => $t, 'ctx' => new RateLimitContextDTO('192.0.2.' . (150 + $n % 30), CHROME, 'acct-har', null, null, false, [], false, null),
            'proof' => null, 'kind' => 'X', 'own' => false, 'who' => 'atk', 'outcome' => 'fail'];
    }
    usort($rows, static fn(array $a, array $b): int => $a['t'] <=> $b['t']);

    return $rows;
};
$sets = ['R' => g1Params('R'), 'Ua16-s40' => ['U_acct' => 16, 'U_src' => 40] + g1Params('R'), 'Uaoff-s40' => ['U_acct' => 1000000, 'U_src' => 40] + g1Params('R')];
$streams = ['BRD', 'BRU', 'X1', 'X7', 'POI', 'ABA_A', 'SP6', 'KD', 'UAC', 'L1', 'D2', 'MIX', 'ABA_B', 'N12'];
foreach ($sets as $set => $p) {
    $cases = [];
    foreach ($streams as $id) {
        foreach (g1Strategies($id) as $strategy) {
            $cases[] = [$id, g1Streams()[$id]['cls'], $strategy, g1Materialise($id, 'login', $strategy)];
        }
    }
    $cases[] = ['HAR', 'both', 'none', $har(false)];
    $cases[] = ['HARp', 'both', 'none', $har(true)];
    foreach ($cases as [$id, $cls, $strategy, $rows]) {
        foreach (['pre+out', 'pre'] as $flow) {
            $cell = new G1Cell('CAND', 'login', $p);
            $r = g1VReplay($cell, $rows, $flow, ['trace' => true]);
            g1VEmit('x03', ['variant' => $variant, 'stream' => $id, 'cls' => $cls, 'strategy' => $strategy, 'set' => $set, 'flow' => $flow,
                'm' => g1Metrics($r['kinds'], $r['lanes'], $r['reach'], $r['dec'], array_map(static fn(array $x): int => $x['t'], $rows), $p['W']),
                'target' => g1VTargetReach($rows, $r['kinds'], $r['reach'], $p['W']),
                'benignOrigins' => $r['benignOrigins'], 'attackOrigins' => $r['attackOrigins'], 'dec' => $r['dec'], 'reach' => $r['reach']]);
        }
    }
}
