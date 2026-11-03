<?php

declare(strict_types=1);

// #181 S1 M-S0b (Lead ruling on F-181-02): are the S0 `out` and `pre` rows at a6c79f4 (DEC-021) byte-identical to the
// same rows on main@0193b79?
//
//   php s0b-compare.php <main-dir> <a6c79f4-dir>
//
// Provenance rows (`s` ending in `.provenance`) are dropped; the remaining rows of t01_matrix.jsonl and t02_trace.jsonl
// are matched by position. Rows whose `flow` is `out` or `pre` are the prediction: exit 0 when every one is identical,
// 1 otherwise, 3 when the two runs do not have the same row identities (the comparison would not mean anything). The
// other rows (`pre+out`, rows without a flow) are counted and listed, outside the prediction.

[, $mainDir, $otherDir] = $argv + [null, null, null];
$scope = ['out', 'pre'];
$counts = [];
$differing = [];
foreach (['t01_matrix.jsonl', 't02_trace.jsonl'] as $file) {
    $rows = static fn (string $dir): array => array_values(array_filter(
        file("$dir/$file", FILE_IGNORE_NEW_LINES),
        static fn (string $l): bool => ! str_ends_with((string) json_decode($l, true, flags: JSON_THROW_ON_ERROR)['s'], '.provenance'),
    ));
    $m = $rows($mainDir);
    $o = $rows($otherDir);
    if (count($m) !== count($o)) {
        fwrite(STDERR, "$file: " . count($m) . ' rows on main, ' . count($o) . " at the other sha\n");
        exit(3);
    }
    foreach ($m as $i => $line) {
        $a = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $b = json_decode($o[$i], true, flags: JSON_THROW_ON_ERROR);
        $id = static fn (array $r): string => implode('|', array_map(
            static fn (string $k): string => (string) ($r[$k] ?? '-'),
            ['s', 'stream', 'class', 'shape', 'flow', 'assert'],
        ));
        if ($id($a) !== $id($b)) {
            fwrite(STDERR, "$file row " . ($i + 1) . ": identity {$id($a)} vs {$id($b)}\n");
            exit(3);
        }
        $group = in_array($a['flow'] ?? null, $scope, true) ? $a['flow'] : 'outside:' . ($a['flow'] ?? 'no-flow');
        $same = $line === $o[$i];
        $counts["$file $group"][$same ? 'identical' : 'differ'] = ($counts["$file $group"][$same ? 'identical' : 'differ'] ?? 0) + 1;
        if (! $same) {
            $differing[] = "$file $group {$id($a)}";
        }
    }
}
ksort($counts);
foreach ($counts as $k => $c) {
    printf("%-40s identical %3d  differ %3d\n", $k, $c['identical'] ?? 0, $c['differ'] ?? 0);
}
foreach ($differing as $d) {
    echo "differs: $d\n";
}
$inScope = array_filter($differing, static fn (string $d): bool => ! str_contains($d, ' outside:'));
echo $inScope === [] ? "PREDICTION HOLDS: every out and pre row identical\n" : 'PREDICTION FAILS: ' . count($inScope) . " out/pre rows differ\n";
exit($inScope === [] ? 0 : 1);
