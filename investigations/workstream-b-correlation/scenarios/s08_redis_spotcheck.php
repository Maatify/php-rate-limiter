<?php

declare(strict_types=1);

// B-F04 — real Redis spot-check of SEMANTICS (counts, decisions, bounded sizes, TTL classes).
// Redis owns time (DEC-016) so the clock is NOT controllable here: only immediate-sequence behaviour is asserted.
// Runs only against the dedicated, empty redis-server instance that run.sh starts and proves it owns (see SAFETY below).
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
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

// SAFETY (F103-01): this script NEVER reads REDIS_HOST/REDIS_PORT and NEVER issues FLUSHDB/FLUSHALL/CONFIG SET/DEBUG.
// It connects to 127.0.0.1 only, on the port that run.sh passes via WSB_REDIS_PORT, and refuses to touch the server unless
// run.sh proves it owns it: the server's own process_id must equal WSB_REDIS_PID (pid file written by the instance run.sh
// started), its working dir must equal WSB_REDIS_DIR (a directory run.sh created), and it must be completely empty (DBSIZE 0).
// Every scenario uses its own key namespace instead of flushing.
$port = (int) (getenv('WSB_REDIS_PORT') ?: '0');
$expectedPid = getenv('WSB_REDIS_PID') ?: '';
$expectedDir = getenv('WSB_REDIS_DIR') ?: '';
if ($port < 1024 || $port > 65535 || $expectedPid === '' || $expectedDir === '') {
    fwrite(STDERR, "refusing: WSB_REDIS_PORT / WSB_REDIS_PID / WSB_REDIS_DIR are set only by run.sh for the instance it started\n");
    exit(3);
}
$ex = new RespRedisCommandExecutor('127.0.0.1', $port);
$info = (string) $ex->execute(['INFO', 'server']);
if (preg_match('/^process_id:(\d+)/m', $info, $mm) !== 1 || $mm[1] !== $expectedPid) {
    fwrite(STDERR, "refusing: server process_id does not match the instance run.sh started\n");
    exit(3);
}
$cfg = $ex->execute(['CONFIG', 'GET', 'dir']);
$dir = is_array($cfg) ? (string) ($cfg[1] ?? '') : '';
if (realpath($dir) === false || realpath($dir) !== realpath($expectedDir)) {
    fwrite(STDERR, "refusing: server working directory does not match the directory run.sh created\n");
    exit(3);
}
if ((int) $ex->execute(['DBSIZE']) !== 0) {
    fwrite(STDERR, "refusing: server is not empty\n");
    exit(3);
}

// Redis owns time (DEC-016). The pipeline clock reads the SAME authority (Redis TIME) instead of a frozen/PHP clock, which
// removes the harness-induced skew that made snapshot-expiry validation fail when a wall-clock second ticked mid-run.
$clock = new class ($ex) implements \Maatify\SharedCommon\Contracts\ClockInterface {
    public function __construct(private readonly RespRedisCommandExecutor $ex)
    {
    }

    public function now(): \DateTimeImmutable
    {
        $t = $this->ex->execute(['TIME']);

        return (new \DateTimeImmutable('@' . (int) $t[0]))->setTimezone($this->getTimezone());
    }

    public function sleep(int $seconds): void
    {
    }

    public function getTimezone(): \DateTimeZone
    {
        return new \DateTimeZone('UTC');
    }
};
$resolver = new DeviceIdentityResolver(new FingerprintHasher(Lab::SECRET));
$policy = new LoginProtectionPolicy();
$pipeline = null;
$use = static function (string $ns) use ($ex, $clock, &$pipeline): void {
    $store = new RedisFullCapabilityStore($ex, $ns);
    $pipeline = new EvaluationPipeline($store, $store, new BudgetTracker($store, $clock), new AntiEquilibriumGate($store), new DecayCalculator($clock), new EphemeralBucket($store), Lab::SECRET, 'prod', $clock);
};

$run = static function (RateLimitContextDTO $c, string $cmd) use (&$pipeline, $policy, $resolver): string {
    $command = match ($cmd) {
        'check' => RateLimitCommand::checkOnly('login_protection'),
        'ok' => RateLimitCommand::recordSuccess('login_protection'),
        'fail' => RateLimitCommand::recordFailure('login_protection'),
    };
    $r = $pipeline->process($policy, $c, $command, $resolver->resolve($c));

    // retryAfter is derived from Redis time; normalise to the 59..60 band so output is stable.
    return $cmd . ':' . ($r->decision === 'ALLOW' ? 'A' : 'H' . $r->blockLevel . '/' . ($r->retryAfter >= 59 ? '~60' : (string) $r->retryAfter));
};

$use('wsb:churn');
$rows = [];
foreach (['A', 'B', 'A'] as $cl) {
    $c = new RateLimitContextDTO(NAT, CHROME, "acct$cl", fp($cl));
    $rows[] = $cl . ': ' . $run($c, 'check') . ' > ' . $run($c, 'ok');
}
emit('F04.redis.churn-A-B-A', ['attempts' => $rows]);

$use('wsb:dilution');
$rows = [];
for ($i = 1; $i <= 7; $i++) {
    $c = new RateLimitContextDTO("198.51.100.$i", CHROME, "user$i");
    $rows[] = "u$i: " . $run($c, 'check') . ' > ' . $run($c, 'ok');
}
emit('F04.redis.low-dilution', ['attempts' => $rows]);

// Record-only flow on Redis: the 6th LOW client is blocked by recordSuccess alone.
$use('wsb:dilution-record-only');
$rows = [];
for ($i = 1; $i <= 7; $i++) {
    $c = new RateLimitContextDTO("198.51.100.$i", CHROME, null);
    $rows[] = "u$i: " . $run($c, 'ok');
}
emit('F04.redis.low-dilution.record-only', ['attempts' => $rows]);

// Bounded state + TTL classes straight from Redis.
$use('wsb:bounded');
$before = array_map('strval', (array) $ex->execute(['KEYS', '*']));
for ($i = 1; $i <= 60; $i++) {
    $c = new RateLimitContextDTO('10.9.' . $i . '.1', CHROME, "u$i", fp('same'));
    $run($c, 'check');
}
$maxSet = 0;
$ttls = [];
foreach (array_diff(array_map('strval', (array) $ex->execute(['KEYS', '*'])), $before) as $k) {
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
