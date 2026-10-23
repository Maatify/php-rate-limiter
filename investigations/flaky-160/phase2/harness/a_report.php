<?php

declare(strict_types=1);

// #160 phase 2 arm A report (PLAN.md §4, §8). Reads verdicts.jsonl of run.sh arec / arate; prints JSON.
//
// Usage: php a_report.php --verdicts=<verdicts.jsonl> [--expect-arec=<N>] [--expect-arate=<N>] [--invalid-stop=0.02]
//
// Registered predictions checked here (PLAN §4.1):
//   P-FAIL  every valid failing arec run has exactly one anomalous worker, of class unit_without_k5_no_conflict
//   P-PASS  every valid passing arec run has no anomalous worker
// The branch itself is named by hand from the saved records of the failing runs (REPORT), never here.

namespace Flaky160B;

require __DIR__ . '/stats.php';

$options = getopt('', ['verdicts:', 'expect-arec:', 'expect-arate:', 'invalid-stop:']);
$byArm = [];
foreach (readRows((string) $options['verdicts']) as $row) {
    $byArm[$row['arm']][$row['id']] = $row; // last row of an id wins; duplicates are reported
}
$all = [];
foreach (readRows((string) $options['verdicts']) as $row) {
    $all[$row['arm']][] = $row['id'];
}
$report = [];
foreach (['arec', 'arate'] as $arm) {
    $rows = $byArm[$arm] ?? [];
    if ($rows === []) {
        continue;
    }
    $expectedN = (int) ($options['expect-' . $arm] ?? 0);
    $ids = array_keys($rows);
    $valid = array_filter($rows, static fn(array $r): bool => $r['valid']);
    $problems = [];
    foreach ($rows as $r) {
        foreach ($r['problems'] ?? [] as $problem) {
            $problems[$problem] = ($problems[$problem] ?? 0) + 1;
        }
    }
    $failing = array_filter($valid, static fn(array $r): bool => $r['test'] === 'fail');
    $entry = [
        'rows' => count($rows), 'duplicates' => count($all[$arm]) - count($rows),
        'valid' => count($valid), 'invalid' => count($rows) - count($valid), 'invalid_by_problem' => $problems,
        'failing_valid' => count($failing), 'wilson95_failure_rate' => wilson(count($failing), count($valid)),
        'failure_rate' => count($valid) > 0 ? count($failing) / count($valid) : null,
        'failing_ids' => array_values(array_map(static fn(array $r): int => $r['id'], $failing)),
        'failure_messages' => array_count_values(array_map(static fn(array $r): string => ($r['failure']['message'] ?? '?') . ' @' . implode(',', $r['failure']['lines'] ?? []), $failing)),
    ];
    if ($expectedN > 0) {
        $missing = array_values(array_diff(range(1, $expectedN), $ids));
        $entry['expected'] = $expectedN;
        $entry['missing_ids'] = count($missing) <= 50 ? $missing : ['count' => count($missing)];
        $entry['complete'] = $missing === [];
    }
    if ($arm === 'arec') {
        $unresolved = count(array_filter($rows, static fn(array $r): bool => in_array('v_worker:pid_unresolved', $r['problems'] ?? [], true)));
        $stop = (float) ($options['invalid-stop'] ?? 0.02);
        $entry['invalid_pid_unresolved'] = $unresolved;
        $entry['invalid_pid_share'] = count($rows) > 0 ? $unresolved / count($rows) : 0;
        $entry['arm_stops_per_LT_160B_03'] = $entry['invalid_pid_share'] > $stop;
        $pFailBreaks = [];
        foreach ($failing as $r) {
            $classes = array_map(static fn(array $a): string => $a['class'], $r['anomalies'] ?? []);
            if ($classes !== ['unit_without_k5_no_conflict']) {
                $pFailBreaks[] = ['id' => $r['id'], 'anomaly_classes' => $classes];
            }
        }
        $pPassBreaks = [];
        foreach ($valid as $r) {
            if ($r['test'] === 'pass' && ($r['anomalies'] ?? []) !== []) {
                $pPassBreaks[] = ['id' => $r['id'], 'anomaly_classes' => array_map(static fn(array $a): string => $a['class'], $r['anomalies'])];
            }
        }
        $entry['P_FAIL'] = ['holds' => $pFailBreaks === [] && count($failing) > 0, 'exceptions' => $pFailBreaks];
        $entry['P_PASS'] = ['holds' => $pPassBreaks === [], 'exceptions' => $pPassBreaks];
        $entry['naming_target_reached'] = count($failing) >= 5;
        $entry['anomaly_signatures_of_failing'] = [];
        foreach ($failing as $r) {
            foreach ($r['anomalies'] ?? [] as $a) {
                $entry['anomaly_signatures_of_failing'][$r['id']][] = ['class' => $a['class'], 'wave' => $a['worker']['wave'], 'outcome' => $a['worker']['outcome'], 'u' => $a['worker']['u'], 'k' => $a['worker']['k'], 'signature' => $a['worker']['signature']];
            }
        }
    }
    $report[$arm] = $entry;
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
