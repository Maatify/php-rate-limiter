<?php

declare(strict_types=1);

// G1 M02 child — DV-6 old worker (S0 runtime): continues the CAND state and serves the proven owner of A (no lanes on S0).

require dirname(__DIR__) . '/lib/g1.php';
require_once __DIR__ . '/../lib/g1.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

g1Provenance();
if (g1Runtime() !== 'S0') {
    fwrite(STDERR, "m02_child must run on S0\n");
    exit(4);
}
$cell = new G1Cell('S0', 'login', g1Params('R'), state: g1LoadState($argv[1]));
$c = new RateLimitContextDTO('203.0.113.77', CHROME, 'acctA', null, 'sd-acctA-dev1', true, [], false, null);
$trace = [];
for ($k = 0; $k < 6; $k++) {
    $cell->lab->at(400 + 30 * $k);
    $r = $cell->cmd($c, 'check', 'U');
    if ($r['d'] === 'A') {
        $r = $cell->cmd($c, 'fail', 'U');
    }
    $trace[] = $r['d'];
}
echo json_encode(['trace' => implode('', $trace), 'snap' => g1Snap($cell, $c)]), "\n";
