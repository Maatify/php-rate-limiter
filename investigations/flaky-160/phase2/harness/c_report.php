<?php

declare(strict_types=1);

// #160 phase 2 arm C report (PLAN.md §3). Reads rows from c.php; prints JSON.
//
// Usage: php c_report.php [--det=<cdet.rows.jsonl>] [--nat=<cnat.rows.jsonl[.gz]>] [--expect-det=<n>] [--expect-nat=<k>]

namespace Flaky160B;

require __DIR__ . '/stats.php';

$options = getopt('', ['det:', 'nat:', 'expect-det:', 'expect-nat:']);
$report = [];

if (isset($options['det'])) {
    $trials = [];
    foreach (readRows((string) $options['det']) as $row) {
        $trials[$row['variant']][] = $row;
    }
    $expected = (int) ($options['expect-det'] ?? 20);
    // registered predictions (PLAN §3.1)
    $predict = [
        'C-DET-1' => static fn(array $r): bool => $r['claim'] === true && $r['read_reentry_null'] === true,
        'C-DET-0' => static fn(array $r): bool => $r['claim'] === false && $r['read_reentry_null'] === true,
        'C-DET-2' => static fn(array $r): bool => $r['claim'] === true && $r['read_reentry_null'] === false,
    ];
    foreach ($predict as $variant => $holds) {
        $rows = $trials[$variant] ?? [];
        $valid = array_values(array_filter($rows, static fn(array $r): bool => $r['valid']));
        $hits = count(array_filter($valid, $holds));
        $report['det'][$variant] = [
            'trials' => count($rows), 'valid' => count($valid), 'invalid' => count($rows) - count($valid),
            'prediction_holds' => $hits, 'complete' => count($rows) === $expected,
            'claim_true' => count(array_filter($valid, static fn(array $r): bool => $r['claim'])),
            'read_reentry_not_null' => count(array_filter($valid, static fn(array $r): bool => ! $r['read_reentry_null'])),
            'wilson95_prediction' => wilson($hits, count($valid)),
            'holds' => count($valid) === $expected && $hits === $expected,
        ];
    }
}

if (isset($options['nat'])) {
    $k = 0;
    $discordant = [];
    $straddle = 0;      // sec(CLAIM) > sec(BLOCK)
    $blockToRead = 0;   // sec(READ) > sec(BLOCK): predicted failure at L1095
    $readToClaim = 0;   // sec(READ) == sec(BLOCK) and sec(CLAIM) > sec(BLOCK): predicted failure at L1097
    $failAt1095 = 0;
    $failAt1097 = 0;
    $expectedAll = 0.0;
    $expectedFirst = 0.0;
    $expectedSecond = 0.0;
    $gaps = [];
    $badReads = 0;
    $invalid = 0;
    foreach (readRows((string) $options['nat']) as $row) {
        $k++;
        if ($row['reads'] !== 4 || array_map('count', $row['t']) !== [1, 1, 1, 1] || ! $row['applied']) {
            $row['reads'] !== 4 || array_map('count', $row['t']) !== [1, 1, 1, 1] ? $badReads++ : $invalid++;
            continue;
        }
        [$m, $b, $r, $c] = array_map(static fn(array $t): string => $t[0], $row['t']);
        $secB = (int) $b;
        $secR = (int) $r;
        $secC = (int) $c;
        $elapsed = (float) $c - (float) $b;
        $predictedTrue = $secC > $secB || $elapsed >= 1.0;
        $actualTrue = $row['claim'] === true;
        $reentryNotNull = $row['read_reentry_null'] === false;
        if ($actualTrue !== $predictedTrue) {
            $discordant[] = ['i' => $row['i'], 'block' => $b, 'read' => $r, 'claim' => $c, 'claim_returned' => $row['claim']];
        }
        if ($secC > $secB) {
            $straddle++;
        }
        if ($reentryNotNull) {
            $failAt1095++;
        } elseif ($actualTrue) {
            $failAt1097++;
        }
        if ($secR > $secB) {
            $blockToRead++;
        } elseif ($secC > $secB) {
            $readToClaim++;
        }
        $expectedAll += (float) $c - (float) $b;
        $expectedFirst += (float) $r - (float) $b;
        $expectedSecond += (float) $c - (float) $r;
        $gaps[] = ((float) $c - (float) $b) * 1e6;
    }
    sort($gaps);
    $pick = static fn(float $q): float => $gaps[(int) floor($q * (count($gaps) - 1))] ?? 0.0;
    $valid = $k - $badReads - $invalid;
    [$lo, $hi] = poissonBand($expectedAll);
    [$lo1, $hi1] = poissonBand($expectedFirst);
    [$lo2, $hi2] = poissonBand($expectedSecond);
    $report['nat'] = [
        'k' => $k, 'valid' => $valid, 'bad_read_shape' => $badReads, 'invalid_not_applied' => $invalid,
        'n_c1_discordant' => count($discordant), 'n_c1_holds' => $discordant === [],
        'discordant_examples' => array_slice($discordant, 0, 10),
        'straddles' => $straddle, 'straddle_rate' => $valid > 0 ? $straddle / $valid : null,
        'wilson95_straddle_rate' => wilson($straddle, $valid),
        'n_c2' => ['expected_sum_gap_seconds' => $expectedAll, 'poisson95_band' => [$lo, $hi], 'observed' => $straddle, 'inside' => $straddle >= $lo && $straddle <= $hi],
        'split' => [
            'observed_L1095' => $failAt1095, 'observed_L1097' => $failAt1097,
            'expected_L1095_sum_gap_block_to_read' => $expectedFirst, 'band_L1095' => [$lo1, $hi1],
            'expected_L1097_sum_gap_read_to_claim' => $expectedSecond, 'band_L1097' => [$lo2, $hi2],
            'inside_L1095' => $failAt1095 >= $lo1 && $failAt1095 <= $hi1,
            'inside_L1097' => $failAt1097 >= $lo2 && $failAt1097 <= $hi2,
            'by_reading_only_L1095' => $blockToRead, 'by_reading_only_L1097' => $readToClaim,
        ],
        'gap_block_to_claim_us' => ['min' => $gaps[0] ?? null, 'p50' => $pick(0.5), 'p99' => $pick(0.99), 'max' => $gaps[count($gaps) - 1] ?? null],
    ];
    if (isset($options['expect-nat'])) {
        $report['nat']['complete'] = $k === (int) $options['expect-nat'];
    }
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
