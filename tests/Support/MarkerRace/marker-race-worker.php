<?php

declare(strict_types=1);

// F-185-17 (#185): one failure of the marker race. Usage: marker-race-worker.php <host> <port> <marker key> <barrier key>
// The process waits right before it reads (or replaces) the `last_missing_fp` marker until both processes are there,
// so both reach the marker before either has written it. It prints its decision.
require __DIR__ . '/../../../vendor/autoload.php';

use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\MarkerRace\MarkerRaceSetup;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

$rawArguments = $_SERVER['argv'] ?? null;
$arguments = is_array($rawArguments) ? array_values(array_filter($rawArguments, is_string(...))) : [];
if (! is_array($rawArguments) || count($rawArguments) !== count($arguments) || count($arguments) !== 5) {
    fwrite(STDERR, "Expected 4 command-line arguments.\n");
    exit(2);
}
[, $host, $port, $marker, $barrier] = $arguments;
$reflection = new ReflectionClass(RedisFullCapabilityStore::class);
$scripts = [];
foreach (['SCORE_GET', 'SCORE_REPLACE'] as $name) {
    $script = $reflection->hasConstant($name) ? $reflection->getConstant($name) : null;
    if (is_string($script)) {
        $scripts[] = $script;
    }
}
$executor = new class (new RespRedisCommandExecutor($host, (int) $port), $scripts, $marker, $barrier) implements RedisCommandExecutorInterface {
    private bool $passed = false;

    /** @param list<string> $scripts */
    public function __construct(private RedisCommandExecutorInterface $inner, private array $scripts, private string $marker, private string $barrier) {}

    /** @param non-empty-list<int|string|float> $command */
    public function execute(array $command): mixed
    {
        if (! $this->passed && array_filter($this->scripts, static fn(string $script): bool => OverlayWire::isScript($command, $script)) !== [] && ($command[3] ?? null) === $this->marker) {
            $this->passed = true;
            $this->inner->execute(['INCR', $this->barrier]);
            $deadline = microtime(true) + 10;
            while ($this->arrived() < 2) {
                if (microtime(true) > $deadline) {
                    fwrite(STDERR, "barrier timeout\n");
                    exit(3);
                }
                usleep(200);
            }
        }

        return $this->inner->execute($command);
    }

    private function arrived(): int
    {
        $reply = $this->inner->execute(['GET', $this->barrier]);

        return is_string($reply) ? (int) $reply : 0;
    }
};
$result = MarkerRaceSetup::fail($executor);
echo json_encode(['decision' => $result->decision, 'blockLevel' => $result->blockLevel]), "\n";
