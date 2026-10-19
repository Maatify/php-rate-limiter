<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

/** Runs N copies of `race-worker.php` released at one barrier instant and collects their JSON replies. */
final class AdmissionRaceRunner
{
    /**
     * @return list<array{ok: bool, refused: ?string, claim: ?string}>
     */
    public static function run(string $host, int $port, int $processes, AdmissionRaceSpec $spec): array
    {
        $barrier = microtime(true) + 1.5 + ($processes / 10);
        $encoded = base64_encode($spec->toJson());
        $handles = [];
        for ($index = 0; $index < $processes; $index++) {
            $command = implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, __DIR__ . '/race-worker.php', $host, (string) $port, sprintf('%.6f', $barrier), (string) $index, $encoded])) . ' 2>&1';
            $handle = popen($command, 'r');
            if ($handle === false) {
                throw new \RuntimeException('Unable to start a race worker.');
            }
            $handles[] = $handle;
        }
        $results = [];
        foreach ($handles as $handle) {
            $out = (string) stream_get_contents($handle);
            $status = pclose($handle);
            if ($status !== 0 || $out === '') {
                throw new \RuntimeException('Race worker failed (' . $status . '): ' . $out);
            }
            $decoded = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || ! is_bool($decoded['ok'] ?? null)) {
                throw new \RuntimeException('Race worker returned a malformed reply: ' . $out);
            }
            $refused = $decoded['refused'] ?? null;
            $claim = $decoded['claim'] ?? null;
            $results[] = ['ok' => $decoded['ok'], 'refused' => is_string($refused) ? $refused : null, 'claim' => is_string($claim) ? $claim : null];
        }

        return $results;
    }

    /** @param list<array{ok: bool, refused: ?string, claim: ?string}> $results */
    public static function admitted(array $results): int
    {
        return count(array_filter($results, static fn(array $r): bool => $r['ok']));
    }
}
