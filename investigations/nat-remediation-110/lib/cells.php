<?php

declare(strict_types=1);

// #110 plan 1 (OQC) — catalogue of every matrix cell scenarios/o01_matrix.php must emit PER ARM. report.php validates against it
// (a missing cell is missing evidence, never a silent skip). No dependency on the Lab.

const OQC_ARMS_LIST = ['S0', 'OQC', 'OQCW', 'OQCE'];

/** @return array<string, string> stream id => class (benign | attack | both | evasion | poisoning) */
function oqcStreamClasses(): array
{
    return [
        // #108 K1/NAT streams (lib/k1.php), unchanged
        'D2' => 'benign', 'N3' => 'benign', 'N4' => 'benign', 'N5' => 'benign', 'N6' => 'benign', 'N12' => 'benign', 'N12T' => 'benign', 'N12M' => 'benign',
        'Nu3' => 'benign', 'Nu4' => 'benign', 'Nu5' => 'benign', 'Nu6' => 'benign', 'Nu12' => 'benign', 'Nu12T' => 'benign', 'Nu12M' => 'benign',
        'SP1f' => 'attack', 'SP1n' => 'attack', 'SP3' => 'attack', 'SP6' => 'attack', 'SP2' => 'attack', 'SP4' => 'attack', 'SP4u' => 'attack', 'SP4b' => 'attack', 'SP5' => 'attack',
        'MIX' => 'both', 'MIXT' => 'both', 'MIXU' => 'both', 'MIXN' => 'both',
        // #104 anti-evasion streams (fixtures/anti_evasion.php), unchanged
        'L1' => 'benign', 'L1f' => 'benign', 'L2' => 'benign', 'L3' => 'benign', 'L4' => 'benign',
        'X1' => 'evasion', 'X2' => 'evasion', 'X2L' => 'both', 'X3' => 'evasion', 'X4' => 'poisoning', 'X5' => 'evasion', 'X6' => 'poisoning', 'X7' => 'evasion', 'X8' => 'both',
        'Y1' => 'evasion', 'Y2a' => 'evasion', 'Y2b' => 'evasion', 'Y2c' => 'evasion', 'Y2c2' => 'evasion', 'Y4b' => 'poisoning', 'Y4c' => 'poisoning', 'Y4d' => 'poisoning',
        'Y4e' => 'poisoning', 'Y4f' => 'poisoning', 'Y6b' => 'poisoning', 'Y6c' => 'poisoning', 'Y7' => 'evasion', 'Y9a' => 'evasion', 'Y9b' => 'evasion',
        // #105 design streams (design/lib/dsg.php), unchanged
        'D1' => 'benign', 'D5' => 'attack', 'D6' => 'both', 'D7' => 'attack',
        // #110 streams (lib/oqc.php)
        'ABA_B' => 'benign', 'ABA_B1' => 'benign', 'ABA_A' => 'attack', 'K1R4' => 'attack', 'SP5c' => 'attack', 'GAP' => 'benign', 'OTPB' => 'benign',
    ];
}

/** @return list<array{0: string, 1: string, 2: string}> [stream, shape, flow] */
function oqcMatrixCells(): array
{
    $cells = [];
    foreach (array_keys(oqcStreamClasses()) as $id) {
        foreach (['pre+out', 'pre', 'out'] as $flow) {
            $cells[] = [$id, 'login', $flow];
        }
    }
    foreach (['D2', 'Nu12', 'Nu12T', 'SP1n', 'SP3', 'MIXN', 'ABA_B', 'ABA_A', 'OTPB'] as $id) {
        foreach (['pre+out', 'out'] as $flow) {
            $cells[] = [$id, 'otp', $flow];
        }
    }
    foreach (['D2', 'SP1n', 'SP3', 'MIXN', 'ABA_A'] as $id) {
        foreach (['pre+out', 'out'] as $flow) {
            $cells[] = [$id, 'login-anon', $flow];
        }
    }
    // API_OVERUSE is NOT an OQC-eligible capability: these cells must be identical on every arm.
    foreach (['L1', 'X1', 'Y7', 'ABA_A'] as $id) {
        foreach (['api', 'api_custom'] as $shape) {
            foreach (['pre+out', 'out'] as $flow) {
                $cells[] = [$id, $shape, $flow];
            }
        }
    }

    return $cells;
}

/** Integer fields every matrix cell must carry (fail-closed validation). */
function oqcCellFields(): array
{
    return ['legitAttempts', 'legitDenied', 'attackAttempts', 'attackReachedVerification', 'attackDenied', 'attackDistinctSubjectsVerified', 'sprayObservations', 'correlationIssuances'];
}
