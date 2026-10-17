<?php

declare(strict_types=1);

// G1 M02 — device-level isolation (ARCHITECTURE_PROPOSAL.md §7.3, acceptance streams DV-1 … DV-6). G1-defined fixtures.
// Runs on BOTH runtimes: on S0 the "proven" request is the same trusted-session context (S0 has no proof), which shows whether
// the path exists today; on CAND it carries a proof for its own account and a secret sessionDeviceId (rule P-1).
// DV-6 (mixed fleet) hands the state from this process to a process of the OTHER runtime (scenarios/m02_child.php).

require dirname(__DIR__) . '/lib/g1.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

g1Provenance();
$rt = g1Runtime();
$arm = $rt === 'S0' ? 'S0' : 'CAND';
const X = '203.0.113.77';

function dvCtx(string $ip, string $acct, ?string $fp, ?string $sid = null, bool $trusted = false, bool $verified = false, string $ua = CHROME): RateLimitContextDTO
{
    return new RateLimitContextDTO($ip, $ua, $acct, $fp === null ? null : fp($fp), $sid, $trusted, [], $verified, null);
}

/** One pre+out attempt; returns [decision char, cause]. */
function dvTry(G1Cell $cell, RateLimitContextDTO $c, ?string $proof, string $outcome): array
{
    $lane = $cell->lane($c, $proof);
    $r = $cell->cmd($c, 'check', $lane);
    if ($r['d'] !== 'A') {
        return [$r['d'], $cell->penaltyCause($c, $r['d'], $lane), $lane];
    }
    $a = $cell->admit($c, $lane);
    if (! $a['ok']) {
        return ['S', 'cap:' . $a['refused'], $lane];
    }
    $rec = $cell->cmd($c, $outcome, $lane);

    return [$rec['d'] === 'A' ? 'A' : $rec['d'], $rec['d'] === 'A' ? '-' : $cell->penaltyCause($c, $rec['d'], $lane), $lane];
}

/** The proven owner device of account A: proof for acctA + secret device id (trusted session context on S0). */
function dvOwner(int $dev = 1): RateLimitContextDTO
{
    return dvCtx(X, 'acctA', null, "sd-acctA-dev$dev", true);
}

/** @param callable(G1Cell): void $population */
function dvRun(string $arm, string $id, ?callable $population, int $attempts, array $p): array
{
    $cell = new G1Cell($arm, 'login', $p);
    if ($population !== null) {
        $population($cell);
    }
    $trace = [];
    for ($k = 0; $k < $attempts; $k++) {
        $cell->lab->at(400 + 30 * $k);
        [$d, $cause, $lane] = dvTry($cell, dvOwner(), 'acctA', 'fail');
        $trace[] = "$d:$cause:$lane";
    }

    return ['cell' => $cell, 'trace' => $trace, 'snap' => g1Snap($cell, dvOwner())];
}

$p = g1Params('R');
// DV-1 — 50 U devices from IP X (distinct fingerprints, other accounts) within 900 s, then the proven owner of A fails 6x from X.
$pop50 = static function (G1Cell $cell): void {
    for ($i = 0; $i < 50; $i++) {
        $cell->lab->at(2 * $i);
        dvTry($cell, dvCtx(X, "u$i", "u$i"), null, 'ok');
    }
};
// DV-2 — 10 U devices on account A (new fingerprints, other IPs), failing, then the proven owner of A fails 6x.
$pop10 = static function (G1Cell $cell): void {
    for ($i = 0; $i < 10; $i++) {
        $cell->lab->at(5 * $i);
        dvTry($cell, dvCtx("198.51.100.1$i", 'acctA', "atkdev$i"), null, 'fail');
    }
};
/** Proven-lane state of the owner (U-lane K4 state of the account is EXPECTED to differ in DV-2 and is reported separately). */
function dvPState(array $snap): array
{
    return array_intersect_key($snap, array_flip(['K4P', 'K5', 'K5key', 'blkK4P', 'blkK5', 'budgetK4P', 'allowance']));
}

foreach (['DV-1' => $pop50, 'DV-2' => $pop10] as $dv => $pop) {
    $with = dvRun($arm, $dv, $pop, 6, $p);
    $ctl = dvRun($arm, $dv . '-control', null, 6, $p);
    g1Emit('m02', ['dv' => $dv, 'arm' => $arm, 'trace' => $with['trace'], 'snap' => $with['snap'], 'controlTrace' => $ctl['trace'],
        'controlSnap' => $ctl['snap'],
        'identicalToControl' => $with['trace'] === $ctl['trace'] && dvPState($with['snap']) === dvPState($ctl['snap'])]);
}

// DV-3 — N_P live proven devices of A, then one more (CAND only: S0 has no P device cap).
if ($arm === 'CAND') {
    // Explicit DV-3 capacities: the account / proof counters are made non-binding so that only the device cap N_P can refuse.
    $p3 = ['P_acct' => 1000, 'P_acct_day' => 1000, 'P_proof' => 1000, 'P_proof_day' => 1000] + $p;
    $cell = new G1Cell('CAND', 'login', $p3);
    $admitted = [];
    for ($k = 1; $k <= $p['N_P']; $k++) {
        $cell->lab->at(10 * $k);
        $admitted[] = dvTry($cell, dvOwner($k), 'acctA', 'ok')[0];
    }
    $cell->lab->at(200);
    $extra = dvCtx(X, 'acctA', null, 'sd-acctA-dev' . ($p['N_P'] + 1), true);
    $before = g1Snap($cell, $extra);
    $keysBefore = $cell->adm->store->liveKeys($cell->now());
    $writesBefore = $cell->adm->store->writes;
    $r = dvTry($cell, $extra, 'acctA', 'fail');
    $after = g1Snap($cell, $extra);
    $writesAfter = $cell->adm->store->writes;
    $keysAfter = $cell->adm->store->liveKeys($cell->now());
    $cell->lab->at(210);
    $again = dvTry($cell, dvOwner(1), 'acctA', 'ok');
    g1Emit('m02', ['dv' => 'DV-3', 'arm' => $arm, 'existingAdmitted' => implode('', $admitted), 'extra' => $r,
        'stateUnchanged' => $before === $after, 'admissionWritesUnchanged' => $writesBefore === $writesAfter,
        'admissionKeysUnchanged' => $keysBefore === $keysAfter, 'existingDeviceAfter' => $again,
        'params' => ['N_P' => $p3['N_P'], 'T_P' => $p3['T_P'], 'P_acct' => $p3['P_acct'], 'P_proof' => $p3['P_proof']]]);
}

// DV-4 — 50 proven devices of 50 different accounts on IP X, then one U request (historically known, so it scores K5) fails 3x.
$p4 = $p;
$runU = static function (bool $withP) use ($arm, $p4): array {
    $cell = new G1Cell($arm, 'login', $p4);
    if ($withP) {
        for ($i = 0; $i < 50; $i++) {
            $cell->lab->at(2 * $i);
            dvTry($cell, dvCtx(X, "pa$i", null, "sd-pa$i", true), "pa$i", 'ok');
        }
    }
    $u = dvCtx(X, 'acctU', 'udev', null, false, true);
    $trace = [];
    for ($k = 0; $k < 3; $k++) {
        $cell->lab->at(400 + 30 * $k);
        $trace[] = implode(':', dvTry($cell, $u, null, 'fail'));
    }

    return [$trace, g1Snap($cell, $u)];
};
[$t4, $s4] = $runU(true);
[$c4, $cs4] = $runU(false);
g1Emit('m02', ['dv' => 'DV-4', 'arm' => $arm, 'trace' => $t4, 'snap' => $s4, 'controlTrace' => $c4, 'controlSnap' => $cs4,
    'identicalToControl' => $t4 === $c4 && $s4 === $cs4]);

// DV-5 — a U request presenting the proven device's sessionDeviceId (untrusted, historically known, no proof) shares its K5.
$cell = new G1Cell($arm, 'login', $p);
$own = dvOwner(7);
$shadow = dvCtx(X, 'acctA', null, 'sd-acctA-dev7', false, true);
$tr = [];
for ($k = 0; $k < 3; $k++) {
    $cell->lab->at(30 * $k);
    $tr[] = implode(':', dvTry($cell, $shadow, null, 'fail'));
}
$cell->lab->at(120);
$ownAfter = dvTry($cell, $own, 'acctA', 'fail');
g1Emit('m02', ['dv' => 'DV-5', 'arm' => $arm, 'sameK5Key' => $cell->lab->keys($own)['K5'] === $cell->lab->keys($shadow)['K5'],
    'shadowTrace' => $tr, 'ownerAfter' => $ownAfter, 'snap' => g1Snap($cell, $own)]);

// DV-6 — mixed fleet: this runtime processes the 50-device U population (DV-1), the OTHER runtime processes the proven owner.
if ($arm === 'CAND') {
    $cell = new G1Cell('CAND', 'login', $p);
    $pop50($cell);
    $file = tempnam(sys_get_temp_dir(), 'g1dv6');
    g1SaveState($cell, (string) $file);
    $repo = dirname(__DIR__, 4);
    $cmd = 'env -u G1_ARM LAB_ROOT=' . escapeshellarg($repo) . ' php ' . escapeshellarg(__DIR__ . '/m02_child.php') . ' ' . escapeshellarg((string) $file);
    $out = trim((string) shell_exec($cmd));
    @unlink((string) $file);
    g1Emit('m02', ['dv' => 'DV-6', 'arm' => 'CAND->S0', 'oldWorkerResult' => json_decode($out, true)]);
}
