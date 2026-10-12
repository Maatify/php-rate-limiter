<?php

declare(strict_types=1);

// B-F04 — real Redis spot-check of SEMANTICS (counts, decisions, bounded sizes, TTL classes).
// Redis owns time (DEC-016) so the clock is NOT controllable here: only immediate-sequence behaviour is asserted.
// Run against a dedicated redis-server instance (run.sh starts one on REDIS_PORT); FLUSHDB is issued on it.
require dirname(__DIR__) . '/lib/bootstrap.php';

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

$host = getenv('REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('REDIS_PORT') ?: '6399');
$ex = new RespRedisCommandExecutor($host, $port);
$ex->execute(['FLUSHDB']);

$clock = new FixedClock('@' . time());
$store = new RedisFullCapabilityStore($ex, 'study:b');
$pipeline = new EvaluationPipeline($store, $store, new BudgetTracker($store, $clock), new AntiEquilibriumGate($store), new DecayCalculator($clock), new EphemeralBucket($store), Lab::SECRET, 'prod', $clock);
$resolver = new DeviceIdentityResolver(new FingerprintHasher(Lab::SECRET));
$policy = new LoginProtectionPolicy();

$run = static function (RateLimitContextDTO $c, string $cmd) use ($pipeline, $policy, $resolver): string {
    $command = match ($cmd) {
        'check' => RateLimitCommand::checkOnly('login_protection'),
        'ok' => RateLimitCommand::recordSuccess('login_protection'),
        'fail' => RateLimitCommand::recordFailure('login_protection'),
    };
    $r = $pipeline->process($policy, $c, $command, $resolver->resolve($c));

    // retryAfter is derived from Redis time; normalise to the 59..60 band so output is stable.
    return $cmd . ':' . ($r->decision === 'ALLOW' ? 'A' : 'H' . $r->blockLevel . '/' . ($r->retryAfter >= 59 ? '~60' : (string) $r->retryAfter));
};

$rows = [];
foreach (['A', 'B', 'A'] as $cl) {
    $c = new RateLimitContextDTO(NAT, CHROME, "acct$cl", fp($cl));
    $rows[] = $cl . ': ' . $run($c, 'check') . ' > ' . $run($c, 'ok');
}
emit('F04.redis.churn-A-B-A', ['attempts' => $rows]);

$ex->execute(['FLUSHDB']);
$rows = [];
for ($i = 1; $i <= 7; $i++) {
    $c = new RateLimitContextDTO("198.51.100.$i", CHROME, "user$i");
    $rows[] = "u$i: " . $run($c, 'check') . ' > ' . $run($c, 'ok');
}
emit('F04.redis.low-dilution', ['attempts' => $rows]);

// Record-only flow on Redis: the 6th LOW client is blocked by recordSuccess alone.
$ex->execute(['FLUSHDB']);
$rows = [];
for ($i = 1; $i <= 7; $i++) {
    $c = new RateLimitContextDTO("198.51.100.$i", CHROME, null);
    $rows[] = "u$i: " . $run($c, 'ok');
}
emit('F04.redis.low-dilution.record-only', ['attempts' => $rows]);

// Bounded state + TTL classes straight from Redis.
$ex->execute(['FLUSHDB']);
for ($i = 1; $i <= 60; $i++) {
    $c = new RateLimitContextDTO('10.9.' . $i . '.1', CHROME, "u$i", fp('same'));
    $run($c, 'check');
}
$maxSet = 0;
$ttls = [];
foreach ($ex->execute(['KEYS', '*']) as $k) {
    $type = (string) $ex->execute(['TYPE', $k]);
    $ttl = (int) $ex->execute(['TTL', $k]);
    foreach ([60, 600, 900, 1800, 21600] as $known) {
        if (abs($ttl - $known) <= 2) {
            $ttl = $known;
            break;
        }
    }
    $ttls[$ttl] = true;
    if ($type === 'set') {
        $maxSet = max($maxSet, (int) $ex->execute(['SCARD', $k]));
    }
}
ksort($ttls);
emit('F04.redis.bounded-state', ['largestSetCardinality' => $maxSet, 'dilutionCap' => 6, 'ttlClassesSeconds' => array_keys($ttls)]);
