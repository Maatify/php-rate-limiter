<?php

declare(strict_types=1);

// Integer replies of ext-redis rawCommand() by PHP width (#210, second round, F254-01). Run on both PHP builds against the same Redis.
//   php integer-reply-probe.php <host> <port>
// For each stored absolute expiry: what PEXPIRETIME and a script's integer reply return through the native client, and what a script returns
// when it converts the value with tostring(). On PHP_INT_SIZE 4 an integer reply above PHP_INT_MAX comes back as PHP_INT_MAX, silently.
$r = new Redis();
$r->connect($argv[1] ?? '127.0.0.1', (int) ($argv[2] ?? 6379));
echo 'PHP_INT_SIZE ', PHP_INT_SIZE, ' ext-redis ', phpversion('redis'), "\n";
foreach (['4102444800123', '4102444800124', '2147483648000'] as $value) {
    $key = 'integer-reply-probe-' . $value;
    $r->rawCommand('SET', $key, 'x');
    $r->rawCommand('PEXPIREAT', $key, $value);
    $pexpiretime = $r->rawCommand('PEXPIRETIME', $key);
    $script = $r->rawCommand('EVAL', "return redis.call('PEXPIRETIME', KEYS[1])", '1', $key);
    $string = $r->rawCommand('EVAL', "return tostring(redis.call('PEXPIRETIME', KEYS[1]))", '1', $key);
    echo "stored $value: PEXPIRETIME=", var_export($pexpiretime, true), ' script integer=', var_export($script, true), ' script tostring=', var_export($string, true), "\n";
}
