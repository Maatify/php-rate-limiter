<?php

declare(strict_types=1);

// F-185-08 (#185): one process of the score race. Usage: score-race-worker.php <host> <port> <barrier key|''> <parties>
// With a barrier key, the process waits before its first score write until <parties> processes reached the barrier, so
// every process has read the same snapshot before any of them writes (the reviewer's two-process barrier).
require __DIR__ . '/../../../vendor/autoload.php';

use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\RateLimiter\Tests\Support\ScoreRace\ScoreRaceSetup;

[, $host, $port, $barrier, $parties] = $argv;
// The store's scripts are private constants: `defined()` does not see them, reflection does.
$scripts = [];
$store = new ReflectionClass(RedisFullCapabilityStore::class);
foreach (['SCORE_INCREMENT', 'SCORE_SET_IF_SNAPSHOT'] as $name) {
    $script = $store->hasConstant($name) ? $store->getConstant($name) : null;
    if (is_string($script)) {
        $scripts[] = $script;
    }
}
if ($scripts === []) {
    fwrite(STDERR, "no score script found\n");
    exit(4);
}
$executor = new class (new RespRedisCommandExecutor($host, (int) $port), $scripts, $barrier, (int) $parties) implements RedisCommandExecutorInterface {
    private bool $passed = false;

    /** @param list<string> $scripts */
    public function __construct(private RedisCommandExecutorInterface $inner, private array $scripts, private string $barrier, private int $parties) {}

    /** @param non-empty-list<int|string|float> $command */
    public function execute(array $command): mixed
    {
        if (! $this->passed && $this->barrier !== '' && $command[0] === 'EVAL' && in_array($command[1] ?? null, $this->scripts, true)) {
            $this->passed = true;
            $this->inner->execute(['INCR', $this->barrier]);
            $deadline = microtime(true) + 10;
            while ((int) ($this->arrived() ?? 0) < $this->parties) {
                if (microtime(true) > $deadline) {
                    fwrite(STDERR, "barrier timeout\n");
                    exit(3);
                }
                usleep(1000);
            }
        }

        return $this->inner->execute($command);
    }

    private function arrived(): ?string
    {
        $reply = $this->inner->execute(['GET', $this->barrier]);

        return is_string($reply) ? $reply : null;
    }
};
ScoreRaceSetup::fail(ScoreRaceSetup::limiter($executor, ScoreRaceSetup::NOW));
echo "ok\n";
