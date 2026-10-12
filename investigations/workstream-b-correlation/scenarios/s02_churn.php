<?php

declare(strict_types=1);

// #100 — shared-K2 churn. Real pipeline, controlled clock, login policy.
require dirname(__DIR__) . '/lib/bootstrap.php';

/** @param list<string> $seq */
function churnRun(string $id, string $flow, array $seq, int $step, callable $ctxFor, string $outcome = 'ok'): void
{
    $lab = new Lab('login');
    foreach ($seq as $i => $cl) {
        $lab->at($i * $step);
        $c = $ctxFor($lab, $cl, $i);
        emit($id, ['who' => $cl] + $lab->attempt($c, $flow, $outcome) + ['blocks' => $lab->blocks($c), 'scores' => $lab->scores($c)]);
    }
}

$nat = static fn(Lab $lab, string $cl, int $i) => $lab->ctx(NAT, CHROME, "acct$cl", fp($cl));

churnRun('B1.pre+out.valid', 'pre+out', ['A', 'B', 'A', 'B', 'A', 'B'], 20, $nat);
churnRun('B1.pre-only', 'pre', ['A', 'B', 'A', 'B', 'A', 'B'], 20, $nat);
churnRun('B1.out-only.valid', 'out', ['A', 'B', 'A', 'B', 'A', 'B'], 20, $nat);
churnRun('B2.same-fingerprint', 'pre+out', ['A', 'A', 'A', 'A', 'A', 'A'], 20, $nat);
churnRun('B3.separate-k2', 'pre+out', ['A', 'B', 'A', 'B', 'A', 'B'], 20, static fn(Lab $lab, string $cl, int $i) => $lab->ctx(NAT, $cl === 'A' ? CHROME : CHROME_B, "acct$cl", fp($cl)));
churnRun('B4.three-clients', 'pre+out', ['A', 'B', 'C', 'A', 'B', 'C'], 20, $nat);
churnRun('B4b.low-clients-no-fp', 'pre+out', ['A', 'B', 'A', 'B'], 20, static fn(Lab $lab, string $cl, int $i) => $lab->ctx(NAT, CHROME, "acct$cl"));

// Adversary: fresh client FP per request, failing against one victim account.
$lab = new Lab('login');
for ($i = 0; $i < 8; $i++) {
    $lab->at($i * 5);
    $c = $lab->ctx('198.51.100.77', CHROME, 'victim', fp("rot$i"));
    emit('B5.fp-rotation.fail', ['who' => "rot$i"] + $lab->attempt($c, 'pre+out', 'fail') + ['blocks' => $lab->blocks($c), 'scores' => $lab->scores($c)]);
}
$lab->at(45);
foreach ([['same IP, new UA major', '198.51.100.77', CHROME_B], ['new IP, same UA', '198.51.100.78', CHROME]] as [$who, $ip, $ua]) {
    $c = $lab->ctx($ip, $ua, 'victim', fp("evade-$who"));
    emit('B5b.pivot', ['who' => $who] + $lab->attempt($c, 'pre+out', 'fail') + ['blocks' => $lab->blocks($c)]);
}

// Enforcement scope after a K2 churn block.
$lab = new Lab('login');
$lab->at(0);
$lab->attempt($lab->ctx(NAT, CHROME, 'acctA', fp('A')));
$lab->at(5);
$lab->attempt($lab->ctx(NAT, CHROME, 'acctB', fp('B')));
$lab->at(10);
foreach ([
    ['acctA other IP same UA', '203.0.113.60', CHROME, 'acctA', fp('A')],
    ['acctA same IP other UA major', NAT, CHROME_B, 'acctA', fp('A2')],
    ['acctC same K2 third client', NAT, CHROME, 'acctC', fp('C')],
    ['acctA same K2 same FP', NAT, CHROME, 'acctA', fp('A')],
] as [$who, $ip, $ua, $acct, $f]) {
    $c = $lab->ctx($ip, $ua, $acct, $f);
    emit('E1.enforcement-scope', ['who' => $who] + $lab->attempt($c) + ['blocks' => $lab->blocks($c)]);
}

// IPv6.
churnRun('B-v6.two-clients-one-64', 'pre+out', ['A', 'B', 'A'], 20, static fn(Lab $lab, string $cl, int $i) => $lab->ctx('2001:db8:aaaa:1::' . ($i + 1), CHROME, null, fp($cl)));
churnRun('B-v6-macro.six-clients-six-64', 'pre+out', ['A', 'B', 'C', 'D', 'E', 'F'], 20, static fn(Lab $lab, string $cl, int $i) => $lab->ctx(sprintf('2001:db8:aaaa:%x::1', $i + 1), CHROME, null, fp($cl)));

// Timeline. A at 0, B at 10 triggers; then single legit client A alone every 15 s.
$lab = new Lab('login');
$lab->at(0);
$lab->attempt($lab->ctx(NAT, CHROME, 'acctA', fp('A')));
$lab->at(10);
emit('B6.seed', $lab->attempt($lab->ctx(NAT, CHROME, 'acctB', fp('B'))));
$runs = [];
$cur = null;
for ($t = 15; $t <= 2400; $t += 15) {
    $lab->at($t);
    $r = $lab->attempt($lab->ctx(NAT, CHROME, 'acctA', fp('A')));
    if ($cur === null || $cur['b'] !== $r['blocked']) {
        if ($cur !== null) {
            $runs[] = $cur;
        }
        $cur = ['b' => $r['blocked'], 'from' => $t, 'to' => $t];
    } else {
        $cur['to'] = $t;
    }
}
$runs[] = $cur;
emit('B6.single-client-after-pair', ['runs' => array_map(static fn(array $x): string => ($x['b'] ? 'B' : 'a') . "{$x['from']}-{$x['to']}", $runs)]);

// B7. Two legit clients alternate every 30 s for 4000 s.
$lab = new Lab('login');
$res = ['A' => ['b' => 0, 'n' => 0, 'runs' => []], 'B' => ['b' => 0, 'n' => 0, 'runs' => []]];
$curs = ['A' => null, 'B' => null];
for ($t = 0, $n = 0; $t <= 4000; $t += 30, $n++) {
    $cl = $n % 2 === 1 ? 'B' : 'A';
    $lab->at($t);
    $r = $lab->attempt($lab->ctx(NAT, CHROME, "acct$cl", fp($cl)));
    $res[$cl]['n']++;
    $res[$cl]['b'] += $r['blocked'] ? 1 : 0;
    $cur = &$curs[$cl];
    if ($cur === null || $cur['b'] !== $r['blocked']) {
        if ($cur !== null) {
            $res[$cl]['runs'][] = $cur['l'] . "{$cur['from']}-{$cur['to']}";
        }
        $cur = ['b' => $r['blocked'], 'l' => $r['blocked'] ? 'B' : 'a', 'from' => $t, 'to' => $t];
    } else {
        $cur['to'] = $t;
    }
    unset($cur);
}
foreach (['A', 'B'] as $cl) {
    $res[$cl]['runs'][] = $curs[$cl]['l'] . "{$curs[$cl]['from']}-{$curs[$cl]['to']}";
    emit('B7.two-legit-clients-4000s', ['client' => $cl, 'blockedAttempts' => "{$res[$cl]['b']}/{$res[$cl]['n']}", 'runs' => $res[$cl]['runs']]);
}
