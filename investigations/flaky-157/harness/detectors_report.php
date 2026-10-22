<?php

declare(strict_types=1);

// #157 detector table (PLAN.md §10): expected vs observed for each detector run
// by detectors.sh. Prints one JSON document; "fires_as_expected" must be true everywhere.
//
// Usage: php detectors_report.php <out>

namespace Flaky157;

require __DIR__ . '/lib.php';

$out = $argv[1];
$verdicts = [];
foreach (readJsonl($out . '/verdicts.jsonl') as $verdict) {
    $verdicts[$verdict['arm'] . '#' . $verdict['id']] = $verdict;
}
$log = (string) @file_get_contents($out . '/detectors.log');
$get = static fn(string $id): ?array => $verdicts[$id] ?? null;

$rows = [];
$add = static function (string $detector, string $expected, mixed $observed, bool $ok) use (&$rows): void {
    $rows[] = ['detector' => $detector, 'expected' => $expected, 'observed' => $observed, 'fires_as_expected' => $ok];
};

preg_match('/v_head_negative_exit=(\d+)/', $log, $match);
$add('V-HEAD (against 1e951ee)', 'refused, non-zero exit', ['exit' => isset($match[1]) ? (int) $match[1] : null, 'message' => str_contains($log, 'V-HEAD FAIL')], isset($match[1]) && (int) $match[1] !== 0 && str_contains($log, 'V-HEAD FAIL'));

$clean = $get('l#1');
$add('L clean run', 'valid, target pass, no finding, every MUTATE probe PTTL > 0', $clean === null ? null : ['valid' => $clean['valid'], 'target' => $clean['target'], 'ordinal' => $clean['ordinal'], 'k_ref' => $clean['k_ref'], 'pttl' => array_map(static fn(array $m): mixed => $m['probe']['pttl'] ?? null, $clean['mutate'] ?? []), 'findings' => count($clean['findings'])],
    $clean !== null && $clean['valid'] && $clean['target'] === 'pass' && $clean['findings'] === [] && count($clean['mutate'] ?? []) === 3);

foreach (['l#2' => 'drop', 'l#3' => 'reorder'] as $id => $fault) {
    $run = $get($id);
    $add("V-FRAME ({$fault} in the tap log)", 'v_frame false, run invalid', $run === null ? null : ['v_frame' => $run['v_frame'], 'valid' => $run['valid'], 'problems' => $run['problems']],
        $run !== null && ! $run['v_frame'] && ! $run['valid']);
}

$perturbed = $get('lconfig#4');
$add('V-ORD (first prefix file dropped)', 'ordinal ≠ k_ref, run invalid', $perturbed === null ? null : ['ordinal' => $perturbed['ordinal'], 'k_ref' => $perturbed['k_ref'], 'v_ord' => $perturbed['v_ord'], 'valid' => $perturbed['valid']],
    $perturbed !== null && $perturbed['v_ord'] === false && ! $perturbed['valid']);

foreach ([0, 1, 2] as $case) {
    $run = $get("d{$case}#1");
    $add("D signature, case {$case}", 'accepted at L3749/L1546, PTTL −2 after DEL, reply {1,"9",…,1}', $run === null ? null : ($run['d_signature'] ?? null) + ['valid' => $run['valid']],
        $run !== null && $run['valid'] && ($run['d_signature']['d_ok'] ?? false));
}

$selftest = json_decode((string) @file_get_contents($out . '/h-selftest.json'), true) ?? [];
$expected = [
    'P2 inside C1 and band: no violation' => ['holds', 'holds', 'holds'],
    'throw outside C1, D>=3: no violation' => ['holds', 'holds', 'holds'],
    'non-throw outside C1: H-1 violation' => ['FINDING', 'holds', 'holds'],
    'non-throw with D>=3: H-2 violation' => ['holds', 'FINDING', 'holds'],
    'throw with D<=-3: H-2 violation' => ['holds', 'FINDING', 'holds'],
    'other call accepted: H-3 finding' => ['holds', 'holds', 'FINDING'],
];
foreach ($expected as $label => $want) {
    $got = isset($selftest[$label]) ? array_values($selftest[$label]) : null;
    $add('H classifier: ' . $label, implode('/', $want), $got === null ? null : implode('/', $got), $got === $want);
}

$overhead = json_decode((string) @file_get_contents($out . '/overhead.json'), true);
$add('Tap overhead measured (small K)', 'present', $overhead === null ? null : ['k' => $overhead['k'], 'ping' => $overhead['ping'], 'span' => $overhead['span']], $overhead !== null);

foreach (['probe', 'forced'] as $mode) {
    $report = json_decode((string) @file_get_contents($out . "/h-{$mode}-0.report.json"), true);
    if ($report !== null) {
        $add("H {$mode} tiny run at fe8775c", 'H-1/H-2/H-3 hold; seconds recorded', ['sequences' => $report['sequences'], 'verdict' => $report['verdict'], 'm1_nonthrow' => $report['m1_nonthrow'], 'span_us' => $report['span_us'], 'seconds' => $report['probe_summaries'][0]['seconds'] ?? null],
            ! in_array('FINDING', $report['verdict'], true));
    }
}

$all = array_reduce($rows, static fn(bool $carry, array $row): bool => $carry && $row['fires_as_expected'], true);
echo json_encode(['all_fire_as_expected' => $all, 'detectors' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
