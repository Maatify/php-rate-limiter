<?php

declare(strict_types=1);

// Turns the raw JSON cells of the EVALSHA study (#210) into the Markdown tables of RESULTS.md.
//   php summarize.php [raw-directory]
// Percentiles use the nearest-rank method on the measured samples; nothing is smoothed or dropped.

$dir = $_SERVER['argv'][1] ?? __DIR__ . '/raw';
$cells = [];
foreach (glob($dir . '/*.json') ?: [] as $file) {
    $data = json_decode((string) file_get_contents($file), true);
    if (is_array($data) && isset($data['samples'])) {
        $cells[basename($file, '.json')] = $data;
    }
}
ksort($cells);

function pct(array $values, float $p): float
{
    sort($values);
    $n = count($values);
    if ($n === 0) {
        return NAN;
    }

    return $values[max(0, (int) ceil($p / 100 * $n) - 1)];
}

function med(array $values): float
{
    return pct($values, 50);
}

function col(array $samples, string $key): array
{
    return array_map(static fn(array $s): float => (float) $s[$key], $samples);
}

function kb(float $bytes): string
{
    return number_format($bytes / 1024, 1);
}

$workloads = ['read', 'newdevice', 'failure', 'admission-reserve', 'admission-outcome', 'recovery'];

echo "### Per-request cost, local loopback (median of the measured requests)\n\n";
echo "| Workload | Mode | n | sent KB | received KB | commands | p50 ms | p95 ms | p99 ms | NOSCRIPT | errors |\n| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |\n";
foreach (['local', 'rtt'] as $net) {
    if ($net === 'rtt') {
        echo "\n### Per-request cost with about 7 ms added round trip (3.5 ms each way)\n\n";
        echo "| Workload | Mode | n | sent KB | received KB | commands | p50 ms | p95 ms | p99 ms | NOSCRIPT | errors |\n| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |\n";
    }
    foreach ($workloads as $w) {
        foreach (['eval', 'evalsha', 'evalsha-flush-each', 'evalsha-flush-commit'] as $m) {
            $cell = $cells["{$net}_{$w}_{$m}"] ?? null;
            if ($cell === null) {
                continue;
            }
            $s = $cell['samples'];
            printf(
                "| %s | %s | %d | %s | %s | %d | %.2f | %.2f | %.2f | %d | %d |\n",
                $w,
                $m,
                count($s),
                kb(med(col($s, 'sent'))),
                kb(med(col($s, 'received'))),
                (int) med(col($s, 'commands')),
                med(col($s, 'ms')),
                pct(col($s, 'ms'), 95),
                pct(col($s, 'ms'), 99),
                array_sum(col($s, 'noscript')),
                count(array_filter($s, static fn(array $x): bool => $x['error'] !== null)),
            );
        }
    }
}

echo "\n### Reduction in bytes sent, evalsha against eval (median per request)\n\n| Network | Workload | eval KB | evalsha KB | reduction |\n| --- | --- | ---: | ---: | ---: |\n";
foreach (['local', 'rtt'] as $net) {
    foreach ($workloads as $w) {
        $a = $cells["{$net}_{$w}_eval"] ?? null;
        $b = $cells["{$net}_{$w}_evalsha"] ?? null;
        if ($a === null || $b === null) {
            continue;
        }
        $x = med(col($a['samples'], 'sent'));
        $y = med(col($b['samples'], 'sent'));
        printf("| %s | %s | %s | %s | %.1f %% |\n", $net, $w, kb($x), kb($y), (1 - $y / $x) * 100);
    }
}

echo "\n### Cold cache: SCRIPT FLUSH before the first request (sample 0) against the later requests\n\n| Workload | sample 0 commands | sample 0 NOSCRIPT | sample 0 sent KB | sample 0 ms | later median commands | later median sent KB | later median ms |\n| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |\n";
foreach ($workloads as $w) {
    $cell = $cells["cold_{$w}_evalsha"] ?? null;
    if ($cell === null) {
        continue;
    }
    $s = $cell['samples'];
    $first = $s[0];
    $rest = array_slice($s, 1);
    printf(
        "| %s | %d | %d | %s | %.2f | %d | %s | %.2f |\n",
        $w,
        $first['commands'],
        $first['noscript'],
        kb((float) $first['sent']),
        $first['ms'],
        (int) med(col($rest, 'commands')),
        kb(med(col($rest, 'sent'))),
        med(col($rest, 'ms')),
    );
}

echo "\n### Hot-key contention (one account, recordFailure, default retry budget)\n\n| Network | Workers x requests | Mode | requests | concurrency blocks | errors | NOSCRIPT | p50 ms | p95 ms | p99 ms | max ms | total sent MB |\n| --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |\n";
foreach ($cells as $name => $cell) {
    if (! str_contains($name, '_hot-')) {
        continue;
    }
    $s = $cell['samples'];
    printf(
        "| %s | %d x %d | %s | %d | %d | %d | %d | %.1f | %.1f | %.1f | %.1f | %.2f |\n",
        str_starts_with($name, 'rtt') ? 'about 7 ms RTT' : 'loopback',
        $cell['workers'],
        $cell['requestsPerWorker'],
        $cell['mode'],
        count($s),
        count(array_filter($s, static fn(array $x): bool => $x['conflict'])),
        count(array_filter($s, static fn(array $x): bool => $x['error'] !== null)),
        array_sum(col($s, 'noscript')),
        med(col($s, 'ms')),
        pct(col($s, 'ms'), 95),
        pct(col($s, 'ms'), 99),
        max(col($s, 'ms')),
        array_sum(col($s, 'sent')) / 1048576,
    );
}

echo "\n### Server restart (script cache lost): new connection, `newdevice`\n\n| Request | commands | NOSCRIPT | sent KB | ms |\n| ---: | ---: | ---: | ---: | ---: |\n";
foreach (['warmup-before-restart_newdevice_evalsha' => 'before', 'after-restart_newdevice_evalsha' => 'after'] as $name => $when) {
    $cell = $cells[$name] ?? null;
    if ($cell === null) {
        continue;
    }
    foreach ($cell['samples'] as $i => $x) {
        printf("| %s #%d | %d | %d | %s | %.2f |\n", $when, $i, $x['commands'], $x['noscript'], kb((float) $x['sent']), $x['ms']);
    }
}

echo "\n### Repeated contention runs (alternating eval / evalsha; per-run p50, p95, p99 and concurrency blocks)\n\n| Cell | Mode | Run | p50 ms | p95 ms | p99 ms | max ms | concurrency blocks | errors |\n| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |\n";
$byCell = [];
foreach ($cells as $name => $cell) {
    if (preg_match('/^repeat-(local|rtt)_hot-(w\d+)-r(\d+)_(eval|evalsha)$/', $name, $m) !== 1) {
        continue;
    }
    $s = $cell['samples'];
    $row = [med(col($s, 'ms')), pct(col($s, 'ms'), 95), pct(col($s, 'ms'), 99), max(col($s, 'ms')), count(array_filter($s, static fn(array $x): bool => $x['conflict'])), count(array_filter($s, static fn(array $x): bool => $x['error'] !== null))];
    $byCell[$m[1] . '-' . $m[2]][$m[4]][(int) $m[3]] = $row;
    printf("| %s-%s | %s | %d | %.1f | %.1f | %.1f | %.1f | %d | %d |\n", $m[1], $m[2], $m[4], (int) $m[3], ...$row);
}
echo "\n| Cell | Mode | runs | median of p50 | median of p99 | min p99 | max p99 | total concurrency blocks |\n| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |\n";
foreach ($byCell as $cellName => $modes) {
    foreach ($modes as $mode => $runs) {
        $p50 = array_column($runs, 0);
        $p99 = array_column($runs, 2);
        printf("| %s | %s | %d | %.1f | %.1f | %.1f | %.1f | %d |\n", $cellName, $mode, count($runs), med($p50), med($p99), min($p99), max($p99), array_sum(array_column($runs, 4)));
    }
}
