<?php

declare(strict_types=1);

// G4 v2 CGN-B: the archived G4 CGN-B model (g4/integrated/scenarios/g4_cgn.php) unchanged for Part R (R-FULL, R-FULL-40,
// R-NO-BIND, compared with the archive by V1), and for FULL2 / FULL2-40 with the P-lane refund budget of PLAN.md §3.
// Original description follows.
// G4 CGN-B (PLAN.md §2): admission-layer model, no pipeline (as G3 S04). N clients behind one source, each with its own account;
// λ logins per client per day over 3 days, one peak hour (09:00) at 3× the mean hourly rate; 5 % of logins are a typo followed by
// a correct retry 30 s later. A client is bound after its first verified success (binding arms); bound requests take the P lane
// (P_acct, P_proof keyed by the client's token, N_P). Unbound requests take the U lane: net failure cap F = 4 on the source, then
// U_src. The per-account bucket is not modelled: each client fails at most once before its retry, so with C = 4 it never refuses.
// Sprayer: one distinct victim account per second, all of day 2 (index 1), same source. Fixed seeds, 4 replications.
// Build-independent: runs once, on the G4 build.

require dirname(__DIR__) . '/lib/g4v2.php';

if (g4Provenance() !== 'G4') {
    exit(0);
}

function g4v2Poisson(float $mean): int
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

/** An hour of the day with the 09:00 hour at 3× the mean rate and the other 23 sharing the rest. */
function g4v2Hour(): int
{
    $x = mt_rand(0, 23 * 1000 - 1);
    if ($x < 3000) {
        return 9;
    }
    $h = intdiv($x - 3000, 1000);

    return $h >= 9 ? $h + 1 : $h;
}

foreach (['R-FULL', 'R-FULL-40', 'R-NO-BIND', 'FULL2', 'FULL2-40'] as $arm) {
    [, $uSrc, $u, $mode, , $pMode] = g4v2Arms()[$arm];
    foreach ([500, 5000] as $n) {
        foreach ([1, 4] as $lambda) {
            foreach ([false, true] as $spray) {
                $days = array_fill(0, 3, ['attempts' => 0, 'refused' => 0, 'boundAttempts' => 0, 'boundRefused' => 0, 'unboundRefused' => 0]);
                $sprayAdmitted = 0;
                $boundAtEnd = 0;
                for ($rep = 1; $rep <= 4; $rep++) {
                    mt_srand($rep * 1000003 + $n * 31 + $lambda * 7);
                    $events = [];
                    for ($d = 0; $d < 3; $d++) {
                        for ($i = 0; $i < $n; $i++) {
                            for ($j = 0, $k = g4v2Poisson((float) $lambda); $j < $k; $j++) {
                                $t = $d * 86400 + g4v2Hour() * 3600 + mt_rand(0, 3599);
                                if (mt_rand(1, 100) <= 5) {
                                    $events[] = [$t, 1, $i, 'fail'];
                                    $t += 30;
                                }
                                $events[] = [$t, 1, $i, 'ok'];
                            }
                        }
                    }
                    if ($spray) {
                        for ($t = 86400; $t < 172800; $t++) {
                            $events[] = [$t, 0, $t, 'fail'];
                        }
                    }
                    usort($events, static fn(array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
                    $adm = new G1Admission(new G1RefStore(), g4v2Params($uSrc, $u, $pMode));
                    $pb = $pMode === 'g1' ? null : new G4V2PBudget($pMode, g4v2PCaps());
                    $failSet = [];
                    $bound = [];
                    foreach ($events as [$t, $legit, $who, $outcome]) {
                        $acct = $legit === 1 ? "c$who" : "x$who";
                        $p = $legit === 1 && $mode !== 'off' && isset($bound[$who]);
                        if ($p) {
                            $keys = $pb?->keys($acct, "tok-$who", $t);
                            $ok = ($pb === null || $pb->check($keys) === null) && $adm->reserve('P', 'cgn', $acct, "tok-$who", "dev-$who", $t)['ok'];
                            if ($ok && $pb !== null) {
                                $pb->add($keys, 1);
                                $pb->outcome($keys, $outcome);
                            }
                        } else {
                            $sk = intdiv($t, 600);
                            $ok = ! (! isset($failSet[$sk][$acct]) && count($failSet[$sk] ?? []) >= $u['fc'])
                                && $adm->reserve('U', 'cgn', $acct, null, null, $t)['ok'];
                            if ($ok && $outcome === 'fail') {
                                $failSet[$sk][$acct] = true;
                            } elseif ($ok) {
                                unset($failSet[$sk][$acct]);
                            }
                        }
                        if ($legit === 0) {
                            $sprayAdmitted += $ok ? 1 : 0;
                            continue;
                        }
                        $d = intdiv($t, 86400);
                        $days[$d]['attempts']++;
                        $days[$d]['refused'] += $ok ? 0 : 1;
                        $days[$d]['boundAttempts'] += $p ? 1 : 0;
                        $days[$d]['boundRefused'] += $p && ! $ok ? 1 : 0;
                        $days[$d]['unboundRefused'] += ! $p && ! $ok ? 1 : 0;
                        if ($ok && $outcome === 'ok' && $mode !== 'off') {
                            $bound[$who] = true;
                        }
                    }
                    $boundAtEnd += count($bound);
                }
                foreach ($days as $d => $x) {
                    $days[$d]['refusedShare'] = $x['attempts'] === 0 ? 0.0 : round($x['refused'] / $x['attempts'], 6);
                }
                g4Emit('cgn', ['arm' => $arm, 'n' => $n, 'lambda' => $lambda, 'sprayer' => $spray, 'reps' => 4, 'days' => $days,
                    'sprayAdmitted' => $sprayAdmitted, 'boundAtEnd' => $boundAtEnd]);
            }
        }
    }
}
