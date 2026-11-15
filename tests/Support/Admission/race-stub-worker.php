<?php

declare(strict_types=1);

// A stand-in worker for the tests of AdmissionRaceRunner (#228): it speaks the readiness protocol and records when it was ready and
// when it was released, so a test can prove that nobody ran before everybody was ready.
//   race-stub-worker.php <mode> <index> <directory>
//   modes: ok | slow-first | die-before-ready | never-ready | hang-after-go | exit-after-go
//          | close-output-then-hang (prints its reply, closes stdout and stderr, stays alive) | close-output-then-exit (the same, then exits after 0.4 s)
//          | sibling-in-startup (worker 0 is still starting when worker 1 dies: it touches a marker and stays alive before READY)
// It does not publish its pid: the runner records each child's pid itself at the spawn (an earlier version wrote a pid file, which a sibling
// killed mid-write left empty, #228). Its reply carries its pid for the tests of a run that succeeds.

$argv = $_SERVER['argv'] ?? [];
$args = [];
foreach (is_array($argv) ? array_slice($argv, 1) : [] as $argument) {
    $args[] = is_string($argument) ? $argument : '';
}
[$mode, $index, $directory] = [$args[0] ?? '', (int) ($args[1] ?? 0), $args[2] ?? ''];
if ($mode === 'sibling-in-startup' && $index === 0) {
    // Worker 0 is inside its startup window (not ready, nothing published) when worker 1 dies; the runner then has to kill it.
    touch($directory . '/starting-0');
    sleep(300);
}
if ($mode === 'sibling-in-startup' && $index === 1) {
    // Bounded wait for the marker: worker 0 is in its window before this worker fails.
    for ($i = 0; $i < 4000 && ! is_file($directory . '/starting-0'); $i++) {
        usleep(5000);
    }
    fwrite(STDERR, "stub setup failed on purpose\n");
    exit(7);
}

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

echo json_encode(['index' => $index, 'pid' => getmypid(), 'readyAt' => $readyAt, 'releasedAt' => $releasedAt, 'ok' => true], JSON_THROW_ON_ERROR);

if ($index === 1 && in_array($mode, ['close-output-then-hang', 'close-output-then-exit'], true)) {
    // The output reaches EOF, the process does not end: EOF must not be taken for an exit.
    fflush(STDOUT);
    fclose(STDOUT);
    fclose(STDERR);
    $mode === 'close-output-then-hang' ? sleep(300) : usleep(400_000);
}
