<?php

declare(strict_types=1);

// #160 step M — M2a: baselines from phase 2b's committed logs, no run.
//   php m2a.php --logs=<phase2b/logs/anat/E-CI>
// (1) the 2,000 verdict lines: workers that ended in a conflict, by site and publication attempts (the only per-worker
//     fields a verdict line keeps for a passing run: its record files were deleted when the run passed);
// (2) the two retained failing runs (362, 756): every worker's mutation and publication attempts, from the record files.

require __DIR__ . '/lib.php';

use Flaky160B\Analysis;

$o = getopt('', ['logs:']);
$logs = (string) $o['logs'];

$runs = 0;
$failing = 0;
$sites = ['mutation' => 0, 'publication' => 0];
$pubConflictAttempts = [];
$perRunMutationConflicts = [];
foreach (glob("$logs/shard-*/verdicts.jsonl") ?: [] as $f) {
    foreach (file($f, FILE_IGNORE_NEW_LINES) as $l) {
        $r = json_decode($l, true);
        if ($r['arm'] !== 'anat') {
            continue;
        }
        $runs++;
        $failing += $r['test'] === 'fail' ? 1 : 0;
        $m = 0;
        foreach ($r['conflict_sites'] as $c) {
            $sites[$c['site']]++;
            if ($c['site'] === 'publication') {
                $pubConflictAttempts[$c['pubs']] = ($pubConflictAttempts[$c['pubs']] ?? 0) + 1;
            } else {
                $m++;
            }
        }
        $perRunMutationConflicts[$m] = ($perRunMutationConflicts[$m] ?? 0) + 1;
    }
}
ksort($perRunMutationConflicts);

$retained = [];
foreach (glob("$logs/shard-*/runs/anat-*") ?: [] as $dir) {
    $run = basename($dir);
    $dist = ['muts' => [], 'pubs' => [], 'workers' => 0, 'publication_reached' => 0, 'first_attempt_applied' => 0, 'later_attempt_applied' => 0, 'not_applied' => 0];
    foreach (glob("$dir/rec/rec-*.jsonl") ?: [] as $rf) {
        [$records, , $root] = Analysis::readFile($rf);
        if ($root) {
            continue;
        }
        $w = Analysis::worker($records);
        $dist['workers']++;
        $dist['muts'][$w['muts']] = ($dist['muts'][$w['muts']] ?? 0) + 1;
        $dist['pubs'][$w['pubs']] = ($dist['pubs'][$w['pubs']] ?? 0) + 1;
        if ($w['pubs'] >= 1) {
            $dist['publication_reached']++;
            if ($w['pub_non_applied'] === 0) {
                $dist['first_attempt_applied']++;
            } elseif ($w['pubs'] > $w['pub_non_applied']) {
                $dist['later_attempt_applied']++;
            } else {
                $dist['not_applied']++;
            }
        }
    }
    ksort($dist['muts']);
    ksort($dist['pubs']);
    $retained[$run] = $dist;
}
ksort($retained);
echo json_encode([
    'verdict_runs' => $runs, 'failing_runs' => $failing, 'conflict_workers_by_site' => $sites,
    'publication_conflict_attempts' => $pubConflictAttempts, 'runs_by_number_of_mutation_conflict_workers' => $perRunMutationConflicts,
    'retained_runs_worker_attempts' => $retained,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
