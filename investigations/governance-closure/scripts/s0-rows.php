<?php

declare(strict_types=1);

// #181 S1 M-S0 (PLAN §3, §10 LT-181-02): which S0 rows changed between expected/ and one run, and how.
//
//   php s0-rows.php <expected-dir> <actual-dir>
//
// Rows are matched by position (both runs emit the same order); provenance rows are skipped. For every differing row:
// its identity (s, stream, class, shape, flow, or assert / trace step) and the fields whose values differ, old -> new.

[, $expectedDir, $actualDir] = $argv + [null, null, null];
foreach (['t01_matrix.jsonl', 't02_trace.jsonl'] as $file) {
    $e = file("$expectedDir/$file", FILE_IGNORE_NEW_LINES);
    $a = file("$actualDir/$file", FILE_IGNORE_NEW_LINES);
    foreach ($e as $i => $line) {
        if ($line === ($a[$i] ?? null)) {
            continue;
        }
        $old = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $new = json_decode($a[$i], true, flags: JSON_THROW_ON_ERROR);
        if (str_ends_with((string) $old['s'], '.provenance')) {
            continue;
        }
        $id = array_intersect_key($old, array_flip(['s', 'stream', 'class', 'shape', 'flow', 'assert', 'account']));
        $changed = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $k) {
            if (($old[$k] ?? null) !== ($new[$k] ?? null) && ! isset($id[$k])) {
                $changed[$k] = [$old[$k] ?? null, $new[$k] ?? null];
            }
        }
        $identityChanged = array_diff_assoc($id, array_intersect_key($new, $id)) !== [];
        echo json_encode(['file' => $file, 'line' => $i + 1, 'id' => $id, 'identity_changed' => $identityChanged,
            'changed' => array_map(static fn (array $p): array|string => is_scalar($p[0]) || $p[0] === null ? "{$p[0]} -> {$p[1]}" : $p, $changed)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    }
}
