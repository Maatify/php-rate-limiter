<?php

declare(strict_types=1);

// Deterministic event streams shared by the real-pipeline replay and the offline policy model.
// Event: t (s), ip, ua (normalized-UA label), fp (client fingerprint id or null => LOW), acct (or null),
//        outcome ok|fail, kind legit|attack. No wall-clock, no mt_rand: reproducible byte-for-byte.

/** @return array<string, list<array<string, mixed>>> */
function evidenceStreams(): array
{
    $ua = 'chrome126';
    $e = static fn(int $t, string $ip, ?string $fp, ?string $acct, string $o, string $k, string $u = 'chrome126'): array => ['t' => $t, 'ip' => $ip, 'ua' => $u, 'fp' => $fp, 'acct' => $acct, 'outcome' => $o, 'kind' => $k];
    $S = [];

    $ev = [];
    for ($t = 0, $n = 0; $t <= 4000; $t += 30, $n++) {
        $c = $n % 2 === 1 ? 'B' : 'A';
        $ev[] = $e($t, '203.0.113.50', "fp$c", "acct$c", 'ok', 'legit');
    }
    $S['L1 NAT: 2 legit MEDIUM clients, valid logins'] = $ev;

    $r = new Lcg(7);
    $ev = [];
    for ($t = 0, $n = 0; $t <= 4000; $t += 30, $n++) {
        $c = $n % 2 === 1 ? 'B' : 'A';
        $ev[] = $e($t, '203.0.113.50', "fp$c", "acct$c", $r->pct() <= 8 ? 'fail' : 'ok', 'legit');
    }
    $S['L1f NAT: same + 8% typo failures'] = $ev;

    $ev = [];
    for ($t = 0, $n = 1; $t <= 2400; $t += 20, $n++) {
        $ev[] = $e($t, "ip$n", null, "u$n", 'ok', 'legit');
    }
    $S['L2 LOW population 3 new users/min, valid'] = $ev;

    $r = new Lcg(11);
    $ev = [];
    for ($t = 0, $n = 1; $t <= 2400; $t += 20, $n++) {
        $ev[] = $e($t, "ip$n", null, "u$n", $r->pct() <= 8 ? 'fail' : 'ok', 'legit');
    }
    $S['L3 LOW population + 8% typos'] = $ev;

    $ev = [];
    for ($i = 1; $i <= 12; $i++) {
        $ev[] = $e($i * 20, "ip$i", 'dev', 'userR', 'ok', 'legit');
    }
    $S['L4 one real device roaming across 12 IPs (MEDIUM)'] = $ev;

    $ev = [];
    for ($t = 0, $n = 0; $t <= 600; $t += 10, $n++) {
        $ev[] = $e($t, '198.51.100.77', "rot$n", 'victim', 'fail', 'attack');
    }
    $S['X1 attacker: fresh FP per request, same IP/UA, failing'] = $ev;

    $ev = [];
    for ($t = 0, $n = 1; $t <= 600; $t += 5, $n++) {
        $ev[] = $e($t, "atk$n", null, "v$n", 'fail', 'attack');
    }
    $S['X2 attacker: LOW, 1 UA, new IP+new account per attempt'] = $ev;

    $ev = [];
    for ($t = 0, $n = 1; $t <= 600; $t += 5, $n++) {
        $ev[] = $e($t, "atk$n", "rot$n", 'victim', 'fail', 'attack');
    }
    $S['X3 attacker: FP rotation + IP rotation (new K2 each)'] = $ev;

    $ev = [];
    for ($t = 0, $n = 0; $t <= 1200; $t += 10, $n++) {
        if ($n % 3 === 0) {
            $ev[] = $e($t, '203.0.113.50', 'fpA', 'acctA', 'ok', 'legit');
        } elseif ($n % 3 === 1) {
            $ev[] = $e($t, '203.0.113.50', 'fpB', 'acctB', 'ok', 'legit');
        } else {
            $ev[] = $e($t, '203.0.113.50', "rot$n", 'victim', 'fail', 'attack');
        }
    }
    $S['X4 FP-rotating attacker sharing a K2 with 2 legit clients'] = $ev;

    $ev = [];
    $leg = [];
    for ($t = 0, $n = 1; $t <= 600; $t += 5, $n++) {
        $ev[] = $e($t, "atk$n", null, "v$n", 'fail', 'attack');
    }
    $r = new Lcg(5);
    for ($t = 2, $n = 1; $t <= 600; $t += 20, $n++) {
        $ev[] = $e($t, "leg$n", null, "u$n", $r->pct() <= 8 ? 'fail' : 'ok', 'legit');
    }
    usort($ev, static fn(array $a, array $b): int => $a['t'] <=> $b['t']);
    $S['X2L attacker X2 + legit LOW population on same UA'] = $ev;

    $ev = [];
    for ($t = 0, $n = 1; $t <= 3000; $t += 150, $n++) {
        $ev[] = $e($t, "atk$n", null, "v$n", 'fail', 'attack');
    }
    $S['X5 attacker: LOW slow-roll 4 IP/10min (under threshold)'] = $ev;

    // B-F03 threat-model streams ------------------------------------------------------------
    // X6: insider on the same NAT+UA as two legit colleagues deliberately fails with rotating fingerprints.
    $ev = [];
    for ($t = 0, $n = 0; $t <= 1200; $t += 10, $n++) {
        if ($n % 3 === 0) {
            $ev[] = $e($t, '203.0.113.50', 'fpA', 'acctA', 'ok', 'legit');
        } elseif ($n % 3 === 1) {
            $ev[] = $e($t, '203.0.113.50', 'fpB', 'acctB', 'ok', 'legit');
        } else {
            $ev[] = $e($t, '203.0.113.50', "ins$n", 'acctInsider', 'fail', 'attack');
        }
    }
    $S['X6 weaponization: insider on shared K2 fails with rotating FPs (colleagues legit)'] = $ev;

    // X7: ratio dilution — the X2 attacker pads every failed guess with two successful logins on its own account.
    $ev = [];
    for ($t = 0, $n = 1; $t <= 600; $t += 5, $n++) {
        $ev[] = $e($t, "atk$n", null, $n % 3 === 0 ? "v$n" : 'own', $n % 3 === 0 ? 'fail' : 'ok', 'attack');
    }
    $S['X7 evasion: X2 attacker pads each failure with 2 valid logins (ratio dilution)'] = $ev;

    // X8: weaponization of ratio — a botnet of 8 IPs fails with the common UA while legit LOW users log in.
    $ev = [];
    for ($t = 0, $n = 1; $t <= 600; $t += 4, $n++) {
        $ev[] = $e($t, 'bot' . (($n % 8) + 1), null, "v$n", 'fail', 'attack');
    }
    $r = new Lcg(9);
    for ($t = 1, $n = 1; $t <= 600; $t += 12, $n++) {
        $ev[] = $e($t, "leg$n", null, "u$n", $r->pct() <= 5 ? 'fail' : 'ok', 'legit');
    }
    usort($ev, static fn(array $a, array $b): int => $a['t'] <=> $b['t']);
    $S['X8 weaponization: 8-IP botnet fails on the popular UA; legit LOW users share the UA'] = $ev;

    return $S;
}
