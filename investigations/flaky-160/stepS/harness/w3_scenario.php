<?php

declare(strict_types=1);

// #160 step S — the identity scenario of w3_check.php: single-process, in-memory store, no Redis. Prints the list of
// results returned by RateLimiterEngine::limit, as plain tuples. With --instrumented=1 W3 is loaded first.
//   php w3_scenario.php --root=<tree> --instrumented=<0|1>
$o = getopt('', ['root:', 'instrumented:']);
$root = (string) $o['root'];
if ((string) $o['instrumented'] === '1') {
    putenv('FLAKY160_ROOT=' . $root);
    require __DIR__ . '/w3_bootstrap.php';
} else {
    require $root . '/vendor/autoload.php';
}

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Service\{AntiEquilibriumGate, BudgetTracker, CircuitBreaker, DecayCalculator, DeviceIdentityResolver, EphemeralBucket, EvaluationPipeline, FailureModeResolver, FingerprintHasher, RateLimiterEngine};
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;

$clock = new FixedClock();
$store = new InMemoryRateLimitStore($clock);
$correlation = new StatefulInMemoryCorrelationStore($clock);
$signals = new RecordingFailureSignalEmitter();
$pipeline = new EvaluationPipeline($store, $correlation, new BudgetTracker($store, $clock), new AntiEquilibriumGate($correlation), new DecayCalculator($clock), new EphemeralBucket($correlation), 'test_secret', 'prod', $clock, null);
$engine = new RateLimiterEngine(new DeviceIdentityResolver(new FingerprintHasher('test_secret')), $pipeline, new CircuitBreaker(new InMemoryCircuitBreakerStore(), $signals, $clock), new FailureModeResolver(), $signals, $clock, [new LoginProtectionPolicy(), new OtpProtectionPolicy()]);

$ctx = new RateLimitContextDTO('198.51.100.10', 'Mozilla/5.0 lifecycle', 'w3-account', ['device' => 'w3-device']);
$out = [];
$run = static function (string $policy, string $kind) use ($engine, $ctx, &$out): void {
    $cmd = $kind === 'failure' ? RateLimitCommand::recordFailure($policy) : ($kind === 'success' ? RateLimitCommand::recordSuccess($policy) : RateLimitCommand::checkOnly($policy));
    $r = $engine->limit($ctx, $cmd);
    $out[] = [$r->decision, $r->blockLevel, $r->retryAfter, $r->failureMode, $r->metadata?->cause, $r->metadata?->context?->reason];
};
foreach (['failure', 'failure', 'failure', 'failure', 'check'] as $k) {
    $run('login_protection', $k);
}
$clock->setNow($clock->now()->modify('+601 seconds'));
foreach (['check', 'success', 'failure', 'failure'] as $k) {
    $run('login_protection', $k);
}
foreach (['failure', 'failure', 'check'] as $k) {
    $run('otp_protection', $k);
}
echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
