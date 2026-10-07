<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\ReleaseVerification;

use Closure;
use JsonException;

/**
 * Durable, fail-closed persistence of qualifying evidence/reports.
 *
 * Persistence is part of qualification success: a destination is accepted only if it
 * is outside the repository worktree (so persisting cannot dirty the qualified
 * candidate), does not already exist (stale evidence is never silently replaced), and
 * can be written completely. The payload is written to a temporary file in the same
 * directory, byte-count verified, flushed, re-read and JSON-decoded, and only then
 * atomically renamed to the final path, which is re-read once more. Any failure leaves
 * no final file.
 */
final class EvidenceWriter
{
    /**
     * @param Closure(string): void|null $beforeVerify Test seam: runs on the temporary file before read-back verification
     */
    public function __construct(private readonly ?Closure $beforeVerify = null) {}

    /**
     * Validates a destination without writing anything.
     *
     * @return array{status: 'PASS', message: string, path: string}|array{status: 'FAIL', message: string}
     */
    public function resolveDestination(string $destination, string $repoRoot): array
    {
        $destination = trim($destination);
        if ($destination === '') {
            return $this->fail('Evidence destination is empty.');
        }
        if (! str_starts_with($destination, '/')) {
            $destination = (string) getcwd() . '/' . $destination;
        }

        $name = basename($destination);
        if ($name === '' || $name === '.' || $name === '..') {
            return $this->fail(sprintf('Evidence destination "%s" does not name a file.', $destination));
        }

        $dir = realpath(dirname($destination));
        if ($dir === false || ! is_dir($dir)) {
            return $this->fail(sprintf('Evidence destination directory "%s" does not exist.', dirname($destination)));
        }

        $root = realpath($repoRoot);
        if ($root === false) {
            return $this->fail('Repository root could not be resolved; cannot prove the destination is outside the candidate.');
        }
        if ($dir === $root || str_starts_with($dir . '/', $root . '/')) {
            return $this->fail(sprintf('Evidence destination "%s" is inside the repository worktree; qualifying evidence must be persisted outside the candidate repository.', $dir . '/' . $name));
        }

        $final = $dir . '/' . $name;
        if (is_link($final) || file_exists($final)) {
            return $this->fail(sprintf('Evidence destination "%s" already exists; stale evidence is never overwritten (remove it explicitly).', $final));
        }
        if (! is_writable($dir)) {
            return $this->fail(sprintf('Evidence destination directory "%s" is not writable.', $dir));
        }

        return ['status' => 'PASS', 'message' => 'Evidence destination is acceptable.', 'path' => $final];
    }

    /**
     * @param array<mixed> $data
     * @return array{status: 'PASS', message: string, path: string, bytes: int, sha256: string}|array{status: 'FAIL', message: string}
     */
    public function write(string $destination, array $data, string $repoRoot): array
    {
        $resolved = $this->resolveDestination($destination, $repoRoot);
        if ($resolved['status'] === 'FAIL') {
            return $resolved;
        }
        $final = $resolved['path'];

        try {
            $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException $e) {
            return $this->fail('Evidence could not be JSON-encoded: ' . $e->getMessage());
        }

        $length = strlen($payload);
        $temp = dirname($final) . '/.' . basename($final) . '.tmp-' . bin2hex(random_bytes(6));
        $handler = static fn(): bool => true;
        set_error_handler($handler);
        try {
            $handle = fopen($temp, 'xb');
            if ($handle === false) {
                return $this->fail(sprintf('Unable to create temporary evidence file in "%s".', dirname($final)));
            }

            $written = 0;
            while ($written < $length) {
                $chunk = fwrite($handle, substr($payload, $written));
                if ($chunk === false || $chunk === 0) {
                    fclose($handle);
                    unlink($temp);

                    return $this->fail(sprintf('Short or failed write while persisting evidence (%d of %d bytes).', $written, $length));
                }
                $written += $chunk;
            }
            $flushed = fflush($handle);
            $closed = fclose($handle);
            if (! $flushed || ! $closed) {
                unlink($temp);

                return $this->fail('Evidence file could not be flushed and closed.');
            }

            if ($this->beforeVerify !== null) {
                ($this->beforeVerify)($temp);
            }

            if (file_get_contents($temp) !== $payload || json_decode((string) file_get_contents($temp), true) !== json_decode($payload, true)) {
                unlink($temp);

                return $this->fail('Read-back verification of the temporary evidence file failed.');
            }

            if (! rename($temp, $final)) {
                unlink($temp);

                return $this->fail('Evidence file could not be moved into its final destination.');
            }

            if (file_get_contents($final) !== $payload) {
                unlink($final);

                return $this->fail('Read-back verification of the persisted evidence file failed.');
            }
        } finally {
            restore_error_handler();
            if (is_file($temp)) {
                unlink($temp);
            }
        }

        return [
            'status' => 'PASS',
            'message' => sprintf('Persisted %d bytes to "%s".', $length, $final),
            'path' => $final,
            'bytes' => $length,
            'sha256' => hash('sha256', $payload),
        ];
    }

    /**
     * @return array{status: 'FAIL', message: string}
     */
    private function fail(string $message): array
    {
        return ['status' => 'FAIL', 'message' => $message];
    }
}
