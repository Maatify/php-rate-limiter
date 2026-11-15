<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

/**
 * Runs N copies of `race-worker.php` and releases them together, after every one of them has finished its setup (#228).
 *
 * The protocol replaces a guessed future start time: a worker builds its connection, its store and its public runtime, prints
 * `READY` on stdout and then blocks on stdin; the runner waits (bounded) until every worker is ready, writes `GO` to all of them,
 * and only then do the workers run the measured operation. A worker that cannot become ready, or dies, fails the run before any
 * operation ran. Result collection is bounded as well, the stderr and exit status of a worker are kept for the failure message,
 * and every child is terminated and reaped on success and on failure.
 */
final class AdmissionRaceRunner
{
    public const WORKER = __DIR__ . '/race-worker.php';

    /** Seconds a worker may take to become ready, and the same bound for the whole readiness phase. */
    public const READY_TIMEOUT = 90.0;

    /** Seconds for the workers to answer after the release. */
    public const RESULT_TIMEOUT = 120.0;

    /**
     * @return list<array{ok: bool, refused: ?string, claim: ?string}>
     */
    public static function run(string $host, int $port, int $processes, AdmissionRaceSpec $spec): array
    {
        $encoded = base64_encode($spec->toJson());
        $replies = self::launch(
            self::WORKER,
            static fn(int $index): array => [$host, (string) $port, (string) self::READY_TIMEOUT, (string) $index, $encoded],
            $processes,
        );
        $results = [];
        foreach ($replies as $index => $decoded) {
            if (! is_bool($decoded['ok'] ?? null)) {
                throw new \RuntimeException('Race worker ' . $index . ' returned a malformed reply: ' . json_encode($decoded));
            }
            $refused = $decoded['refused'] ?? null;
            $claim = $decoded['claim'] ?? null;
            $results[] = ['ok' => $decoded['ok'], 'refused' => is_string($refused) ? $refused : null, 'claim' => is_string($claim) ? $claim : null];
        }

        return $results;
    }

    /**
     * Start `$processes` copies of a worker script, wait until all print `READY`, release them with `GO`, and return their decoded JSON replies.
     *
     * @param callable(int): list<string> $arguments the arguments of worker `$index` after the script path
     * @return list<array<string, mixed>>
     */
    public static function launch(string $script, callable $arguments, int $processes, float $readyTimeout = self::READY_TIMEOUT, float $resultTimeout = self::RESULT_TIMEOUT): array
    {
        /** @var array<int, RaceWorkerProcess> $workers */
        $workers = [];
        try {
            for ($index = 0; $index < $processes; $index++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, $script, ...$arguments($index)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (! is_resource($process)) {
                    throw new \RuntimeException('Unable to start race worker ' . $index . '.');
                }
                [$stdin, $stdout, $stderr] = [self::pipe($pipes, 0), self::pipe($pipes, 1), self::pipe($pipes, 2)];
                if (! is_resource($stdin) || ! is_resource($stdout) || ! is_resource($stderr)) {
                    proc_terminate($process, 9);
                    proc_close($process);
                    throw new \RuntimeException('Race worker ' . $index . ' has no pipes.');
                }
                stream_set_blocking($stdout, false);
                stream_set_blocking($stderr, false);
                $workers[$index] = new RaceWorkerProcess($index, $process, $stdin, $stdout, $stderr);
            }

            // Phase 1: every worker ready, before any measured operation can start.
            $deadline = microtime(true) + $readyTimeout;
            while (true) {
                $waiting = array_filter($workers, static fn(RaceWorkerProcess $w): bool => ! $w->ready);
                if ($waiting === []) {
                    break;
                }
                self::pump($workers, min(0.2, max(0.0, $deadline - microtime(true))));
                foreach ($waiting as $worker) {
                    if (str_starts_with($worker->out, "READY\n")) {
                        $worker->ready = true;
                        $worker->out = substr($worker->out, strlen("READY\n"));
                    }
                }
                foreach ($workers as $worker) {
                    if (! $worker->ready && $worker->exited()) {
                        self::pump($workers, 0.05);
                        throw new \RuntimeException('Race worker ' . $worker->index . ' ended before it was ready. ' . $worker->diagnostics());
                    }
                }
                if (microtime(true) >= $deadline) {
                    $late = array_filter($workers, static fn(RaceWorkerProcess $w): bool => ! $w->ready);
                    throw new \RuntimeException('Race workers not ready within ' . $readyTimeout . ' s: ' . implode(', ', array_keys($late)) . '. ' . array_values($late)[0]->diagnostics());
                }
            }

            // Phase 2: release all of them; from here on the measured operation runs.
            foreach ($workers as $worker) {
                if (@fwrite($worker->stdin, "GO\n") === false) {
                    throw new \RuntimeException('Race worker ' . $worker->index . ' could not be released. ' . $worker->diagnostics());
                }
                fclose($worker->stdin);
            }

            // Phase 3: bounded wait until every worker has EXITED. Output reaching EOF is not an exit: a worker may close its
            // streams and stay alive, so the deadline also applies to a live child after its output ended (it is then killed and
            // reaped below, with the output and the status collected so far).
            $deadline = microtime(true) + $resultTimeout;
            while (true) {
                self::pump($workers, 0.2);
                $alive = array_filter($workers, static fn(RaceWorkerProcess $w): bool => ! $w->exited());
                if ($alive === []) {
                    self::pump($workers, 0.05);
                    break;
                }
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Race workers did not exit within ' . $resultTimeout . ' s: ' . implode(', ', array_keys($alive)) . '. ' . array_values($alive)[0]->diagnostics());
                }
            }

            $replies = [];
            foreach ($workers as $worker) {
                $status = $worker->exit;
                if ($status !== 0 || trim($worker->out) === '') {
                    throw new \RuntimeException('Race worker ' . $worker->index . ' failed (exit ' . var_export($status, true) . '). ' . $worker->diagnostics());
                }
                $decoded = json_decode(trim($worker->out), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($decoded)) {
                    throw new \RuntimeException('Race worker ' . $worker->index . ' returned a malformed reply: ' . $worker->out);
                }
                /** @var array<string, mixed> $decoded */
                $replies[] = $decoded;
            }
            if (count($replies) !== $processes) {
                throw new \RuntimeException('Not every race worker took part: ' . count($replies) . ' of ' . $processes . '.');
            }

            return $replies;
        } finally {
            self::reap($workers);
        }
    }

    /** One pipe handed back by `proc_open()`: its declared type differs between the PHPStan versions the repository is checked with. */
    private static function pipe(mixed $pipes, int $index): mixed
    {
        return is_array($pipes) ? ($pipes[$index] ?? null) : null;
    }

    /** @param list<array{ok: bool, refused: ?string, claim: ?string}> $results */
    public static function admitted(array $results): int
    {
        return count(array_filter($results, static fn(array $r): bool => $r['ok']));
    }

    /**
     * Read what the workers wrote since the last call (stdout and stderr), waiting up to `$seconds` for something. A pipe at EOF is no
     * longer waited on; when nothing is left to wait on the call sleeps for the same time instead of spinning.
     *
     * @param array<int, RaceWorkerProcess> $workers
     */
    private static function pump(array $workers, float $seconds): void
    {
        $read = [];
        foreach ($workers as $worker) {
            foreach ([$worker->stdout, $worker->stderr] as $pipe) {
                if (is_resource($pipe) && ! feof($pipe)) {
                    $read[] = $pipe;
                }
            }
        }
        if ($read !== []) {
            $write = $except = null;
            @stream_select($read, $write, $except, (int) $seconds, (int) (($seconds - (int) $seconds) * 1_000_000));
        } else {
            usleep((int) (min($seconds, 0.05) * 1_000_000));
        }
        foreach ($workers as $worker) {
            $worker->out .= (string) stream_get_contents($worker->stdout);
            $worker->err .= (string) stream_get_contents($worker->stderr);
        }
    }

    /** @param array<int, RaceWorkerProcess> $workers */
    private static function reap(array $workers): void
    {
        foreach ($workers as $worker) {
            if (is_resource($worker->process) && proc_get_status($worker->process)['running']) {
                proc_terminate($worker->process, 9);
            }
            foreach ([$worker->stdin, $worker->stdout, $worker->stderr] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($worker->process)) {
                proc_close($worker->process);
            }
        }
    }
}
