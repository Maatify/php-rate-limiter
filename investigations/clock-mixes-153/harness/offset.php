<?php

declare(strict_types=1);

/*
 * #153 LT-153-07 clock offset probe (PLAN §7.1 V-2, as phase 1 PLAN §4.3):
 * 1,000 TIME round trips on a dedicated connection.
 * argv: package root, Redis host, Redis port, output file.
 */

namespace Maatify\RateLimiter\Investigations\ClockMixes153;

use Maatify\RateLimiter\Investigations\ClockSkew153 as P1;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

[$self, $root, $host, $port, $file] = $argv;
require $root . '/vendor/autoload.php';
require $root . '/investigations/clock-skew-153/harness/lib.php';

$time = new P1\RedisTime(new RespRedisCommandExecutor($host, (int) $port));
$handle = fopen($file, 'wb');
for ($i = 0; $i < 1000; $i++) {
    [$send, $redis, $recv] = $time->probe();
    P1\writeRow($handle, ['send' => round($send, 6), 'redis' => $redis, 'recv' => round($recv, 6)]);
}
fclose($handle);
