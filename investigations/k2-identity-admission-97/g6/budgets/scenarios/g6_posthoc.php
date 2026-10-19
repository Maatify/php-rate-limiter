<?php

declare(strict_types=1);

// G6 POST-HOC diagnostic (NOT in the plan, NOT in any gate or verdict; added after a development run showed gate U1 failing). It re-runs the LOST fixture of
// the gate for NOUA2 and UKD with no change of arm, parameter or input, and records what the gated replay discards: the denial causes, the account-bucket
// admissions, the recorded failures on the account allowance counters, and the largest block level reached. It reads; it varies nothing.

require dirname(__DIR__) . '/lib/g6.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

if (g6Provenance() !== 'G6UK') {
    exit(0);
}
foreach (['NOUA2', 'UKD'] as $arm) {
    foreach ([600, 300, 60] as $iv) {
        [$cell, $gate, $host] = g6Cell($arm);
        $rows = [];
        for ($t = 0; $t < 86400; $t += $iv) {
            $rows[] = g4v2Row1($t, new RateLimitContextDTO('198.51.100.20', CHROME, 'acct-kd', null, null, false, [], true, null), 'O', 'kd', 'fail', 'i:kd', 'h:kd');
        }
        $r = g6Replay($cell, $gate, $host, g4v2Sort($rows), 'pre+out', g6Spec($arm));
        $takes = count(array_filter($gate->u->tbLog, static fn(array $e): bool => $e['kind'] === 'take'));
        $lab = $cell->lab;
        $allow = static fn(string $key): ?int => $lab->store->getBudget($key)?->count;
        g4Emit('posthoc', ['fixture' => 'LOST', 'arm' => $arm, 'interval' => $iv, 'attempts' => strlen($r['reach']), 'reach' => substr_count($r['reach'], '1'),
            'bucketAdmissions' => $takes, 'deniedByCause' => $r['legitDeniedByCause'],
            'allowanceU' => $allow(g5AllowanceKey($lab, 'acct-kd', null)), 'allowanceUK' => $allow(g6AllowanceUkKey($lab, 'acct-kd'))]);
    }
}
