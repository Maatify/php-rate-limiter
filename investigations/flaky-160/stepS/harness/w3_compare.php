<?php

declare(strict_types=1);

// #160 step S — V-DECISION comparison. Reads the w3-*.json files of two suite runs (base, change) and compares, for
// every test present in both, the sequence of `RateLimiterEngine::limit` results recorded in the root process, as a count and a sha256 over the canonical
// row sequence (rows themselves are kept only for the first 8 of each test). Forked worker processes are counted apart (their interleaving is non-deterministic by construction).
//   php w3_compare.php --base=<rec dir> --change=<rec dir> [--expected-diff=<Class::test>,...]
// Prints one JSON object; exit 1 when a shared test differs outside --expected-diff.

namespace Flaky160S;

final class Compare
{
    /** @return array{root: array<string, array{n: int, sha256: string, head: list<mixed>}>, worker_tests: int} */
    public static function load(string $dir): array
    {
        $root = [];
        $workerTests = 0;
        foreach (glob("$dir/w3-*.json") ?: [] as $f) {
            $j = json_decode((string) file_get_contents($f), true);
            if (! is_array($j)) {
                continue;
            }
            if (! $j['root']) {
                $workerTests += count($j['tests']);
                continue;
            }
            $root = $j['tests'];
        }

        return ['root' => $root, 'worker_tests' => $workerTests];
    }

    /**
     * @param array{root: array<string, array{n: int, sha256: string, head: list<mixed>}>, worker_tests: int} $base
     * @param array{root: array<string, array{n: int, sha256: string, head: list<mixed>}>, worker_tests: int} $change
     * @param list<string> $expected
     * @return array<string, mixed>
     */
    public static function diff(array $base, array $change, array $expected): array
    {
        $shared = array_values(array_intersect(array_keys($base['root']), array_keys($change['root'])));
        $different = [];
        $rows = 0;
        foreach ($shared as $t) {
            $rows += $base['root'][$t]['n'];
            if ($base['root'][$t]['n'] !== $change['root'][$t]['n'] || $base['root'][$t]['sha256'] !== $change['root'][$t]['sha256']) {
                $different[] = ['test' => $t, 'expected' => in_array($t, $expected, true), 'base' => $base['root'][$t], 'change' => $change['root'][$t]];
            }
        }
        $unexpected = array_values(array_filter($different, static fn(array $d): bool => ! $d['expected']));

        return [
            'shared_tests' => count($shared), 'shared_rows' => $rows,
            'only_base' => array_values(array_diff(array_keys($base['root']), array_keys($change['root']))),
            'only_change' => array_values(array_diff(array_keys($change['root']), array_keys($base['root']))),
            'different' => $different, 'unexpected_differences' => count($unexpected),
            'worker_tests' => ['base' => $base['worker_tests'], 'change' => $change['worker_tests']],
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
