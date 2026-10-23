<?php

declare(strict_types=1);

// #160 phase 2 full-tap overhead (PLAN.md §8 LT-160B-01): one PING round trip,
// K times direct and K times through the full-record tap, alternating. The
// test span is measured by run.sh overhead (whole --filter run, direct vs tapped).
//
// Usage: php overhead.php --autoload=<vendor/autoload.php> --direct-port=<p> --tap-port=<p> --k=<K> --out=<json>

use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

$options = getopt('', ['autoload:', 'direct-port:', 'tap-port:', 'k:', 'out:']);
require (string) $options['autoload'];
$k = (int) $options['k'];
$executors = [
    'direct' => new RespRedisCommandExecutor('127.0.0.1', (int) $options['direct-port']),
    'tap' => new RespRedisCommandExecutor('127.0.0.1', (int) $options['tap-port']),
];
$data = ['direct' => [], 'tap' => []];
for ($i = 0; $i < $k; $i++) {
    foreach ($i % 2 === 0 ? ['direct', 'tap'] : ['tap', 'direct'] as $label) {
        $start = hrtime(true);
        $executors[$label]->execute(['PING']);
        $data[$label][] = intdiv(hrtime(true) - $start, 1000);
    }
}
$percentile = static function (array $values, float $q): int {
    sort($values);
    return $values[(int) floor($q * (count($values) - 1))];
};
$result = ['k' => $k, 'php' => PHP_VERSION, 'unit' => 'µs'];
foreach (['direct', 'tap'] as $label) {
    $result[$label] = ['p50' => $percentile($data[$label], 0.5), 'p99' => $percentile($data[$label], 0.99), 'min' => min($data[$label])];
}
$result['added_p50'] = $result['tap']['p50'] - $result['direct']['p50'];
$result['added_p99'] = $result['tap']['p99'] - $result['direct']['p99'];
file_put_contents((string) $options['out'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
echo json_encode($result, JSON_UNESCAPED_UNICODE), "\n";
