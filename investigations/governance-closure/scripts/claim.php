<?php

declare(strict_types=1);

// #181 S1 (PLAN §10, LT-181-08): append one claim to results/results.jsonl.
//
//   php claim.php <id> <M-id>/<name> <predicted> <observed> <true|false> [data|prediction_wording]
//
// A mismatch carries its cause (Lead ruling on F-181-01, PLAN §11.1): `data` stops the sequence, `prediction_wording`
// is recorded and the sequence continues.
//
// sha is origin/main (the measured tree; versions.txt shows src/tests/composer identical to it), command and exit are
// read from results/<M-id>/<name>.command.txt and .exit.txt.

[, $id, $run, $predicted, $observed, $matches, $cause] = $argv + array_fill(0, 7, null);
if (($matches !== 'true' || $cause !== null) && ($matches !== 'false' || !in_array($cause, ['data', 'prediction_wording'], true))) {
    fwrite(STDERR, "usage: claim.php <id> <M-id>/<name> <predicted> <observed> <true|false> [data|prediction_wording]\n");
    exit(2);
}
$results = dirname(__DIR__) . '/results';
$line = json_encode([
    'id' => $id,
    'sha' => trim((string) shell_exec('git rev-parse origin/main')),
    'command' => trim(file_get_contents("$results/$run.command.txt")),
    'exit' => (int) trim(file_get_contents("$results/$run.exit.txt")),
    'predicted' => $predicted,
    'observed' => $observed,
    'matches_prediction' => $matches === 'true',
] + ($cause === null ? [] : ['cause' => $cause]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
file_put_contents("$results/results.jsonl", $line . "\n", FILE_APPEND);
echo $line, "\n";
