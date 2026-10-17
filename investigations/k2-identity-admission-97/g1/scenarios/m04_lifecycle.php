<?php

declare(strict_types=1);

// G1 M04 — K5 continuity, DEC-021 shared allowance, DEC-007 on K4P, and LT-1 scenarios S1–S6. G1-defined fixtures.
// Continuity / rollback hand the state to a process of the S0 runtime (scenarios/m04_child.php).

require dirname(__DIR__) . '/lib/g1.php';

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;

g1Provenance();
$rt = g1Runtime();
$arm = $rt === 'S0' ? 'S0' : 'CAND';
$p = g1Params('R');

function lcCtx(string $acct, ?string $sid, bool $trusted, bool $verified, string $ua = FIREFOX_130, string $ip = '198.51.100.44'): RateLimitContextDTO
{
    return new RateLimitContextDTO($ip, $ua, $acct, null, $sid, $trusted, [], $verified, null);
}

/** One pre+out attempt; [decision, reached]. */
function lcTry(G1Cell $cell, RateLimitContextDTO $c, ?string $proof, string $outcome): array
{
    $lane = $cell->lane($c, $proof);
    $r = $cell->cmd($c, 'check', $lane);
    if ($r['d'] !== 'A') {
        return [$r['d'], false];
    }
    $a = $cell->admit($c, $lane);
    if (! $a['ok']) {
        return ['S', false];
    }
    $rec = $cell->cmd($c, $outcome, $lane);

    return [$rec['d'], true];
}

/** The pre-check metadata cause (DEC-007 re-entry is exposed there). */
function lcPrecheckCause(G1Cell $cell, RateLimitContextDTO $c, string $lane): ?string
{
    if ($cell->cand()) {
        $cell->lab->pipeline->g1SetLane($lane === 'P' ? 'P' : 'U');
    }
    $r = $cell->lab->pipeline->process($cell->lab->policy, $c, RateLimitCommand::checkOnly($cell->lab->policy->getName()), $cell->lab->resolver->resolve($c));

    return $r->metadata?->cause;
}

if ($arm === 'CAND') {
    // K5-1 … K5-4 — continuity of a device that starts sending a secret (ARCHITECTURE_PROPOSAL.md §7.3, continuity trace).
    $cell = new G1Cell('CAND', 'login', $p);
    $passive = lcCtx('acctK', null, false, true);
    $proven = lcCtx('acctK', 'sd-acctK-1', true, false);
    for ($k = 0; $k < 3; $k++) {
        $cell->lab->at(60 * $k);
        lcTry($cell, $passive, null, 'fail');
    }
    $before = ['passive' => g1Snap($cell, $passive), 'proven' => g1Snap($cell, $proven)];
    for ($k = 0; $k < 2; $k++) {
        $cell->lab->at(300 + 60 * $k);
        lcTry($cell, $proven, 'acctK', 'fail');
    }
    $after = ['passive' => g1Snap($cell, $passive), 'proven' => g1Snap($cell, $proven)];
    g1Emit('m04', ['case' => 'K5-1 passive -> secret', 'before' => $before, 'after' => $after,
        'newK5Key' => $before['passive']['K5key'] !== $before['proven']['K5key'],
        'passiveK5Unchanged' => $before['passive']['K5'] === $after['passive']['K5'], 'provenK5Started' => $after['proven']['K5']]);

    // K5-2 — outer-secret rotation (current lab-secret-2, previous lab-secret): the proven K5 written under the old secret is read.
    $file = (string) tempnam(sys_get_temp_dir(), 'g1k5');
    g1SaveState($cell, $file);
    $rot = new G1Cell('CAND', 'login', $p, state: g1LoadState($file), previousSecret: Lab::SECRET, secret: 'lab-secret-2');
    $rot->lab->at(600);
    $r = lcTry($rot, $proven, 'acctK', 'fail');
    $rotScores = $rot->lab->scores($proven);
    g1Emit('m04', ['case' => 'K5-2 outer-secret rotation', 'decision' => $r[0],
        'note' => 'scores under the NEW secret after one failure; a value above the single-failure delta proves the previous-generation K5 was read',
        'newSecretK5' => $rotScores['K5'] ?? null]);

    // K5-3 / K5-4 — code rollback to S0 with and without the Host context (child process on the S0 runtime).
    $repo = dirname(__DIR__, 4);
    foreach (['with-session' => 'keep', 'without-session' => 'drop'] as $label => $mode) {
        $cmd = 'env -u G1_ARM LAB_ROOT=' . escapeshellarg($repo) . ' php ' . escapeshellarg(__DIR__ . '/m04_child.php') . ' rollback ' . escapeshellarg($file) . ' ' . $mode;
        g1Emit('m04', ['case' => "K5-3 rollback $label", 'oldWorker' => json_decode(trim((string) shell_exec($cmd)), true)]);
    }
    @unlink($file);

    // DEC-021 shared allowance coupling (§7.2 item 3): 8 historical-known U failures, then one proven failure of the same account.
    $runCouple = static function (bool $withU) use ($p): array {
        $cell = new G1Cell('CAND', 'login', $p);
        if ($withU) {
            for ($k = 0; $k < 8; $k++) {
                $cell->lab->at(400 * $k);
                lcTry($cell, lcCtx('acctC', null, false, true, FIREFOX_130, "198.51.100.6$k"), null, 'fail');
            }
        }
        $cell->lab->at(4000);
        $c = lcCtx('acctC', 'sd-acctC-1', true, false);
        lcTry($cell, $c, 'acctC', 'fail');

        return g1Snap($cell, $c);
    };
    g1Emit('m04', ['case' => 'DEC-021 shared allowance', 'afterUFailures' => $runCouple(true), 'control' => $runCouple(false)]);

    // DEC-007 on K4P: a proof-bearing NEW device (proof, no trusted session flag, not verified) records failures (record-only
    // route, so pre-check SOFT blocks do not stop the score) until K4P reaches L2; after the punishment is served, the proven
    // pre-check exposes re-entry evidence, an unproven pre-check on the same account does not.
    $cell = new G1Cell('CAND', 'login', $p);
    $pnew = new RateLimitContextDTO('198.51.100.45', FIREFOX_130, 'acctR', null, 'sd-acctR-1', false, [], false, null);
    $tr = [];
    for ($k = 0; $k < 3; $k++) {
        $cell->lab->at(10 * $k);
        $tr[] = $cell->cmd($pnew, 'fail', 'P')['d'];
    }
    $snap = g1Snap($cell, $pnew);
    $cell->lab->at(200);
    g1Emit('m04', ['case' => 'DEC-007 on K4P', 'failTrace' => implode('', $tr), 'snapAfterFailures' => $snap,
        'provenPrecheckCause' => lcPrecheckCause($cell, $pnew, 'P'),
        'unprovenPrecheckCause' => lcPrecheckCause($cell, lcCtx('acctR', null, false, false, FIREFOX_130, '198.51.100.46'), 'U')]);
}

// LT-1 — S1–S6 on both runtimes (CAND = M-A with P caps; S0 = shared K4, trusted sessions as known devices).
$lt = [
    'S1' => ['policy' => 'login', 'hours' => 24, 'events' => static function (int $t): array {
        return $t % 300 === 0 ? [['atk', lcCtx('vic1', 'sd-steal', true, false), 'vic1', 'fail']] : [];
    }],
    'S2' => ['policy' => 'login', 'hours' => 24, 'events' => static function (int $t): array {
        return $t % 300 === 0 ? [['atk', lcCtx('vic2', 'sd-steal', true, false, k1Ua(100 + intdiv($t, 300) % 40)), 'vic2', 'fail']] : [];
    }],
    'S3' => ['policy' => 'otp', 'hours' => 2, 'events' => static function (int $t): array {
        return $t % 60 === 0 ? [['atk', lcCtx('vic3', 'sd-pwd-holder', true, false), 'vic3', 'fail']] : [];
    }],
    'S4' => ['policy' => 'login', 'hours' => 24, 'events' => static function (int $t): array {
        return $t % 100 === 0 ? [['atk', lcCtx('vic4', 'sd-steal-' . (intdiv($t, 100) % 3), true, false), 'vic4', 'fail']] : [];
    }],
    'S5' => ['policy' => 'login', 'hours' => 24, 'events' => static function (int $t): array {
        $e = [];
        if ($t % 600 === 0) {
            $e[] = ['own', lcCtx('vic5', 'sd-owner', true, false), 'vic5', 'ok'];
        }
        if ($t % 120 === 60) {
            $e[] = ['atk', lcCtx('vic5', 'sd-steal', true, false, CHROME), 'vic5', 'fail'];
        }

        return $e;
    }],
    'S6' => ['policy' => 'login', 'hours' => 24, 'events' => static function (int $t): array {
        $m = $t % 3600;

        return in_array($m, [0, 60, 120, 180], true) ? [['own', lcCtx('vic6', 'sd-owner', true, false), 'vic6', $m === 180 ? 'ok' : 'fail']] : [];
    }],
];
foreach ($lt as $id => $s) {
    $cell = new G1Cell($arm, $s['policy'], $p);
    $m = ['attackerAttempts' => 0, 'attackerReach' => 0, 'ownerAttempts' => 0, 'ownerDenied' => 0];
    $per6h = [];
    for ($t = 0; $t < $s['hours'] * 3600; $t += 60) {
        foreach (($s['events'])($t) as [$who, $c, $proof, $out]) {
            $cell->lab->at($t);
            [$d, $reached] = lcTry($cell, $c, $proof, $out);
            if ($who === 'atk') {
                $m['attackerAttempts']++;
                $m['attackerReach'] += $reached ? 1 : 0;
                $per6h[intdiv($t, 21600)] = ($per6h[intdiv($t, 21600)] ?? 0) + ($reached ? 1 : 0);
            } else {
                $m['ownerAttempts']++;
                $m['ownerDenied'] += ($d !== 'A') ? 1 : 0;
            }
        }
    }
    g1Emit('m04', ['case' => "LT-1 $id", 'arm' => $arm, 'policy' => $s['policy'], 'm' => $m, 'attackerReachPer6h' => array_values($per6h)]);
}
