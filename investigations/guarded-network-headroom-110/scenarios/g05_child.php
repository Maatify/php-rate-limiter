<?php

declare(strict_types=1);

// Child process of g05_redis.php: ONE sequence of checkOnly requests on real Redis with the runtime selected by LAB_ROOT / GNH_ARM
// (provenance enforced), on the namespace written by the parent. Used to run the REAL unpatched S0 code on Redis state written by a
// candidate (rollback / mixed version). Same ownership checks as the parent except emptiness. Prints one JSON line:
// {"arm": ..., "steps": [...], "error": null | "<class>: <message>"}.
//   php g05_child.php <namespace> <json [[ip, fp|null, acct, uaIdx], ...]>
require dirname(__DIR__, 2) . '/workstream-b-anti-evasion/lib/redis.php';
require dirname(__DIR__) . '/lib/gnh.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

[, $ns, $json] = $argv + array_fill(0, 3, null);
$prov = gnhProvenance();
$ex = wsbRedisConnectVerified(false);
$rt = wsbRedisPipeline($ex, (string) $ns);
$steps = [];
$error = null;
try {
    foreach (json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR) as [$ip, $fp, $acct, $ua]) {
        $steps[] = wsbRedisRun($rt, new RateLimitContextDTO($ip, k1Ua((int) $ua), $acct, $fp === null ? null : fp((string) $fp)), 'check');
    }
} catch (\Throwable $e) {
    $error = get_class($e) . ': ' . $e->getMessage();
}
echo json_encode(['arm' => $prov['arm'], 'steps' => $steps, 'error' => $error], JSON_THROW_ON_ERROR), "\n";
