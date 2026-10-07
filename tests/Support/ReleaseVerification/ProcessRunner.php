<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\ReleaseVerification;

use RuntimeException;

/**
 * Shell-free process execution for release verification tooling.
 *
 * Commands are always argument lists (never shell strings), so no caller-supplied
 * value is ever interpreted by a shell. An explicit environment may be supplied;
 * when it is, NOTHING is inherited from the parent process.
 */
final class ProcessRunner
{
    /**
     * @param list<string> $command
     * @param array<string, string>|null $env Full child environment, or null to inherit
     * @param string|null $stdoutFile When set, stdout is streamed to this file instead of being captured
     * @param bool $mergeStderr Append stderr to the captured output (otherwise it is discarded)
     * @return array{code: int, output: string}
     */
    public static function run(
        array $command,
        ?string $cwd = null,
        ?array $env = null,
        ?string $stdoutFile = null,
        bool $mergeStderr = false,
    ): array {
        $stdoutSpec = $stdoutFile !== null ? ['file', $stdoutFile, 'w'] : ['pipe', 'w'];
        $stderrFile = $mergeStderr && $stdoutFile === null ? tempnam(sys_get_temp_dir(), 'maatify-stderr-') : false;
        $stderrSpec = $stderrFile !== false ? ['file', $stderrFile, 'w'] : ['file', '/dev/null', 'w'];

        $pipes = [];
        // A failure to start (for example a missing executable) is reported through the return
        // value below and surfaced as an explicit exception, never as a stray PHP warning.
        set_error_handler(static fn(): bool => true);
        try {
            $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $stdoutSpec, 2 => $stderrSpec], $pipes, $cwd, $env);
        } finally {
            restore_error_handler();
        }
        if (! is_resource($process)) {
            if ($stderrFile !== false) {
                unlink($stderrFile);
            }
            throw new RuntimeException('Unable to start process: ' . $command[0]);
        }

        $output = '';
        $stdout = self::pipe($pipes, 1);
        if ($stdoutFile === null && is_resource($stdout)) {
            $output = (string) stream_get_contents($stdout);
            fclose($stdout);
        }

        $code = proc_close($process);
        if ($stderrFile !== false) {
            $output .= (string) file_get_contents($stderrFile);
            unlink($stderrFile);
        }

        return ['code' => $code, 'output' => $output];
    }

    /**
     * Returns the proc_open() pipe at $index, or null when it is not an open resource.
     * Takes `mixed` so the check is valid across PHPStan versions' proc_open() typing.
     */
    public static function pipe(mixed $pipes, int $index): mixed
    {
        if (! is_array($pipes)) {
            return null;
        }
        $pipe = $pipes[$index] ?? null;

        return is_resource($pipe) ? $pipe : null;
    }
}
