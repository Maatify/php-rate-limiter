<?php

declare(strict_types=1);

// Workstream B / Anti-Evasion — scenario catalog. Reuses the published X1–X8 / L1–L4 streams from PR #103 UNCHANGED
// (fixtures/streams.php of workstream-b-correlation) and adds the missing axes (Y*). Deterministic: no wall clock, no mt_rand.
//
// Event: t (s), ip, ua (normalized-UA label), fp (client fingerprint id or null => LOW), acct (or null),
//        outcome ok|fail (Host-provided), kind legit|attack.
require dirname(__DIR__, 2) . '/workstream-b-correlation/fixtures/streams.php';

/**
 * What the attacker / the legitimate party controls and where the outcome comes from. This is data, not a verdict:
 * it documents the threat model of each stream (task §4 "من يتحكم ...").
 */
function wsbControls(): array
{
    $hostOutcome = 'Host-provided (recordFailure/recordSuccess); the library cannot verify it. The attacker controls it ONLY where it controls the account (success on its own account).';

    return [
        'L1' => ['ip' => 'shared NAT (neither party)', 'ua' => 'legit', 'fingerprint' => 'legit (2 stable clients)', 'account' => 'legit', 'outcome' => $hostOutcome],
        'L1f' => ['ip' => 'shared NAT', 'ua' => 'legit', 'fingerprint' => 'legit', 'account' => 'legit', 'outcome' => $hostOutcome . ' 8% typos.'],
        'L2' => ['ip' => 'legit (distinct)', 'ua' => 'legit (popular UA)', 'fingerprint' => 'absent (LOW)', 'account' => 'legit', 'outcome' => $hostOutcome],
        'L3' => ['ip' => 'legit (distinct)', 'ua' => 'legit', 'fingerprint' => 'absent (LOW)', 'account' => 'legit', 'outcome' => $hostOutcome . ' 8% typos.'],
        'L4' => ['ip' => 'legit device roaming (carrier/Wi-Fi)', 'ua' => 'legit', 'fingerprint' => 'legit, stable', 'account' => 'legit', 'outcome' => $hostOutcome],
        'X1' => ['ip' => 'attacker (1 IP)', 'ua' => 'attacker', 'fingerprint' => 'attacker (fresh per request)', 'account' => 'victim account (targeted)', 'outcome' => $hostOutcome . ' attacker cannot influence it (fails by construction).'],
        'X2' => ['ip' => 'attacker (new IP per request)', 'ua' => 'attacker (popular UA)', 'fingerprint' => 'absent (LOW)', 'account' => 'attacker (new victim account per attempt)', 'outcome' => $hostOutcome],
        'X2L' => ['ip' => 'attacker + legit', 'ua' => 'shared popular UA', 'fingerprint' => 'absent (LOW)', 'account' => 'both', 'outcome' => $hostOutcome],
        'X3' => ['ip' => 'attacker (new IP per request)', 'ua' => 'attacker', 'fingerprint' => 'attacker (fresh per request)', 'account' => 'single victim', 'outcome' => $hostOutcome],
        'X4' => ['ip' => 'shared NAT', 'ua' => 'shared', 'fingerprint' => 'attacker rotates; 2 legit stable', 'account' => 'attacker targets victim; legit own', 'outcome' => $hostOutcome],
        'X5' => ['ip' => 'attacker (4 IP / 10 min)', 'ua' => 'attacker', 'fingerprint' => 'absent (LOW)', 'account' => 'new victim per attempt', 'outcome' => $hostOutcome],
        'X6' => ['ip' => 'shared NAT', 'ua' => 'shared', 'fingerprint' => 'insider rotates; 2 colleagues stable', 'account' => 'insider own account (failures are free)', 'outcome' => $hostOutcome . ' The insider can fail at will on its own account.'],
        'X7' => ['ip' => 'attacker (new IP per request)', 'ua' => 'attacker', 'fingerprint' => 'absent (LOW)', 'account' => 'attacker (own account for padding successes)', 'outcome' => 'Host-provided; the attacker FABRICATES successes by logging in to its own account (untrustworthy exoneration signal).'],
        'X8' => ['ip' => 'botnet (8 IPs) + legit', 'ua' => 'shared popular UA', 'fingerprint' => 'absent (LOW)', 'account' => 'attacker victims / legit own', 'outcome' => $hostOutcome],
        'Y1' => ['ip' => 'attacker pool of 4 IPs, rotated', 'ua' => 'attacker', 'fingerprint' => 'attacker (fresh per request)', 'account' => 'attacker (new account per request)', 'outcome' => $hostOutcome],
        'Y2a' => ['ip' => 'attacker (1 IP)', 'ua' => 'attacker', 'fingerprint' => 'attacker (2 fps / 601 s cycle: hovers at N-1)', 'account' => 'new victim per attempt', 'outcome' => $hostOutcome],
        'Y2b' => ['ip' => 'attacker (1 IP)', 'ua' => 'attacker', 'fingerprint' => 'attacker (2 fps / 1801 s cycle: outlasts the 30-min WATCH)', 'account' => 'victim', 'outcome' => $hostOutcome],
        'Y2c2' => ['ip' => 'attacker (5 IPs / cycle)', 'ua' => 'attacker', 'fingerprint' => 'absent (LOW)', 'account' => 'new victim per attempt', 'outcome' => $hostOutcome . ' LOW dilution, 1801 s cycles.'],
        'Y2c' => ['ip' => 'attacker (5 IPs / cycle)', 'ua' => 'attacker', 'fingerprint' => 'absent (LOW)', 'account' => 'new victim per attempt', 'outcome' => $hostOutcome . ' LOW dilution hovering at 5/6.'],
        'Y4b' => ['ip' => 'shared NAT', 'ua' => 'shared', 'fingerprint' => 'attacker: 3 failing rotating fps once; legit: 1 stable client', 'account' => 'attacker: new own account per attempt', 'outcome' => $hostOutcome],
        'Y4c' => ['ip' => 'shared NAT', 'ua' => 'shared', 'fingerprint' => 'attacker: seed 3 failing fps then 1 failing fresh fp / 61 s; legit: 1 stable client', 'account' => 'attacker: new own account per attempt', 'outcome' => $hostOutcome . ' Attacker pays one failure per block cycle.'],
        'Y4d' => ['ip' => 'shared NAT', 'ua' => 'shared', 'fingerprint' => 'attacker: 3 fps with SUCCESSFUL own-account logins; legit: 1 stable client', 'account' => 'attacker own (zero failures)', 'outcome' => $hostOutcome . ' Attacker produces ZERO failures.'],
        'Y4e' => ['ip' => 'shared NAT', 'ua' => 'shared', 'fingerprint' => 'insider rotates; 2 colleagues stable', 'account' => 'insider: new own account per attempt (failures are free, K4 cannot accumulate)', 'outcome' => $hostOutcome],
        'Y4f' => ['ip' => 'shared NAT', 'ua' => 'shared', 'fingerprint' => 'insider rotates; 2 colleagues stable', 'account' => 'insider: pool of 2 own accounts (under the spray cap, bounded K4)', 'outcome' => $hostOutcome],
        'Y6b' => ['ip' => 'shared NAT', 'ua' => 'shared', 'fingerprint' => 'attacker re-seeds 3 fps every 601 s + 1 successful request / 61 s; legit: 1 stable client', 'account' => 'attacker own (zero failures)', 'outcome' => $hostOutcome . ' Recovery abuse without failures.'],
        'Y6c' => ['ip' => 'shared NAT', 'ua' => 'shared', 'fingerprint' => 'attacker re-seeds 3 fps every 601 s + 1 FAILING request / 61 s; legit: 1 stable client', 'account' => 'attacker own', 'outcome' => $hostOutcome . ' Recovery abuse paying failures.'],
        'Y7' => ['ip' => 'attacker (1 IP)', 'ua' => 'attacker', 'fingerprint' => 'attacker: fixed pool of 3, cycled', 'account' => 'new victim per attempt', 'outcome' => $hostOutcome],
        'Y9a' => ['ip' => 'one IPv6 /64 (attacker rotates the interface id)', 'ua' => 'attacker', 'fingerprint' => 'attacker (fresh per request)', 'account' => 'victim', 'outcome' => $hostOutcome],
        'Y9b' => ['ip' => 'six IPv6 /64 of one /48 (attacker rents the /48)', 'ua' => 'attacker', 'fingerprint' => 'attacker (6 distinct, one per /64, repeated)', 'account' => 'victim', 'outcome' => $hostOutcome],
    ];
}

/**
 * @return array<string, array{title: string, events: list<array<string, mixed>>}>
 */
function wsbStreams(): array
{
    $S = [];
    foreach (evidenceStreams() as $name => $events) {
        $id = explode(' ', $name)[0];
        $S[$id] = ['title' => $name, 'events' => $events];
    }
    $e = static fn(int $t, string $ip, ?string $fp, ?string $acct, string $o, string $k, string $u = 'chrome126'): array => ['t' => $t, 'ip' => $ip, 'ua' => $u, 'fp' => $fp, 'acct' => $acct, 'outcome' => $o, 'kind' => $k];

    // Y1 — combined rotation: IP (pool of 4) + fingerprint + account rotated together, one attempt / 5 s.
    $ev = [];
    for ($t = 0, $n = 1; $t <= 600; $t += 5, $n++) {
        $ev[] = $e($t, '198.51.100.' . (($n % 4) + 1), "rot$n", "v$n", 'fail', 'attack');
    }
    $S['Y1'] = ['title' => 'Y1 combined rotation: 4-IP pool + fresh FP + fresh account per attempt', 'events' => $ev];

    // Y2a/Y2b — N-1 gaming on churn: exactly two distinct fingerprints per cycle (count 2 = N-1). 601 s cycles keep the set
    // alive only until it expires; 1801 s cycles also outlast the 1800 s WATCH.
    foreach (['Y2a' => 601, 'Y2b' => 1801] as $id => $gap) {
        $ev = [];
        for ($c = 0; $c < 4; $c++) {
            // a NEW victim account per attempt: a single hammered account would be stopped by K4 and hide the correlation signal
            $ev[] = $e($c * $gap, '198.51.100.88', "g{$c}a", "va{$c}", 'fail', 'attack');
            $ev[] = $e($c * $gap + 1, '198.51.100.88', "g{$c}b", "vb{$c}", 'fail', 'attack');
        }
        $S[$id] = ['title' => "$id N-1 churn gaming, 2 distinct fingerprints every {$gap} s (4 cycles)", 'events' => $ev];
    }
    // Y2c — N-1 gaming on LOW dilution: 5 distinct IPs (N-1 of 6) per cycle, cycles 601 s apart (WATCH-visible) vs 1801 s apart.
    foreach (['Y2c' => 601, 'Y2c2' => 1801] as $id => $gap) {
        $ev = [];
        for ($c = 0; $c < 4; $c++) {
            for ($i = 1; $i <= 5; $i++) {
                $ev[] = $e($c * $gap + $i, "dil{$c}_{$i}", null, "v{$c}_{$i}", 'fail', 'attack');
            }
        }
        $S[$id] = ['title' => "$id N-1 LOW dilution gaming, 5 IPs every {$gap} s (4 cycles)", 'events' => $ev];
    }

    // Y4b/c/d/e + Y6b/c — shared-K2 poisoning / recovery abuse by someone sharing the NAT+UA with ONE legitimate stable client
    // (fp "fpA", acct "acctA", one login attempt / 60 s for 1800 s: slow enough that K1/K2 access scoring never fires by itself). The attacker uses a NEW own account per attempt (no K4 build-up)
    // and THREE distinct fingerprints: the minimum that satisfies every candidate's evidence rule, so no candidate is flattered by an
    // under-powered attacker. $o = outcome of the attacker's requests (fail = pays failures, ok = zero failures).
    $legit = static function () use ($e): array {
        $ev = [];
        for ($t = 60; $t <= 1800; $t += 60) {
            $ev[] = $e($t, NAT, 'fpA', 'acctA', 'ok', 'legit');
        }

        return $ev;
    };
    $sort = static function (array $ev): array {
        usort($ev, static fn(array $a, array $b): int => $a['t'] <=> $b['t']);

        return $ev;
    };
    $seed = static function (int $t, string $tag, string $o) use ($e): array {
        return [
            $e($t, NAT, "{$tag}1", "o{$tag}1", $o, 'attack'),
            $e($t + 1, NAT, "{$tag}2", "o{$tag}2", $o, 'attack'),
            $e($t + 2, NAT, "{$tag}3", "o{$tag}3", $o, 'attack'),
        ];
    };
    $S['Y4b'] = ['title' => 'Y4b cheap poisoning burst: 3 FAILING rotating-FP requests once (new account each)', 'events' => $sort(array_merge($legit(), $seed(100, 'pb', 'fail')))];
    $S['Y4d'] = ['title' => 'Y4d zero-failure poisoning: 3 SUCCESSFUL rotating-FP requests once (own account)', 'events' => $sort(array_merge($legit(), $seed(100, 'pd', 'ok')))];
    $ev = array_merge($legit(), $seed(100, 'pc', 'fail'));
    for ($t = 161, $n = 0; $t <= 1800; $t += 61, $n++) {
        $ev[] = $e($t, NAT, "pcs{$n}", "opcs{$n}", 'fail', 'attack');
    }
    $S['Y4c'] = ['title' => 'Y4c sustained poisoning: seed 3 failing FPs, then 1 failing fresh-FP request / 61 s', 'events' => $sort($ev)];

    // Y4e — X6 repaired: the insider fails with rotating fingerprints on a NEW own account each time (K4 cannot stop it);
    // 2 legit colleagues on the same NAT+UA (as X6).
    $ev = [];
    for ($t = 0, $n = 0; $t <= 1200; $t += 10, $n++) {
        if ($n % 3 === 0) {
            $ev[] = $e($t, NAT, 'fpA', 'acctA', 'ok', 'legit');
        } elseif ($n % 3 === 1) {
            $ev[] = $e($t, NAT, 'fpB', 'acctB', 'ok', 'legit');
        } else {
            $ev[] = $e($t, NAT, "ins$n", "oins$n", 'fail', 'attack');
        }
    }
    $S['Y4e'] = ['title' => 'Y4e X6 repaired: insider fails with rotating FPs on a NEW account each time', 'events' => $ev];

    // Y4f — Y4e with a POOL of 2 own accounts (stays under the credential-spray cap and keeps each account's K4 score low).
    $ev = [];
    for ($t = 0, $n = 0; $t <= 1200; $t += 10, $n++) {
        if ($n % 3 === 0) {
            $ev[] = $e($t, NAT, 'fpA', 'acctA', 'ok', 'legit');
        } elseif ($n % 3 === 1) {
            $ev[] = $e($t, NAT, 'fpB', 'acctB', 'ok', 'legit');
        } else {
            $ev[] = $e($t, NAT, "ins$n", 'oPool' . ($n % 2), 'fail', 'attack');
        }
    }
    $S['Y4f'] = ['title' => 'Y4f insider with a pool of 2 own accounts fails with rotating FPs', 'events' => $ev];

    foreach (['Y6b' => 'ok', 'Y6c' => 'fail'] as $id => $o) {
        $ev = $legit();
        for ($seedT = 100; $seedT <= 1800; $seedT += 601) {
            $ev = array_merge($ev, $seed($seedT, "r{$seedT}", $o));
        }
        for ($t = 161, $n = 0; $t <= 1800; $t += 61, $n++) {
            $ev[] = $e($t, NAT, 'r1001', 'or1001', $o, 'attack');
        }
        $S[$id] = ['title' => "$id recovery abuse: re-seed 3 FPs every 601 s + 1 request / 61 s (" . ($o === 'ok' ? 'zero failures' : 'failing') . ')', 'events' => $sort($ev)];
    }

    // Y7 — fingerprint-POOL cycler: the attacker cycles a fixed pool of 3 fingerprints forever (1 attempt / 5 s, new account each),
    // never rotating to a fresh one. Stale-set renewal (S0) keeps blocking it; a fresh-arrival latch (RL) would not.
    $ev = [];
    for ($t = 0, $n = 0; $t <= 1200; $t += 5, $n++) {
        $ev[] = $e($t, '198.51.100.99', 'pool' . ($n % 3), "cy{$n}", 'fail', 'attack');
    }
    $S['Y7'] = ['title' => 'Y7 fingerprint-pool cycler: 3 fixed fingerprints cycled for 20 minutes, new account each', 'events' => $ev];

    // Y9 — IPv6. Y9a: one /64, attacker rotates the interface id and the fingerprint. Y9b: six /64 of one /48, one fingerprint per /64.
    $ev = [];
    for ($t = 0, $n = 1; $t <= 300; $t += 10, $n++) {
        $ev[] = $e($t, '2001:db8:aaaa:1::' . $n, "v6rot$n", null, 'fail', 'attack');
    }
    $S['Y9a'] = ['title' => 'Y9a IPv6 same /64: rotating interface id + fresh FP, failing', 'events' => $ev];
    $ev = [];
    foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $i => $cl) {
        $ev[] = $e($i * 20, sprintf('2001:db8:aaaa:%x::1', $i + 1), $cl, null, 'fail', 'attack');
    }
    for ($i = 0; $i < 6; $i++) {
        $ev[] = $e(130 + $i * 20, sprintf('2001:db8:aaaa:%x::1', $i + 1), chr(65 + $i), null, 'fail', 'attack');
    }
    $S['Y9b'] = ['title' => 'Y9b IPv6 six /64 in one /48: one fingerprint per /64, failing', 'events' => $ev];

    return $S;
}

/**
 * Attack class decides WHICH metric is the security objective of a stream (never a verdict):
 *   evasion   — attacker wants verification attempts to reach the protected operation  -> attackReachedVerification
 *   poisoning — attacker wants legitimate users denied                                -> legitDenied(ByCorrelation)
 *   both      — evasion + collateral on legitimate users
 *   benign    — only legitimate users                                                 -> legitDenied
 */
function wsbClass(string $id): string
{
    return match (true) {
        in_array($id, ['L1', 'L1f', 'L2', 'L3', 'L4'], true) => 'benign',
        in_array($id, ['X4', 'X6', 'Y4b', 'Y4c', 'Y4d', 'Y4e', 'Y4f', 'Y6b', 'Y6c'], true) => 'poisoning',
        in_array($id, ['X2L', 'X8'], true) => 'both',
        default => 'evasion',
    };
}

/** The published-stream ids that form the primary matrix, in order. */
function wsbPrimaryOrder(): array
{
    return ['L1', 'L1f', 'L2', 'L3', 'L4', 'X1', 'X2', 'X2L', 'X3', 'X4', 'X5', 'X6', 'X7', 'X8', 'Y1', 'Y2a', 'Y2b', 'Y2c', 'Y2c2', 'Y4b', 'Y4c', 'Y4d', 'Y4e', 'Y4f', 'Y6b', 'Y6c', 'Y7', 'Y9a', 'Y9b'];
}
