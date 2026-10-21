<?php

declare(strict_types=1);

/*
 * #153 question 4 (PLAN.md §7.4, LT-153-05): what leaves limit() when the
 * validator rejects a snapshot. argv: package root, env, host, port, output file.
 * Prints each assertion; exits 1 on the first failure.
 */

namespace Maatify\RateLimiter\Investigations\ClockSkew153;

use Maatify\RateLimiter\Exception\RateLimiterException;

[$self, $root, $env, $host, $port, $outFile] = $argv + [null, null, null, null, null, null];
require $root . '/vendor/autoload.php';
require __DIR__ . '/lib.php';

$lines = [];
$fail = static function (string $line) use (&$lines, $outFile): never {
    $lines[] = 'FAIL ' . $line;
    file_put_contents($outFile, implode("\n", $lines) . "\n");
    fwrite(STDERR, 'FAIL ' . $line . "\n");
    exit(1);
};
$pass = static function (string $line) use (&$lines): void {
    $lines[] = 'PASS ' . $line;
    echo 'PASS ', $line, "\n";
};

$expectedException = RateLimiterException::class . ': ' . VALIDATOR_MESSAGE;
foreach (POLICY_NAMES as $policyId => $policy) {
    $lab = new Lab($host, (int) $port, 'c153-' . bin2hex(random_bytes(4)) . '-' . $env . '-host-' . $policyId, 'ALIGN-BEHIND1', 0.0);
    $runtime = $lab->runtime(baseConfig());
    $circuitBefore = json_encode($lab->store->load($policy));
    $returned = 0;
    $wrong = [];
    for ($i = 0; $i < 100; $i++) {
        $record = $lab->request($runtime, $policy, context('H-' . $i, 'd' . $i, 90_000 + $i));
        if ($record['outcome'] !== 'EXCEPTION') {
            $returned++;
        } elseif ($record['exception'] !== $expectedException) {
            $wrong[] = $record['exception'];
        }
    }
    $circuitAfter = json_encode($lab->store->load($policy));
    $emitted = count($lab->emitter->getEmitted());

    // H-1: the throwable is exactly RateLimiterException (get_class, so no subclass), with the validator's message.
    $wrong === [] && $returned === 0
        ? $pass("{$env} {$policy} H-1 100/100 threw exactly {$expectedException}")
        : $fail("{$env} {$policy} H-1 returned={$returned} other=" . json_encode(array_slice($wrong, 0, 3)));
    // H-2: no failure signal.
    $emitted === 0 ? $pass("{$env} {$policy} H-2 failure signals emitted: 0") : $fail("{$env} {$policy} H-2 failure signals emitted: {$emitted}");
    // H-3: circuit-breaker state unchanged.
    $circuitBefore === $circuitAfter
        ? $pass("{$env} {$policy} H-3 circuit state unchanged: {$circuitAfter}")
        : $fail("{$env} {$policy} H-3 circuit state before {$circuitBefore} after {$circuitAfter}");
    // H-4: no result DTO (no fallback decision): limit() never returned.
    $returned === 0 ? $pass("{$env} {$policy} H-4 limit() returned a result: 0/100") : $fail("{$env} {$policy} H-4 limit() returned a result: {$returned}/100");
}

file_put_contents($outFile, implode("\n", $lines) . "\n");
