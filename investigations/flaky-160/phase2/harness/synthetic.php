<?php

declare(strict_types=1);

// #160 phase 2 detector demonstration (PLAN.md §4.1, §8): derives a synthetic
// variant of a REAL tapped run directory by editing the RECORD only (never a
// stream), so a_check.php can be shown to refuse or classify it.
//
// Usage: php synthetic.php --from=<run dir> --to=<new dir> --edit=<drop-line|drop-conn|null-pid|k5-amount|dup-seq|extra-incr>

$options = getopt('', ['from:', 'to:', 'edit:']);
$from = (string) $options['from'];
$to = (string) $options['to'];
@mkdir($to, 0777, true);
foreach (['junit.xml', 'phpunit.out'] as $file) {
    if (is_file("$from/$file")) {
        copy("$from/$file", "$to/$file");
    }
}
$lines = file("$from/tap.jsonl", FILE_IGNORE_NEW_LINES);
$rows = array_map(static fn(string $line): array => json_decode($line, true, 64, JSON_THROW_ON_ERROR), $lines);
$edit = (string) $options['edit'];
$done = false;
foreach ($rows as $index => $row) {
    if ($row['t'] !== 'cmd') {
        continue;
    }
    switch ($edit) {
        case 'drop-line': // remove one mid-run command record
            if ($row['seq'] === 150) {
                unset($rows[$index]);
                $done = true;
            }
            break;
        case 'null-pid': // one worker connection unresolved
            if ($row['pid'] !== null && ($row['args'][0] ?? '') === 'INCR') {
                foreach ($rows as $i => $other) {
                    if (($other['pid'] ?? null) === $row['pid']) {
                        $rows[$i]['pid'] = null;
                    }
                }
                $done = true;
            }
            break;
        case 'k5-amount': // the first K5 SCORE_INCREMENT of a worker no longer counts as +2 (record edited, stream untouched)
            if ($row['args'][0] === 'EVAL' && count($row['args']) === 7 && $row['args'][2] === '1' && $row['args'][4] === '86400' && $row['args'][5] === '2') {
                $rows[$index]['args'][5] = '3';
                $done = true;
            }
            break;
        case 'extra-incr': // one worker took a second allowance unit (a BUDGET_INCREMENT record duplicated, seq renumbered)
            if ($row['args'][0] === 'EVAL' && count($row['args']) === 7 && $row['args'][2] === '1' && $row['args'][4] === '86400' && $row['args'][5] === '1' && is_array($row['reply']) && count($row['reply']) === 2 && $row['reply'][0] === '1') {
                $copy = $row;
                $copy['seq'] = -1;
                array_splice($rows, $index + 1, 0, [$copy]);
                $done = true;
            }
            break;
        case 'dup-seq':
            if ($row['seq'] === 200) {
                $rows[$index]['seq'] = 199;
                $done = true;
            }
            break;
    }
    if ($done) {
        break;
    }
}
if ($edit === 'drop-conn') {
    foreach ($rows as $index => $row) {
        if ($row['t'] === 'conn' && $row['conn'] === 30) {
            unset($rows[$index]);
            $done = true;
        }
    }
}
if ($edit === 'extra-incr') { // renumber so only the allowance count differs, and fix the summary counts accordingly
    $seq = 0;
    foreach ($rows as $index => $row) {
        if ($row['t'] === 'cmd') {
            $rows[$index]['seq'] = ++$seq;
        }
    }
    foreach ($rows as $index => $row) {
        if ($row['t'] === 'summary') {
            $rows[$index]['commands'] = $rows[$index]['replies'] = $rows[$index]['records'] = $seq;
        }
    }
}
if (! $done) {
    fwrite(STDERR, "edit $edit found nothing to change\n");
    exit(1);
}
$handle = fopen("$to/tap.jsonl", 'wb');
foreach ($rows as $row) {
    fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n");
}
fclose($handle);
