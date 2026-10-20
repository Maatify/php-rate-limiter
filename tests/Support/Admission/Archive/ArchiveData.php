<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission\Archive;

/**
 * @phpstan-type Effect array{issue?: array{holder: string, inst: string, acct: string, ttl: int, bindOffset: int}, revoke?: array{acct: string, times: int}, copy?: array{from: string, to: string}, password?: array{holder: string, acct: string, inst: string}}
 * @phpstan-type Ctx array{ip: string, ua: string, accountId: ?string, clientFingerprint: ?array<string, mixed>, sessionDeviceId: ?string, isSessionTrusted: bool, headers: array<string, array<string>|string>, isDevicePreviouslyVerifiedForAccount: bool, correlationId: ?string}
 * @phpstan-type DumpRow array{t: int, effects?: list<Effect>, kind?: string, own?: bool, who?: string, outcome?: string, inst?: ?string, holder?: string, proof?: ?string, ctx?: Ctx}
 * @phpstan-type Tuple array{stream: string, cls: string, strategy: string, ids: string, rows: list<DumpRow>}
 *
 * Runs `archive-dump.php` in its own PHP process at test time (LT-20 conditions 1, 2, 4: the archive is not touched, its own
 * functions build the rows, nothing is committed, `PHP_INI_SCAN_DIR=` and the archive memory limit) and exposes the JSON it
 * wrote. The sha-256 and line count of every file are kept for `RESULTS.md` (condition 3).
 */
final class ArchiveData
{
    public const ROOT = __DIR__ . '/../../../../investigations/k2-identity-admission-97';

    private static ?self $instance = null;

    /** @var array<string, array{lines: int, sha256: string}> */
    public array $manifest = [];

    private function __construct(private readonly string $dir) {}

    public static function get(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        $dir = sys_get_temp_dir() . '/archive-dump-' . bin2hex(random_bytes(6));
        if (! mkdir($dir) && ! is_dir($dir)) {
            throw new \RuntimeException('Cannot create the dump directory.');
        }
        $root = realpath(self::ROOT);
        if ($root === false) {
            throw new \RuntimeException('The archive root is missing.');
        }
        $command = sprintf(
            'PHP_INI_SCAN_DIR= %s -d memory_limit=1G %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/archive-dump.php'),
            escapeshellarg($root),
            escapeshellarg($dir),
        );
        exec($command, $output, $status);
        if ($status !== 0) {
            throw new \RuntimeException('The archive dump failed (' . $status . '): ' . implode("\n", $output));
        }
        $instance = new self($dir);
        $manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($manifest)) {
            throw new \RuntimeException('Malformed dump manifest.');
        }
        /** @var array<string, array{lines: int, sha256: string}> $manifest */
        $instance->manifest = $manifest;
        register_shutdown_function(static function () use ($dir): void {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        });

        return self::$instance = $instance;
    }

    /** @return array{tokenTtl: int, taint: int, maxLife: int} */
    public function constants(): array
    {
        /** @var array{tokenTtl: int, taint: int, maxLife: int} $c */
        $c = json_decode((string) file_get_contents($this->dir . '/constants.json'), true, 4, JSON_THROW_ON_ERROR);

        return $c;
    }

    /**
     * One stream tuple at a time (the dump is several MB; the test process runs with the default memory limit).
     *
     * @return \Generator<int, Tuple>
     */
    public function streams(): \Generator
    {
        $handle = fopen($this->dir . '/streams.jsonl', 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot read the stream dump.');
        }
        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                /** @var Tuple $tuple */
                $tuple = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                yield $tuple;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * The G7 office and multi-session streams (`N12T`, `TPAIRT`, `TMS`, `TMSd`, `TMX`), one tuple at a time.
     *
     * @return \Generator<int, Tuple>
     */
    public function g7Streams(): \Generator
    {
        foreach ($this->lines('g7streams.jsonl') as $line) {
            /** @var Tuple $line */
            yield $line;
        }
    }

    /**
     * A fixture of `fixtures.jsonl` by name (LT-23): its `source` (the archive lines it transcribes) and its row parts.
     *
     * @return array<string, mixed>
     */
    public function fixture(string $name): array
    {
        foreach ($this->lines('fixtures.jsonl') as $fixture) {
            if ($fixture['fixture'] === $name) {
                return $fixture;
            }
        }

        throw new \OutOfBoundsException("No dumped fixture {$name}.");
    }

    /**
     * The dumped rows of a fixture part (`rows`, `pre`, `after`).
     *
     * @return list<DumpRow>
     */
    public function fixtureRows(string $name, string $part = 'rows'): array
    {
        $rows = $this->fixture($name)[$part] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new \UnexpectedValueException("Fixture {$name} has no row list {$part}.");
        }
        /** @var list<DumpRow> $rows */
        return $rows;
    }

    /**
     * One dumped row of a fixture (`owner`, `change`).
     *
     * @return DumpRow
     */
    public function fixtureRow(string $name, string $part): array
    {
        $row = $this->fixture($name)[$part] ?? null;
        if (! is_array($row) || ! is_int($row['t'] ?? null)) {
            throw new \UnexpectedValueException("Fixture {$name} has no row {$part}.");
        }
        /** @var DumpRow $row */
        return $row;
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function lines(string $file): \Generator
    {
        $handle = fopen($this->dir . '/' . $file, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot read {$file}.");
        }
        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                /** @var array<string, mixed> $row */
                $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return list<DumpRow> the BRU24 (`$hv` false) or BRU24-HV attacker rows of `g4v2Bru()` */
    public function bru(bool $hv): array
    {
        $lines = file($this->dir . '/bru.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        /** @var array{rows: list<DumpRow>} $row */
        $row = json_decode($lines[$hv ? 1 : 0], true, 64, JSON_THROW_ON_ERROR);
        $rows = $row['rows'];

        return $rows;
    }

    /**
     * Archive expected rows (`expected/*.jsonl`), read as the archive wrote them.
     *
     * @return list<array<string, mixed>>
     */
    public static function expected(string $file): array
    {
        $out = [];
        foreach (file(self::ROOT . '/' . $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            /** @var array<string, mixed> $row */
            $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            $out[] = $row;
        }

        return $out;
    }
}
