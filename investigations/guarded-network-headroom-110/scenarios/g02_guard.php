<?php

declare(strict_types=1);

// G02 — the guard itself, sequential, on the loaded arm (reference stores, controlled clock, Login).
//   B1  K1 spray budget exactness: 5 subjects open the S0 zone, then one KNOWN subject repeats (checkOnly only: no outcome, no K4)
//   B2  fixed-window bound and the boundary burst: two budget windows back to back, admissions inside one sliding 600 s
//   B3  K2 churn budget exactness (checkOnly only), B4 the same in pre+out (two commands per attempt) and record-only
//   B5  one party exhausts the NAT headroom: an attacker spends the K2 budget, then a colleague with its own device logs in
//   B6  60 / 600 s boundaries and block renewal after exhaustion (probes on the B3 scope)
//   B7  DEC-003 hard-block cycles on ABA_A and XN1 (cycle log of the real store)
require dirname(__DIR__) . '/lib/gnh.php';

emit('G02.provenance', gnhProvenance());
$arm = gnhArm();
$B = GNH_BUDGET_PER_RULE;

/** @param list<array<string, mixed>> $events */
$play = static function (array $events, string $flow = 'pre', ?Lab $lab = null): array {
    $lab ??= gnhLab();

    return gnhReplay($lab, $events, $flow, 'login', false);
};
$allowed = static fn(array $rows): int => count(array_filter($rows, static fn(array $r): bool => ! $r['blocked']));
$firstDeny = static function (array $rows): ?int {
    foreach ($rows as $r) {
        if ($r['blocked']) {
            return $r['t'];
        }
    }

    return null;
};
$sum = static fn(array $rows, string $f): int => array_sum(array_column($rows, $f));

// B1 — K1 spray: t=0..4 subjects s1..s5 (the 5th meets the S0 rule), t=5..64 subject s1 again every second.
$ev = [];
for ($i = 0; $i < 5; $i++) {
    $ev[] = k1Ev($i, '198.51.100.150', null, "b1s$i", 'fail', 'attack', ['uaRaw' => k1Ua(91)]);
}
for ($t = 5; $t <= 64; $t++) {
    $ev[] = k1Ev($t, '198.51.100.150', null, 'b1s0', 'fail', 'attack', ['uaRaw' => k1Ua(91)]);
}
$rows = $play($ev);
$b1 = ['requests' => count($rows), 'allowed' => $allowed($rows), 'firstDenyT' => $firstDeny($rows), 'headroomAdmissions' => $sum($rows, 'headroom'), 'budgetMax' => max(array_column($rows, 'budgetMax')), 'firstDenial' => $rows[array_search(true, array_column($rows, 'blocked'), true) ?: 0]['steps']];
emit('G02.B1.spray-budget', ['arm' => $arm] + $b1);
GnhAsserts::check('G02', 'B1 ' . match ($arm) {
    'S0' => 'S0: the 5th distinct subject is denied (t=4) and nothing more passes: 4 of 65 checkOnly requests allowed',
    'TH5' => 'TH5: the x5 spray rule never stops a KNOWN subject repeated inside the S0 zone: all 65 checkOnly requests allowed (the zone [5, 25) is free)',
    'GNH5' => 'GNH5: exactly B=20 headroom admissions (the 5th subject + 19 repeats), the 21st in-zone request is denied at t=24: 24 of 65 allowed',
}, match ($arm) {
    'S0' => $b1['allowed'] === 4 && $b1['firstDenyT'] === 4,
    'TH5' => $b1['allowed'] === 65 && $b1['firstDenyT'] === null,
    'GNH5' => $b1['allowed'] === 24 && $b1['firstDenyT'] === 24 && $b1['headroomAdmissions'] === $B['spray'],
}, $b1);

// B2 — fixed window and boundary burst. Window 1: t=0..3 subjects 1..4 (free), t=4 subject 5 (budget #1), t=500..530 repeats of
// subject 1 (budget #2.. until exhausted). The S0 set expires at t=600, the budget at t=604. Window 2: t=605..608 subjects 1..4,
// t=609 subject 5, t=610..640 repeats. Every request is checkOnly only.
$ev = [];
$add = static function (int $t, string $s) use (&$ev): void {
    $ev[] = k1Ev($t, '198.51.100.152', null, $s, 'fail', 'attack', ['uaRaw' => k1Ua(94)]);
};
foreach ([0, 605] as $base) {
    for ($i = 0; $i < 5; $i++) {
        $add($base + $i, "b2s$i");
    }
}
for ($t = 500; $t <= 530; $t++) {
    $add($t, 'b2s0');
}
for ($t = 610; $t <= 640; $t++) {
    $add($t, 'b2s0');
}
$ev = k1Sort($ev);
$rows = $play($ev);
$admitT = [];
foreach ($rows as $r) {
    if (! $r['blocked']) {
        $admitT[] = $r['t'];
    }
}
$maxSliding = 0;
$maxSlidingHeadroom = 0;
foreach ($rows as $a) {
    $n = 0;
    $h = 0;
    foreach ($rows as $r) {
        if ($r['t'] >= $a['t'] && $r['t'] < $a['t'] + 600) {
            $n += $r['blocked'] ? 0 : 1;
            $h += $r['headroom'];
        }
    }
    $maxSliding = max($maxSliding, $n);
    $maxSlidingHeadroom = max($maxSlidingHeadroom, $h);
}
$perWindowHeadroom = [];
foreach ($rows as $r) {
    $w = $r['t'] < 604 ? 'w1' : 'w2';
    $perWindowHeadroom[$w] = ($perWindowHeadroom[$w] ?? 0) + $r['headroom'];
}
$b2 = ['requests' => count($rows), 'allowed' => count($admitT), 'headroomPerBudgetWindow' => $perWindowHeadroom, 'maxAllowedInSliding600' => $maxSliding, 'maxHeadroomInSliding600' => $maxSlidingHeadroom];
emit('G02.B2.window-boundary', ['arm' => $arm] + $b2);
GnhAsserts::check('G02', 'B2 ' . match ($arm) {
    'S0' => 'S0: 4 checkOnly requests pass in the first S0 window and 3 in the second (the 1800 s N-1 WATCH carries over): 7 in total, never more than 4 inside any 600 s',
    'TH5' => 'TH5: every one of the 72 checkOnly requests passes (no bound on repeated in-zone requests)',
    'GNH5' => 'GNH5: exactly B=20 headroom admissions per fixed budget window; across the window boundary one sliding 600 s holds at most 2B = 40 of them',
}, match ($arm) {
    'S0' => count($admitT) === 7 && $maxSliding <= 4,
    'TH5' => count($admitT) === 72,
    'GNH5' => $perWindowHeadroom === ['w1' => 20, 'w2' => 20] && $maxSlidingHeadroom <= 40,
}, $b2);

// B3 / B4 — K2 churn: fingerprints f0, f1, f2 at t=0,1,2 on one IP+UA, one account per device (the 3rd meets the S0 rule; three devices
// on ONE account would meet the §5.2 distributed-account rule instead), then f0 on its own account again every second to t=40.
$churnEvents = static function (string $ip, int $ua, string $outcome): array {
    $ev = [];
    for ($i = 0; $i < 3; $i++) {
        $ev[] = k1Ev($i, $ip, "b3f$i", "b3acct$i", $outcome, 'attack', ['uaRaw' => k1Ua($ua)]);
    }
    for ($t = 3; $t <= 40; $t++) {
        $ev[] = k1Ev($t, $ip, 'b3f0', 'b3acct0', $outcome, 'attack', ['uaRaw' => k1Ua($ua)]);
    }

    return $ev;
};
$b3 = [];
foreach (['pre' => 'ok', 'pre+out' => 'ok', 'out' => 'ok'] as $flow => $outcome) {
    $rows = $play($churnEvents('198.51.100.153', 95, $outcome), $flow);
    $b3[$flow] = ['requests' => count($rows), 'allowedAttempts' => $allowed($rows), 'firstDenyT' => $firstDeny($rows), 'headroomCommands' => $sum($rows, 'headroom'), 'budgetMax' => max(array_column($rows, 'budgetMax'))];
}
emit('G02.B3.churn-budget-flows', ['arm' => $arm, 'flows' => $b3]);
GnhAsserts::check('G02', 'B3 ' . match ($arm) {
    'S0' => 'S0: checkOnly only, the 3rd fingerprint is denied (t=2): 2 of 41 allowed',
    'TH5' => 'TH5: checkOnly only, a known fingerprint repeated inside the S0 zone is never stopped: 41 of 41 allowed',
    'GNH5' => 'GNH5: checkOnly only, exactly B=12 headroom admissions (the 3rd fingerprint + 11 repeats), then denied at t=14: 14 of 41 allowed',
}, match ($arm) {
    'S0' => $b3['pre']['allowedAttempts'] === 2 && $b3['pre']['firstDenyT'] === 2,
    'TH5' => $b3['pre']['allowedAttempts'] === 41,
    'GNH5' => $b3['pre']['allowedAttempts'] === 14 && $b3['pre']['firstDenyT'] === 14 && $b3['pre']['headroomCommands'] === $B['churn'],
}, $b3['pre']);
GnhAsserts::check('G02', 'B4 ' . ($arm === 'GNH5'
    ? 'GNH5: the budget counts evaluated COMMANDS (no logical-attempt id exists): in pre+out the zone opens at the 2nd fingerprint\'s outcome (the S0 N-1 WATCH is incremented by both commands) and each zone attempt spends 2, so 7 attempts pass and the 8th is denied at its outcome (t=7); record-only spends 1 (14 allowed); both spend exactly B=12'
    : "$arm: pre+out and record-only allowed attempts recorded as reference (no budget on this arm)"), $arm === 'GNH5'
    ? $b3['pre+out']['allowedAttempts'] === 7 && $b3['pre+out']['firstDenyT'] === 7 && $b3['pre+out']['headroomCommands'] === 12 && $b3['out']['allowedAttempts'] === 14 && $b3['out']['headroomCommands'] === 12
    : $b3['pre+out']['headroomCommands'] === 0 && $b3['out']['headroomCommands'] === 0, ['pre+out' => $b3['pre+out'], 'out' => $b3['out']]);

// B5 — one party exhausts the NAT headroom with traffic that looks legitimate. On NAT + one browser: a neighbour with three devices on three
// accounts of its own logs in SUCCESSFULLY once per second for 15 s (a0, a1, a2, then a0 repeated); then a colleague with its own stable
// device logs in successfully every 20 s from t=20 to t=300 (15 attempts). Nothing fails: only the shared K2 headroom is spent.
$ev = [];
for ($t = 0; $t < 15; $t++) {
    $d = min($t, 2) === 2 && $t > 2 ? 0 : min($t, 2);
    $ev[] = k1Ev($t, NAT, "b5a$d", "b5atk$d", 'ok', 'attack', ['uaRaw' => k1Ua(96)]);
}
for ($t = 20; $t <= 300; $t += 20) {
    $ev[] = k1Ev($t, NAT, 'b5colleague', 'b5col', 'ok', 'legit', ['uaRaw' => k1Ua(96), 'who' => 'col']);
}
$rows = $play($ev, 'pre+out');
$leg = array_values(array_filter($rows, static fn(array $r): bool => $r['kind'] === 'legit'));
$atk = array_values(array_filter($rows, static fn(array $r): bool => $r['kind'] === 'attack'));
$b5 = ['attackerAttempts' => count($atk), 'attackerReached' => count(array_filter($atk, static fn(array $r): bool => $r['reached'])), 'colleagueAttempts' => count($leg), 'colleagueDenied' => count(array_filter($leg, static fn(array $r): bool => $r['blocked'])), 'colleagueSteps' => gnhSteps($leg), 'colleagueDeniedBySource' => gnhAggregate($leg)['legitDeniedBySource']];
emit('G02.B5.nat-exhaustion', ['arm' => $arm] + $b5);
GnhAsserts::check('G02', 'B5 ' . match ($arm) {
    'S0' => 'S0: the colleague arriving after the neighbour is denied 15/15 by the shared K2 scope (reference for the exhaustion case)',
    'TH5' => 'TH5: the colleague is never denied (4 fingerprints < 15)',
    'GNH5' => 'GNH5: the neighbour alone spends the K2 budget within its first seconds; the colleague is then denied exactly like S0 (15/15): an exhausted network falls back to S0 for everyone on it',
}, match ($arm) {
    'S0' => $b5['colleagueDenied'] === 15,
    'TH5' => $b5['colleagueDenied'] === 0,
    'GNH5' => $b5['colleagueDenied'] === 15,
}, $b5);

// B6 — boundaries on the B3 scope (checkOnly only): probes with the known fingerprint after the first denial.
$ev = $churnEvents('198.51.100.154', 97, 'ok');
$ev = array_values(array_filter($ev, static fn(array $e): bool => $e['t'] <= 14));
foreach ([73, 74, 75, 134, 135, 600, 601] as $t) {
    $ev[] = k1Ev($t, '198.51.100.154', 'b3f0', 'b3acct0', 'ok', 'attack', ['uaRaw' => k1Ua(97)]);
}
$rows = $play($ev);
$probe = array_values(array_filter($rows, static fn(array $r): bool => $r['t'] >= 73));
$deny = array_values(array_filter($rows, static fn(array $r): bool => $r['blocked']));
$b6 = ['firstDenial' => $deny === [] ? null : ['t' => $deny[0]['t'], 'steps' => $deny[0]['steps']], 'probes' => array_map(static fn(array $r): string => $r['t'] . ':' . ($r['blocked'] ? 'B(' . $r['src'] . ')' : 'a'), $probe), 'issuedAt' => array_values(array_map(static fn(array $r): int => $r['t'], array_filter($rows, static fn(array $r): bool => $r['issued'] !== [])))];
emit('G02.B6.boundaries', ['arm' => $arm] + $b6);
$flags = array_map(static fn(array $r): string => $r['t'] . ':' . ($r['blocked'] ? 'B' : 'a'), $probe);
GnhAsserts::check('G02', 'B6 ' . match ($arm) {
    'S0' => 'S0: L2/60 s K2 block at t=2; the 600 s set re-issues it at t=73/74 (#100 renewal) and t=134/135; at t=600 the set has expired and the request passes',
    'TH5' => 'TH5: no probe is ever denied on this scope',
    'GNH5' => 'GNH5: L2/60 s K2 block at t=14 on exhaustion; the exhausted budget re-issues it after expiry exactly like S0 (#100 renewal stays), and at t=600 the S0 zone ends and the request passes',
}, match ($arm) {
    'S0' => $flags === ['73:B', '74:B', '75:B', '134:B', '135:B', '600:a', '601:a'] && ($b6['firstDenial']['t'] ?? null) === 2,
    'TH5' => $deny === [],
    'GNH5' => $flags === ['73:B', '74:B', '75:B', '134:B', '135:B', '600:a', '601:a'] && ($b6['firstDenial']['t'] ?? null) === 14 && str_contains($b6['firstDenial']['steps'], 'H2/60'),
}, $b6);

// B7 — DEC-003 cycle log on two attack streams (pre+out): newCycle transitions, the highest cycle count and decay-pause activations.
$streams = gnhStreams();
$b7 = [];
foreach (['ABA_A', 'XN1', 'D2'] as $id) {
    $lab = gnhLab();
    gnhReplay($lab, $streams[$id]['events'], 'pre+out', 'login', false);
    $log = $lab->store->cycleLog;
    $b7[$id] = [
        'blockWrites' => count($log),
        'newCycles' => count(array_filter($log, static fn(array $c): bool => $c['result']->newCycle)),
        'maxCycleCount' => $log === [] ? 0 : max(array_map(static fn(array $c): int => $c['result']->cycleCount, $log)),
        'pauseActivations' => count(array_filter($log, static fn(array $c): bool => $c['result']->pauseActivated)),
    ];
}
emit('G02.B7.dec003-cycles', ['arm' => $arm, 'streams' => $b7]);
GnhAsserts::check('G02', 'B7 DEC-003 cycle tracking stays active on this arm: every stream that writes L2+ blocks records new cycles and activates the decay pause at the 2nd cycle', array_reduce($b7, static fn(bool $c, array $s): bool => $c && ($s['blockWrites'] === 0 || ($s['newCycles'] > 0 && ($s['maxCycleCount'] < 2 || $s['pauseActivations'] > 0))), true), $b7);
GnhAsserts::finish();
