<?php

declare(strict_types=1);

// #261 implementation (DEC-042): the latency of the request save (the commit EVAL) of the production store.
// usage: php bench-impl.php <redis host> <redis port> <label> <requests>   (run once against the base tree and once against the fix tree, one process, alone)

require __DIR__ . '/../../vendor/autoload.php';

use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

$host = $argv[1];
$port = (int) $argv[2];
$label = $argv[3];
$count = (int) ($argv[4] ?? 300);

$nanos = [];
$resp = new RespRedisCommandExecutor($host, $port, RespRedisCommandExecutor::MODE_EVAL);
$resp->execute(['FLUSHALL']);
$timed = new class ($resp, $nanos) implements RedisCommandExecutorInterface {
    /** @param list<float> $nanos */
    public function __construct(private readonly RedisCommandExecutorInterface $inner, private array &$nanos) {}

    public function execute(array $command): mixed
    {
        if (! OverlayWire::isCommit($command)) {
            return $this->inner->execute($command);
        }
        $start = hrtime(true);
        $reply = $this->inner->execute($command);
        $this->nanos[] = (float) (hrtime(true) - $start);

        return $reply;
    }
};
$store = new RedisFullCapabilityStore($timed, 'bench-261-impl');
$now = time();
$run = static function (int $i) use ($store, $now): void {
    $store->transactRequest(static function () use ($store, $now, $i): void {
        $store->incrementAt('score-' . ($i % 20), 600, 1, $now);
        $store->incrementBudgetAt('budget-' . ($i % 20), 3600, 1, $now);
        $store->addDistinctAt('distinct-' . ($i % 20), 'item-' . $i, 600, $now);
        $store->incrementWatchFlagAt('watch-' . ($i % 20), 600, $now);
        $store->addDistinctBoundedAt('bounded-' . ($i % 20), 'item-' . $i, 600, 100, $now);
    });
};
for ($i = 0; $i < 50; $i++) {
    $run($i);
}
$nanos = [];
for ($i = 0; $i < $count; $i++) {
    $run($i);
}
sort($nanos);
$n = count($nanos);
printf("%s: PHP %s INT=%d n=%d commit p50=%.0f us p95=%.0f us mean=%.0f us\n", $label, PHP_VERSION, PHP_INT_SIZE, $n, $nanos[intdiv($n, 2)] / 1000, $nanos[(int) floor($n * 0.95)] / 1000, array_sum($nanos) / $n / 1000);
