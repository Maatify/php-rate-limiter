<?php

declare(strict_types=1);

// A stand-in worker for the tests of AdmissionRaceRunner (#228): it speaks the readiness protocol and records when it was ready and
// when it was released, so a test can prove that nobody ran before everybody was ready.
//   race-stub-worker.php <mode> <index> <directory>
//   modes: ok | slow-first | die-before-ready | never-ready | hang-after-go | exit-after-go
// It writes its pid to <directory>/pid-<index> so that a test can check that it was reaped.

$argv = $_SERVER['argv'] ?? [];
$args = [];
foreach (is_array($argv) ? array_slice($argv, 1) : [] as $argument) {
    $args[] = is_string($argument) ? $argument : '';
}
[$mode, $index, $directory] = [$args[0] ?? '', (int) ($args[1] ?? 0), $args[2] ?? ''];
file_put_contents($directory . '/pid-' . $index, (string) getmypid());

if ($mode === 'die-before-ready' && $index === 1) {
    fwrite(STDERR, "stub setup failed on purpose\n");
    exit(7);
}
if ($mode === 'never-ready' && $index === 1) {
    sleep(300);
}
if ($mode === 'slow-first' && $index === 0) {
    usleep(1_500_000);
}

$readyAt = microtime(true);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
if (fgets(STDIN) !== "GO\n") {
    exit(3);
}
$releasedAt = microtime(true);

if ($mode === 'hang-after-go' && $index === 1) {
    sleep(300);
}
if ($mode === 'exit-after-go' && $index === 1) {
    fwrite(STDERR, "stub failed after the release\n");
    exit(5);
}

echo json_encode(['index' => $index, 'readyAt' => $readyAt, 'releasedAt' => $releasedAt, 'ok' => true], JSON_THROW_ON_ERROR);
