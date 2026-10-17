<?php

declare(strict_types=1);

// G2 section D (PLAN.md §4–§5 D): does a new identity, source, device, proof, renewal or own account create capacity?

require dirname(__DIR__) . '/lib/sim.php';

$emit = static function (array $row): void {
    echo json_encode(['s' => 'd'] + $row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
};
$p = g1Params('R');
$W = $p['W'];
$subject = G2_TENANT . '|' . G2_VICTIM;

/** @param array<int, int> $perWindow */
$maxOf = static fn(array $perWindow): int => $perWindow === [] ? 0 : max($perWindow);

// ---- D-cid / D-ip / D-dev: U on the victim, 6 windows, one attempt every 3 s ----
foreach (['D-cid' => [1, 100], 'D-ip' => [1, 30], 'D-dev' => [1, 50]] as $fixture => $ns) {
    foreach ($ns as $n) {
        $adm = new G1Admission(new G1RefStore(), $p, 'login');
        $host = new G2Host(g2Arm('PA'), new G2RefPathStore());
        $host->register(G2_TENANT, G2_VICTIM);
        $bd = new G2Boundary($host);
        $perWindow = [];
        $lanes = [];
        for ($t = 0, $i = 0; $t < 3600; $t += 3, $i++) {
            $src = $fixture === 'D-ip' ? g2Src($i, $n) : '198.18.0.1';
            $dev = $fixture === 'D-dev' ? 'atk-dev-' . ($i % $n) : 'atk-dev-0';
            $claim = $fixture === 'D-cid' ? 'cid-' . ($i % $n) : null;
            $l = $bd->lane($t, G2_TENANT, G2_VICTIM, 'login', $dev, $claim);
            $lanes[$l['lane']] = ($lanes[$l['lane']] ?? 0) + 1;
            if ($adm->reserve('U', $src, $subject, null, null, $t)['ok']) {
                $perWindow[intdiv($t, $W)] = ($perWindow[intdiv($t, $W)] ?? 0) + 1;
            }
        }
        $emit(['fixture' => $fixture, 'n' => $n, 'lanes' => $lanes, 'reachForeign' => array_sum($perWindow), 'reachForeignMaxWindow' => $maxOf($perWindow)]);
    }
}

// ---- D-stolen: the attacker holds the owner's private key ----
foreach ([3600, 86400] as $T) {
    foreach (['one', 'renew', 'renew-dev'] as $mode) {
        $adm = new G1Admission(new G1RefStore(), $p, 'login');
        $host = new G2Host(g2Arm('PA'), new G2RefPathStore());
        [$sk, $cred] = $host->register(G2_TENANT, G2_VICTIM);
        $bd = new G2Boundary($host);
        $token = null;
        $perWindow = [];
        $perDay = [];
        $refused = [];
        $devices = [];
        $acquire = static function (int $t, string $src, string $dev) use ($host, $sk, $cred): ?string {
            $c = $host->challenge($t, 'thief', $src, G2_TENANT, G2_VICTIM, 'login')['challenge'];
            if ($c === null) {
                return null;
            }

            return $host->assert(
                $t,
                'thief',
                $src,
                G2_TENANT,
                G2_VICTIM,
                'login',
                $c,
                $cred,
                sodium_crypto_sign_detached(G2Host::signedMessage(G2_TENANT, $c), $sk),
                $dev,
            )['token'];
        };
        for ($t = 0, $i = 0; $t < $T; $t += 15, $i++) {
            $src = g2Src($i, 30, '198.20');
            $dev = $mode === 'renew-dev' ? 'thief-dev-' . $i : 'thief-dev';
            if ($mode !== 'one' || $token === null || $bd->lane($t, G2_TENANT, G2_VICTIM, 'login', $dev, $token)['lane'] !== 'P') {
                $token = $acquire($t, $src, $dev);
            }
            $l = $bd->lane($t, G2_TENANT, G2_VICTIM, 'login', $dev, $token);
            if ($l['lane'] !== 'P') {
                $refused['no-proof'] = ($refused['no-proof'] ?? 0) + 1;
                continue;
            }
            $r = $adm->reserve('P', $src, $subject, $l['jti'], $dev, $t);
            if (! $r['ok']) {
                $refused[(string) $r['refused']] = ($refused[(string) $r['refused']] ?? 0) + 1;
                continue;
            }
            $devices[$dev] = true;
            $perWindow[intdiv($t, $W)] = ($perWindow[intdiv($t, $W)] ?? 0) + 1;
            $perDay[intdiv($t, 86400)] = ($perDay[intdiv($t, 86400)] ?? 0) + 1;
        }
        ksort($refused);
        $emit(['fixture' => 'D-stolen', 'mode' => $mode, 'T' => $T, 'attempts' => intdiv($T, 15), 'reachProof' => array_sum($perWindow),
            'reachProofMaxWindow' => $maxOf($perWindow), 'reachProofMaxDay' => $maxOf($perDay), 'admittedDevices' => count($devices),
            'refusedBy' => $refused, 'proofsMinted' => $host->stats['thief']['proofsMinted'] ?? 0,
            'sigVerify' => $host->stats['thief']['sigVerify'] ?? 0]);
    }
}

// ---- D-own: 1 vs 20 attacker-owned accounts (renewing proofs) plus the U attack on the victim, 1 h ----
foreach ([1, 20] as $m) {
    $adm = new G1Admission(new G1RefStore(), $p, 'login');
    $store = new G2RefPathStore();
    $host = new G2Host(g2Arm('PA'), $store);
    $bd = new G2Boundary($host);
    $keys = [];
    for ($j = 0; $j < $m; $j++) {
        $keys['acct-x' . $j] = $host->register(G2_TENANT, 'acct-x' . $j);
    }
    $own = [];
    $foreign = [];
    $verifier = 0;
    for ($t = 0; $t < 3600; $t++) {
        if ($t % 3 === 0 && $adm->reserve('U', g2Src(intdiv($t, 3), 30), $subject, null, null, $t)['ok']) {
            $foreign[intdiv($t, $W)] = ($foreign[intdiv($t, $W)] ?? 0) + 1;
            $verifier++;
        }
        if ($t % 15 !== 0) {
            continue;
        }
        foreach ($keys as $acct => [$sk, $cred]) {
            $src = g2Src(crc32($acct) + $t, 30, '198.21');
            $c = (string) $host->challenge($t, 'attacker', $src, G2_TENANT, $acct, 'login')['challenge'];
            $tok = $host->assert(
                $t,
                'attacker',
                $src,
                G2_TENANT,
                $acct,
                'login',
                $c,
                $cred,
                sodium_crypto_sign_detached(G2Host::signedMessage(G2_TENANT, $c), $sk),
                'own-dev-' . $acct,
            )['token'];
            $l = $bd->lane($t, G2_TENANT, $acct, 'login', 'own-dev-' . $acct, $tok);
            if ($l['lane'] === 'P' && $adm->reserve('P', $src, G2_TENANT . '|' . $acct, $l['jti'], 'own-dev-' . $acct, $t)['ok']) {
                $own[$acct][intdiv($t, $W)] = ($own[$acct][intdiv($t, $W)] ?? 0) + 1;
                $verifier++;
            }
        }
    }
    $ownMax = 0;
    $ownTotal = 0;
    foreach ($own as $pw) {
        $ownMax = max($ownMax, $maxOf($pw));
        $ownTotal += array_sum($pw);
    }
    $st = $host->stats['attacker'] ?? [];
    $emit(['fixture' => 'D-own', 'm' => $m, 'reachOwn' => $ownTotal, 'reachOwnMaxPerAccountWindow' => $ownMax,
        'reachForeign' => array_sum($foreign), 'reachForeignMaxWindow' => $maxOf($foreign), 'verifierCallsTotal' => $verifier,
        'proofAcquisitionOps' => ['challenges' => $st['challenge:ok'] ?? 0, 'sigVerify' => $st['sigVerify'] ?? 0, 'proofsMinted' => $st['proofsMinted'] ?? 0],
        'pathStoreWrites' => $store->writes, 'admissionStoreWrites' => $adm->store instanceof G1RefStore ? $adm->store->writes : null]);
}
