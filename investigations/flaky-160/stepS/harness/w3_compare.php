<?php

declare(strict_types=1);

// #160 step S — V-DECISION comparison. Reads the w3-*.json files of two suite runs (base, change) and compares, for
// every test present in both, the sequence of `RateLimiterEngine::limit` results recorded in the root process.
// Rows from forked worker processes are counted apart (their interleaving is non-deterministic by construction).
//   php w3_compare.php --base=<rec dir> --change=<rec dir> [--expected-diff=<Class::test>,...]
// Prints one JSON object; exit 1 when a shared test differs outside --expected-diff.

namespace Flaky160S;

final class Compare
{
    /** @return array{root: array<string, list<array<int,mixed>>>, worker_rows: int} */
    public static function load(string $dir): array
    {
        $root = [];
        $workerRows = 0;
        foreach (glob("$dir/w3-*.json") ?: [] as $f) {
            $j = json_decode((string) file_get_contents($f), true);
            if (! is_array($j)) {
                continue;
            }
            if (! $j['root']) {
                $workerRows += count($j['decisions']);
                continue;
            }
            foreach ($j['decisions'] as $d) {
                $root[$d[0]][] = array_slice($d, 1);
            }
        }
        foreach ($root as &$rows) {
            usort($rows, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        }

        return ['root' => $root, 'worker_rows' => $workerRows];
    }

    /**
     * @param array{root: array<string, list<array<int,mixed>>>, worker_rows: int} $base
     * @param array{root: array<string, list<array<int,mixed>>>, worker_rows: int} $change
     * @param list<string> $expected
     * @return array<string, mixed>
     */
    public static function diff(array $base, array $change, array $expected): array
    {
        $shared = array_values(array_intersect(array_keys($base['root']), array_keys($change['root'])));
        $different = [];
        $rows = 0;
        foreach ($shared as $t) {
            $rows += count($base['root'][$t]);
            if ($base['root'][$t] !== $change['root'][$t]) {
                $different[] = ['test' => $t, 'expected' => in_array($t, $expected, true), 'base' => $base['root'][$t], 'change' => $change['root'][$t]];
            }
        }
        $unexpected = array_values(array_filter($different, static fn(array $d): bool => ! $d['expected']));

        return [
            'shared_tests' => count($shared), 'shared_rows' => $rows,
            'only_base' => array_values(array_diff(array_keys($base['root']), array_keys($change['root']))),
            'only_change' => array_values(array_diff(array_keys($change['root']), array_keys($base['root']))),
            'different' => $different, 'unexpected_differences' => count($unexpected),
            'worker_rows' => ['base' => $base['worker_rows'], 'change' => $change['worker_rows']],
            'ok' => $unexpected === [],
        ];
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $o = getopt('', ['base:', 'change:', 'expected-diff::']);
    $expected = isset($o['expected-diff']) && $o['expected-diff'] !== '' ? explode(',', (string) $o['expected-diff']) : [];
    $r = Compare::diff(Compare::load((string) $o['base']), Compare::load((string) $o['change']), $expected);
    echo json_encode($r, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit($r['ok'] ? 0 : 1);
}
