<?php

declare(strict_types=1);

// G3 v2 — bucket with refund and known-device routing (PLAN.md §2, §4, §5 TBK-1 … TBK-3) on the NOESC build. TB30R is the v1
// arm re-run (V0); TBK30R-HV runs BRU24 with every attacker request marked Host-verified (reported only).

require dirname(__DIR__) . '/lib/g3v2.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

$prov = g3Provenance();
if ($prov['build'] !== 'NOESC') {
    fwrite(STDERR, "v2_tb must run on the NOESC build\n");
    exit(4);
}
$off = 1000000;
$arms = [
    'TB30R' => ['tbC' => 4, 'tbT' => 1800, 'refund' => true],
    'TBK10R' => ['tbC' => 4, 'tbT' => 600, 'refund' => true, 'known' => true],
    'TBK30R' => ['tbC' => 4, 'tbT' => 1800, 'refund' => true, 'known' => true],
    'TBK60R' => ['tbC' => 4, 'tbT' => 3600, 'refund' => true, 'known' => true],
];
foreach ($arms as $arm => $opts) {
    [$cell, $gate] = g3v2Cell(g3Params(40, $off), $opts);
    $r = g3v2Replay($cell, $gate, g3v2Bru24(), 'pre+out');
    $takes = count(array_filter($gate->tbLog, static fn(array $e): bool => $e['kind'] === 'take'));
    g3Emit('v2tb', ['fixture' => 'BRU24', 'arm' => $arm, 'tbT' => $opts['tbT'], 'tbC' => $opts['tbC'],
        'attackAdmitted' => substr_count($r['reach'], '1'), 'tbTakesRecount' => $takes, 'refunds' => $gate->refunds,
        'knownBypass' => $gate->knownBypass, 'attackDeniedByCause' => $r['attackDeniedByCause']]);

    foreach ([0, 60, 300, 900, 1800, 3600] as $delta) {
        [$cell, $gate] = g3v2Cell(g3Params(40, $off), $opts);
        g3v2Replay($cell, $gate, array_values(array_filter(g3v2Bru24(), static fn(array $x): bool => $x['t'] < 1800)), 'pre+out');
        $owner = new RateLimitContextDTO('203.0.113.200', FIREFOX_130, 'acct-v', null, null, false, [], false, null);
        $admittedAt = null;
        for ($t = 1785 + $delta; $t <= 1785 + $delta + 7200; $t += 60) {
            $res = g3v2Replay($cell, $gate, [['t' => $t, 'ctx' => $owner, 'proof' => null, 'kind' => 'O', 'own' => false, 'who' => 'owner', 'outcome' => 'ok']], 'pre+out');
            if ($res['dec'] === 'A') {
                $admittedAt = $t;
                break;
            }
        }
        g3Emit('v2tb', ['fixture' => 'REC', 'arm' => $arm, 'tbT' => $opts['tbT'], 'delta' => $delta,
            'admittedAfterAttackEnd' => $admittedAt === null ? null : $admittedAt - 1785,
            'waitFromArrival' => $admittedAt === null ? null : $admittedAt - 1785 - $delta]);
    }

    foreach ($arm === 'TBK30R' ? ['pre+out', 'pre'] : ['pre+out'] as $flow) {
        foreach (G3_STREAMS as $id) {
            foreach (g1Strategies($id) as $strategy) {
                $rows = g1Materialise($id, 'login', $strategy);
                [$cell, $gate] = g3v2Cell(g3Params(40, $off), $opts);
                $r = g3v2Replay($cell, $gate, $rows, $flow);
                g3Emit('v2tb', ['fixture' => 'stream', 'stream' => $id, 'cls' => g1Streams()[$id]['cls'], 'strategy' => $strategy,
                    'arm' => $arm, 'flow' => $flow] + g3Row($rows, $r));
            }
        }
    }
}
[$cell, $gate] = g3v2Cell(g3Params(40, $off), $arms['TBK30R']);
$r = g3v2Replay($cell, $gate, g3v2Bru24(true), 'pre+out');
g3Emit('v2tb', ['fixture' => 'BRU24-HV', 'arm' => 'TBK30R-HV', 'tbT' => 1800, 'attackAdmitted' => substr_count($r['reach'], '1'),
    'knownBypass' => $gate->knownBypass, 'attackDeniedByCause' => $r['attackDeniedByCause']]);
