<?php

declare(strict_types=1);

// #181 S1 M-120/M-S0 (PLAN §10, LT-181-02): a study's committed expected/ against one run's output, dropping provenance only.
//
//   php compare-provenance.php <expected-dir> <actual-dir>
//
// .jsonl: a provenance row is one whose "row" is "provenance" or whose "s" ends in ".provenance"; every other row must be
// byte-identical, and each provenance row must be equal once its SHA fields (baseSha, sha256, pipelineSha256) are removed.
// .md: the differing line numbers are listed (a line is compared as a whole). One JSON line per file, then a summary line.
// Exit 0 when every .jsonl matches under that rule, 1 otherwise (the .md lines are reported, not judged).

const SHA_FIELDS = ['baseSha', 'sha256', 'pipelineSha256'];

[, $expectedDir, $actualDir] = $argv + [null, null, null];
if (! is_dir((string) $expectedDir) || ! is_dir((string) $actualDir)) {
    fwrite(STDERR, "usage: php compare-provenance.php <expected-dir> <actual-dir>\n");
    exit(2);
}

/** @return array{provenance: list<array<string, mixed>>, decisions: list<string>} */
function split(string $path): array
{
    $out = ['provenance' => [], 'decisions' => []];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (($row['row'] ?? null) === 'provenance' || str_ends_with((string) ($row['s'] ?? ''), '.provenance')) {
            $out['provenance'][] = array_diff_key($row, array_flip(SHA_FIELDS));
        } else {
            $out['decisions'][] = $line;
        }
    }

    return $out;
}

$ok = true;
$files = glob("$expectedDir/*.{jsonl,md}", GLOB_BRACE);
foreach ($files as $expected) {
    $name = basename($expected);
    $actual = "$actualDir/$name";
    if (! is_file($actual)) {
        echo json_encode(['file' => $name, 'missing_in_actual' => true]), "\n";
        $ok = false;
        continue;
    }
    if (str_ends_with($name, '.md')) {
        $e = file($expected, FILE_IGNORE_NEW_LINES);
        $a = file($actual, FILE_IGNORE_NEW_LINES);
        $lines = [];
        for ($i = 0; $i < max(count($e), count($a)); $i++) {
            if (($e[$i] ?? null) !== ($a[$i] ?? null)) {
                $lines[] = $i + 1;
            }
        }
        echo json_encode(['file' => $name, 'lines_expected' => count($e), 'lines_actual' => count($a), 'differing_lines' => $lines]), "\n";
        continue;
    }
    $e = split($expected);
    $a = split($actual);
    $differing = 0;
    for ($i = 0; $i < max(count($e['decisions']), count($a['decisions'])); $i++) {
        $differing += ($e['decisions'][$i] ?? null) === ($a['decisions'][$i] ?? null) ? 0 : 1;
    }
    $row = [
        'file' => $name,
        'decision_rows_expected' => count($e['decisions']),
        'decision_rows_actual' => count($a['decisions']),
        'decision_rows_differing' => $differing,
        'provenance_rows' => count($e['provenance']) . '/' . count($a['provenance']),
        'provenance_equal_without_sha' => $e['provenance'] === $a['provenance'],
    ];
    $row['match'] = $differing === 0 && $row['provenance_equal_without_sha'];
    $ok = $ok && $row['match'];
    echo json_encode($row), "\n";
}
echo json_encode(['files' => count($files), 'jsonl_match' => $ok]), "\n";
exit($ok ? 0 : 1);
