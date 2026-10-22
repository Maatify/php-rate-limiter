<?php

declare(strict_types=1);

// #157 arm L / D summary (PLAN.md §5.5, §9.1 LT-157-03/05/06). Reads verdict
// JSONL files from run.sh and prints one JSON document.
//
// Usage: php summarize.php [--expect=<arm>:<first>-<last>[:<per shard>]]... <verdicts.jsonl> [...]
//
// --expect states the run ids an arm must have (Lead condition 2 before arm L):
// a run that writes no verdict line (V-HEAD refusing in a shard, a job killed
// by its timeout) is then a counted shortfall, per shard, instead of a line
// that is simply absent. Arm L on E-CI: --expect=l:1-3300:165.

namespace Flaky157;

require __DIR__ . '/lib.php';

const HISTORICAL_FAILURES = 1;
const HISTORICAL_EXECUTIONS = 1082;

/**
 * Expected ids against the verdict lines: missing, duplicated and out-of-range
 * ids, and per shard (ids (s-1)*per+first .. s*per+first-1) the shortfall.
 *
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
    $duplicated = array_keys(array_filter($seen, static fn(int $n): bool => $n > 1));
    $outside = array_keys(array_filter($seen, static fn(int $n, int $id): bool => $id < $range['first'] || $id > $range['last'], ARRAY_FILTER_USE_BOTH));
    $result = [
        'expected' => $range['last'] - $range['first'] + 1,
        'shortfall' => count($missing),
        'missing_ids' => $missing,
        'duplicated_ids' => $duplicated,
        'outside_ids' => $outside,
    ];
    if ($range['per'] !== null) {
        $shards = [];
        foreach (range(1, intdiv($result['expected'] + $range['per'] - 1, $range['per'])) as $shard) {
            $low = $range['first'] + ($shard - 1) * $range['per'];
            $high = min($low + $range['per'] - 1, $range['last']);
            $absent = array_values(array_filter($missing, static fn(int $id): bool => $id >= $low && $id <= $high));
            $shards[$shard] = ['expected' => $high - $low + 1, 'verdicts' => $high - $low + 1 - count($absent), 'shortfall' => count($absent)];
        }
        $result['shards'] = $shards;
    }
    return $result;
}

$byArm = [];
$expect = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--expect=')) {
        if (preg_match('/^--expect=([^:]+):(\d+)-(\d+)(?::(\d+))?$/', $argument, $match) !== 1) {
            fwrite(STDERR, "bad $argument\n");
            exit(2);
        }
        $expect[$match[1]] = ['first' => (int) $match[2], 'last' => (int) $match[3], 'per' => isset($match[4]) ? (int) $match[4] : null];
        $byArm[$match[1]] ??= [];
        continue;
    }
    foreach (readJsonl($argument) as $verdict) {
        $byArm[$verdict['arm']][] = $verdict;
    }
}

$report = [];
foreach ($byArm as $arm => $verdicts) {
    $entry = ['expected' => null, 'runs' => count($verdicts), 'valid' => 0, 'invalid' => 0, 'invalid_reasons' => [], 'reproduced' => 0, 'target_other' => 0, 'other_failures_runs' => 0, 'findings' => []];
    foreach ($verdicts as $verdict) {
        if (! $verdict['valid']) {
            $entry['invalid']++;
            $reasons = $verdict['problems'];
            if (isset($verdict['v_ord']) && ! $verdict['v_ord']) {
                $reasons[] = 'v_ord:ordinal=' . var_export($verdict['ordinal'], true);
            }
            if (! $verdict['v_port']) {
                $reasons[] = 'v_port';
            }
            if ($verdict['target'] === 'missing') {
                $reasons[] = 'target_missing';
            }
            $entry['invalid_reasons'][] = ['id' => $verdict['id'], 'reasons' => $reasons];
            continue;
        }
        $entry['valid']++;
        if ($verdict['reproduced']) {
            $entry['reproduced']++;
        } elseif ($verdict['target'] === 'fail') {
            $entry['target_other']++;
        }
        if ($verdict['other_failures'] > 0) {
            $entry['other_failures_runs']++;
        }
        foreach ($verdict['findings'] as $finding) {
            $entry['findings'][] = ['id' => $verdict['id'], 'finding' => $finding];
        }
        if (isset($verdict['d_signature'])) {
            $entry['d_ok'] = ($entry['d_ok'] ?? 0) + ($verdict['d_signature']['d_ok'] ? 1 : 0);
        }
    }
    if ($arm === 'l') {
        $entry['rate_wilson95'] = wilson($entry['reproduced'], $entry['valid']);
        $p = HISTORICAL_FAILURES / HISTORICAL_EXECUTIONS;
        [$low, $high] = wilson(HISTORICAL_FAILURES, HISTORICAL_EXECUTIONS);
        $entry['power_at_historical_rate'] = [
            'point' => 1 - (1 - $p) ** $entry['valid'],
            'wilson_low' => 1 - (1 - $low) ** $entry['valid'],
            'wilson_high' => 1 - (1 - $high) ** $entry['valid'],
        ];
    }
    if (isset($expect[$arm])) {
        $entry = array_merge($entry, coverage($expect[$arm], $verdicts));
    }
    $report[$arm] = $entry;
}
ksort($report);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
