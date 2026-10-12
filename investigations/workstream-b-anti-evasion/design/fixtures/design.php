<?php

declare(strict_types=1);

// Design & Proof Gate (#97 / #100) — fixture metadata shared by the harness (lib/dsg.php) and the report generator (report.php).
// No dependency on the Lab: only the stream catalogue ids of PR #104 (fixtures/anti_evasion.php) and the cell lists below.
require_once dirname(__DIR__, 2) . '/fixtures/anti_evasion.php';

/** @return array<string, string> */
function dsgClass(): array
{
    return ['D1' => 'benign', 'D2' => 'benign', 'D3' => 'both', 'D4' => 'both', 'D5' => 'evasion', 'D6' => 'both', 'D7' => 'evasion'];
}

/** @return array<string, array<string, string>> */
function dsgControls(): array
{
    $host = 'Host-provided (recordFailure/recordSuccess); the library cannot verify it.';

    return [
        'D1' => ['ip' => 'legit (distinct)', 'ua' => 'legit (popular UA)', 'fingerprint' => 'absent (LOW)', 'account' => 'legit', 'outcome' => $host . ' 8% typos.'],
        'D2' => ['ip' => 'shared NAT', 'ua' => 'legit', 'fingerprint' => 'legit (fresh per login)', 'account' => 'legit (12 own accounts)', 'outcome' => $host . ' 8% typos.'],
        'D3' => ['ip' => 'legit distinct + botnet (8 IPs)', 'ua' => 'shared popular UA', 'fingerprint' => 'absent (LOW)', 'account' => 'legit own / attacker victims', 'outcome' => $host],
        'D4' => ['ip' => 'legit distinct + 1 attacker IP', 'ua' => 'shared popular UA', 'fingerprint' => 'absent (LOW)', 'account' => 'legit own / attacker victims', 'outcome' => $host . ' The attacker fails at will.'],
        'D5' => ['ip' => 'attacker (1 IP)', 'ua' => 'attacker', 'fingerprint' => 'attacker (fresh per request)', 'account' => 'victims + attacker own account (padding)', 'outcome' => $host . ' The attacker FABRICATES successes on its own account.'],
        'D6' => ['ip' => 'shared NAT', 'ua' => 'shared', 'fingerprint' => 'burst: 3 fresh; legit: 1 stable', 'account' => 'burst: new own accounts; legit: own', 'outcome' => $host],
        'D7' => ['ip' => 'attacker (1 IP)', 'ua' => 'attacker', 'fingerprint' => 'attacker (fresh per request)', 'account' => 'new victim per attempt', 'outcome' => $host],
    ];
}

/** @return list<string> */
function dsgMatrixOrder(): array
{
    return array_merge(wsbPrimaryOrder(), ['D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7']);
}

/** Streams measured on the secondary policy shapes (risk based, not Cartesian). */
function dsgShapeStreams(): array
{
    return ['L1', 'L2', 'L3', 'X1', 'X2', 'X3', 'X4', 'X6', 'X7', 'X8', 'Y2a', 'Y4d', 'Y4e', 'Y7', 'D1', 'D2', 'D3', 'D4', 'D5'];
}

/**
 * EVERY (stream, shape, flow) cell d01_matrix must emit for each variant; the report validates against this list (a missing cell is
 * missing evidence, never a silent skip).
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function dsgMatrixCells(): array
{
    $cells = [];
    foreach (dsgMatrixOrder() as $id) {
        foreach (['pre+out', 'out', 'pre'] as $flow) {
            $cells[] = [$id, 'login', $flow];
        }
    }
    foreach (['login-anon', 'otp', 'api', 'api_custom'] as $shape) {
        foreach (dsgShapeStreams() as $id) {
            foreach (['pre+out', 'out'] as $flow) {
                $cells[] = [$id, $shape, $flow];
            }
        }
    }

    return $cells;
}
