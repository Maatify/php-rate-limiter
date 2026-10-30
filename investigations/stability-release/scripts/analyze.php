<?php

declare(strict_types=1);

// #176 (PLAN §3.4, §8): the checks and cost of collected Stability Evidence runs (collect.sh), as one JSON document,
// and one line per job in data/stability.jsonl (rewritten from the given runs).
//
//   php analyze.php --ref=<sha> --repeats=<n> --baseline=<baseline jobs.json> <run_id> [<run_id> …]
//
//   V-REF       every job ran on --ref (head_sha)
//   V-NAMES     one "Plan (repeats and V-REF)" job; every other job is "ci (N) / <name>", N in 1..repeats, and
//               each repeat has exactly the baseline's 16 job names
//   V-COUNT     jobs = 1 + repeats × 16
//   V-PARALLEL  for every job name, the repeats' [started, completed] intervals overlap (latest start < earliest end),
//               and no job was cancelled or skipped
//   V-DEPS      for every job name with environment lines, those lines are identical across all repeats of all runs
//   V-ENV       every environment file has "Locking" lines and an "Installed PHP" line, and those of the PHPUnit jobs
//               (Test Suite, Archive Replay Suite, Integration, Lowest) a "PHPUnit x.y.z by" line: the inputs reached the file
//   queued_after_plan_s  reported only (F-176-01, a proposed V-PARALLEL criterion for the Lead): the latest
//               created_at of a non-gate job minus the plan job's completed_at; Final Gate is queued by its own repeat's needs
//   peak_concurrent_jobs reported only: the largest number of jobs running at one instant
//   cost        per repeat: the sum of its jobs' durations; per run: the wall time from the first start to the last end

$options = getopt('', ['ref:', 'repeats:', 'baseline:'], $rest);
$runs = array_slice($argv, $rest);
$ref = (string) $options['ref'];
$repeats = (int) $options['repeats'];
$dir = __DIR__ . '/../data';
$time = static fn(string $iso): int => (int) strtotime($iso);
$baselineNames = array_map(static fn(array $j): string => $j['name'], json_decode((string) file_get_contents((string) $options['baseline']), true)['jobs']);
sort($baselineNames);
$baselineSeconds = array_sum(array_map(static fn(array $j): int => $time($j['completed_at']) - $time($j['started_at']), json_decode((string) file_get_contents((string) $options['baseline']), true)['jobs']));

$report = ['ref' => $ref, 'repeats' => $repeats, 'baseline_minutes_per_repeat' => round($baselineSeconds / 60, 1), 'runs' => []];
$lines = [];
$envByName = [];
$envProblems = [];
foreach ($runs as $run) {
    $jobs = json_decode((string) file_get_contents("$dir/runs/$run.jobs.json"), true, flags: JSON_THROW_ON_ERROR);
    $checks = ['v_ref' => true, 'v_names' => true, 'v_count' => count($jobs) === 1 + $repeats * 16, 'v_parallel' => true];
    $plan = 0;
    $planDone = null;
    $queuedLatest = 0;
    $events = [];
    $byRepeat = [];
    $byName = [];
    $first = PHP_INT_MAX;
    $last = 0;
    foreach ($jobs as $job) {
        $checks['v_ref'] = $checks['v_ref'] && $job['head_sha'] === $ref;
        $start = $time($job['started_at']);
        $end = $time($job['completed_at']);
        $first = min($first, $start);
        $events[] = [$start, 1];
        $events[] = [$end, -1];
        $last = max($last, $end);
        $seconds = $end - $start;
        $repeat = null;
        $name = $job['name'];
        if ($name === 'Plan (repeats and V-REF)') {
            $plan++;
            $planDone = $end;
        } elseif (preg_match('/\Aci \((\d+)\) \/ (.+)\z/', $name, $m) === 1 && (int) $m[1] >= 1 && (int) $m[1] <= $repeats) {
            $repeat = (int) $m[1];
            $name = $m[2];
            $byRepeat[$repeat][$name] = ['conclusion' => $job['conclusion'], 'seconds' => $seconds];
            if ($name !== 'Final Gate') {
                $queuedLatest = max($queuedLatest, $time($job['created_at']));
            }
            $byName[$name][$repeat] = [$start, $end, $job['conclusion']];
            $env = "$dir/runs/$run/env/{$job['id']}.txt";
            if (is_file($env)) {
                $text = (string) file_get_contents($env);
                $envByName[$name]["$run/$repeat"] = hash('sha256', $text);
                $phpunitJob = preg_match('/\A(Test Suite|Archive Replay Suite|Integration Test Suite|Lowest Supported Dependencies)/', $name) === 1;
                if (! str_contains($text, '  - Locking ') || preg_match('/Installed PHP \d+\.\d+\.\d+/', $text) !== 1
                    || ($phpunitJob && preg_match('/^PHPUnit \d+\.\d+\.\d+ by/m', $text) !== 1)) {
                    $envProblems[] = "$run/$repeat/$name";
                }
            }
        } else {
            $checks['v_names'] = false;
        }
        $lines[] = ['run' => (int) $run, 'repeat' => $repeat, 'job' => $name, 'job_id' => $job['id'], 'head_sha' => $job['head_sha'],
            'conclusion' => $job['conclusion'], 'started_at' => $job['started_at'], 'completed_at' => $job['completed_at'], 'seconds' => $seconds];
    }
    $checks['v_names'] = $checks['v_names'] && $plan === 1 && count($byRepeat) === $repeats;
    foreach ($byRepeat as $names) {
        $keys = array_keys($names);
        sort($keys);
        $checks['v_names'] = $checks['v_names'] && $keys === $baselineNames;
    }
    $overlap = [];
    foreach ($byName as $name => $intervals) {
        $latestStart = max(array_column($intervals, 0));
        $earliestEnd = min(array_column($intervals, 1));
        $overlap[$name] = $latestStart < $earliestEnd;
        foreach ($intervals as [, , $conclusion]) {
            $checks['v_parallel'] = $checks['v_parallel'] && ! in_array($conclusion, ['cancelled', 'skipped'], true);
        }
        $checks['v_parallel'] = $checks['v_parallel'] && $overlap[$name];
    }
    $perRepeat = [];
    foreach ($byRepeat as $repeat => $names) {
        $outcomes = array_count_values(array_column($names, 'conclusion'));
        ksort($outcomes);
        $perRepeat[$repeat] = ['outcomes' => $outcomes, 'minutes' => round(array_sum(array_column($names, 'seconds')) / 60, 1)];
    }
    ksort($perRepeat);
    usort($events, static fn(array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
    $running = 0;
    $peak = 0;
    foreach ($events as [, $delta]) {
        $running += $delta;
        $peak = max($peak, $running);
    }
    $report['runs'][$run] = ['jobs' => count($jobs), 'checks' => $checks, 'per_repeat' => $perRepeat,
        'wall_minutes' => round(($last - $first) / 60, 1), 'overlap_per_job' => $overlap,
        'queued_after_plan_s' => $planDone === null ? null : $queuedLatest - $planDone, 'peak_concurrent_jobs' => $peak];
}
$deps = [];
foreach ($envByName as $name => $hashes) {
    $deps[$name] = ['samples' => count($hashes), 'distinct' => count(array_unique($hashes))];
}
ksort($deps);
$report['v_deps'] = ['holds' => $deps !== [] && max(array_column($deps, 'distinct')) === 1, 'per_job' => $deps];
$report['v_env'] = ['holds' => $envByName !== [] && $envProblems === [], 'files' => array_sum(array_map('count', $envByName)), 'incomplete' => $envProblems];
file_put_contents("$dir/stability.jsonl", implode('', array_map(static fn(array $l): string => json_encode($l, JSON_UNESCAPED_SLASHES) . "\n", $lines)));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
