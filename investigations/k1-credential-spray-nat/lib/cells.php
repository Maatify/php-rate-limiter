<?php

declare(strict_types=1);

// #106 — catalogue of every matrix cell scenarios/k02_matrix.php must emit. report.php validates against it (a missing cell is missing
// evidence, never a silent skip). No dependency on the Lab.

const K1_ARMS_LIST = ['A', 'B', 'C', 'D'];

/** @return array<string, string> stream id => class (benign | attack | both) */
function k1StreamClasses(): array
{
    return [
        'D2' => 'benign', 'N3' => 'benign', 'N4' => 'benign', 'N5' => 'benign', 'N6' => 'benign', 'N12' => 'benign', 'N12T' => 'benign', 'N12M' => 'benign',
        'Nu3' => 'benign', 'Nu4' => 'benign', 'Nu5' => 'benign', 'Nu6' => 'benign', 'Nu12' => 'benign', 'Nu12T' => 'benign', 'Nu12M' => 'benign',
        'SP1f' => 'attack', 'SP1n' => 'attack', 'SP3' => 'attack', 'SP6' => 'attack', 'SP2' => 'attack', 'SP4' => 'attack', 'SP4u' => 'attack', 'SP4b' => 'attack', 'SP5' => 'attack',
        'MIX' => 'both', 'MIXT' => 'both', 'MIXU' => 'both', 'MIXN' => 'both',
    ];
}

/** @return list<array{0: string, 1: string, 2: string}> [stream, shape, flow] */
function k1MatrixCells(): array
{
    $cells = [];
    foreach (array_keys(k1StreamClasses()) as $id) {
        foreach (['pre+out', 'pre', 'out'] as $flow) {
            $cells[] = [$id, 'login', $flow];
        }
    }
    foreach (['D2', 'Nu12', 'Nu12T', 'SP1n', 'SP3', 'MIXN'] as $id) {
        foreach (['pre+out', 'out'] as $flow) {
            $cells[] = [$id, 'otp', $flow];
        }
    }
    foreach (['D2', 'SP1n', 'SP3', 'MIXN'] as $id) {
        foreach (['pre+out', 'out'] as $flow) {
            $cells[] = [$id, 'login-anon', $flow];
        }
    }

    return $cells;
}

/** Integer fields every matrix arm record must carry (fail-closed validation). */
function k1ArmFields(): array
{
    return ['legitAttempts', 'legitDenied', 'attackAttempts', 'attackReachedVerification', 'attackDenied', 'attackDistinctSubjectsVerified', 'sprayObservations', 'sprayThresholdRequests'];
}
