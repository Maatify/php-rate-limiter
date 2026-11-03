<?php

declare(strict_types=1);

// #181 S1 M-122 (PLAN §3, §10): the 11 design files of #122 against the three indexes that describe them.
//
//   php m122.php <issue-122-body.md>
//
// Prints one JSON line per file and a final summary line; exits 1 if any recorded value differs from the file.

$root = dirname(__DIR__, 3);
$dir = $root . '/investigations/nat-architecture-proposals-110';
$issueBody = file_get_contents($argv[1] ?? throw new InvalidArgumentException('usage: m122.php <issue-122-body.md>'));

/** @return array<string, array<string, string>> keyed by two-digit number */
function rows(string $text, string $pattern): array
{
    preg_match_all($pattern, $text, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $row) {
        $out[sprintf('%02d', (int) $row['n'])] = array_filter($row, 'is_string', ARRAY_FILTER_USE_KEY);
    }

    return $out;
}

$hex = '`(?<sha>[0-9a-f]{64})`';
$readme = rows(
    file_get_contents("$dir/README.md"),
    "/^\\| (?<n>\\d+) \\| [^|]+ \\| (?<status>[^|]+?) \\| \\[proposals\\/(?<file>[^\\]]+)\\][^|]* \\| (?<lines>[\\d,]+) \\| (?<chars>[\\d,]+) \\| (?<bytes>[\\d,]+) \\| $hex \\|/mu",
);
$comparison = rows(
    file_get_contents("$dir/COMPARISON.md"),
    "/^\\| (?<n>\\d{2}) \\| `proposals\\/(?<file>[^`]+)` \\| $hex \\|/mu",
);
$issue = rows(
    $issueBody,
    "/^\\| (?<n>\\d+) \\| [^|]+ \\| (?<status>[^|]+?) \\| \\[`proposals\\/(?<file>[^`]+)`\\][^—]*— (?<lines>[\\d,]+) سطرًا، (?<chars>[\\d,]+) حرفًا، SHA-256 $hex \\|/mu",
);

$num = static fn (string $v): int => (int) str_replace(',', '', $v);
$files = glob("$dir/proposals/*.md");
$mismatches = 0;
foreach ($files as $path) {
    $s = file_get_contents($path);
    $n = substr(basename($path), 0, 2);
    $observed = [
        'file' => basename($path),
        'sha256' => hash('sha256', $s),
        'lines' => substr_count($s, "\n") + (str_ends_with($s, "\n") || $s === '' ? 0 : 1),
        'chars' => mb_strlen($s, 'UTF-8'),
        'bytes' => strlen($s),
    ];
    $checks = [
        'readme' => isset($readme[$n]) && $readme[$n]['file'] === $observed['file'] && $readme[$n]['sha'] === $observed['sha256']
            && $num($readme[$n]['lines']) === $observed['lines'] && $num($readme[$n]['chars']) === $observed['chars']
            && $num($readme[$n]['bytes']) === $observed['bytes'],
        'readme_status' => $readme[$n]['status'] ?? null,
        'comparison' => isset($comparison[$n]) && $comparison[$n]['file'] === $observed['file'] && $comparison[$n]['sha'] === $observed['sha256'],
        'issue_122' => isset($issue[$n]) && $issue[$n]['file'] === $observed['file'] && $issue[$n]['sha'] === $observed['sha256']
            && $num($issue[$n]['lines']) === $observed['lines'] && $num($issue[$n]['chars']) === $observed['chars'],
        'issue_122_status' => $issue[$n]['status'] ?? null,
    ];
    $match = $checks['readme'] && $checks['comparison'] && $checks['issue_122'];
    $mismatches += $match ? 0 : 1;
    echo json_encode(['n' => $n] + $observed + $checks + ['match' => $match], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
}
echo json_encode([
    'files' => count($files),
    'readme_rows' => count($readme),
    'comparison_rows' => count($comparison),
    'issue_122_rows' => count($issue),
    'matching' => count($files) - $mismatches,
], JSON_UNESCAPED_SLASHES), "\n";
exit($mismatches === 0 && count($files) === 11 && count($readme) === 11 && count($comparison) === 11 && count($issue) === 11 ? 0 : 1);
