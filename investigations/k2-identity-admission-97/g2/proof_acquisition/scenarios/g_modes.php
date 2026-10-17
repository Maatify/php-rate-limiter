<?php

declare(strict_types=1);

// G2 section G (PLAN.md §4–§5 G): host integration modes on the REAL G1 candidate pipeline (DAOF build, unchanged).
// Run with G1_ARM=CAND G1_BUILD=DAOF LAB_ROOT=<results/work/daof>.

require dirname(__DIR__, 3) . '/g1/verify/lib/verify.php';
require_once dirname(__DIR__) . '/lib/proof.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

$prov = g1Provenance();
if ($prov['build'] !== 'DAOF') {
    fwrite(STDERR, "g_modes must run on the DAOF build\n");
    exit(4);
}
$p = g1Params('R');
$acct = 'acct-g';
$tenant = 'tenant-a';
$dev = 'sd-g-new';
$ownerIp = '203.0.113.60';

foreach (['PA:pre', 'PA:pre+out', 'PA:out', 'NOPROOF:pre+out'] as $case) {
    [$armName, $flow] = explode(':', $case);
    $h = new G2Host(g2Arm($armName), new G2RefPathStore());
    [$sk, $cred] = $h->register($tenant, $acct);
    $bd = new G2Boundary($h);
    $token = null;
    if ($armName === 'PA') {
        $c = (string) $h->challenge(1234, 'owner', $ownerIp, $tenant, $acct, 'login')['challenge'];
        $token = $h->assert(
            1239,
            'owner',
            $ownerIp,
            $tenant,
            $acct,
            'login',
            $c,
            $cred,
            sodium_crypto_sign_detached(G2Host::signedMessage($tenant, $c), $sk),
            $dev,
        )['token'];
    }
    $rows = [];
    for ($t = 0, $n = 0; $t < 3600; $t += 15, $n++) {
        $rows[] = ['t' => $t, 'ctx' => new RateLimitContextDTO('198.18.0.' . (1 + $n % 30), k1Ua(100 + $n % 30), $acct, null, null, false, [], false, null),
            'proof' => null, 'kind' => 'X', 'own' => false, 'who' => 'X', 'outcome' => 'fail'];
    }
    foreach ([1230, 1250, 1370, 1490] as $t) {
        $proven = $bd->lane($t, $tenant, $acct, 'login', $dev, $token)['lane'] === 'P';
        $rows[] = ['t' => $t + 1, 'ctx' => new RateLimitContextDTO($ownerIp, FIREFOX_130, $acct, null, $dev, false, [], false, null),
            'proof' => $proven ? $acct : null, 'kind' => 'O', 'own' => false, 'who' => 'O', 'outcome' => 'ok'];
    }
    usort($rows, static fn(array $a, array $b): int => $a['t'] <=> $b['t']);
    $cell = new G1Cell('CAND', 'login', $p);
    $r = g1VReplay($cell, $rows, $flow, ['trace' => true]);
    $perWindow = [];
    $owner = '';
    $ownerLanes = '';
    foreach ($rows as $i => $x) {
        if ($x['kind'] === 'X' && $r['reach'][$i] === '1') {
            $perWindow[intdiv($x['t'], $p['W'])] = ($perWindow[intdiv($x['t'], $p['W'])] ?? 0) + 1;
        }
        if ($x['kind'] === 'O') {
            $owner .= $r['dec'][$i];
            $ownerLanes .= $r['lanes'][$i];
        }
    }
    echo json_encode(['s' => 'g', 'arm' => $armName, 'flow' => $flow, 'attackAttempts' => 240, 'attackerReach' => array_sum($perWindow),
        'attackerReachMaxWindow' => $perWindow === [] ? 0 : max($perWindow), 'ownerDecisions' => $owner, 'ownerLanes' => $ownerLanes,
        'ownerProofAcquired' => $token !== null], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
}
