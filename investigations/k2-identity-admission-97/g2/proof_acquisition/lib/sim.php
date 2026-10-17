<?php

declare(strict_types=1);

// G2 proof acquisition (#97) — timeline driver for sections A and B (PLAN.md §4). EVIDENCE ONLY.
// U and P admission use the G1 admission layer unchanged (g1/lib/admission.php, parameter set R). The proof path is the
// Host model of proof.php. One run = one owner arrival from clean state; within a second the attacker acts first.

require_once dirname(__DIR__, 3) . '/g1/lib/admission.php';
require_once __DIR__ . '/proof.php';

const G2_TENANT = 'tenant-a';
const G2_VICTIM = 'acct-v';
const G2_OWNER_SRC = '203.0.113.50';
const G2_OWNER_SRC2 = '100.64.7.9';
const G2_ARRIVALS = [1, 300, 599, 615, 900, 1199, 1234, 1815, 2101, 2415, 2999, 3300];

/** @return list<int> ACQ-LONG arrivals (PLAN.md §4) */
function g2LongArrivals(): array
{
    return array_map(static fn(int $k): int => 1 + 1199 * $k, range(0, 11));
}

function g2Src(int $i, int $n, string $prefix = '198.18'): string
{
    $i %= $n;

    return $prefix . '.' . intdiv($i, 250) . '.' . ($i % 250 + 1);
}

/**
 * One owner arrival under the ACQ family of fixtures.
 *
 * @param array{fixture: string, T: int, x: int, nSrc: int, coloc: bool, glob: bool} $fx
 * @return array<string, mixed>
 */
function g2RunAcq(string $armName, array $fx, int $arrival): array
{
    $arm = g2Arm($armName);
    mt_srand(97 + $arrival);
    $p = g1Params('R');
    $adm = new G1Admission(new G1RefStore(), $p, 'login');
    $v = G2_VALUES;
    if ($fx['glob']) {
        $v['PV_glob'] = 200;
        $v['ISS_glob'] = 100;
    }
    $store = new G2RefPathStore();
    $chargeU = static fn(string $src, string $subject, int $t): bool => $adm->reserve('U', $src, $subject, null, null, $t)['ok'];
    $host = new G2Host($arm, $store, $v, \Closure::fromCallable($chargeU));
    $bd = new G2Boundary($host, $arm['bind']);
    $subject = G2_TENANT . '|' . G2_VICTIM;
    [$ownerSk, $ownerCred] = $host->register(G2_TENANT, G2_VICTIM);
    [$atkSk] = g2Keypair();
    $W = $p['W'];

    $atk = ['uAdmissions' => 0, 'verifier' => 0, 'pAdmissions' => 0, 'perWindow' => [], 'issueAttempts' => [], 'codeHits' => 0];
    $own = ['done' => false, 'admitted' => false, 'lane' => null, 'at' => null, 'requests' => 0, 'refusals' => [],
        'acquired' => false, 'issueRefusedLinkPresent' => 0, 'uAdmissions' => 0, 'verifier' => 0, 'retries' => 0];
    $device = 'owner-dev-' . $arrival;
    // COLOC: the attacker's whole proof-path flood comes from the owner's (first) source
    $colocSrc = '198.19.0.1';
    $src = $fx['coloc'] ? $colocSrc : G2_OWNER_SRC;
    $plan = [$arrival => 'U'];
    $challenge = null;
    $link = null;
    $token = null;
    $fail = static function (int $t, string $why) use (&$own, &$plan, &$src, $fx): void {
        $own['refusals'][] = $why;
        if ($own['retries'] >= 1) {
            $own['done'] = true;

            return;
        }
        $own['retries']++;
        if ($fx['coloc']) {
            $src = G2_OWNER_SRC2;
        }
        $plan[$t + 60] = 'ACQ';
    };
    $pAttempt = static function (int $t) use (&$own, &$token, $bd, $adm, $subject, $device, &$src, $fail): void {
        $own['requests']++;
        $l = $bd->lane($t, G2_TENANT, G2_VICTIM, 'login', $device, $token);
        $r = $l['lane'] === 'P'
            ? $adm->reserve('P', $src, $subject, $l['jti'], $device, $t)
            : $adm->reserve('U', $src, $subject, null, null, $t);
        if (! $r['ok']) {
            $fail($t, 'P-attempt:' . $l['lane'] . ':cap:' . $r['refused']);

            return;
        }
        $own['verifier']++;
        $own['uAdmissions'] += $l['lane'] === 'U' ? 1 : 0;
        $own['admitted'] = true;
        $own['lane'] = $l['lane'];
        $own['at'] = $t;
        $own['done'] = true;
    };

    for ($t = 0; $t < $fx['T']; $t++) {
        // ---- attacker ----
        if ($t % 3 === 0) {
            $i = intdiv($t, 3);
            if ($adm->reserve('U', g2Src($i, 30), $subject, null, null, $t)['ok']) {
                $atk['uAdmissions']++;
                $atk['verifier']++;
                $w = intdiv($t, $W);
                $atk['perWindow'][$w] = ($atk['perWindow'][$w] ?? 0) + 1;
            }
        }
        $pSrc = static fn(int $k): string => $fx['coloc'] ? $colocSrc : g2Src($t * 7 + $k, $fx['nSrc'], '198.19');
        if ($arm['proof'] === 'A') {
            for ($k = 0; $k < $fx['x']; $k++) {
                if ($t % 2 === 0) {
                    $host->challenge($t, 'attacker', $pSrc($k), G2_TENANT, G2_VICTIM, 'login');
                }
                if ($t % 3 === 0) {
                    $host->challenge($t, 'attacker', $pSrc($k + 1), G2_TENANT, G2_VICTIM, $t % 6 === 0 ? 'otp' : 'recovery');
                }
                if ($t % 5 === 0) {
                    $c = $host->challenge($t, 'attacker', $pSrc($k + 2), G2_TENANT, G2_VICTIM, 'login')['challenge'];
                    if ($c !== null) {
                        $sig = sodium_crypto_sign_detached(G2Host::signedMessage(G2_TENANT, $c), $atkSk);
                        $host->assert($t, 'attacker', $pSrc($k + 2), G2_TENANT, G2_VICTIM, 'login', $c, $ownerCred, $sig, 'atk-dev-' . $t . '-' . $k);
                    }
                }
            }
        } elseif ($arm['proof'] === 'B' && $t % 5 === 0) {
            for ($k = 0; $k < $fx['x']; $k++) {
                if ($fx['glob']) {
                    $host->issue($t, 'attacker', $pSrc($k), G2_TENANT, 'acct-r' . ($t * 10 + $k), 'login', 'atk-dev');
                } else {
                    $iw = intdiv($t, $v['W_iss']);
                    $n = $atk['issueAttempts'][$iw] = ($atk['issueAttempts'][$iw] ?? 0) + 1;
                    $host->issue($t, 'attacker', $pSrc($k), G2_TENANT, G2_VICTIM, $n <= 3 ? 'recovery' : 'login', 'atk-dev');
                }
                $guess = $arm['short'] ? sprintf('%06d', ($t * 7919 + $k * 104729) % 1000000) : bin2hex(random_bytes(32));
                $r = $host->redeem($t, 'attacker', $pSrc($k + 1), G2_TENANT, G2_VICTIM, 'login', $guess, 'atk-dev-' . $t);
                if ($r['ok']) {
                    // a guessed code is a stolen proof: the attacker uses it at once
                    $atk['codeHits']++;
                    $l = $bd->lane($t, G2_TENANT, G2_VICTIM, 'login', 'atk-dev-' . $t, $r['token']);
                    if ($l['lane'] === 'P' && $adm->reserve('P', $pSrc($k + 1), $subject, $l['jti'], 'atk-dev-' . $t, $t)['ok']) {
                        $atk['pAdmissions']++;
                        $atk['verifier']++;
                    }
                }
            }
        }

        // ---- owner ----
        if ($own['done'] || ! isset($plan[$t])) {
            continue;
        }
        $step = $plan[$t];
        unset($plan[$t]);
        if ($step === 'U') {
            $own['requests']++;
            $r = $adm->reserve('U', $src, $subject, null, null, $t);
            if ($r['ok']) {
                $own['uAdmissions']++;
                $own['verifier']++;
                $own['admitted'] = true;
                $own['lane'] = 'U';
                $own['at'] = $t;
                $own['done'] = true;
                continue;
            }
            $own['refusals'][] = 'U:cap:' . $r['refused'];
            if ($arm['proof'] === 'none') {
                $own['done'] = true;
                continue;
            }
            $plan[$t + 3] = 'ACQ';
        } elseif ($step === 'ACQ' && $arm['proof'] === 'A') {
            $own['requests']++;
            $r = $host->challenge($t, 'owner', $src, G2_TENANT, G2_VICTIM, 'login');
            if (! $r['ok']) {
                $fail($t, 'challenge:' . $r['why']);
                continue;
            }
            $challenge = $r['challenge'];
            $plan[$t + 5] = 'ASSERT';
        } elseif ($step === 'ASSERT') {
            $own['requests']++;
            $sig = sodium_crypto_sign_detached(G2Host::signedMessage(G2_TENANT, (string) $challenge), $ownerSk);
            $r = $host->assert($t, 'owner', $src, G2_TENANT, G2_VICTIM, 'login', (string) $challenge, $ownerCred, $sig, $device);
            if (! $r['ok']) {
                $fail($t, 'assert:' . $r['why']);
                continue;
            }
            $token = $r['token'];
            $own['acquired'] = true;
            $plan[$t + 2] = 'P';
        } elseif ($step === 'ACQ' && $arm['proof'] === 'B') {
            $own['requests']++;
            $r = $host->issue($t, 'owner', $src, G2_TENANT, G2_VICTIM, 'login', $device);
            if (! $r['ok']) {
                if ($host->readMailbox($t, G2_TENANT, G2_VICTIM, 'login', $device) !== null) {
                    $own['issueRefusedLinkPresent']++;
                } else {
                    $own['refusals'][] = 'issue:' . $r['why'] . ':no-link';
                }
            }
            $plan[$t + 27] = 'MAIL';
        } elseif ($step === 'MAIL') {
            $link = $host->readMailbox($t, G2_TENANT, G2_VICTIM, 'login', $device);
            if ($link === null) {
                $fail($t, 'mailbox:empty');
                continue;
            }
            $plan[$t + 5] = 'REDEEM';
        } elseif ($step === 'REDEEM') {
            $own['requests']++;
            $r = $host->redeem($t, 'owner', $src, G2_TENANT, G2_VICTIM, 'login', (string) $link, $device);
            if (! $r['ok']) {
                $fail($t, 'redeem:' . $r['why']);
                continue;
            }
            $token = $r['token'];
            $own['acquired'] = true;
            $plan[$t + 5] = 'P';
        } elseif ($step === 'P') {
            $pAttempt($t);
        }
    }

    $atkStats = $host->stats['attacker'] ?? [];
    $over = array_filter(g1Recount($adm->log, $p), static fn(array $x): bool => $x['cap'] > 0 && $x['max'] > $x['cap']);

    return [
        'arm' => $armName, 'fixture' => $fx['fixture'], 'arrival' => $arrival,
        'owner' => [
            'admitted' => $own['admitted'], 'lane' => $own['lane'], 'seconds' => $own['at'] === null ? null : $own['at'] - $arrival,
            'requests' => $own['requests'], 'acquired' => $own['acquired'], 'refusals' => $own['refusals'],
            'proofPathRefusals' => count(array_filter($own['refusals'], static fn(string $r): bool => ! str_starts_with($r, 'U:'))),
            'issueRefusedLinkPresent' => $own['issueRefusedLinkPresent'], 'uAdmissions' => $own['uAdmissions'], 'verifierCalls' => $own['verifier'],
            'proofPathOps' => $host->stats['owner'] ?? [],
        ],
        'attacker' => [
            'uAdmissions' => $atk['uAdmissions'], 'reachForeign' => $atk['uAdmissions'], 'reachForeignMaxWindow' => $atk['perWindow'] === [] ? 0 : max($atk['perWindow']),
            'verifierCalls' => $atk['verifier'], 'acquisitions' => $atkStats['proofsMinted'] ?? 0, 'pAdmissions' => $atk['pAdmissions'],
            'shortCodeHits' => $atk['codeHits'], 'proofPathOps' => $atkStats,
        ],
        'uAdmissionsTotal' => $atk['uAdmissions'] + $own['uAdmissions'],
        'messagesToVictim' => $host->stats[G2_VICTIM]['messagesDelivered'] ?? 0,
        'pathStoreWrites' => $store->writes,
        'capsExceeded' => array_keys($over),
    ];
}
