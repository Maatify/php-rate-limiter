<?php

declare(strict_types=1);

// G1 verification V07 — the pre-declared, limited U_acct sweep (VERIFY_PLAN.md §3, F126-G1-04). Nothing is tuned or selected.
// Candidate runtime: U_acct {4, 8, 16, 32, off} x U_src {12, 40}, arms CAND and ALLU (= Login day-0, no proof honoured), flow
// pre+out, the archived streams of the plan plus the new harassment fixtures HAR / HARp. S0 runtime: HAR / HARp only (the other
// S0 references are the archived ../../expected/m01_matrix.S0.jsonl rows).

require dirname(__DIR__) . '/lib/verify.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

$prov = g1Provenance();
if ($prov['build'] !== 'CAND' && $prov['build'] !== 'S0') {
    fwrite(STDERR, "v07 must run on the candidate build or S0\n");
    exit(4);
}

/** HAR / HARp (VERIFY_PLAN.md §3): owner every 120 s (ok), attacker fails every 10 s from 30 rotating sources, 1800 s. */
function g1VHar(bool $proven): array
{
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
}

$emit = static function (string $id, string $cls, string $strategy, string $arm, string $set, array $p, array $rows, array $r): void {
    $ts = array_map(static fn(array $x): int => $x['t'], $rows);
    g1VEmit('v07', ['stream' => $id, 'cls' => $cls, 'strategy' => $strategy, 'arm' => $arm, 'set' => $set,
        'U_acct' => $p['U_acct'] ?? null, 'U_src' => $p['U_src'] ?? null,
        'm' => g1Metrics($r['kinds'], $r['lanes'], $r['reach'], $r['dec'], $ts, $p['W'] ?? 600),
        'target' => g1VTargetReach($rows, $r['kinds'], $r['reach'], $p['W'] ?? 600),
        'legitDeniedByCause' => $r['legitDeniedByCause'], 'dec' => $r['dec'], 'reach' => $r['reach'], 'kinds' => $r['kinds'], 'lanes' => $r['lanes']]);
};
$har = ['HAR' => g1VHar(false), 'HARp' => g1VHar(true)];

if ($prov['build'] === 'S0') {
    foreach ($har as $id => $rows) {
        $cell = new G1Cell('S0', 'login', g1Params('R'));
        $emit($id, 'both', 'none', 'S0', 'S0', g1Params('R'), $rows, g1VReplay($cell, $rows, 'pre+out'));
    }
    exit(0);
}

const G1V_SWEEP_STREAMS = ['L1', 'ABA_B', 'D2', 'N12', 'Nu4', 'Y6b', 'KD', 'UAC', 'MIX', 'OFF12', 'POI', 'L3', 'D1',
    'SP6', 'SP3', 'SP1n', 'ABA_A', 'X2', 'X1', 'X7', 'BRU', 'BRD'];
$sets = [];
foreach ([4, 8, 16, 32, 'off'] as $ua) {
    foreach ([12, 40] as $us) {
        $sets["Ua$ua-s$us"] = ['U_acct' => $ua === 'off' ? 1000000 : $ua, 'U_src' => $us] + g1Params('R');
    }
}
foreach (['CAND', 'ALLU'] as $arm) {
    foreach ($sets as $set => $p) {
        foreach (G1V_SWEEP_STREAMS as $id) {
            $s = g1Streams()[$id];
            foreach (g1Strategies($id) as $strategy) {
                $rows = g1Materialise($id, 'login', $strategy);
                $emit($id, $s['cls'], $strategy, $arm, $set, $p, $rows, g1VReplay(new G1Cell($arm, 'login', $p), $rows, 'pre+out'));
            }
        }
        foreach ($har as $id => $rows) {
            $emit($id, 'both', 'none', $arm, $set, $p, $rows, g1VReplay(new G1Cell($arm, 'login', $p), $rows, 'pre+out'));
        }
    }
}
