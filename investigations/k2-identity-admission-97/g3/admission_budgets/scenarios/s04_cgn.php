<?php

declare(strict_types=1);

// G3 S04 — CGN (PLAN.md §4.2): admission-layer simulation only. Under the attribution rule no collective penalty exists, so at a
// shared source only U_src decides (and FC, when enabled). N clients behind one source, each with its own account, Poisson
// arrivals during one peak hour, 24 fixed-seed replications, with and without a co-located attacker spraying distinct accounts
// at 1 / s, plus one mobile client alternating between the shared source and its own cell source every 120 s.

require dirname(__DIR__) . '/lib/g3.php';

function g3Poisson(float $mean): int
{
    $l = exp(-$mean);
    $k = 0;
    $p = 1.0;
    do {
        $k++;
        $p *= mt_rand() / mt_getrandmax();
    } while ($p > $l);

    return $k - 1;
}

$arms = ['S12' => [12, null], 'S24' => [24, null], 'S40' => [40, null], 'FC8' => [40, 8]];
foreach ($arms as $arm => [$uSrc, $fc]) {
    foreach ([1, 4] as $lambda) {
        foreach ([0.0, 0.8] as $pShare) {
            foreach ([50, 500, 5000] as $n) {
                foreach ([false, true] as $attack) {
                    $refused = 0;
                    $attempts = 0;
                    $mobileRefused = 0;
                    $atkAdmitted = 0;
                    for ($rep = 1; $rep <= 24; $rep++) {
                        mt_srand($rep * 1000003 + $n * 31 + $lambda * 7 + (int) ($pShare * 10));
                        $events = [];
                        $mean = $lambda * (1 - $pShare) * 3 / 86400 * 3600;
                        for ($i = 0; $i < $n; $i++) {
                            $k = g3Poisson($mean);
                            for ($j = 0; $j < $k; $j++) {
                                $events[] = [mt_rand(0, 3599), 1, "c$i", 'cgn'];
                            }
                        }
                        foreach ([300, 1200, 2100, 3000] as $mt) {
                            $events[] = [$mt, 1, 'mobile', intdiv($mt, 120) % 2 === 0 ? 'cgn' : 'cell'];
                        }
                        if ($attack) {
                            for ($t = 0; $t < 3600; $t++) {
                                $events[] = [$t, 0, "x$t", 'cgn'];
                            }
                        }
                        usort($events, static fn(array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
                        $adm = new G1Admission(new G1RefStore(), g3Params($uSrc));
                        $failSet = [];
                        foreach ($events as [$t, $legit, $acct, $src]) {
                            $sk = $src . '@' . intdiv($t, 600);
                            $ok = true;
                            if ($fc !== null && ! isset($failSet[$sk][$acct]) && count($failSet[$sk] ?? []) >= $fc) {
                                $ok = false;
                            }
                            if ($ok) {
                                $ok = $adm->reserve('U', $src, $acct, null, null, $t)['ok'];
                            }
                            if ($legit === 1) {
                                if ($acct === 'mobile') {
                                    $mobileRefused += $ok ? 0 : 1;
                                    continue;
                                }
                                $attempts++;
                                $refused += $ok ? 0 : 1;
                            } elseif ($ok) {
                                $atkAdmitted++;
                                if ($fc !== null) {
                                    $failSet[$sk][$acct] = true;
                                }
                            }
                        }
                    }
                    g3Emit('s04', ['arm' => $arm, 'lambda' => $lambda, 'p' => $pShare, 'n' => $n, 'attacker' => $attack,
                        'legitAttempts' => $attempts, 'legitRefused' => $refused,
                        'refusedShare' => $attempts === 0 ? 0.0 : round($refused / $attempts, 6),
                        'mobileRefused' => $mobileRefused, 'mobileAttempts' => 4 * 24, 'attackAdmitted' => $atkAdmitted]);
                }
            }
        }
    }
}
