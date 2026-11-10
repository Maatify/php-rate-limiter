<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Investigations\AdmissionConcurrency185;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Redis;

/**
 * Deterministic measurement harness for in-flight admission concurrency envelopes (#185, LT-185-37a, PR-Q).
 *
 * Measures:
 * 1. U-lane concurrency: net failure set (ufail) under in-flight permits from the same source IP.
 * 2. P-lane concurrency (Case 1): single proof credential under concurrent in-flight permits.
 * 3. P-lane concurrency (Case 2): multiple distinct proofs & accounts from the same source IP.
 */

$port = (int) ($argv[1] ?? 6398);
$host = '127.0.0.1';

$executor = new RespRedisCommandExecutor($host, $port);
$redis = new Redis();
$redis->connect($host, $port);

echo "# Admission In-Flight Concurrency Measurements (#185, LT-185-37a)\n\n";

// --- 1. U-lane Concurrency ---
echo "## 1. U-lane In-Flight Concurrency (source_capacity = 24, source_failure_cap = 4)\n\n";
echo "| Concurrency | Admitted | Refused (Cause) | Final ufail ZCARD | Subsequent Request Decision (Cause) |\n";
echo "| :---: | :---: | :--- | :---: | :--- |\n";

foreach ([1, 4, 10, 20, 24, 25, 30] as $concurrency) {
    $redis->flushAll();
    $ns = 'conc_u_' . bin2hex(random_bytes(4));
    $store = new RedisFullCapabilityStore($executor, $ns);
    $lab = new EngineLab(budgets: EngineLab::budgets(), admissionInner: $store);

    $ip = '198.51.100.50';
    $permits = [];
    $admitted = 0;
    $refusedCount = 0;
    $refusedCause = 'none';

    for ($i = 0; $i < $concurrency; $i++) {
        $account = "user-u-$i";
        $ctx = $lab->context($account, $ip);
        $res = $lab->check($ctx);
        if ($res->decision === 'ALLOW' && $res->metadata?->permit !== null) {
            $permits[] = [$res->metadata->permit, $ctx];
            $admitted++;
        } else {
            $refusedCount++;
            $refusedCause = $res->metadata?->cause ?? 'unknown';
        }
    }

    $refusedSummary = $refusedCount === 0 ? '0' : "$refusedCount ($refusedCause)";

    // All admitted in-flight permits record failure
    foreach ($permits as [$token, $ctx]) {
        $lab->outcome($ctx, false, $token);
    }

    $keys = $redis->keys('*:adm:s:*');
    $zcard = 0;
    foreach ($keys as $k) {
        $zcard = max($zcard, (int) $redis->zCard($k));
    }

    // Subsequent request from same IP after failures committed
    $subCtx = $lab->context("user-u-subsequent", $ip);
    $subRes = $lab->check($subCtx);
    $subSummary = "{$subRes->decision} (" . ($subRes->metadata?->cause ?? 'none') . ")";

    echo "| $concurrency | $admitted | $refusedSummary | $zcard | $subSummary |\n";
}

// --- 2. P-lane Concurrency (Case 1: Single Proof) ---
echo "\n## 2. P-lane In-Flight Concurrency: Single Proof (proof_proof_window = 6, pfail cap = 4)\n\n";
echo "| Concurrency | Admitted | Refused (Cause) | Final pfail ZCARD | Subsequent Request Decision (Cause) |\n";
echo "| :---: | :---: | :--- | :---: | :--- |\n";

foreach ([1, 4, 6, 8, 10, 20] as $concurrency) {
    $redis->flushAll();
    $ns = 'conc_p1_' . bin2hex(random_bytes(4));
    $store = new RedisFullCapabilityStore($executor, $ns);
    $lab = new EngineLab(budgets: EngineLab::budgets(), admissionInner: $store);

    $ip = '198.51.100.50';
    $account = 'shared-account';
    $inst = 'inst-shared';
    $cred = $lab->mint($account, $inst);

    $permits = [];
    $admitted = 0;
    $refusedCount = 0;
    $refusedCause = 'none';

    for ($i = 0; $i < $concurrency; $i++) {
        $ctx = $lab->context($account, $ip, instance: $inst, credential: $cred);
        $res = $lab->check($ctx);
        if ($res->decision === 'ALLOW' && $res->metadata?->permit !== null) {
            $permits[] = [$res->metadata->permit, $ctx];
            $admitted++;
        } else {
            $refusedCount++;
            $refusedCause = $res->metadata?->cause ?? 'unknown';
        }
    }

    $refusedSummary = $refusedCount === 0 ? '0' : "$refusedCount ($refusedCause)";

    foreach ($permits as [$token, $ctx]) {
        $lab->outcome($ctx, false, $token);
    }

    $keys = $redis->keys('*:adm:s:*');
    $zcard = 0;
    foreach ($keys as $k) {
        $zcard = max($zcard, (int) $redis->zCard($k));
    }

    $subCtx = $lab->context($account, $ip, instance: $inst, credential: $cred);
    $subRes = $lab->check($subCtx);
    $subSummary = "{$subRes->decision} (" . ($subRes->metadata?->cause ?? 'none') . ")";

    echo "| $concurrency | $admitted | $refusedSummary | $zcard | $subSummary |\n";
}

// --- 3. P-lane Concurrency (Case 2: Distinct Proofs & Accounts from Same IP) ---
echo "\n## 3. P-lane In-Flight Concurrency: Distinct Proofs & Accounts from Same IP (pfail cap = 4)\n\n";
echo "| Concurrency | Admitted | Refused (Cause) | Final pfail ZCARD | Subsequent Request Decision (Cause) |\n";
echo "| :---: | :---: | :--- | :---: | :--- |\n";

foreach ([1, 4, 10, 20, 24, 25, 30] as $concurrency) {
    $redis->flushAll();
    $ns = 'conc_p2_' . bin2hex(random_bytes(4));
    $store = new RedisFullCapabilityStore($executor, $ns);
    $lab = new EngineLab(budgets: EngineLab::budgets(), admissionInner: $store);

    $ip = '198.51.100.50';
    $permits = [];
    $admitted = 0;
    $refusedCount = 0;
    $refusedCause = 'none';

    for ($i = 0; $i < $concurrency; $i++) {
        $account = "user-p2-$i";
        $inst = "inst-$i";
        $cred = $lab->mint($account, $inst);
        $ctx = $lab->context($account, $ip, instance: $inst, credential: $cred);
        $res = $lab->check($ctx);
        if ($res->decision === 'ALLOW' && $res->metadata?->permit !== null) {
            $permits[] = [$res->metadata->permit, $ctx];
            $admitted++;
        } else {
            $refusedCount++;
            $refusedCause = $res->metadata?->cause ?? 'unknown';
        }
    }

    $refusedSummary = $refusedCount === 0 ? '0' : "$refusedCount ($refusedCause)";

    foreach ($permits as [$token, $ctx]) {
        $lab->outcome($ctx, false, $token);
    }

    $keys = $redis->keys('*:adm:s:*');
    $zcard = 0;
    foreach ($keys as $k) {
        $zcard = max($zcard, (int) $redis->zCard($k));
    }

    // Subsequent request from a 31st proof on the same IP
    $subAccount = "user-p2-subsequent";
    $subInst = "inst-subsequent";
    $subCred = $lab->mint($subAccount, $subInst);
    $subCtx = $lab->context($subAccount, $ip, instance: $subInst, credential: $subCred);
    $subRes = $lab->check($subCtx);
    $subSummary = "{$subRes->decision} (" . ($subRes->metadata?->cause ?? 'none') . ")";

    echo "| $concurrency | $admitted | $refusedSummary | $zcard | $subSummary |\n";
}
