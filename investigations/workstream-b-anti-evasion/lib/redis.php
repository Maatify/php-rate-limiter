<?php

declare(strict_types=1);

// Real-Redis helpers for Workstream B / Anti-Evasion. SAFETY (inherits F103-01 of PR #103):
//  - never reads REDIS_HOST / REDIS_PORT; connects to 127.0.0.1 only, on the port run.sh passes via WSB_REDIS_PORT;
//  - never issues FLUSHDB / FLUSHALL / CONFIG SET / DEBUG / SHUTDOWN / KEYS on anything but its own namespace views;
//  - refuses unless the server's process_id equals WSB_REDIS_PID (pid file of the instance run.sh started), its working directory
//    equals WSB_REDIS_DIR (a directory run.sh created) and — for the PARENT process only — it is completely empty (DBSIZE 0).
//  - every scenario uses its own key namespace instead of flushing.
require dirname(__DIR__) . '/lib/wsb.php';

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

function wsbRedisConnectVerified(bool $requireEmpty): RespRedisCommandExecutor
{
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
    if ($requireEmpty && (int) $ex->execute(['DBSIZE']) !== 0) {
        fwrite(STDERR, "refusing: server is not empty\n");
        exit(3);
    }

    return $ex;
}

/** Redis owns time (DEC-016): the pipeline clock reads the SAME authority as the store. */
function wsbRedisClock(RespRedisCommandExecutor $ex): \Maatify\SharedCommon\Contracts\ClockInterface
{
    return new class ($ex) implements \Maatify\SharedCommon\Contracts\ClockInterface {
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
}

/** @return array{pipeline: EvaluationPipeline, store: RedisFullCapabilityStore, resolver: DeviceIdentityResolver, policy: LoginProtectionPolicy} */
function wsbRedisPipeline(RespRedisCommandExecutor $ex, string $namespace): array
{
    $clock = wsbRedisClock($ex);
    $store = new RedisFullCapabilityStore($ex, $namespace);
    $pipeline = new EvaluationPipeline($store, $store, new BudgetTracker($store, $clock), new AntiEquilibriumGate($store), new DecayCalculator($clock), new EphemeralBucket($store), Lab::SECRET, 'prod', $clock);

    return ['pipeline' => $pipeline, 'store' => $store, 'resolver' => new DeviceIdentityResolver(new FingerprintHasher(Lab::SECRET)), 'policy' => new LoginProtectionPolicy()];
}

/** @param array{pipeline: EvaluationPipeline, store: RedisFullCapabilityStore, resolver: DeviceIdentityResolver, policy: LoginProtectionPolicy} $rt */
function wsbRedisRun(array $rt, \Maatify\RateLimiter\DTO\RateLimitContextDTO $c, string $cmd): string
{
    $name = 'login_protection';
    $command = match ($cmd) {
        'check' => RateLimitCommand::checkOnly($name),
        'ok' => RateLimitCommand::recordSuccess($name),
        'fail' => RateLimitCommand::recordFailure($name),
    };
    $r = $rt['pipeline']->process($rt['policy'], $c, $command, $rt['resolver']->resolve($c));

    return $cmd . ':' . ($r->decision === 'ALLOW' ? 'A' : 'H' . $r->blockLevel . '/' . ($r->retryAfter >= 59 ? '~60' : (string) $r->retryAfter));
}
