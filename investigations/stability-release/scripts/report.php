<?php

declare(strict_types=1);

// #176 S4 (PLAN §8): the REPORT numbers, computed from what analyze.php wrote over the ten counted runs
// (data/stability.jsonl, data/s3.analysis.json) and the ci.yml timeouts. Prints one JSON document; it computes,
// it does not measure, and it is not one of the two frozen tools (V-TOOLS covers collect.sh and analyze.php).
//
//   php investigations/stability-release/scripts/report.php > investigations/stability-release/data/s4-report.json
//
// Wilson 95 % and two-sided Fisher are the definitions in investigations/flaky-160/fix-a3/harness/stats.php and k_lib.php.
// Quantiles: linear interpolation between the closest ranks.
// Two durations per job (F-176-04): seconds = completed_at − started_at (the job as GitHub records it, which the
// analysis uses), and steps_seconds = the last step's completed_at − started_at (the execution of its steps); the
// difference is GitHub's delay after the job's last step, reported per job as post_steps_max_s. steps_seconds is
// counted only for a job whose step list, as the API returned it, ends with "Complete job"; the API returned no steps
// or a partial list for some jobs, and those are left out of the steps_* fields (steps_n says how many remain).

require __DIR__ . '/../../flaky-160/fix-a3/harness/k_lib.php';

use function Flaky160B\wilson;
use function Flaky160D\fisherTwoSided;

$data = __DIR__ . '/../data';
// ci.yml timeout-minutes per job at 2bac701 (PLAN §3.5, #169)
$timeouts = ['Composer Validate' => 10, 'PHP Coding Style (PER 3.1)' => 10, 'PHP Syntax Check' => 10, 'PHP Examples' => 10,
    'Static Analysis (PHPStan)' => 10, 'Test Suite (PHP 8.4)' => 15, 'Test Suite (PHP 8.5)' => 15, 'Archive Replay Suite (PHP 8.4)' => 25,
    'Archive Replay Suite (PHP 8.5)' => 25, 'Integration Test Suite' => 15, 'Lowest Supported Dependencies' => 20,
    'Composer Security Audit' => 10, 'Consumer Verification Harness' => 15, 'Workflow Lint' => 10, 'Whitespace Verification' => 10,
    'Final Gate' => 5];

$quantile = static function (array $values, float $p): float {
    sort($values);
    $position = $p * (count($values) - 1);
    $low = (int) floor($position);
    $high = (int) ceil($position);

    return $values[$low] + ($values[$high] - $values[$low]) * ($position - $low);
};
$percent = static fn(array $interval): array => array_map(static fn(float $v): float => round(100 * $v, 3), $interval);

$stepsSeconds = [];
foreach (array_filter(array_map('trim', file("$data/s3-runs.txt"))) as $run) {
    foreach (json_decode((string) file_get_contents("$data/runs/$run.jobs.json"), true, flags: JSON_THROW_ON_ERROR) as $job) {
        $steps = $job['steps'] ?? [];
        $complete = $steps !== [] && end($steps)['name'] === 'Complete job' && end($steps)['completed_at'] !== null;
        $stepsSeconds[$job['id']] = $complete ? (int) strtotime(end($steps)['completed_at']) - (int) strtotime($job['started_at']) : null;
    }
}
$jobs = [];
$repeats = [];
foreach (file("$data/stability.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    if ($row['repeat'] === null) {
        continue;
    }
    $jobs[$row['job']][] = $row;
    $key = $row['run'] . '/' . $row['repeat'];
    $repeats[$key] = ($repeats[$key] ?? true) && $row['conclusion'] === 'success';
}

$perJob = [];
foreach ($jobs as $name => $rows) {
    $seconds = array_column($rows, 'seconds');
    $steps = array_values(array_filter(array_map(static fn(array $r): ?int => $stepsSeconds[$r['job_id']] ?? null, $rows), static fn(?int $v): bool => $v !== null));
    $postSteps = array_map(static fn(array $r): int => $r['seconds'] - $stepsSeconds[$r['job_id']], array_values(array_filter($rows, static fn(array $r): bool => ($stepsSeconds[$r['job_id']] ?? null) !== null)));
    $failures = count(array_filter($rows, static fn(array $r): bool => $r['conclusion'] !== 'success'));
    $timeout = $timeouts[$name] * 60;
    $perJob[$name] = [
        'n' => count($rows),
        'failures' => $failures,
        'wilson95_pct' => $percent(wilson($failures, count($rows))),
        'min_s' => min($seconds),
        'median_s' => round($quantile($seconds, 0.5), 1),
        'p95_s' => round($quantile($seconds, 0.95), 1),
        'max_s' => max($seconds),
        'timeout_s' => $timeout,
        'margin_at_max_pct' => round(100 * (1 - max($seconds) / $timeout), 1),
        'steps_n' => count($steps),
        'steps_median_s' => round($quantile($steps, 0.5), 1),
        'steps_p95_s' => round($quantile($steps, 0.95), 1),
        'steps_max_s' => max($steps),
        'steps_margin_at_max_pct' => round(100 * (1 - max($steps) / $timeout), 1),
        'post_steps_max_s' => max($postSteps),
    ];
}
ksort($perJob);

$analysis = json_decode((string) file_get_contents("$data/s3.analysis.json"), true, flags: JSON_THROW_ON_ERROR);
$runnerMinutes = 0.0;
$walls = [];
foreach ($analysis['runs'] as $run => $result) {
    $runnerMinutes += array_sum(array_column($result['per_repeat'], 'minutes'));
    $walls[$run] = $result['wall_minutes'];
}

$n = count($repeats);
$failed = count(array_filter($repeats, static fn(bool $ok): bool => ! $ok));
echo json_encode([
    'repeats' => $n,
    'failed_repeats' => $failed,
    'wilson95_pct' => $percent(wilson($failed, $n)),
    'fisher_two_sided_vs_history' => [
        'start_of_day_5_of_243' => round(fisherTwoSided($failed, $n - $failed, 5, 238), 4),
        'tag_time_5_of_237' => round(fisherTwoSided($failed, $n - $failed, 5, 232), 4),
    ],
    'runner_hours' => round($runnerMinutes / 60, 1),
    'dispatch_wall_minutes' => $walls,
    'dispatch_wall_minutes_quantiles' => ['min' => min($walls), 'median' => round($quantile(array_values($walls), 0.5), 2), 'max' => max($walls)],
    'v_env_files' => $analysis['v_env']['files'],
    'v_deps_samples_per_job' => array_values(array_unique(array_column($analysis['v_deps']['per_job'], 'samples'))),
    'per_job' => $perJob,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
