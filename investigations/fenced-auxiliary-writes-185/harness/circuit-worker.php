<?php

declare(strict_types=1);

// One backend failure reported to the circuit breaker (#185, F-185-18, DEC-031).
// Usage: circuit-worker.php <checkout> <port> <barrier key> <parties>
// Waits before its first state save (a plain SET, or the fenced save) until <parties> processes have read the state.
// Prints the fenced saves applied / missed.
require __DIR__ . '/load.php';

use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

[, , $port, $barrier, $parties] = $argv;
$reflection = new ReflectionClass(RedisFullCapabilityStore::class);
$fenced = $reflection->hasConstant('CIRCUIT_SAVE_IF_VERSION') ? $reflection->getConstant('CIRCUIT_SAVE_IF_VERSION') : null;
$executor = new class (new RespRedisCommandExecutor('127.0.0.1', (int) $port), is_string($fenced) ? $fenced : null, $barrier, (int) $parties) implements RedisCommandExecutorInterface {
    public int $applied = 0;
    public int $missed = 0;
    private bool $passed = false;

    public function __construct(private RedisCommandExecutorInterface $inner, private ?string $fenced, private string $barrier, private int $parties) {}

    public function execute(array $command): mixed
    {
        $isFenced = $this->fenced !== null && $command[0] === 'EVAL' && ($command[1] ?? null) === $this->fenced;
        if (! $this->passed && ($isFenced || $command[0] === 'SET')) {
            $this->passed = true;
            $this->inner->execute(['INCR', $this->barrier]);
            $deadline = microtime(true) + 10;
            while ((int) $this->inner->execute(['GET', $this->barrier]) < $this->parties) {
                if (microtime(true) > $deadline) { fwrite(STDERR, "barrier timeout\n"); exit(3); }
                usleep(200);
            }
        }
        $reply = $this->inner->execute($command);
        if ($isFenced) { $reply === 1 ? $this->applied++ : $this->missed++; }

        return $reply;
    }
};
(new CircuitBreaker(new RedisFullCapabilityStore($executor, 'f185-18-measure'), new RecordingFailureSignalEmitter(), new FixedClock('2026-11-04 12:00:00')))->reportFailure('login_protection');
echo json_encode(['applied' => $executor->applied, 'missed' => $executor->missed]), "\n";
