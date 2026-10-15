<?php

declare(strict_types=1);

// H02 — can an office actually REACH the proposed ESTABLISHED standing? (Lead review F118-02.) Multi-day bootstrap from a FRESH store.
//
// Evidence level, stated per actor (never mixed):
//   * NEW browsers (no standing yet) — REAL: every attempt goes through the unpatched MAIN pipeline (pinned hashes), with the inputs a
//     direction-C Host would send for them: the instance id is NOT part of the DeviceFP, so their context is M0 (no client fingerprint,
//     no session). Under the proposal, NEW = MAIN, so this part is the actual runtime, not a model.
//   * ESTABLISHED browsers — IDEALIZED / UNREACHABLE-BY-THIS-HARNESS: no candidate exists, so they are NOT sent to the pipeline. They
//     are assumed admitted, and their traffic is assumed absent from the shared K1 spray / K2 churn sets (as ARCHITECTURE §3.3 proposes).
//     This is the most favourable assumption for the office; it is an upper bound on how fast standing can spread, not a measurement
//     of the established browsers' own experience.
//   * Establishment rule (MODEL of ARCHITECTURE §3.2): a NEW browser becomes ESTABLISHED when its checkOnly was ADMITTED by MAIN and
//     its successful outcome was recorded, subject to the per-network daily cap E_net. No other way in.
//
// Office: 100 browsers, one NAT IP, one Firefox major, one account each, arriving from 09:00 in a per-day order (one every 18 s),
// ~10 % typo then retry after 15 s. A browser whose check is denied retries the same logical attempt after max(retryAfter, 60) s, at
// most 3 times per day. 14 days, one shared store (state carries across days).
//
// Variants:
//   MAIN   no establishment at all (E_net = 0): today's runtime, day after day — the control
//   BASE   E_net unlimited, standing never expires within the horizon (sliding renewal)
//   CAP5   E_net = 5 per day
//   CLR10  BASE + each morning 10 % of established browsers have cleared cookies (back to NEW, new id)
//   FIX7   BASE but standing expires 7 days after establishment (no renewal)
//   ATK    BASE + a credential sprayer (no valid credential, all its ids NEW) on the same NAT, one attempt every 60 s from 08:50 to 10:00
//   ATKM   MAIN + the same sprayer — the control for ATK's attacker reach
//
// `cappedEligible` counts browsers whose admitted successful login would have established them but for E_net (for MAIN / ATKM, where
// E_net = 0, it is simply "admitted successful logins").
require_once dirname(__DIR__) . '/lib/hmci.php';

const H02_DAYS = 14;
const H02_BROWSERS = 100;
const H02_VARIANTS = [
    'MAIN' => ['enet' => 0, 'clear' => 0, 'ttlDays' => null, 'attack' => false],
    'BASE' => ['enet' => null, 'clear' => 0, 'ttlDays' => null, 'attack' => false],
    'CAP5' => ['enet' => 5, 'clear' => 0, 'ttlDays' => null, 'attack' => false],
    'CLR10' => ['enet' => null, 'clear' => 10, 'ttlDays' => null, 'attack' => false],
    'FIX7' => ['enet' => null, 'clear' => 0, 'ttlDays' => 7, 'attack' => false],
    'ATK' => ['enet' => null, 'clear' => 0, 'ttlDays' => null, 'attack' => true],
    'ATKM' => ['enet' => 0, 'clear' => 0, 'ttlDays' => null, 'attack' => true],
];

$prov = hmciProvenance();
emit('h02', ['row' => 'provenance'] + $prov);

foreach (H02_VARIANTS as $variant => $cfg) {
    $lab = new Lab('login');
    $rng = new Lcg(1102);
    /** @var array<string, int> $establishedDay who => day of establishment */
    $establishedDay = [];
    $idGen = array_fill_keys(array_map(static fn(int $i): string => sprintf('u%03d', $i), range(0, H02_BROWSERS - 1)), 0);

    for ($day = 0; $day < H02_DAYS; $day++) {
        $base = $day * 86400 + 9 * 3600;
        $startEstablished = 0;
        $cleared = 0;
        $expired = 0;
        foreach (array_keys($establishedDay) as $who) {
            if ($cfg['ttlDays'] !== null && $day - $establishedDay[$who] >= $cfg['ttlDays']) {
                unset($establishedDay[$who]);
                $expired++;
                continue;
            }
            if ($cfg['clear'] > 0 && $rng->pct() <= $cfg['clear']) {
                unset($establishedDay[$who]);
                $idGen[$who]++;
                $cleared++;
                continue;
            }
            $startEstablished++;
        }

        // Per-day arrival order (deterministic shuffle) and typo draw.
        $order = array_keys($idGen);
        for ($i = count($order) - 1; $i > 0; $i--) {
            $j = $rng->pct() % ($i + 1);
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        }
        $queue = [];
        foreach ($order as $pos => $who) {
            if (isset($establishedDay[$who])) {
                continue; // IDEALIZED: admitted, not sent to the pipeline
            }
            $t = $base + $pos * 18;
            if ($rng->pct() <= 10) {
                $queue[] = ['t' => $t, 'who' => $who, 'outcome' => 'fail', 'try' => 0];
                $queue[] = ['t' => $t + 15, 'who' => $who, 'outcome' => 'ok', 'try' => 0];
            } else {
                $queue[] = ['t' => $t, 'who' => $who, 'outcome' => 'ok', 'try' => 0];
            }
        }
        if ($cfg['attack']) {
            for ($t = $base - 600, $n = 0; $t <= $base + 3600; $t += 60, $n++) {
                $queue[] = ['t' => $t, 'who' => 'attacker', 'outcome' => 'fail', 'try' => 0, 'n' => $n];
            }
        }

        $newcomers = [];
        $deniedOnce = [];
        $admittedOk = [];
        $gained = 0;
        $capped = 0;
        $attackReach = 0;
        $check = '';
        while ($queue !== []) {
            usort($queue, static fn(array $a, array $b): int => [$a['t'], $a['who'], $a['try']] <=> [$b['t'], $b['who'], $b['try']]);
            $e = array_shift($queue);
            $lab->at($e['t']);
            if ($e['who'] === 'attacker') {
                $c = hmciCtx('M0', OFFICE_NAT, FIREFOX_130, sprintf('acct-v%02d-%03d', $day, $e['n']), bid('attacker', $day * 1000 + $e['n']));
                $r = $lab->attempt($c, 'pre+out', 'fail');
                $attackReach += decisionChars($r)[0] === 'A' ? 1 : 0;
                continue;
            }
            $who = $e['who'];
            if (isset($establishedDay[$who])) {
                continue; // became established earlier today: later attempts are IDEALIZED
            }
            $newcomers[$who] = true;
            $c = hmciCtx('M0', OFFICE_NAT, FIREFOX_130, "acct-$who", bid($who, $idGen[$who]));
            $r = $lab->attempt($c, 'pre+out', $e['outcome']);
            [$ch] = decisionChars($r);
            $check .= $ch;
            if ($ch !== 'A') {
                $deniedOnce[$who] = true;
                if ($e['try'] < 3) {
                    preg_match('#/(\d+)$#', explode('>', $r['steps'])[0], $m);
                    $queue[] = ['t' => $e['t'] + max((int) ($m[1] ?? 60), 60), 'who' => $who, 'outcome' => $e['outcome'], 'try' => $e['try'] + 1];
                }
                continue;
            }
            if ($e['outcome'] === 'ok') {
                $admittedOk[$who] = true;
                if ($cfg['enet'] === null || $gained < $cfg['enet']) {
                    $establishedDay[$who] = $day;
                    $gained++;
                } else {
                    $capped++;
                }
            }
        }

        emit('h02', [
            'row' => 'day', 'variant' => $variant, 'day' => $day + 1,
            'establishedAtStart' => $startEstablished, 'cleared' => $cleared, 'expired' => $expired,
            'newcomers' => count($newcomers), 'newcomersDeniedOnce' => count($deniedOnce),
            'newcomersAdmittedLogin' => count($admittedOk), 'newcomersNoLoginToday' => count($newcomers) - count($admittedOk),
            'gained' => $gained, 'cappedEligible' => $capped, 'establishedAtEnd' => count($establishedDay),
            'attackerReach' => $cfg['attack'] ? $attackReach : null, 'check' => $check,
        ]);
    }
}
