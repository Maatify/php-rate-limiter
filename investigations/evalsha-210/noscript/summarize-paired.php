<?php

declare(strict_types=1);

// Turns the raw cells of the paired p99 measurement (#210, second round) into Markdown tables.
//   php summarize-paired.php [raw-directory]
// Percentiles are nearest-rank on the measured samples; nothing is smoothed or dropped. A "pair" is the eval cell and the evalsha cell of
// the same set run back to back (the manifest records which ran first). The delta is (evalsha - eval) / eval of the same percentile.

$dir = $_SERVER['argv'][1] ?? __DIR__ . '/raw-paired';

function pct(array $values, float $p): float
{
    sort($values);
    $n = count($values);

    return $n === 0 ? NAN : $values[max(0, (int) ceil($p / 100 * $n) - 1)];
}

/** @param array<string, mixed> $cell */
function metrics(array $cell): array
{
    $samples = $cell['samples'];
    $ms = array_map(static fn(array $s): float => (float) $s['ms'], $samples);
    $blocked = array_values(array_filter($samples, static fn(array $s): bool => $s['conflict'] === true));
    $free = array_values(array_filter($samples, static fn(array $s): bool => $s['conflict'] !== true));

    return [
        'n' => count($samples),
        'p50' => pct($ms, 50),
        'p95' => pct($ms, 95),
        'p99' => pct($ms, 99),
        'max' => max($ms),
        'blocks' => count($blocked),
        'errors' => count(array_filter($samples, static fn(array $s): bool => $s['error'] !== null)),
        'noscript' => array_sum(array_map(static fn(array $s): int => (int) $s['noscript'], $samples)),
        'blockedMedianMs' => $blocked === [] ? NAN : pct(array_map(static fn(array $s): float => (float) $s['ms'], $blocked), 50),
        'freeP99' => $free === [] ? NAN : pct(array_map(static fn(array $s): float => (float) $s['ms'], $free), 99),
        'commitsPerBlocked' => $blocked === [] ? NAN : array_sum(array_map(static fn(array $s): int => (int) $s['commits'], $blocked)) / count($blocked),
        'commandsPerRequest' => array_sum(array_map(static fn(array $s): int => (int) $s['commands'], $samples)) / max(1, count($samples)),
        'sentKb' => array_sum(array_map(static fn(array $s): int => (int) $s['sent'], $samples)) / max(1, count($samples)) / 1024,
        'evalCalls' => (int) ($cell['serverCommandCalls']['eval'] ?? 0),
        'evalshaCalls' => (int) ($cell['serverCommandCalls']['evalsha'] ?? 0),
    ];
}

function f(float $v, int $d = 1): string
{
    return is_nan($v) ? '-' : number_format($v, $d, '.', '');
}

function pc(float $new, float $old): string
{
    return $old > 0 ? sprintf('%+.1f %%', ($new - $old) / $old * 100) : '-';
}

$manifest = [];
foreach (file($dir . '/manifest.tsv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    [$set, $pair, $position, $mode, $file] = explode("\t", $line);
    $cell = json_decode((string) file_get_contents($dir . '/' . $file), true);
    if (! is_array($cell)) {
        fwrite(STDERR, "unreadable $file\n");
        continue;
    }
    $manifest[$set][(int) $pair][$mode] = ['position' => (int) $position, 'm' => metrics($cell), 'file' => $file];
}

foreach ($manifest as $set => $pairs) {
    $first = reset($pairs);
    $anyCell = json_decode((string) file_get_contents($dir . '/' . $first['eval']['file']), true);
    echo "### Set `$set` ({$anyCell['workers']} workers x {$anyCell['requestsPerWorker']} requests, warm-up {$anyCell['warmUpRequests']}, php {$anyCell['php']} INT_SIZE {$anyCell['phpIntSize']}, redis {$anyCell['redis']})\n\n";
    echo "| Pair | First | n | EVAL p50 | EVALSHA p50 | EVAL p95 | EVALSHA p95 | EVAL p99 | EVALSHA p99 | p99 delta | EVAL blocks | EVALSHA blocks | errors (e/s) | NOSCRIPT (s) |\n";
    echo "| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- | ---: |\n";
    $deltas = [];
    $higher = 0;
    $evalP99 = [];
    $shaP99 = [];
    $evalBlocked = [];
    $shaBlocked = [];
    $evalFree = [];
    $shaFree = [];
    foreach ($pairs as $p => $modes) {
        $e = $modes['eval']['m'];
        $s = $modes['evalsha']['m'];
        $firstMode = $modes['eval']['position'] === 1 ? 'eval' : 'evalsha';
        $delta = $e['p99'] > 0 ? ($s['p99'] - $e['p99']) / $e['p99'] * 100 : NAN;
        $deltas[] = $delta;
        $higher += $s['p99'] > $e['p99'] ? 1 : 0;
        $evalP99[] = $e['p99'];
        $shaP99[] = $s['p99'];
        $evalBlocked[] = $e['blockedMedianMs'];
        $shaBlocked[] = $s['blockedMedianMs'];
        $evalFree[] = $e['freeP99'];
        $shaFree[] = $s['freeP99'];
        echo sprintf(
            "| %d | %s | %d | %s | %s | %s | %s | %s | %s | %s | %d | %d | %d/%d | %d |\n",
            $p, $firstMode, $e['n'], f($e['p50']), f($s['p50']), f($e['p95']), f($s['p95']), f($e['p99']), f($s['p99']), pc($s['p99'], $e['p99']),
            $e['blocks'], $s['blocks'], $e['errors'], $s['errors'], $s['noscript'],
        );
    }
    $valid = array_values(array_filter($deltas, static fn(float $d): bool => ! is_nan($d)));
    sort($valid);
    $count = count($pairs);
    $median = static function (array $v): float {
        $v = array_values(array_filter($v, static fn(float $x): bool => ! is_nan($x)));
        sort($v);
        $n = count($v);

        return $n === 0 ? NAN : ($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2);
    };
    echo "\n";
    echo sprintf(
        "p99 in ms, EVALSHA above EVAL in **%d of %d** pairs; median EVAL %s ms (range %s to %s), median EVALSHA %s ms (range %s to %s); median of the per-pair deltas **%s %%** (min %s %%, max %s %%).\n\n",
        $higher, $count, f($median($evalP99)), f(min($evalP99)), f(max($evalP99)), f($median($shaP99)), f(min($shaP99)), f(max($shaP99)),
        f($median($deltas)), f($valid[0] ?? NAN), f($valid[count($valid) - 1] ?? NAN),
    );
    echo sprintf(
        "Where the tail comes from: median latency of a concurrency-blocked request EVAL %s ms against EVALSHA %s ms; p99 of the requests that were not blocked EVAL %s ms against EVALSHA %s ms (medians over the pairs).\n\n",
        f($median($evalBlocked)), f($median($shaBlocked)), f($median($evalFree)), f($median($shaFree)),
    );
}
