<?php

declare(strict_types=1);

// One failed login of the credit scenario. Usage: worker.php <checkout> <port> <startAtMicroseconds> <k5 key> <k1 key>
// Counts, per score key, the fenced writes applied and missed (three misses on one key are one exhaustion, DEC-030 3),
// and whether this request wrote K5 and K1 at all (a request refused by an active block writes neither).
require __DIR__ . '/load.php';

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

[, , $port, $startAt, $k5Key, $k1Key] = $argv;
// The store's scripts are private constants: `defined()` does not see them, reflection does.
$store = new ReflectionClass(RedisFullCapabilityStore::class);
$fenced = $store->hasConstant('SCORE_SET_IF_SNAPSHOT') ? (string) $store->getConstant('SCORE_SET_IF_SNAPSHOT') : null;
$increment = (string) $store->getConstant('SCORE_INCREMENT');
$executor = new class (new RespRedisCommandExecutor('127.0.0.1', (int) $port), $fenced, $increment, [$k5Key => 'k5', $k1Key => 'k1']) implements RedisCommandExecutorInterface {
    public int $applied = 0;
    public int $misses = 0;
    public int $exhausted = 0;
    /** @var array{k5: int, k1: int} */
    public array $wrote = ['k5' => 0, 'k1' => 0];
    /** @var array<string, int> */
    private array $missesByKey = [];

    /** @param array<string, string> $scopes */
    public function __construct(private RedisCommandExecutorInterface $inner, private ?string $fenced, private string $increment, private array $scopes) {}

    public function execute(array $command): mixed
    {
        $reply = $this->inner->execute($command);
        $scope = $this->scopes[(string) ($command[3] ?? '')] ?? null;
        if ($scope !== null && ($command[0] ?? '') === 'EVAL' && ($command[1] === $this->increment || ($command[1] === $this->fenced && $reply !== []))) {
            $this->wrote[$scope]++;
        }
        if ($this->fenced !== null && ($command[0] ?? '') === 'EVAL' && $command[1] === $this->fenced) {
            $key = (string) $command[3];
            if ($reply === []) {
                $this->misses++;
                $this->missesByKey[$key] = ($this->missesByKey[$key] ?? 0) + 1;
                if ($this->missesByKey[$key] === 3) {
                    $this->exhausted++;
                }
            } else {
                $this->applied++;
            }
        }

        return $reply;
    }
};
$limiter = RateLimiterBuilder::fromFullCapabilityStore(
    new RateLimiterConfig('f185-08-key', 'f185-08-fingerprint', 'prod'),
    new RedisFullCapabilityStore($executor, 'f185-08'),
    new RecordingFailureSignalEmitter(),
)->withClock(new FixedClock('2026-11-04 12:00:00'))->build();
while (($wait = (int) $startAt - (int) (microtime(true) * 1e6)) > 0) {
    usleep(min($wait, 500));
}
$limiter->limit(new RateLimitContextDTO('198.51.100.60', 'Mozilla/5.0 Chrome/120.0', 'account-1', null, 'session-device-1', true), RateLimitCommand::recordFailure('login_protection'));
echo json_encode(['applied' => $executor->applied, 'misses' => $executor->misses, 'exhausted' => $executor->exhausted, 'wroteK5' => $executor->wrote['k5'], 'wroteK1' => $executor->wrote['k1']]), "\n";
