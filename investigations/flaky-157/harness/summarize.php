<?php

declare(strict_types=1);

// #157 arm L / D summary (PLAN.md §5.5, §9.1 LT-157-03/05/06). Reads verdict
// JSONL files from run.sh and prints one JSON document.
//
// Usage: php summarize.php <verdicts.jsonl> [...]

namespace Flaky157;

require __DIR__ . '/lib.php';

const HISTORICAL_FAILURES = 1;
const HISTORICAL_EXECUTIONS = 1082;

$byArm = [];
foreach (array_slice($argv, 1) as $path) {
    foreach (readJsonl($path) as $verdict) {
        $byArm[$verdict['arm']][] = $verdict;
    }
}

$report = [];
foreach ($byArm as $arm => $verdicts) {
    $entry = ['runs' => count($verdicts), 'valid' => 0, 'invalid' => 0, 'invalid_reasons' => [], 'reproduced' => 0, 'target_other' => 0, 'other_failures_runs' => 0, 'findings' => []];
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
    $report[$arm] = $entry;
}
ksort($report);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
