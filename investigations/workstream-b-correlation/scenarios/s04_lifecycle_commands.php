<?php

declare(strict_types=1);

// B-F02 — public commands are independent: checkOnly() / recordFailure() / recordSuccess() each may be the ONLY
// call a host makes. Measures correlation under every flow for Login, OTP, API_OVERUSE and a custom API_OVERUSE.
require dirname(__DIR__) . '/lib/bootstrap.php';

/** @return list<array{string, string}> flow label => [flow, outcome] */
function flowsFor(string $policy): array
{
    if ($policy === 'api' || $policy === 'api_custom') {
        return [['pre+out(ok)', 'pre+out', 'ok'], ['pre-only', 'pre', 'ok'], ['recordSuccess-only', 'out', 'ok']];
    }

    return [
        ['pre+out(ok)', 'pre+out', 'ok'],
        ['pre+out(fail)', 'pre+out', 'fail'],
        ['pre-only', 'pre', 'ok'],
        ['recordSuccess-only', 'out', 'ok'],
        ['recordFailure-only', 'out', 'fail'],
    ];
}

$scenarios = [
    // id => [attempts, ctx factory]
    'churn-shared-k2(MEDIUM,2 clients)' => [6, static fn(Lab $lab, int $i) => $lab->ctx(NAT, CHROME, null, fp($i % 2 === 0 ? 'A' : 'B'))],
    'low-dilution(8 IPs,same UA)' => [8, static fn(Lab $lab, int $i) => $lab->ctx('198.51.100.' . ($i + 1), CHROME, null)],
];

foreach (['login', 'otp', 'api', 'api_custom'] as $policy) {
    foreach ($scenarios as $sid => [$n, $factory]) {
        foreach (flowsFor($policy) as [$label, $flow, $outcome]) {
            $lab = new Lab($policy);
            $firstBlock = null;
            $issuer = null;
            $dTotal = 0;
            $wTotal = 0;
            $perAttempt = [];
            for ($i = 0; $i < $n; $i++) {
                $lab->at($i * 10);
                $c = $factory($lab, $i);
                $r = $lab->attempt($c, $flow, $outcome);
                $dTotal += $r['dOps'];
                $wTotal += $r['wOps'];
                $perAttempt[] = $r['steps'];
                if ($firstBlock === null && $r['blocked']) {
                    $firstBlock = $i + 1;
                    $issuer = $r['blockCmd'] . ($r['allowedThenBlockedBy'] !== null ? ' (preceding command in the same logical attempt was ALLOW)' : '');
                }
            }
            $c = $factory($lab, $n - 1);
            emit('F02.' . $policy, [
                'scenario' => $sid,
                'flow' => $label,
                'firstBlockedLogicalAttempt' => $firstBlock,
                'blockIssuedBy' => $issuer,
                'distinctOps' => $dTotal,
                'watchOps' => $wTotal,
                'persistedBlocks' => $lab->blocks($c),
                'attempts' => $perAttempt,
            ]);
        }
    }
}
