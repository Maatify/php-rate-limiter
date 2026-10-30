<?php

declare(strict_types=1);

// #176 S0: the natural CI history table from ci-history.raw.json (gh run list --workflow ci.yml --limit 400 ...).
// Runs created at or after the v1.0.0-rc.3 tag (2026-10-07T13:14:02Z). Prints JSON.
//
// Usage: php investigations/stability-release/scripts/summarize_history.php investigations/stability-release/data/ci-history.raw.json

$rows = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$since = '2026-10-07T13:14:02Z';
$runs = array_values(array_filter($rows, static fn(array $r): bool => $r['createdAt'] >= $since));
$count = static function (array $runs, callable $p): int {
    return count(array_filter($runs, $p));
};
$byConclusion = [];
foreach ($runs as $r) {
    $byConclusion[$r['conclusion'] === '' ? '(none)' : $r['conclusion']] = ($byConclusion[$r['conclusion'] === '' ? '(none)' : $r['conclusion']] ?? 0) + 1;
}
ksort($byConclusion);
$main = array_values(array_filter($runs, static fn(array $r): bool => $r['headBranch'] === 'main'));
$mainByConclusion = [];
foreach ($main as $r) {
    $mainByConclusion[$r['conclusion']] = ($mainByConclusion[$r['conclusion']] ?? 0) + 1;
}
$reruns = array_values(array_map(
    static fn(array $r): array => ['headSha' => substr($r['headSha'], 0, 7), 'createdAt' => $r['createdAt'], 'branch' => $r['headBranch'], 'attempt' => $r['attempt'], 'conclusion' => $r['conclusion']],
    array_filter($runs, static fn(array $r): bool => $r['attempt'] > 1),
));
echo json_encode([
    'since' => $since,
    'rows_in_file' => count($rows),
    'oldest_in_file' => end($rows)['createdAt'],
    'runs_since_rc3' => count($runs),
    'by_conclusion' => $byConclusion,
    'main_runs' => count($main),
    'main_by_conclusion' => $mainByConclusion,
    'runs_with_attempt_gt_1' => $reruns,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
