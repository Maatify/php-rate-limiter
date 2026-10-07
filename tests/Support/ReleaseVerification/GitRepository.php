<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\ReleaseVerification;

use RuntimeException;

/**
 * Read-only access to immutable Git objects.
 *
 * Release qualification is for an exact Git candidate commit/tree. Everything that can be
 * proven from Git objects is read through this class instead of from the mutable worktree.
 */
final class GitRepository
{
    public function __construct(public readonly string $path) {}

    /**
     * @param list<string> $args
     * @return array{code: int, output: string}
     */
    public function run(array $args, ?string $stdoutFile = null): array
    {
        /** @var array<string, string> $env */
        $env = array_filter(getenv(), 'is_string');
        $env['LC_ALL'] = 'C';
        $env['GIT_TERMINAL_PROMPT'] = '0';

        return ProcessRunner::run(['git', '-C', $this->path, ...$args], null, $env, $stdoutFile);
    }

    public function isWorkTree(): bool
    {
        $r = $this->run(['rev-parse', '--is-inside-work-tree']);

        return $r['code'] === 0 && trim($r['output']) === 'true';
    }

    public function objectType(string $rev): ?string
    {
        if ($rev === '' || str_starts_with($rev, '-')) {
            return null;
        }
        $r = $this->run(['cat-file', '-t', $rev]);

        return $r['code'] === 0 ? trim($r['output']) : null;
    }

    public function resolve(string $rev): ?string
    {
        if ($rev === '' || str_starts_with($rev, '-')) {
            return null;
        }
        $r = $this->run(['rev-parse', '--verify', '--quiet', $rev]);
        $sha = trim($r['output']);

        return $r['code'] === 0 && ReleaseContract::isSha($sha) ? strtolower($sha) : null;
    }

    public function isAncestor(string $ancestor, string $descendant): bool
    {
        return $this->run(['merge-base', '--is-ancestor', $ancestor, $descendant])['code'] === 0;
    }

    /**
     * Blob object id of a path at an immutable revision, or null when absent / not a blob.
     */
    public function blobOid(string $rev, string $path): ?string
    {
        $oid = $this->resolve($rev . ':' . $path);
        if ($oid === null || $this->objectType($oid) !== 'blob') {
            return null;
        }

        return $oid;
    }

    /**
     * Content of a blob at an immutable revision, or null when absent.
     */
    public function showBlob(string $rev, string $path): ?string
    {
        $oid = $this->blobOid($rev, $path);
        if ($oid === null) {
            return null;
        }
        $r = $this->run(['cat-file', 'blob', $oid]);

        return $r['code'] === 0 ? $r['output'] : null;
    }

    /**
     * Writes every regular-file blob of the revision's tree to $dir (raw object content;
     * no attributes, filters, export-ignore or eol conversion).
     *
     * @return array{files: list<string>, unsupported: list<string>}
     */
    public function exportTree(string $rev, string $dir): array
    {
        $ls = $this->run(['ls-tree', '-r', '-z', '--full-tree', $rev]);
        if ($ls['code'] !== 0) {
            throw new RuntimeException('Unable to list tree for ' . $rev);
        }

        /** @var array<string, string> $blobs path => oid */
        $blobs = [];
        $unsupported = [];
        foreach (explode("\0", $ls['output']) as $entry) {
            if ($entry === '') {
                continue;
            }
            $parts = explode("\t", $entry, 2);
            $meta = explode(' ', $parts[0]);
            $entryPath = $parts[1] ?? '';
            if (count($meta) !== 3 || $entryPath === '') {
                throw new RuntimeException('Malformed ls-tree entry.');
            }
            if ($meta[0] === '120000' || $meta[1] !== 'blob') {
                $unsupported[] = $entryPath;
                continue;
            }
            $blobs[$entryPath] = $meta[2];
        }

        $pipes = [];
        $process = proc_open(
            ['git', '-C', $this->path, 'cat-file', '--batch'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        $stdin = is_array($pipes) ? ($pipes[0] ?? null) : null;
        $stdout = is_array($pipes) ? ($pipes[1] ?? null) : null;
        if (! is_resource($process) || ! is_resource($stdin) || ! is_resource($stdout)) {
            throw new RuntimeException('Unable to start git cat-file.');
        }

        try {
            foreach ($blobs as $entryPath => $oid) {
                fwrite($stdin, $oid . "\n");
                fflush($stdin);
                $header = fgets($stdout);
                if (! is_string($header) || ! (bool) preg_match('/^[0-9a-f]{40} blob (\d+)$/', rtrim($header), $m)) {
                    throw new RuntimeException('Unexpected git cat-file response for ' . $entryPath);
                }
                $remaining = (int) $m[1];
                $content = '';
                while ($remaining > 0) {
                    $chunk = fread($stdout, min($remaining, 65536));
                    if ($chunk === false || $chunk === '') {
                        throw new RuntimeException('Truncated git object ' . $oid);
                    }
                    $content .= $chunk;
                    $remaining -= strlen($chunk);
                }
                fgets($stdout);

                $target = $dir . '/' . $entryPath;
                $targetDir = dirname($target);
                if (! is_dir($targetDir) && ! mkdir($targetDir, 0777, true) && ! is_dir($targetDir)) {
                    throw new RuntimeException('Unable to create ' . $targetDir);
                }
                file_put_contents($target, $content);
            }
        } finally {
            fclose($stdin);
            fclose($stdout);
            proc_close($process);
        }

        $files = array_keys($blobs);
        sort($files);

        return ['files' => $files, 'unsupported' => $unsupported];
    }
}
