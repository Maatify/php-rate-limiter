<?php

declare(strict_types=1);

// F-185-18 (#185, the Lead's adv6/r3): one backend failure reported to the circuit breaker.
// Usage: circuit-race-worker.php <host> <port> <namespace> <barrier key> <parties>
// The process waits before its first state save (a plain SET, or the fenced save) until <parties> processes have read
// the state, so every process computes from the same read. It prints the fenced saves applied / missed.
require __DIR__ . '/../../../vendor/autoload.php';

use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

$rawArguments = $_SERVER['argv'] ?? null;
$arguments = is_array($rawArguments) ? array_values(array_filter($rawArguments, is_string(...))) : [];
if (! is_array($rawArguments) || count($rawArguments) !== count($arguments) || count($arguments) !== 6) {
    fwrite(STDERR, "Expected 5 command-line arguments.\n");
    exit(2);
}
[, $host, $port, $namespace, $barrier, $parties] = $arguments;
$reflection = new ReflectionClass(RedisFullCapabilityStore::class);
$fenced = $reflection->hasConstant('CIRCUIT_SAVE_IF_VERSION') ? $reflection->getConstant('CIRCUIT_SAVE_IF_VERSION') : null;
$executor = new class (new RespRedisCommandExecutor($host, (int) $port), is_string($fenced) ? $fenced : null, $barrier, (int) $parties) implements RedisCommandExecutorInterface {
    public int $applied = 0;

    public int $missed = 0;

    private bool $passed = false;

    public function __construct(private RedisCommandExecutorInterface $inner, private ?string $fenced, private string $barrier, private int $parties) {}

    /** @param non-empty-list<int|string|float> $command */
    public function execute(array $command): mixed
    {
        $isFenced = $this->fenced !== null && $command[0] === 'EVAL' && ($command[1] ?? null) === $this->fenced;
        if (! $this->passed && ($isFenced || $command[0] === 'SET')) {
            $this->passed = true;
            $this->inner->execute(['INCR', $this->barrier]);
            $deadline = microtime(true) + 10;
            while ($this->arrived() < $this->parties) {
                if (microtime(true) > $deadline) {
                    fwrite(STDERR, "barrier timeout\n");
                    exit(3);
                }
                usleep(200);
            }
        }
        $reply = $this->inner->execute($command);
        if ($isFenced) {
            $reply === 1 ? $this->applied++ : $this->missed++;
        }

        return $reply;
    }

    private function arrived(): int
    {
        $reply = $this->inner->execute(['GET', $this->barrier]);

        return is_string($reply) ? (int) $reply : 0;
    }
};
(new CircuitBreaker(new RedisFullCapabilityStore($executor, $namespace), new RecordingFailureSignalEmitter(), new FixedClock('2026-11-04 12:00:00')))->reportFailure('login_protection');
echo json_encode(['applied' => $executor->applied, 'missed' => $executor->missed]), "\n";
