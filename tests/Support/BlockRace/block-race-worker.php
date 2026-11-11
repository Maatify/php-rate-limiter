<?php

declare(strict_types=1);

// F-185-16 (#185): request A of the block race. Usage: block-race-worker.php <host> <port> <block key> <flag prefix>
// A pauses right before its block write on <block key> until request B has finished, then writes. It prints its decision.
require __DIR__ . '/../../../vendor/autoload.php';

use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\BlockRace\BlockRaceSetup;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

$rawArguments = $_SERVER['argv'] ?? null;
$arguments = is_array($rawArguments) ? array_values(array_filter($rawArguments, is_string(...))) : [];
if (! is_array($rawArguments) || count($rawArguments) !== count($arguments) || count($arguments) !== 5) {
    fwrite(STDERR, "Expected 4 command-line arguments.\n");
    exit(2);
}
[, $host, $port, $blockKey, $flag] = $arguments;
$store = new ReflectionClass(RedisFullCapabilityStore::class);
$writes = [];
foreach (['BLOCK_SET', 'HARD_BLOCK'] as $name) {
    $script = $store->getConstant($name);
    if (is_string($script)) {
        $writes[] = $script;
    }
}
$executor = new class (new RespRedisCommandExecutor($host, (int) $port), $writes, $blockKey, $flag) implements RedisCommandExecutorInterface {
    /** @param list<string> $writes */
    public function __construct(private RedisCommandExecutorInterface $inner, private array $writes, private string $blockKey, private string $flag) {}

    /** @param non-empty-list<int|string|float> $command */
    public function execute(array $command): mixed
    {
        if ($command[0] === 'EVAL' && in_array($command[1] ?? null, $this->writes, true) && ($command[3] ?? null) === $this->blockKey) {
            $this->inner->execute(['SET', $this->flag . ':a-at-block', '1']);
            $deadline = microtime(true) + 10;
            while ($this->inner->execute(['GET', $this->flag . ':b-done']) === null) {
                if (microtime(true) > $deadline) {
                    fwrite(STDERR, "timeout waiting for B\n");
                    exit(3);
                }
                usleep(500);
            }
        }

        return $this->inner->execute($command);
    }
};
$result = BlockRaceSetup::fail($executor);
echo json_encode(['decision' => $result->decision, 'blockLevel' => $result->blockLevel, 'retryAfter' => $result->retryAfter]), "\n";
