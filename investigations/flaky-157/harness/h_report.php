<?php

declare(strict_types=1);

// #157 arm H classifier (PLAN.md §9.3). Reads h_probe.php JSONL files and
// judges H-1, H-2, H-3. Prints one JSON document.
//
// Usage: php h_report.php [--expect=<probe|forced>:<shards>x<M>] <h-<mode>-<shard>/h.jsonl> [...]
//
// --expect (Lead condition 2 before arm L, applied to H as well): shards 1..n of
// that mode must each hold M sequence lines; a shard whose file is missing or
// cut short (no summary line) is a counted shortfall. E-CI: --expect=probe:4x25000
// --expect=forced:10x1000, one call per mode.
//        php h_report.php --selftest   (synthetic rows: each registered violation must be caught)

namespace Flaky157;

require __DIR__ . '/lib.php';

/** @param iterable<array<string, mixed>> $rows */
function classify(iterable $rows): array
{
    $result = [
        'sequences' => 0,
        'm1_nonthrow' => 0,
        'm1_nonthrow_values' => [],
        'c1_true' => 0,
        'c1_true_nonthrow' => 0,
        'd_unavailable' => 0,
        'd_band' => ['le_minus3' => 0, 'band' => 0, 'ge_3' => 0],
        'd_band_nonthrow' => 0,
        'h1_violations' => [],
        'h2_violations' => [],
        'h3_findings' => [],
        'span_us' => [],
        'u0_lt_1000' => 0,
    ];
    foreach ($rows as $row) {
        $result['sequences']++;
        $nonThrow = str_starts_with((string) $row['m1'], 'R:');
        $c1 = $row['u0'] + $row['span'] < 1000;
        $result['span_us'][] = $row['span'];
        $result['u0_lt_1000'] += $row['u0'] < 1000 ? 1 : 0;
        if ($nonThrow) {
            $result['m1_nonthrow']++;
            $result['m1_nonthrow_values'][$row['m1']] = ($result['m1_nonthrow_values'][$row['m1']] ?? 0) + 1;
        }
        if ($c1) {
            $result['c1_true']++;
            $result['c1_true_nonthrow'] += $nonThrow ? 1 : 0;
        }
        // H-1: a non-throwing '-1' MUTATE only if u0 + span < 1000 µs.
        if ($nonThrow && ! $c1) {
            $result['h1_violations'][] = $row;
        }
        // H-2: D ≤ −3 ⇒ {0}; D ≥ 3 ⇒ throws; |D| ≤ 2 reported only.
        if ($row['D'] === null) {
            $result['d_unavailable']++;
        } elseif ($row['D'] <= -3) {
            $result['d_band']['le_minus3']++;
            if (! $nonThrow) {
                $result['h2_violations'][] = $row;
            }
        } elseif ($row['D'] >= 3) {
            $result['d_band']['ge_3']++;
            if ($nonThrow) {
                $result['h2_violations'][] = $row;
            }
        } else {
            $result['d_band']['band']++;
            $result['d_band_nonthrow'] += $nonThrow ? 1 : 0;
        }
        // H-3: every other call throws RateLimiterException.
        if ($row['x'] !== []) {
            $result['h3_findings'][] = $row;
        }
    }
    $spans = $result['span_us'];
    sort($spans);
    $count = count($spans);
    $result['span_us'] = $count === 0 ? null : [
        'min' => $spans[0],
        'p01' => $spans[(int) floor(0.01 * ($count - 1))],
        'p50' => $spans[(int) floor(0.5 * ($count - 1))],
        'p99' => $spans[(int) floor(0.99 * ($count - 1))],
        'max' => $spans[$count - 1],
    ];
    $result['m1_nonthrow_wilson95'] = wilson($result['m1_nonthrow'], $result['sequences']);
    $result['verdict'] = [
        'H-1' => $result['h1_violations'] === [] ? 'holds' : 'FINDING',
        'H-2' => $result['h2_violations'] === [] ? 'holds' : 'FINDING',
        'H-3' => $result['h3_findings'] === [] ? 'holds' : 'FINDING',
    ];
    foreach (['h1_violations', 'h2_violations', 'h3_findings'] as $list) {
        $result[$list . '_count'] = count($result[$list]);
        $result[$list] = array_slice($result[$list], 0, 20);
    }
    return $result;
}

if (($argv[1] ?? '') === '--selftest') {
    $base = ['t0s' => 1, 'polls' => 1, 'span_m' => 0, 'intact' => true, 'x' => []];
    $cases = [
        'P2 inside C1 and band: no violation' => ['u0' => 100, 'span' => 400, 'm1' => 'R:applied=0', 'D' => 0],
        'throw outside C1, D>=3: no violation' => ['u0' => 5000, 'span' => 900, 'm1' => 'T:inconsistent generated score expiry', 'D' => 5],
        'non-throw outside C1: H-1 violation' => ['u0' => 5000, 'span' => 900, 'm1' => 'R:applied=0', 'D' => 0],
        'non-throw with D>=3: H-2 violation' => ['u0' => 10, 'span' => 10, 'm1' => 'R:applied=0', 'D' => 7],
        'throw with D<=-3: H-2 violation' => ['u0' => 10, 'span' => 10, 'm1' => 'T:inconsistent generated score expiry', 'D' => -4],
        'other call accepted: H-3 finding' => ['u0' => 5000, 'span' => 900, 'm1' => 'T:x', 'D' => 5, 'x' => ['0:mutate:R:applied=0']],
    ];
    $report = [];
    foreach ($cases as $label => $row) {
        $one = classify([$row + $base]);
        $report[$label] = $one['verdict'];
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

$rows = [];
$summaries = [];
$expect = [];
$files = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--expect=')) {
        if (preg_match('/^--expect=(probe|forced):(\d+)x(\d+)$/', $argument, $match) !== 1) {
            fwrite(STDERR, "bad $argument\n");
            exit(2);
        }
        $expect[$match[1]] = ['shards' => (int) $match[2], 'm' => (int) $match[3]];
        continue;
    }
    $file = ['rows' => 0, 'summary_m' => null];
    foreach (readJsonl($argument) as $row) {
        if (isset($row['summary'])) {
            $summaries[] = $row;
            $file['summary_m'] = $row['m'];
        } else {
            $rows[] = $row;
            $file['rows']++;
        }
    }
    $files[basename(dirname($argument))] = $file;
}
$result = classify($rows);
$result['probe_summaries'] = $summaries;
$result['files'] = $files;
foreach ($expect as $mode => $spec) {
    $shards = [];
    $shortfall = 0;
    for ($shard = 1; $shard <= $spec['shards']; $shard++) {
        $file = $files["h-$mode-$shard"] ?? null;
        $have = $file['rows'] ?? 0;
        $shards[$shard] = [
            'expected' => $spec['m'],
            'sequences' => $have,
            'shortfall' => $spec['m'] - $have,
            'file' => $file === null ? 'missing' : ($file['summary_m'] === null ? 'no summary line' : 'complete'),
        ];
        $shortfall += $spec['m'] - $have;
    }
    $result['expected'][$mode] = ['expected' => $spec['shards'] * $spec['m'], 'shortfall' => $shortfall, 'shards' => $shards];
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
