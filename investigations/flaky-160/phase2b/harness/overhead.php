<?php

declare(strict_types=1);

// #160 phase 2b — per-call cost of the recorder (PLAN.md §5.3, §8 LT-160C-04), measured once.
//   (a) --calls recorded checkBlockAt calls against --calls calls of the same method on the unrecorded Inner class
//       (same Redis, interleaved in blocks of 1,000, so both see the same load);
//   (b) --calls Rec::begin/Rec::end pairs with no Redis at all (the recorder's own work).
// Prints one JSON object.   php overhead.php --root=<repo> --port=<redis port> --calls=100000 --dir=<out dir>

$o = getopt('', ['root:', 'port:', 'calls:', 'dir:']);
putenv('FLAKY160B_DIR=' . $o['dir']);
require __DIR__ . '/w1_bootstrap.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/fixture.php';

use Flaky160B\{Fixture, Rec};
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStoreInner;

$calls = (int) $o['calls'];
$e = Fixture::executor((int) $o['port']);
$ns = 'it-overhead-' . bin2hex(random_bytes(6));
$rec = new RedisFullCapabilityStore($e, $ns);
$bare = new RedisFullCapabilityStoreInner($e, $ns);
$now = Fixture::redisNow($e);
$key = str_repeat('a', 64);
$tRec = 0;
$tBare = 0;
$block = 1000;
for ($i = 0; $i < intdiv($calls, $block); $i++) {
    $t = hrtime(true);
    for ($j = 0; $j < $block; $j++) {
        $bare->checkBlockAt($key, $now);
    }
    $tBare += hrtime(true) - $t;
    $t = hrtime(true);
    for ($j = 0; $j < $block; $j++) {
        $rec->checkBlockAt($key, $now);
    }
    $tRec += hrtime(true) - $t;
    Rec::$buf = [];
}
Rec::$buf = [];
$t = hrtime(true);
for ($i = 0; $i < $calls; $i++) {
    Rec::end(Rec::begin('checkBlockAt', [$key, $now]), null);
    if (($i & 1023) === 0) {
        Rec::$buf = [];
    }
}
$tMicro = hrtime(true) - $t;
Rec::$buf = [];
Fixture::cleanup($e, $ns);
echo json_encode([
    'calls' => $calls,
    'redis_call_unrecorded_us' => round($tBare / $calls / 1000, 2), 'redis_call_recorded_us' => round($tRec / $calls / 1000, 2),
    'recorder_added_us_per_call' => round(($tRec - $tBare) / $calls / 1000, 2), 'ratio_recorded_to_unrecorded' => round($tRec / $tBare, 3),
    'recorder_only_us_per_call_no_redis' => round($tMicro / $calls / 1000, 2),
]), "\n";
