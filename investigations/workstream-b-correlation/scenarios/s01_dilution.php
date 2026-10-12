<?php

declare(strict_types=1);

// #97 — LOW-confidence dilution. Real pipeline, controlled clock, login policy.
require dirname(__DIR__) . '/lib/bootstrap.php';

/** One new independent client per step; returns rows. */
function dilutionRun(string $id, string $flow, string $outcome, int $users, int $stepSeconds, callable $ctxFor): void
{
    $lab = new Lab('login');
    for ($i = 1; $i <= $users; $i++) {
        $lab->at(($i - 1) * $stepSeconds);
        $c = $ctxFor($lab, $i);
        $r = $lab->attempt($c, $flow, $outcome);
        emit($id, ['who' => "u$i"] + $r + ['blocks' => $lab->blocks($c), 'scores' => $lab->scores($c)]);
    }
}

$v4 = static fn(Lab $lab, int $i) => $lab->ctx("198.51.100.$i", CHROME, "user$i");

dilutionRun('A1.pre+out.ok', 'pre+out', 'ok', 8, 10, $v4);
dilutionRun('A1c.pre+out.ok.five-users', 'pre+out', 'ok', 5, 10, $v4);
dilutionRun('A1d.pre-only', 'pre', 'ok', 6, 10, $v4);
dilutionRun('A1e.out-only.ok', 'out', 'ok', 6, 10, $v4);
dilutionRun('A2.pre+out.fail', 'pre+out', 'fail', 7, 10, $v4);
dilutionRun('A2b.out-only.fail', 'out', 'fail', 6, 10, $v4);

// Collateral after A1 (t=80): who is affected.
$lab = new Lab('login');
for ($i = 1; $i <= 8; $i++) {
    $lab->at(($i - 1) * 10);
    $lab->attempt($v4($lab, $i));
}
$lab->at(80);
foreach ([
    ['new user on blocked IP#6', '198.51.100.6', CHROME, 'uX'],
    ['user6 account from a new IP', '203.0.113.9', CHROME, 'user6'],
    ['same IP#6, other UA major', '198.51.100.6', CHROME_B, 'uY'],
    ['returning user1', '198.51.100.1', CHROME, 'user1'],
] as [$who, $ip, $ua, $acct]) {
    emit('A1b.collateral', ['who' => $who] + $lab->attempt($lab->ctx($ip, $ua, $acct)));
}

// Controls.
$uas = ['120', '121', '122', '123', '124', '125', '126', '128'];
dilutionRun('A3.control.distinct-ua', 'pre+out', 'ok', 8, 10, static fn(Lab $lab, int $i) => $lab->ctx("198.51.100.$i", 'Mozilla/5.0 AppleWebKit/537.36 Chrome/' . $uas[$i - 1] . '.0.0.0 Safari/537.36', "user$i"));
dilutionRun('A4.medium.same-device-roaming', 'pre+out', 'ok', 8, 10, static fn(Lab $lab, int $i) => $lab->ctx("198.51.100.$i", CHROME, 'userR', fp('same')));
dilutionRun('A4.medium.distinct-devices', 'pre+out', 'ok', 8, 10, static fn(Lab $lab, int $i) => $lab->ctx("198.51.100.$i", CHROME, "user$i", fp("dev$i")));
dilutionRun('A5.high.trusted-session', 'pre+out', 'ok', 8, 10, static fn(Lab $lab, int $i) => $lab->ctx("198.51.100.$i", CHROME, 'user1', null, 'sess-1', true));

// IPv6 (no account id so credential spray is not a confound).
dilutionRun('A6.ipv6.same-48-distinct-64', 'pre+out', 'ok', 8, 10, static fn(Lab $lab, int $i) => $lab->ctx(sprintf('2001:db8:aaaa:%x::1', $i), CHROME, null));
dilutionRun('A6.ipv6.distinct-48', 'pre+out', 'ok', 8, 10, static fn(Lab $lab, int $i) => $lab->ctx(sprintf('2001:db8:%x::1', 0x1000 + $i), CHROME, null));

// Population timelines (valid logins). One logical attempt each; report blocked counts per run.
foreach (['C1.every-20s' => 20, 'C1b.every-60s' => 60, 'C1c.every-150s' => 150] as $id => $step) {
    $lab = new Lab('login');
    $blocked = 0;
    $total = 0;
    $runs = [];
    $cur = null;
    for ($t = 0, $n = 1; $t <= 2400; $t += $step, $n++) {
        $lab->at($t);
        $r = $lab->attempt($lab->ctx('198.51.' . intdiv($n, 200) . '.' . ($n % 200 + 1), CHROME, "u$n"));
        $total++;
        $blocked += $r['blocked'] ? 1 : 0;
        if ($cur === null || $cur['blocked'] !== $r['blocked']) {
            if ($cur !== null) {
                $runs[] = $cur;
            }
            $cur = ['blocked' => $r['blocked'], 'from' => $t, 'to' => $t];
        } else {
            $cur['to'] = $t;
        }
    }
    $runs[] = $cur;
    emit($id, ['blockedAttempts' => "$blocked/$total", 'runs' => array_map(static fn(array $x): string => ($x['blocked'] ? 'B' : 'a') . "{$x['from']}-{$x['to']}", $runs)]);
}

// R8: K3 two-window confirmation depends on the wall-clock phase of the 600 s window.
foreach ([0, 100, 300, 500, 590] as $phase) {
    $lab = new Lab('login');
    $off = ($phase - ($lab->t0 % 600) + 600) % 600;
    $first = null;
    foreach ([0, 10, 20, 30, 40, 50, 60, 70, 120, 140, 160, 300, 500, 580] as $i => $d) {
        $lab->at($off + $d);
        $c = $lab->ctx('198.51.100.' . ($i + 1), CHROME, 'userR', fp('same'));
        $r = $lab->attempt($c);
        if ($first === null && $lab->blocks($c) !== []) {
            $first = ['d' => $d, 'blocks' => $lab->blocks($c), 'wallWindowOffset' => $d + $phase];
        }
    }
    emit('R8.k3-confirmation-phase', ['setCreatedAtWindowOffset' => $phase, 'firstK3BlockAfterSeconds' => $first['d'] ?? null, 'blocks' => $first['blocks'] ?? []]);
}
