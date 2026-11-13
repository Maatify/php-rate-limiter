<?php

declare(strict_types=1);

// Contention worker for the default retry budget (T-5, DEC-037): public requests against one hot account at a barrier instant.
//   default-budget-worker.php <host> <port> <barrier-unix-float> <requests> <mode: record|check>
// Prints one JSON object: {"requests": int, "conflicts": int, "errors": int, "milliseconds": list<float>}.

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$argv = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
if (count($argv) !== 6) {
    fwrite(STDERR, "usage: default-budget-worker.php <host> <port> <barrier> <requests> <mode>\n");
    exit(2);
}
[, $host, $port, $barrier, $requests, $mode] = array_map(static fn(mixed $value): string => is_string($value) ? $value : '', $argv);

// The store keeps its default retry limit and time budget: that is what is measured.
$limiter = RateLimiterBuilder::fromFullCapabilityStore(
    new RateLimiterConfig('test_secret', 'test_fp', 'prod'),
    new RedisFullCapabilityStore(new RespRedisCommandExecutor($host, (int) $port), 'default-budget'),
    new RecordingFailureSignalEmitter(),
)->withClock(new FixedClock('2026-11-05 12:00:00'))->build();

$context = new RateLimitContextDTO('203.0.113.7', 'Mozilla/5.0 Chrome/120.0', 'acct-hot', null, 'device-hot', false);
$command = $mode === 'check' ? RateLimitCommand::checkOnly('login_protection') : RateLimitCommand::recordFailure('login_protection');

while (microtime(true) < (float) $barrier) {
    usleep(100);
}
$conflicts = 0;
$errors = 0;
$milliseconds = [];
for ($i = 0; $i < (int) $requests; $i++) {
    $started = microtime(true);
    try {
        $result = $limiter->limit($context, $command);
        if (($result->metadata->cause ?? null) === 'k4_concurrency_conflict') {
            $conflicts++;
        }
    } catch (Throwable) {
        $errors++;
    }
    $milliseconds[] = (microtime(true) - $started) * 1000;
}
echo json_encode(['requests' => (int) $requests, 'conflicts' => $conflicts, 'errors' => $errors, 'milliseconds' => $milliseconds], JSON_THROW_ON_ERROR);
