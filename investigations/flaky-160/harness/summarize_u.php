<?php

declare(strict_types=1);

// #160 arm U summary (PLAN.md §4.4, §12.2 LT-160-02/03). Reads verdict JSONL
// files from run.sh and prints one JSON document per arm label.
//
// Usage: php summarize_u.php [--expect=<arm>:<first>-<last>[:<per shard>]]... <verdicts.jsonl> [...]
//   Arm U on E-CI: --expect=u:1-3300:<per shard> (the shard layout fixed from the measured cost).
//
// Registered (U only): pooled = valid runs with a failure or error in one of
// the six ÷ valid runs, Wilson 95 %; inside iff the point rate lies in the
// Wilson 95 % interval of #157 L (11 / 3,300). Descriptive: per method, the
// all-failures rate (six + seventh), the two-sample Fisher exact p against L,
// and every seventh-method failure listed as a finding.

namespace Flaky160;

require __DIR__ . '/u_lib.php';

/**
 * @param array{first: int, last: int, per: ?int} $range
 * @param list<array<string, mixed>> $verdicts
 */
function coverage(array $range, array $verdicts): array
{
    $seen = [];
    foreach ($verdicts as $verdict) {
        $seen[(int) $verdict['id']] = ($seen[(int) $verdict['id']] ?? 0) + 1;
    }
    $missing = [];
    for ($id = $range['first']; $id <= $range['last']; $id++) {
        if (! isset($seen[$id])) {
            $missing[] = $id;
        }
    }
    $result = [
        'expected' => $range['last'] - $range['first'] + 1,
        'shortfall' => count($missing),
        'missing_ids' => $missing,
        'duplicated_ids' => array_keys(array_filter($seen, static fn(int $n): bool => $n > 1)),
        'outside_ids' => array_keys(array_filter($seen, static fn(int $n, int $id): bool => $id < $range['first'] || $id > $range['last'], ARRAY_FILTER_USE_BOTH)),
    ];
    if ($range['per'] !== null) {
        foreach (range(1, intdiv($result['expected'] + $range['per'] - 1, $range['per'])) as $shard) {
            $low = $range['first'] + ($shard - 1) * $range['per'];
            $high = min($low + $range['per'] - 1, $range['last']);
            $absent = count(array_filter($missing, static fn(int $id): bool => $id >= $low && $id <= $high));
            $result['shards'][$shard] = ['expected' => $high - $low + 1, 'verdicts' => $high - $low + 1 - $absent, 'shortfall' => $absent];
        }
    }
    return $result;
}

$byArm = [];
$expect = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--expect=')) {
        if (preg_match('/^--expect=([^:]+):(\d+)-(\d+)(?::(\d+))?$/', $argument, $match) !== 1) {
            fwrite(STDERR, "bad {$argument}\n");
            exit(2);
        }
        $expect[$match[1]] = ['first' => (int) $match[2], 'last' => (int) $match[3], 'per' => isset($match[4]) ? (int) $match[4] : null];
        $byArm[$match[1]] ??= [];
        continue;
    }
    foreach (\Flaky157\readJsonl($argument) as $verdict) {
        $byArm[$verdict['arm']][] = $verdict;
    }
}

[$lLow, $lHigh] = \Flaky157\wilson(L_FAILED, L_RUNS);
$report = [];
foreach ($byArm as $arm => $verdicts) {
    $entry = ['runs' => count($verdicts), 'valid' => 0, 'invalid' => 0, 'invalid_reasons' => [], 'pooled_runs' => 0, 'any_failure_runs' => 0,
        'per_method' => array_fill_keys(array_keys(SIX), 0), 'pooled_failures' => [], 'seventh_findings' => [], 'wall_seconds' => []];
    foreach ($verdicts as $verdict) {
        $entry['wall_seconds'][] = $verdict['wall_seconds'];
        if (! $verdict['valid']) {
            $entry['invalid']++;
            $entry['invalid_reasons'][] = ['id' => $verdict['id'], 'problems' => $verdict['problems'] ?? [], 'v_ord' => $verdict['v_ord'] ?? null,
                'v_port' => $verdict['v_port'] ?? null, 'ordinals_differ' => $verdict['ordinals_differ'] ?? null];
            continue;
        }
        $entry['valid']++;
        $entry['pooled_runs'] += $verdict['pooled'] ? 1 : 0;
        $entry['any_failure_runs'] += $verdict['any_failure'] ? 1 : 0;
        foreach ($verdict['six'] as $id => $six) {
            if ($six['status'] === 'fail') {
                $entry['per_method'][$id]++;
                $entry['pooled_failures'][] = ['id' => $verdict['id'], 'method' => $id, 'kind' => $six['kind'], 'first_line' => $six['first_line'], 'lines' => $six['lines']];
            }
        }
        foreach ($verdict['seventh'] as $finding) {
            $entry['seventh_findings'][] = ['id' => $verdict['id']] + $finding;
        }
    }
    $walls = $entry['wall_seconds'];
    sort($walls);
    $entry['wall_seconds'] = $walls === [] ? null : [
        'n' => count($walls), 'min' => $walls[0], 'p50' => $walls[intdiv(count($walls), 2)], 'max' => end($walls), 'sum' => round(array_sum($walls), 1),
    ];
    if ($entry['valid'] > 0) {
        $point = $entry['pooled_runs'] / $entry['valid'];
        $entry['pooled_rate'] = $point;
        $entry['pooled_wilson95'] = \Flaky157\wilson($entry['pooled_runs'], $entry['valid']);
        $entry['l_interval'] = [$lLow, $lHigh];
        $entry['position'] = $point < $lLow ? 'below' : ($point > $lHigh ? 'above' : 'inside');
        $entry['any_failure_rate'] = $entry['any_failure_runs'] / $entry['valid'];
        $entry['any_failure_wilson95'] = \Flaky157\wilson($entry['any_failure_runs'], $entry['valid']);
        $entry['per_method_wilson95'] = array_map(static fn(int $k): array => \Flaky157\wilson($k, $entry['valid']), $entry['per_method']);
        $entry['fisher_two_sided_vs_L'] = fisherTwoSided($entry['pooled_runs'], $entry['valid'] - $entry['pooled_runs'], L_FAILED, L_RUNS - L_FAILED);
    }
    if (isset($expect[$arm])) {
        $entry = array_merge($entry, coverage($expect[$arm], $verdicts));
    }
    $report[$arm] = $entry;
}
ksort($report);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
