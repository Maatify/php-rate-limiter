<?php

declare(strict_types=1);

// Separate PHP process control (#262): a fresh process builds its own runtime and makes N fallback calls; it prints one JSON line.
// usage: php child.php <environmentScope> <policyName> <calls>
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Maatify\RateLimiter\Tests\Support\Study262\FallbackStudyRuntime;

[, $env, $policy, $calls] = $argv;
$runtime = FallbackStudyRuntime::build($env, $policy, 'typed');
$decisions = [];
for ($i = 0; $i < (int) $calls; $i++) {
    $decisions[] = $runtime->limit()->decision;
}
echo json_encode(['pid' => getmypid(), 'int' => PHP_INT_SIZE, 'decisions' => $decisions, 'state' => FallbackStudyRuntime::fallbackState()]), "\n";
