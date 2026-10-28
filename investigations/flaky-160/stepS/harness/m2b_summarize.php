<?php

declare(strict_types=1);

// #160 step M — M2b summary: merges the m2b-<pid>.json files of one suite run.
//   php m2b_summarize.php --dir=<out dir with m2b-*.json> --suite=<name> --exit=<phpunit exit status>
// The intended-exhaustion tests below were named in PLAN.md §4 BEFORE any run (read from tests/, never from results).
// A non-zero count outside them is reported as `unexpected`, never silently excluded.

$o = getopt('', ['dir:', 'suite:', 'exit:']);
$dir = (string) $o['dir'];

const EXCLUDED_EXACT = [
    'Maatify\RateLimiter\Tests\System\PostPunishmentReentryLifecycleTest::testMutationAndPublicationConflictsRefreshAndRetryWithFreshState',
    'Maatify\RateLimiter\Tests\System\PostPunishmentReentryLifecycleTest::testThreeMutationConflictsReturnTransientHardWithoutCircuitFailure',
    'Maatify\RateLimiter\Tests\System\PostPunishmentReentryLifecycleTest::testThreePublicationConflictsReturnTransientHardWithoutCircuitFailure', // base only: renamed by DEC-029
    'Maatify\RateLimiter\Tests\Integration\Redis\RedisFullCapabilityStoreIntegrationTest::testWidenedHardBlockExpiryIsValidForCycleTrackingLifecycleAndPreviousReads',
];
const EXCLUDED_CLASS = [
    'Maatify\RateLimiter\Tests\Integration\Redis\RedisFullCapabilityStoreIntegrationTest', // PLAN §8: store tests, excluded by construction
    'Maatify\RateLimiter\Tests\Integration\Redis\KnownDeviceAllowanceConcurrencyIntegrationTest',
];

$excluded = static function (string $test): bool {
    if (in_array($test, EXCLUDED_EXACT, true)) {
        return true;
    }
    foreach (EXCLUDED_CLASS as $c) {
        if (str_starts_with($test, $c . '::')) {
            return true;
        }
    }
    return false;
};

$files = glob("$dir/m2b-*.json") ?: [];
$calls = ['mutate' => 0, 'publish' => 0];
$thrown = [];
$non = [];
foreach ($files as $f) {
    $r = json_decode((string) file_get_contents($f), true);
    foreach ($calls as $k => $_) {
        $calls[$k] += (int) $r['calls'][$k];
    }
    array_push($thrown, ...$r['thrown']);
    array_push($non, ...$r['nonApplied']);
}
$bySite = [];
foreach ($thrown as $t) {
    $s = &$bySite[$t['site']];
    $s ??= ['total' => 0, 'intended' => 0, 'unexpected' => 0, 'unexpected_tests' => []];
    $s['total']++;
    if ($excluded($t['test'])) {
        $s['intended']++;
    } else {
        $s['unexpected']++;
        $s['unexpected_tests'][$t['test']] = ($s['unexpected_tests'][$t['test']] ?? 0) + 1;
    }
    unset($s);
}
$nonBy = [];
foreach ($non as $n) {
    $k = $n['kind'] . (($n['via'] ?? 'pipeline') === 'pipeline' ? '' : '_direct');
    $nonBy[$k] ??= ['total' => 0, 'intended' => 0, 'unexpected' => 0, 'unexpected_tests' => []];
    $nonBy[$k]['total']++;
    if ($excluded($n['test'])) {
        $nonBy[$k]['intended']++;
    } else {
        $nonBy[$k]['unexpected']++;
        $nonBy[$k]['unexpected_tests'][$n['test']] = ($nonBy[$k]['unexpected_tests'][$n['test']] ?? 0) + 1;
    }
}
echo json_encode([
    'suite' => (string) $o['suite'], 'phpunit_exit' => (int) $o['exit'], 'process_files' => count($files),
    'redis_store_calls' => $calls, 'exceptions_by_site' => $bySite, 'redis_store_non_applied_by_kind' => $nonBy,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
