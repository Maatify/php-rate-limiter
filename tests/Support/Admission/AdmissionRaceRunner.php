<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

/** Runs N copies of `race-worker.php` released at one barrier instant and collects their JSON replies. */
final class AdmissionRaceRunner
{
    /**
     * @param array<string, mixed> $spec
     * @return list<array{ok: bool, refused: ?string, claim: ?string}>
     */
    public static function run(string $host, int $port, int $processes, array $spec): array
    {
        $barrier = microtime(true) + 1.5 + ($processes / 40);
        $encoded = base64_encode(json_encode($spec, JSON_THROW_ON_ERROR));
        $handles = [];
        for ($index = 0; $index < $processes; $index++) {
            $command = [PHP_BINARY, __DIR__ . '/race-worker.php', $host, (string) $port, sprintf('%.6f', $barrier), (string) $index, $encoded];
            $pipes = [];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($process)) {
                throw new \RuntimeException('Unable to start a race worker.');
            }
            $handles[] = [$process, $pipes];
        }
        $results = [];
        foreach ($handles as [$process, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            if ($status !== 0 || $out === '') {
                throw new \RuntimeException('Race worker failed (' . $status . '): ' . $err);
            }
            /** @var array{ok: bool, refused: ?string, claim: ?string} $decoded */
            $decoded = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            $results[] = $decoded;
        }

        return $results;
    }

    /** @param list<array{ok: bool, refused: ?string, claim: ?string}> $results */
    public static function admitted(array $results): int
    {
        return count(array_filter($results, static fn(array $r): bool => $r['ok']));
    }
}
