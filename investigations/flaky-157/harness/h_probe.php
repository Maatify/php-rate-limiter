<?php

declare(strict_types=1);

// #157 arm H probe (PLAN.md §5.4, §9.3). Runs at fe8775c through that head's own
// RespRedisCommandExecutor and RedisFullCapabilityStore, replaying the body of
// testMalformedStoredGenerationFailsAcrossReadMutateClaimAndPublication
// (fe8775c L1530–L1544) call for call, M times, with no tap.
//
// The timed part is exactly the test's sequence. Only after all three cases
// does it read TIME, PTTL and HGETALL of the '-1' key to compute D (§9.3).
// FLUSHDB before each sequence stands for the test's setUp() (L47).
//
// Usage: php h_probe.php --autoload=<fe8775c vendor/autoload.php> --port=<p>
//          --mode=probe|forced --m=<M> --out=<jsonl>
// One JSON line per sequence, then one summary line.

namespace Flaky157;

use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

require __DIR__ . '/lib.php';

$options = getopt('', ['autoload:', 'port:', 'mode:', 'm:', 'out:']);
require (string) $options['autoload'];
$mode = (string) $options['mode'];
$m = (int) $options['m'];
if (! in_array($mode, ['probe', 'forced'], true) || $m < 1) {
    fwrite(STDERR, "usage: h_probe.php --autoload=… --port=… --mode=probe|forced --m=M --out=…\n");
    exit(2);
}
$out = fopen((string) $options['out'], 'wb');

$executor = new RespRedisCommandExecutor('127.0.0.1', (int) $options['port']);
$store = new RedisFullCapabilityStore($executor, NAMESPACE_USED);
$keyMethod = new \ReflectionMethod(RedisFullCapabilityStore::class, 'key');
$key = static fn(string $family, string $logical): string => $keyMethod->invoke($store, $family, $logical);

/** The test passes on RateLimiterException that is not BackendFailureException (assertOperationFails, fe8775c L3723–L3737). */
$attempt = static function (callable $operation): string {
    try {
        $result = $operation();
    } catch (BackendFailureException $exception) {
        return 'B:' . $exception->getMessage();
    } catch (RateLimiterException $exception) {
        return 'T:' . $exception->getMessage();
    } catch (\Throwable $exception) {
        return 'U:' . $exception::class . ':' . $exception->getMessage();
    }
    if (is_object($result) && property_exists($result, 'applied')) {
        return 'R:applied=' . ($result->applied ? '1' : '0');
    }
    return 'R:' . json_encode($result);
};

$messages = [];
$started = hrtime(true);
for ($i = 0; $i < $m; $i++) {
    $executor->execute(['FLUSHDB']);
    $polls = 0;
    // --- timed part: the test body ---------------------------------------
    do {
        $time = $executor->execute(['TIME']);
        $polls++;
    } while ($mode === 'forced' && (int) $time[1] >= 200);
    $h0 = hrtime(true);
    $now = (int) $time[0];
    $outcomes = [];
    $hExpire = 0;
    $hMutate = 0;
    foreach (CASES as $index => $malformedGeneration) {
        $logical = 'r1-malformed-generation-' . $index;
        $score = $key('score', $logical);
        $executor->execute(['HSET', $score, 'value', '8', 'updatedAt', (string) $now, 'generation', $malformedGeneration, 'expiresAt', (string) ($now + 600)]);
        if ($index === 1) {
            $hExpire = hrtime(true);
        }
        $executor->execute(['EXPIRE', $score, '600']);
        $outcomes[$index]['read'] = $attempt(fn(): mixed => $store->readGenerationBoundScoreState($logical, null));
        if ($index === 1) {
            $hMutate = hrtime(true);
        }
        $outcomes[$index]['mutate'] = $attempt(fn(): mixed => $store->mutateGenerationBoundScore($logical, null, null, 600, 9));
        $outcomes[$index]['claim'] = $attempt(fn(): mixed => $store->claimPostPunishmentReentry($logical, null, str_repeat('a', 32)));
        $outcomes[$index]['publication'] = $attempt(fn(): mixed => $store->blockWithPunishmentLifecycleTracking($logical, null, 3, str_repeat('a', 32), 2, 60, 600, 2, 600, 86400));
    }
    // --- after the timed part: D for the '-1' key -------------------------
    $key1 = $key('score', 'r1-malformed-generation-1');
    // PTTL and TIME in one MULTI/EXEC so both are read at the same server moment
    // (two separate round trips would understate D by one round trip; PLAN §10).
    $executor->execute(['MULTI']);
    $executor->execute(['PTTL', $key1]);
    $executor->execute(['TIME']);
    [$pttl, $after] = $executor->execute(['EXEC']);
    $pttl = (int) $pttl;
    $hash = $executor->execute(['HGETALL', $key1]);
    $written = ['value' => '8', 'updatedAt' => (string) $now, 'generation' => '-1', 'expiresAt' => (string) ($now + 600)];
    $stored = [];
    for ($j = 0; $j + 1 < count((array) $hash); $j += 2) {
        $stored[$hash[$j]] = $hash[$j + 1];
    }
    ksort($written);
    ksort($stored);
    $intact = $stored === $written && $pttl > 0;
    $expiryMs = (int) $after[0] * 1000 + intdiv((int) $after[1], 1000) + $pttl;

    $anomalies = [];
    foreach ($outcomes as $index => $calls) {
        foreach ($calls as $call => $outcome) {
            $messages[$index . ':' . $call . ':' . $outcome] = ($messages[$index . ':' . $call . ':' . $outcome] ?? 0) + 1;
            if (! str_starts_with($outcome, 'T:') && ! ($index === 1 && $call === 'mutate')) {
                $anomalies[] = $index . ':' . $call . ':' . $outcome;
            }
        }
    }
    writeJsonl($out, [
        'i' => $i,
        't0s' => $now,
        'u0' => (int) $time[1],
        'polls' => $polls,
        'span' => intdiv($hExpire - $h0, 1000),
        'span_m' => intdiv($hMutate - $h0, 1000),
        'm1' => $outcomes[1]['mutate'],
        'D' => $intact ? $expiryMs - ($now + 600) * 1000 : null,
        'intact' => $intact,
        'x' => $anomalies,
    ]);
}
ksort($messages);
writeJsonl($out, ['summary' => true, 'mode' => $mode, 'm' => $m, 'seconds' => round((hrtime(true) - $started) / 1e9, 3), 'php' => PHP_VERSION, 'outcomes' => $messages]);
fclose($out);
echo "h {$mode} m={$m} seconds=" . round((hrtime(true) - $started) / 1e9, 1) . "\n";
