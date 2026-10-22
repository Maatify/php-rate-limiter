<?php

declare(strict_types=1);

// #157 tap overhead (PLAN.md §9.1 LT-157-04): the latency the tap adds, measured
// once before arm L. Alternates direct and tapped connections K times each and
// records (a) one PING round trip and (b) the test's own span from its TIME
// reply to just before the '-1' case's EXPIRE (index 0's six calls, then index
// 1's HSET), the quantity §1.3 / §9.3 depend on. Uses main's own classes.
//
// Usage: php overhead.php --autoload=<vendor/autoload.php> --direct-port=<p> --tap-port=<p> --k=<K> --out=<json>

namespace Flaky157;

use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

require __DIR__ . '/lib.php';

$options = getopt('', ['autoload:', 'direct-port:', 'tap-port:', 'k:', 'out:']);
require (string) $options['autoload'];
$k = (int) $options['k'];

$paths = [];
foreach (['direct' => (int) $options['direct-port'], 'tap' => (int) $options['tap-port']] as $label => $port) {
    $executor = new RespRedisCommandExecutor('127.0.0.1', $port);
    $paths[$label] = [$executor, new RedisFullCapabilityStore($executor, NAMESPACE_USED)];
}
$keyMethod = new \ReflectionMethod(RedisFullCapabilityStore::class, 'key');

$sample = static function (RespRedisCommandExecutor $executor, RedisFullCapabilityStore $store) use ($keyMethod): array {
    $executor->execute(['FLUSHDB']);
    $start = hrtime(true);
    $executor->execute(['PING']);
    $ping = intdiv(hrtime(true) - $start, 1000);
    $time = $executor->execute(['TIME']);
    $h0 = hrtime(true);
    $now = (int) $time[0];
    foreach (['0', '-1'] as $index => $generation) {
        $logical = 'r1-malformed-generation-' . $index;
        $score = $keyMethod->invoke($store, 'score', $logical);
        $executor->execute(['HSET', $score, 'value', '8', 'updatedAt', (string) $now, 'generation', $generation, 'expiresAt', (string) ($now + 600)]);
        if ($index === 1) {
            return [$ping, intdiv(hrtime(true) - $h0, 1000)];
        }
        $executor->execute(['EXPIRE', $score, '600']);
        foreach ([
            fn() => $store->readGenerationBoundScoreState($logical, null),
            fn() => $store->mutateGenerationBoundScore($logical, null, null, 600, 9),
            fn() => $store->claimPostPunishmentReentry($logical, null, str_repeat('a', 32)),
            fn() => $store->blockWithPunishmentLifecycleTracking($logical, null, 3, str_repeat('a', 32), 2, 60, 600, 2, 600, 86400),
        ] as $call) {
            try {
                $call();
            } catch (RateLimiterException) {
            }
        }
    }
    throw new \LogicException('unreachable');
};

$data = ['direct' => ['ping' => [], 'span' => []], 'tap' => ['ping' => [], 'span' => []]];
for ($i = 0; $i < $k; $i++) {
    foreach ($i % 2 === 0 ? ['direct', 'tap'] : ['tap', 'direct'] as $label) {
        [$ping, $span] = $sample(...$paths[$label]);
        $data[$label]['ping'][] = $ping;
        $data[$label]['span'][] = $span;
    }
}
$percentile = static function (array $values, float $q): int {
    sort($values);
    return $values[(int) floor($q * (count($values) - 1))];
};
$result = ['k' => $k, 'php' => PHP_VERSION, 'unit' => 'µs'];
foreach (['ping', 'span'] as $measure) {
    foreach (['direct', 'tap'] as $label) {
        $result[$measure][$label] = ['p50' => $percentile($data[$label][$measure], 0.5), 'p99' => $percentile($data[$label][$measure], 0.99), 'min' => min($data[$label][$measure])];
    }
    $result[$measure]['added_p50'] = $result[$measure]['tap']['p50'] - $result[$measure]['direct']['p50'];
    $result[$measure]['added_p99'] = $result[$measure]['tap']['p99'] - $result[$measure]['direct']['p99'];
}
file_put_contents((string) $options['out'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
echo json_encode($result, JSON_UNESCAPED_UNICODE), "\n";
