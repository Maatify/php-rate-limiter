<?php

declare(strict_types=1);

// Unified Security Feasibility (#97 / #100) — stream and cell catalogue shared by the harness (lib/fsb.php) and the report generator (report.php).
// No dependency on the Lab: pure data.

/** Streams of the unified gate: legitimate (L*, D1, D2), attacks (X1, X2, X7, Y7, X8) and the extended boundary / poisoning / IPv6 set. */
function fsbCoreStreams(): array
{
    return ['L1', 'L2', 'L3', 'D1', 'D2', 'X1', 'X2', 'X7', 'Y7', 'X8'];
}

function fsbExtendedStreams(): array
{
    return ['X4', 'X6', 'Y2a', 'Y4c', 'Y4e', 'Y6b', 'Y9a', 'Y9b', 'D5', 'D6', 'D7'];
}

/**
 * EVERY (stream, shape, flow) cell f01_matrix must emit for each variant. The report validates against this list: a missing cell is
 * missing evidence, never a silent skip. Flows: pre+out (checkOnly then outcome), out (record-only), pre (checkOnly-only host).
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function fsbMatrixCells(): array
{
    $cells = [];
    foreach (array_merge(fsbCoreStreams(), fsbExtendedStreams()) as $id) {
        foreach (['pre+out', 'out', 'pre'] as $flow) {
            $cells[] = [$id, 'login', $flow];
        }
    }
    foreach (fsbCoreStreams() as $id) {
        $cells[] = [$id, 'login-anon', 'pre+out'];
        foreach (['pre+out', 'out'] as $flow) {
            $cells[] = [$id, 'otp', $flow];
        }
    }
    foreach (['api', 'api_custom'] as $shape) {
        foreach (array_merge(fsbCoreStreams(), ['D7']) as $id) {
            foreach (['pre+out', 'out', 'pre'] as $flow) {
                $cells[] = [$id, $shape, $flow];
            }
        }
    }

    return $cells;
}

/** Test-only sensitivity cells (the SAME candidate with its window cap raised): the rows that decide whether any cap separates the pairs. */
function fsbSensitivityCells(): array
{
    return [
        ['api', ['L1', 'D2', 'X1', 'Y7', 'D7'], 'pre+out', 'PSC_CAP_CHURN', [3, 4, 6, 12]],
        ['login', ['L3', 'D1', 'D2', 'X2', 'X7', 'X8'], 'pre+out', 'PSC_CAP_DILUTION', [6, 8, 12, 24]],
    ];
}
