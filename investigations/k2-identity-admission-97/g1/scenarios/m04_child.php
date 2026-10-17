<?php

declare(strict_types=1);

// G1 M04 child — rollback: the S0 runtime continues a CAND state for the device of K5-1 (acctK).
//   rollback <state-file> keep  : the Host still sends the device secret (sessionDeviceId)
//   rollback <state-file> drop  : the Host context is rolled back too (no sessionDeviceId)

require dirname(__DIR__) . '/lib/g1.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

g1Provenance();
if (g1Runtime() !== 'S0') {
    fwrite(STDERR, "m04_child must run on S0\n");
    exit(4);
}
[$_, $mode, $file, $ctxMode] = $argv;
$cell = new G1Cell('S0', 'login', g1Params('R'), state: g1LoadState($file));
$c = $ctxMode === 'keep'
    ? new RateLimitContextDTO('198.51.100.44', FIREFOX_130, 'acctK', null, 'sd-acctK-1', true, [], false, null)
    : new RateLimitContextDTO('198.51.100.44', FIREFOX_130, 'acctK', null, null, false, [], true, null);
$before = g1Snap($cell, $c);
$cell->lab->at(900);
$r = $cell->cmd($c, 'check', 'U');
if ($r['d'] === 'A') {
    $r = $cell->cmd($c, 'fail', 'U');
}
echo json_encode(['context' => $ctxMode, 'K5before' => $before['K5'], 'decision' => $r['d'], 'K5after' => g1Snap($cell, $c)['K5']]), "\n";
