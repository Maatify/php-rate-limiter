<?php

declare(strict_types=1);

// G3 S03 — per-account guessing budget (PLAN.md §2.4, §4.2, §5 TB-1 … TB-4).
//   CAND build : G1ESC (S40 fixed window, G1 escalation active) on BRU24 and REC.
//   NOESC build: FW16 (fixed window 16, no escalation), TB10 / TB30 / TB60 and their refund variants, on BRU24, REC and the §4.1
//                streams (pre+out; pre for TB30 and TB30R; out for TB30R).
// The attacker rotates source and UA on every request (the free identifiers this pipeline sees; the proposal's client-id field
// is not built, so a client id has no input here).

require dirname(__DIR__) . '/lib/g3.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

$prov = g3Provenance();
$build = $prov['build'];
const G3_TB_C = 4;
$off = 1000000;
$arms = $build === 'CAND'
    ? ['G1ESC' => [16, []]]
    : [
        'FW16' => [16, []],
        'TB10' => [$off, ['tbC' => G3_TB_C, 'tbT' => 600]], 'TB30' => [$off, ['tbC' => G3_TB_C, 'tbT' => 1800]], 'TB60' => [$off, ['tbC' => G3_TB_C, 'tbT' => 3600]],
        'TB10R' => [$off, ['tbC' => G3_TB_C, 'tbT' => 600, 'refund' => true]], 'TB30R' => [$off, ['tbC' => G3_TB_C, 'tbT' => 1800, 'refund' => true]],
        'TB60R' => [$off, ['tbC' => G3_TB_C, 'tbT' => 3600, 'refund' => true]],
    ];

/** BRU24: one victim, the attacker fails every 15 s for 86 400 s. */
function g3Bru24(): array
{
    $rows = [];
    for ($t = 0, $n = 0; $t < 86400; $t += 15, $n++) {
        $rows[] = ['t' => $t, 'ctx' => g3Attacker($n, 'acct-v'), 'proof' => null, 'kind' => 'X', 'own' => false, 'who' => 'atk', 'outcome' => 'fail'];
    }

    return $rows;
}

foreach ($arms as $arm => [$uAcct, $opts]) {
    // BRU24
    $rows = g3Bru24();
    [$cell, $gate] = g3Cell(g3Params(40, $uAcct), $opts);
    $r = g3Replay($cell, $gate, $rows, 'pre+out');
    $takes = count(array_filter($gate->tbLog, static fn(array $e): bool => $e['kind'] === 'take'));
    g3Emit('s03', ['fixture' => 'BRU24', 'arm' => $arm, 'build' => $build, 'tbT' => $opts['tbT'] ?? null, 'tbC' => $opts['tbC'] ?? null,
        'attackAdmitted' => substr_count($r['reach'], '1'), 'tbTakesRecount' => $takes, 'refunds' => $gate->refunds,
        'attackDeniedByCause' => $r['attackDeniedByCause']]);

    // REC: 1800 s of attack (last request at 1785), then the owner arrives at 1785 + delta and retries every 60 s.
    foreach ([0, 60, 300, 900, 1800, 3600] as $delta) {
        [$cell, $gate] = g3Cell(g3Params(40, $uAcct), $opts);
        $atk = array_values(array_filter(g3Bru24(), static fn(array $x): bool => $x['t'] < 1800));
        g3Replay($cell, $gate, $atk, 'pre+out');
        $owner = new RateLimitContextDTO('203.0.113.200', FIREFOX_130, 'acct-v', null, null, false, [], false, null);
        $admittedAt = null;
        $tries = [];
        for ($t = 1785 + $delta; $t <= 1785 + $delta + 7200; $t += 60) {
            $res = g3Replay($cell, $gate, [['t' => $t, 'ctx' => $owner, 'proof' => null, 'kind' => 'O', 'own' => false, 'who' => 'owner', 'outcome' => 'ok']], 'pre+out');
            $tries[] = $res['dec'] . ':' . (array_key_first($res['legitDeniedByCause']) ?? '-');
            if ($res['dec'] === 'A') {
                $admittedAt = $t;
                break;
            }
        }
        g3Emit('s03', ['fixture' => 'REC', 'arm' => $arm, 'build' => $build, 'tbT' => $opts['tbT'] ?? null, 'delta' => $delta,
            'admittedAfterAttackEnd' => $admittedAt === null ? null : $admittedAt - 1785, 'tries' => count($tries),
            'firstCauses' => array_slice($tries, 0, 5)]);
    }

    if ($build === 'CAND') {
        continue;
    }
    $flows = in_array($arm, ['TB30', 'TB30R'], true) ? ['pre+out', 'pre'] : ['pre+out'];
    if ($arm === 'TB30R') {
        $flows[] = 'out';
    }
    foreach ($flows as $flow) {
        foreach (G3_STREAMS as $id) {
            foreach (g1Strategies($id) as $strategy) {
                $srows = g1Materialise($id, 'login', $strategy);
                [$cell, $gate] = g3Cell(g3Params(40, $uAcct), $opts);
                $r = g3Replay($cell, $gate, $srows, $flow);
                g3Emit('s03', ['fixture' => 'stream', 'stream' => $id, 'cls' => g1Streams()[$id]['cls'], 'strategy' => $strategy,
                    'arm' => $arm, 'build' => $build, 'flow' => $flow] + g3Row($srows, $r));
            }
        }
    }
}
