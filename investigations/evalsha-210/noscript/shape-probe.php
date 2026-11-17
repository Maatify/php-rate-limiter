<?php

declare(strict_types=1);

// Reply shapes of ext-redis rawCommand() for the cases the NOSCRIPT study needs (#210, second round). Prints, for each command, whether the
// call returned or threw, the value, and getLastError(), in the order run (so the stickiness of getLastError() is visible).
// Usage: php shape-probe.php <host> <port>   (an isolated Redis; flushes nothing but writes a few keys)
$r = new Redis(); $r->connect($argv[1] ?? '127.0.0.1', (int) ($argv[2] ?? 6379));
echo 'ext-redis ', phpversion('redis'), ' php ', PHP_VERSION, "\n";
function show(Redis $r, string $label, array $cmd): void {
    try { $v = $r->rawCommand(...$cmd); $t = 'returned'; } catch (Throwable $e) { $v = get_class($e) . ': ' . $e->getMessage(); $t = 'threw'; }
    echo str_pad($label, 34), $t, ' ', var_export($v, true), ' | getLastError=', var_export($r->getLastError(), true), "\n";
}
show($r, 'SET ok', ['SET','a','1']);
show($r, 'GET missing (nil)', ['GET','nokey']);
show($r, 'EVALSHA unknown (NOSCRIPT)', ['EVALSHA', str_repeat('0',40), '0']);
show($r, 'GET after error (stale?)', ['GET','a']);
show($r, 'nil after error', ['GET','nokey']);
show($r, 'ERR wrongtype', ['LPUSH','a','x']);
show($r, 'EVAL return false', ['EVAL','return false','0']);
show($r, 'EVAL error_reply NOSCRIPTISH', ['EVAL',"return redis.error_reply('NOSCRIPTISH x')",'0']);
show($r, 'EVAL error_reply NOSCRIPT x', ['EVAL',"return redis.error_reply('NOSCRIPT script said')",'0']);
show($r, 'EVAL runtime error', ['EVAL','return redis.call("LPUSH","a","x")','0']);
show($r, 'EVAL raise error()', ['EVAL','error("boom")','0']);
show($r, 'EVAL int 0', ['EVAL','return 0','0']);
show($r, 'EVAL empty array', ['EVAL','return {}','0']);
show($r, 'EVAL string empty', ['EVAL','return ""','0']);
show($r, 'EVAL unknown code (MY err)', ['EVAL',"return redis.error_reply('MY custom error')",'0']);
show($r, 'SCRIPT FLUSH', ['SCRIPT','FLUSH']);
show($r, 'SCRIPT LOAD', ['SCRIPT','LOAD','return 7']);
show($r, 'EVALSHA ok', ['EVALSHA', sha1('return 7'), '0']);
$r->clearLastError();
echo 'after clearLastError: ', var_export($r->getLastError(), true), "\n";
$r->close(); $r->clearLastError();
show($r, 'INCR after close() (reconnect)', ['INCR','shape-probe-counter']);
