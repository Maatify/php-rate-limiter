<?php

declare(strict_types=1);

// HISTORICAL (the study, PR #269 at 127290f4): this script needs `tests/Support/Redis/AclPreflightCommitExecutor.php`, the study variant, which the
// production implementation (DEC-042) replaced and which no longer exists on `main`; run it at 127290f4. For the implementation the same measurement
// is `bench-impl.php` (base tree against fix tree).
// #261 study: what the study variant's preflight (redis.acl_check_cmd before the first write) costs the request save.
// usage: php bench.php <redis host> <redis port> <requests per arm>   (one process, nothing else running; alternating arms ABAB)

require __DIR__ . '/../../vendor/autoload.php';

use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\AclPreflightCommitExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

$host = $argv[1];
$port = (int) $argv[2];
$count = (int) ($argv[3] ?? 300);

$timed = static function (RedisCommandExecutorInterface $inner, array &$commitNanos): RedisCommandExecutorInterface {
    return new class ($inner, $commitNanos) implements RedisCommandExecutorInterface {
        /** @param list<float> $commitNanos */
        public function __construct(private readonly RedisCommandExecutorInterface $inner, private array &$commitNanos) {}

        public function execute(array $command): mixed
        {
            if (! OverlayWire::isCommit($command)) {
                return $this->inner->execute($command);
            }
            $start = hrtime(true);
            $reply = $this->inner->execute($command);
            $this->commitNanos[] = (float) (hrtime(true) - $start);

            return $reply;
        }
    };
};

$resp = new RespRedisCommandExecutor($host, $port, RespRedisCommandExecutor::MODE_EVAL);
$resp->execute(['FLUSHALL']);
$baseNanos = [];
$variantNanos = [];
$base = new RedisFullCapabilityStore($timed($resp, $baseNanos), 'bench-261');
$variant = new RedisFullCapabilityStore(new AclPreflightCommitExecutor($timed($resp, $variantNanos)), 'bench-261');
$now = time();
$run = static function (RedisFullCapabilityStore $store, int $i) use ($now): void {
    $store->transactRequest(static function () use ($store, $now, $i): void {
        $store->incrementAt('score-' . ($i % 20), 600, 1, $now);
        $store->incrementBudgetAt('budget-' . ($i % 20), 3600, 1, $now);
        $store->addDistinctAt('distinct-' . ($i % 20), 'item-' . $i, 600, $now);
        $store->incrementWatchFlagAt('watch-' . ($i % 20), 600, $now);
        $store->addDistinctBoundedAt('bounded-' . ($i % 20), 'item-' . $i, 600, 100, $now);
    });
};
for ($i = 0; $i < 50; $i++) {
    $run($base, $i);
    $run($variant, $i);
}
$baseNanos = [];
$variantNanos = [];
for ($i = 0; $i < $count; $i++) {
    $run($base, $i);
    $run($variant, $i);
}
$stat = static function (array $nanos): string {
    sort($nanos);
    $n = count($nanos);

    return sprintf('n=%d p50=%.0f us p95=%.0f us mean=%.0f us', $n, $nanos[intdiv($n, 2)] / 1000, $nanos[(int) floor($n * 0.95)] / 1000, array_sum($nanos) / $n / 1000);
};
echo 'PHP ', PHP_VERSION, ' INT=', PHP_INT_SIZE, "\n";
echo 'commit without preflight: ', $stat($baseNanos), "\n";
echo 'commit with preflight   : ', $stat($variantNanos), "\n";
