<?php

declare(strict_types=1);

// #160 arm U per-run verdict (PLAN.md §4.2–§4.4, §12.2 LT-160-02/04). Prints
// one JSON line. No tap: the verdict comes from the run's junit only.
//
//   valid   = V-PORT and V-ORD (the #157 target and each of the six at their
//             reference ordinals, and the first k_ref cases hash to the
//             reference sequence) and a readable junit
//   pooled  = at least one of the six failed or errored (PLAN §4.4)
//   seventh = failures/errors in any other method: findings, never pooled
//
// junit.xml and phpunit.out are kept for every run with any failure or error
// and for every invalid run; otherwise deleted (PLAN §4.2).
//
// Usage: php check_u.php --arm=<u|ucost|uconfig> --id=<n> --dir=<run dir> --vref=<vref.json>
//          --exit=<phpunit status> --redis-port=<p> --wall=<seconds, container to container>

namespace Flaky160;

require __DIR__ . '/u_lib.php';

$options = getopt('', ['arm:', 'id:', 'dir:', 'vref:', 'exit:', 'redis-port:', 'wall:']);
$dir = (string) $options['dir'];
$vref = json_decode((string) file_get_contents((string) $options['vref']), true, 8, JSON_THROW_ON_ERROR);
$verdict = [
    'arm' => (string) $options['arm'],
    'id' => (int) $options['id'],
    'exit' => (int) $options['exit'],
    'redis_port' => (int) $options['redis-port'],
    'v_port' => (int) $options['redis-port'] > 0 && (int) $options['redis-port'] !== 6379,
    'wall_seconds' => round((float) $options['wall'], 3),
];
$problems = [];

$junitPath = $dir . '/junit.xml';
$cases = [];
if (is_file($junitPath)) {
    try {
        $cases = \Flaky157\junitCases($junitPath);
    } catch (\Throwable $exception) {
        $problems[] = 'junit_unreadable';
    }
} else {
    $problems[] = 'junit_missing';
}
$verdict['tests'] = count($cases);
if (is_file($dir . '/phpunit.out') && preg_match('/^Time: ([0-9:.]+)/m', (string) file_get_contents($dir . '/phpunit.out'), $time) === 1) {
    $verdict['phpunit_time'] = $time[1];
}

// --- V-ORD -------------------------------------------------------------------
$ordinals = ordinals($cases);
$verdict['ordinals_differ'] = array_filter($ordinals, static fn(?int $ordinal, string $id): bool => $ordinal !== $vref['ordinals'][$id], ARRAY_FILTER_USE_BOTH);
$verdict['sequence_equal'] = count($cases) >= REF_K && sequenceSha($cases, REF_K) === REF_SEQUENCE_SHA256;
$verdict['v_ord'] = $cases !== [] && $verdict['ordinals_differ'] === [] && $verdict['sequence_equal'];

// --- the six, the rest --------------------------------------------------------
$six = [];
foreach (SIX as $id => [$class, $method]) {
    $ordinal = $ordinals[$id];
    if ($ordinal === null) {
        $six[$id] = ['status' => 'missing'];
        continue;
    }
    $case = $cases[$ordinal - 1];
    $six[$id] = $case['failure'] === null ? ['status' => 'pass'] : ['status' => 'fail'] + describeFailure($case['failure'], $case['file']);
}
$verdict['six'] = $six;
$seventh = [];
foreach ($cases as $index => $case) {
    if ($case['failure'] === null) {
        continue;
    }
    $isSix = false;
    foreach (SIX as [$class, $method]) {
        $isSix = $isSix || ($case['class'] === $class && $case['name'] === $method);
    }
    if (! $isSix) {
        $seventh[] = ['ordinal' => $index + 1, 'test' => $case['class'] . '::' . $case['name']] + describeFailure($case['failure'], $case['file']);
    }
}
$verdict['seventh'] = $seventh;
$verdict['pooled'] = array_filter($six, static fn(array $entry): bool => $entry['status'] === 'fail') !== [];
$verdict['any_failure'] = $verdict['pooled'] || $seventh !== [];
// A non-zero PHPUnit exit with no failing testcase (a crash, a warning-as-failure) is a problem, not a pass.
if ($verdict['exit'] !== 0 && ! $verdict['any_failure']) {
    $problems[] = 'nonzero_exit_without_failing_case';
}
if ($verdict['exit'] === 0 && $verdict['any_failure']) {
    $problems[] = 'zero_exit_with_failing_case';
}

$verdict['problems'] = $problems;
$verdict['valid'] = $verdict['v_port'] && $verdict['v_ord'] && $problems === [];

$keep = ! $verdict['valid'] || $verdict['any_failure'];
$verdict['kept_files'] = $keep;
if (! $keep) {
    @unlink($junitPath);
    @unlink($dir . '/phpunit.out');
}
echo json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
