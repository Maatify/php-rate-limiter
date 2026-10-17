<?php

declare(strict_types=1);

// G1 M05 — Host integration modes on the candidate (T0 probe / abandon / lost reply, T1 permits, mixed routes). G1-defined
// fixtures with explicit capacities. 'out' (record-only) routes are in the M01 matrix: nothing is reserved there.

require dirname(__DIR__) . '/lib/g1.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

g1Provenance();
if (g1Runtime() !== 'CAND') {
    fwrite(STDERR, "m05 runs on the candidate runtime only\n");
    exit(4);
}

function hmCtx(string $acct, string $ip = '198.51.100.70'): RateLimitContextDTO
{
    return new RateLimitContextDTO($ip, FIREFOX_130, $acct, null, null, false, [], false, null);
}

// H-1 — probe factor rho: one user, 3 real attempts in one window, U_src = U_acct = 4. rho = 1 vs rho = 2 (probe + real check).
foreach ([1, 2] as $rho) {
    $cell = new G1Cell('CAND', 'login', ['U_src' => 4, 'U_acct' => 4] + g1Params('R'));
    $real = 0;
    for ($k = 0; $k < 3; $k++) {
        $cell->lab->at(10 * $k);
        $ok = true;
        for ($j = 0; $j < $rho; $j++) {
            $c = $cell->cmd(hmCtx('acctH'), 'check', 'U');
            $ok = $c['d'] === 'A' && $cell->admit(hmCtx('acctH'), 'U')['ok'];
        }
        $real += $ok ? 1 : 0;
    }
    g1Emit('m05', ['case' => "H-1 probe rho=$rho", 'realAttempts' => 3, 'realAdmitted' => $real]);
}

// H-2 — abandon: an admitted pre-check with no verifier call and no outcome stays consumed and scores nothing.
$cell = new G1Cell('CAND', 'login', g1Params('R'));
$cell->cmd(hmCtx('acctI'), 'check', 'U');
$a = $cell->admit(hmCtx('acctI'), 'U');
$recount = g1Recount($cell->adm->log, $cell->p);
g1Emit('m05', ['case' => 'H-2 abandon', 'admitted' => $a['ok'], 'srcConsumed' => $recount['src']['max'], 'scores' => $cell->lab->scores(hmCtx('acctI'))]);

// H-3 — lost checkOnly reply: the Host retries, a second admission is charged.
$cell = new G1Cell('CAND', 'login', g1Params('R'));
for ($i = 0; $i < 2; $i++) {
    $cell->cmd(hmCtx('acctJ'), 'check', 'U');
    $cell->admit(hmCtx('acctJ'), 'U');
}
g1Emit('m05', ['case' => 'H-3 lost reply retry', 'acctAdmissionsForOneAttempt' => g1Recount($cell->adm->log, $cell->p)['acct']['max']]);

// H-4 — T1 permits: claim outcomes and at-most-once scoring (the pipeline's recordFailure runs only on an accepted claim).
$D = 30;
$G = 600;
$cell = new G1Cell('CAND', 'login', g1Params('R'));
$claims = [];
$scored = 0;
$run = static function (string $permit, array $exp, string $outcome, int $t) use ($cell, &$claims, &$scored): void {
    $cell->lab->at($t);
    $res = $cell->adm->store->claim($permit, $exp, $outcome, $cell->now());
    $claims[] = "$permit:$outcome@+$t=$res";
    if ($res === 'accepted' && $outcome === 'fail') {
        $cell->cmd(hmCtx($exp['subject']), 'fail', 'U');
        $scored++;
    }
};
$issue = static function (string $id, string $acct, int $t) use ($cell, $D, $G): array {
    $cell->lab->at($t);
    $cell->cmd(hmCtx($acct), 'check', 'U');
    $now = $cell->now();

    return $cell->admit(hmCtx($acct), 'U', ['id' => $id, 'lane' => 'U', 'subject' => $acct, 'op' => 'login', 'deadline' => $now + $D, 'grace' => $G]);
};
$e = ['lane' => 'U', 'subject' => 'acctT', 'op' => 'login'];
$issue('p1', 'acctT', 0);
$run('p1', $e, 'fail', 5);                                           // accepted
$run('p1', $e, 'fail', 6);                                           // duplicate (replay / Host retry after a lost reply)
$run('p1', $e, 'ok', 7);                                             // conflict
$run('p1', ['subject' => 'acctOther'] + $e, 'fail', 8);              // rejected (re-attribution attempt)
$issue('p2', 'acctT', 100);
$run('p2', $e, 'fail', 100 + $D + 1);                                // late (after deadline, before grace end)
$issue('p3', 'acctT', 200);
$run('p3', $e, 'fail', 200 + $D + $G + 1);                           // unknown (record gone)
$run('p4-never-issued', $e, 'fail', 300);                            // unknown
g1Emit('m05', ['case' => 'H-4 T1 claims', 'claims' => $claims, 'scoredFailures' => $scored, 'K4' => $cell->lab->scores(hmCtx('acctT'))['K4'] ?? null,
    'atMostOncePerPermit' => $scored === 1]);

// H-5 — mixed routes on one account and source: route A pre+out is admitted and counted; route B is record-only and is not.
$cell = new G1Cell('CAND', 'login', g1Params('R'));
$verifier = 0;
for ($k = 0; $k < 6; $k++) {
    $cell->lab->at(20 * $k);
    if ($k % 2 === 0) {
        if ($cell->cmd(hmCtx('acctM'), 'check', 'U')['d'] === 'A' && $cell->admit(hmCtx('acctM'), 'U')['ok']) {
            $verifier++;
            $cell->cmd(hmCtx('acctM'), 'ok', 'U');
        }
    } else {
        $verifier++;
        $cell->cmd(hmCtx('acctM'), 'ok', 'U');
    }
}
g1Emit('m05', ['case' => 'H-5 mixed routes', 'verifierCalls' => $verifier, 'countedAdmissions' => count($cell->adm->log),
    'note' => 'record-only calls reach the verifier without being counted; no pre-verifier bound covers them']);
