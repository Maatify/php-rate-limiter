<?php

declare(strict_types=1);

// #181 S1 M-S0 (PLAN §10, LT-181-02): S0 on main against AKDR [MAIN] where the streams intersect.
//
//   php s0-vs-akdr.php <s0 t01_matrix.jsonl> <akdr summary.md>
//
// AKDR summary §2 "main pre+out / main pre / main out" columns (L for legit denied, A for attacker reach) against the S0 Login rows
// (legitDenied/legitAttempts for N12T, attackReachedVerification/attackAttempts otherwise). Exit 0 if all 15 cells agree.

const STREAMS = ['TMSs', 'TMX', 'TMXb', 'TMSd', 'N12T'];
const FLOWS = ['pre+out' => 3, 'pre' => 5, 'out' => 7]; // AKDR §2 column index of "main <flow>" (2, 4, 6 are "S0 [PRIOR]")

[, $t01, $summary] = $argv + [null, null, null];
$akdr = [];
foreach (file((string) $summary, FILE_IGNORE_NEW_LINES) as $line) {
    $cells = array_map('trim', explode('|', $line));
    if (count($cells) === 9 && in_array($cells[1], STREAMS, true) && preg_match('/^[LA] \d+\/\d+$/', $cells[2])) {
        foreach (FLOWS as $flow => $col) {
            $akdr[$cells[1]][$flow] ??= substr($cells[$col], 2);
        }
    }
}
$s0 = [];
foreach (file((string) $t01, FILE_IGNORE_NEW_LINES) as $line) {
    $r = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    if (($r['s'] ?? '') === 'T01.matrix' && $r['shape'] === 'login' && in_array($r['stream'], STREAMS, true)) {
        $s0[$r['stream']][$r['flow']] = $r['stream'] === 'N12T'
            ? "{$r['legitDenied']}/{$r['legitAttempts']}"
            : "{$r['attackReachedVerification']}/{$r['attackAttempts']}";
    }
}
$agree = 0;
foreach (STREAMS as $stream) {
    foreach (array_keys(FLOWS) as $flow) {
        $a = $akdr[$stream][$flow] ?? null;
        $s = $s0[$stream][$flow] ?? null;
        $agree += $a !== null && $a === $s ? 1 : 0;
        echo json_encode(['stream' => $stream, 'flow' => $flow, 's0_main' => $s, 'akdr_main' => $a, 'agree' => $a !== null && $a === $s]), "\n";
    }
}
echo json_encode(['cells' => count(STREAMS) * count(FLOWS), 'agree' => $agree]), "\n";
exit($agree === count(STREAMS) * count(FLOWS) ? 0 : 1);
