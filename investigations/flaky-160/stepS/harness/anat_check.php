<?php

declare(strict_types=1);

// #160 phase 2b — verdict of one recorded A run (PLAN.md §4). Prints one JSON line.
// Usage: php anat_check.php --arm=<label> --id=<n> --dir=<run dir with rec-*.jsonl and phpunit.out> --exit=<phpunit status>
//        [--wall=<s>] [--workers=32]

require __DIR__ . '/lib.php';

use Flaky160B\Analysis;

$o = getopt('', ['arm:', 'id:', 'dir:', 'exit:', 'wall::', 'workers::', 'mode::', 'rows::']);
$change = (($o['mode'] ?? 'base') === 'change');
$dir = (string) $o['dir'];
$expectWorkers = (int) ($o['workers'] ?? 32);
$problems = [];
$out = is_file("$dir/phpunit.out") ? (string) file_get_contents("$dir/phpunit.out") : '';
$pass = str_contains($out, 'OK (1 test');
$failed = ! $pass;
$actual = $expected = null;
$atL136 = false;
if ($failed) {
    if (preg_match('/Failed asserting that (\d+) is identical to (\d+)\./', $out, $m)) {
        $actual = (int) $m[1];
        $expected = (int) $m[2];
    }
    $atL136 = (bool) preg_match('/KnownDeviceAllowanceConcurrencyIntegrationTest\.php:136\b/', $out);
}

$workers = [];
$rootSeen = 0;
foreach (glob("$dir/rec-*.jsonl") ?: [] as $f) {
    [$records, $pp, $root] = Analysis::readFile($f);
    foreach ($pp as $p) {
        $problems[] = basename($f) . ':' . $p;
    }
    if ($root) {
        $rootSeen++;
        continue;
    }
    $workers[basename($f)] = Analysis::worker($records, $change);
}
if ($rootSeen !== 1) {
    $problems[] = "root_files:$rootSeen";
}
if (count($workers) !== $expectWorkers) {
    $problems[] = 'workers:' . count($workers);
}

$sumU = array_sum(array_column($workers, 'u'));
$sumK = array_sum(array_column($workers, 'k'));
$consumed = $workers === [] ? 0 : max(array_column($workers, 'max_count'));
$conflicts = count(array_filter($workers, static fn(array $w): bool => $w['conflict']));
if ($sumU !== $consumed) {
    $problems[] = "v_key:sumU=$sumU,consumed=$consumed";
}
// Internal consistency of the record against the test's own number: the K5 value the test read.
if ($failed && $actual !== null && $actual !== 2 * $sumK) {
    $problems[] = "v_outcome:k5_read=$actual,2sumK=" . (2 * $sumK);
}
if ($failed && $actual !== null && ! $atL136) {
    $problems[] = 'failure_not_at_L136';
}
if ($failed && $actual === null) {
    $problems[] = 'failure_unparsed';
}
if ($failed && $expected !== null && $expected !== 2 * ($sumU - $conflicts)) {
    // The inferred conflicts differ from the test's counted outcomes: reported as a problem, never silently fixed.
    $problems[] = "v_outcome:expected=$expected,2(sumU-conflicts)=" . (2 * ($sumU - $conflicts));
}
if ($pass && $sumK * 2 !== 2 * ($sumU - $conflicts)) {
    $problems[] = "v_outcome:pass_but_sumK=$sumK,sumU-conflicts=" . ($sumU - $conflicts);
}
if (! $pass && ! $failed) {
    $problems[] = 'unclassified';
}

$anom = array_keys(array_filter($workers, static fn(array $w): bool => $w['u'] >= 1 && $w['k'] === 0 && ! $w['conflict']));
$excess = array_keys(array_filter($workers, static fn(array $w): bool => $w['conflict'] && $w['k'] >= 1));
$sites = [];
foreach ($workers as $w) {
    if ($w['conflict']) {
        $sites[] = ['site' => $w['conflict_site'], 'route' => $w['route'], 'k' => $w['k'], 'pubs' => $w['pubs'], 'obs_first' => $w['obs_first'], 'obs_last' => $w['obs_last'], 'exit' => $w['exit']];
    }
}
// Step S: the publication-attempt distribution of the run, and for every deferred worker the lag until another
// worker's applied K4 publication (hrtime, same host): null = no later applied publication in this run.
$dist = [];
$deferred = [];
foreach ($workers as $name => $w) {
    $dist[$w['pub_outcome']] = ($dist[$w['pub_outcome']] ?? 0) + 1;
    if (str_starts_with($w['pub_outcome'], 'deferred_')) {
        $lastEnd = $w['pub_times'] === [] ? 0 : end($w['pub_times'])[1];
        $lag = null;
        foreach ($workers as $other => $ow) {
            if ($other === $name) {
                continue;
            }
            foreach ($ow['pub_times'] as [$t0, $t1, $applied]) {
                if ($applied && $t1 >= $lastEnd && ($lag === null || $t1 - $lastEnd < $lag)) {
                    $lag = $t1 - $lastEnd;
                }
            }
        }
        $deferred[] = ['worker' => $name, 'outcome' => $w['pub_outcome'], 'pubs' => $w['pubs'], 'later_applied_lag_ns' => $lag];
    }
}
if (isset($o['rows'])) {
    $rows = '';
    foreach ($workers as $name => $w) {
        unset($w['pub_times']);
        $rows .= json_encode(['arm' => (string) $o['arm'], 'id' => (int) $o['id'], 'worker' => $name] + $w, JSON_UNESCAPED_SLASHES) . "\n";
    }
    file_put_contents((string) $o['rows'], $rows, FILE_APPEND | LOCK_EX);
}
$line = [
    'arm' => (string) $o['arm'], 'id' => (int) $o['id'], 'valid' => $problems === [], 'problems' => $problems,
    'test' => $pass ? 'pass' : 'fail', 'exit' => (int) $o['exit'], 'failure_at_l136' => $atL136, 'k5_read' => $actual, 'expected' => $expected,
    'sum_u' => $sumU, 'sum_k' => $sumK, 'consumed' => $consumed, 'conflicts' => $conflicts,
    'unit_without_k5_no_conflict' => count($anom), 'conflict_with_k5' => count($excess), 'conflict_sites' => $sites,
    'pub_outcomes' => $dist, 'deferred' => $deferred,
    'wall' => isset($o['wall']) ? (float) $o['wall'] : null,
];
echo json_encode($line, JSON_UNESCAPED_SLASHES), "\n";
