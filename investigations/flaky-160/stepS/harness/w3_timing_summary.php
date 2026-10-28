<?php

declare(strict_types=1);

// #160 step S — timing summary: per arm, the median of persistWinningCandidates durations (ns) over the calls that
// included a store-level K4 publication, pooled over all runs, and the change/base ratio against the 1.25 bound.
// Also reports the same for all calls. Rows whose w3_arm differs from their arm label are a harness error.
namespace Flaky160S;

final class Timing
{
    /** @param list<float|int> $x */
    public static function median(array $x): ?float
    {
        if ($x === []) {
            return null;
        }
        sort($x);
        $n = count($x);

        return $n % 2 ? (float) $x[intdiv($n, 2)] : ($x[$n / 2 - 1] + $x[$n / 2]) / 2;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public static function summarize(array $rows, float $bound = 1.25): array
    {
        $pub = ['base' => [], 'change' => []];
        $all = ['base' => [], 'change' => []];
        $bad = 0;
        $runs = ['base' => 0, 'change' => 0];
        foreach ($rows as $r) {
            if (! isset($pub[$r['arm']]) || $r['w3_arm'] !== $r['arm']) {
                $bad++;
                continue;
            }
            $runs[$r['arm']]++;
            array_push($pub[$r['arm']], ...$r['pub_ns']);
            array_push($all[$r['arm']], ...$r['all_ns']);
        }
        $mp = ['base' => self::median($pub['base']), 'change' => self::median($pub['change'])];
        $ma = ['base' => self::median($all['base']), 'change' => self::median($all['change'])];
        $ratio = $mp['base'] !== null && $mp['change'] !== null && $mp['base'] > 0 ? $mp['change'] / $mp['base'] : null;

        return [
            'runs' => $runs, 'harness_errors' => $bad,
            'calls_with_k4_publication' => ['base' => count($pub['base']), 'change' => count($pub['change'])],
            'median_ns_with_k4_publication' => $mp, 'median_ns_all_calls' => $ma,
            'ratio_change_over_base' => $ratio === null ? null : round($ratio, 3), 'bound' => $bound,
            'within' => $ratio !== null && $ratio <= $bound,
        ];
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $o = getopt('', ['rows:']);
    $rows = array_map(static fn(string $l): array => json_decode($l, true), file((string) $o['rows'], FILE_IGNORE_NEW_LINES) ?: []);
    echo json_encode(Timing::summarize($rows), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
}
