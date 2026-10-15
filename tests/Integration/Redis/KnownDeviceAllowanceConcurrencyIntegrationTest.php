<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\KnownDeviceAccountAllowancePolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Exception\RateLimitConcurrencyException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\TestCase;

/**
 * Real-Redis concurrency proof for the known-device account allowance
 * (#114 hardening slice of #110, F117-01 / F117-03).
 *
 * Every test owns a random store namespace and a random coordination prefix,
 * deletes only those keys, and proves that a key outside them survives. Workers
 * are forked and released together through a Redis barrier, so the measured
 * commands genuinely overlap.
 */
final class KnownDeviceAllowanceConcurrencyIntegrationTest extends TestCase
{
    private const WORKERS = 16;
    private const STORE_PREFIX = 'maatify:rate-limiter:v1';

    private string $host;
    private int $port;
    private RespRedisCommandExecutor $executor;
    private string $namespace;
    private string $coordination;
    private string $sentinel;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $portValue = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $portValue === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->host = $host;
        $this->port = (int) $portValue;
        $this->executor = new RespRedisCommandExecutor($this->host, $this->port);
        $run = bin2hex(random_bytes(8));
        $this->namespace = 'it-known-device-allowance-' . $run;
        $this->coordination = 'test:known-device-allowance:' . $run;
        $this->sentinel = 'test:known-device-allowance-foreign:' . $run;
        $this->executor->execute(['SET', $this->sentinel, 'untouched', 'EX', '600']);
    }

    protected function tearDown(): void
    {
        if (! isset($this->executor)) {
            return;
        }
        self::assertSame('untouched', $this->executor->execute(['GET', $this->sentinel]));
        foreach ([$this->storePattern(), $this->coordination . ':*'] as $pattern) {
            foreach ($this->scan($pattern) as $key) {
                $this->executor->execute(['DEL', $key]);
            }
        }
        $this->executor->execute(['DEL', $this->sentinel]);
        self::assertSame([], $this->scan($this->storePattern()));
    }

    /**
     * No early block is reachable (K4/K5 thresholds far away, one device, one
     * IP), so every one of the overlapping failures must consume the allowance
     * exactly once, and accounts must not share a counter.
     */
    public function testOverlappingFailuresConsumeTheAllowanceExactlyOncePerAccount(): void
    {
        $policy = new WideAllowancePolicy();
        $outcomes = $this->runOverlapping(self::WORKERS + 8, function (int $index, EvaluationPipeline $pipeline) use ($policy): RateLimitResultDTO {
            $account = $index < self::WORKERS ? 'owner-a' : 'owner-b';

            return $pipeline->process($policy, $this->context($account), RateLimitCommand::recordFailure($policy->getName()), $this->device());
        });

        self::assertSame(array_fill(0, self::WORKERS + 8, 'ALLOW'), $outcomes);
        $store = $this->store();
        foreach (['owner-a' => self::WORKERS, 'owner-b' => 8] as $account => $failures) {
            self::assertSame($failures, $store->getBudget($this->auxiliaryKey($policy->getName(), $account, 'test_secret'))?->count);
            self::assertSame(2 * $failures, $store->get($this->key($policy->getName(), 'k5', "{$account}:concurrent-fp"))?->value);
            self::assertSame(3 * ($failures - 4), $store->get($this->key($policy->getName(), 'k4', $account))?->value);
        }
    }

    /**
     * Official Login preset under overlap. A command either reaches the update
     * step (recorded, or a DEC-007 generation conflict after the allowance unit
     * was taken) or is answered early by an active block persisted by another
     * worker. The invariant ties the three classes to the stored state exactly.
     * A second overlapping wave runs after the first wave's blocks exist, so the
     * early-block class is always exercised, whatever the scheduler does.
     */
    public function testLoginOverlapSplitsIntoRecordedConflictAndEarlyBlockExactly(): void
    {
        $policy = new LoginProtectionPolicy();
        $command = function (int $index, EvaluationPipeline $pipeline) use ($policy): RateLimitResultDTO {
            return $pipeline->process($policy, $this->context('login-owner'), RateLimitCommand::recordFailure('login_protection'), $this->device(true));
        };
        $outcomes = array_merge(
            $this->runOverlapping(self::WORKERS, $command, wave: 'first'),
            $this->runOverlapping(self::WORKERS, $command, wave: 'second'),
        );

        $store = $this->store();
        $consumed = $store->getBudget($this->auxiliaryKey('login_protection', 'login-owner', 'test_secret'))->count ?? 0;
        $conflicts = count(array_filter($outcomes, static fn(string $outcome): bool => $outcome === 'conflict'));
        $early = 2 * self::WORKERS - $consumed;
        $k5 = $store->get($this->key('login_protection', 'k5', 'login-owner:concurrent-fp'))->value ?? 0;
        $k4 = $store->get($this->key('login_protection', 'k4', 'login-owner'))->value ?? 0;
        $blocked = max(
            $store->checkBlock($this->key('login_protection', 'k5', 'login-owner:concurrent-fp'))->level ?? 0,
            $store->checkBlock($this->key('login_protection', 'k4', 'login-owner'))->level ?? 0,
        );

        self::assertGreaterThan(0, $early);
        self::assertGreaterThanOrEqual(2, $blocked);
        self::assertSame(2 * ($consumed - $conflicts), $k5);
        self::assertSame(3 * max(0, $consumed - 8 - $conflicts), $k4);
        self::assertGreaterThanOrEqual($early, count(array_filter($outcomes, static fn(string $outcome): bool => $outcome === 'HARD_BLOCK')));
    }

    /**
     * Overlapping failures right after an outer-secret rotation: the previous
     * epoch is seeded into the current key exactly once and stays read-only.
     */
    public function testOverlappingFailuresSeedThePreviousEpochExactlyOnceUnderRotation(): void
    {
        $policy = new WideAllowancePolicy();
        $old = $this->pipeline($this->store(), null, 'old_secret', $this->redisNow());
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $old->process($policy, $this->context('rotating-owner'), RateLimitCommand::recordFailure($policy->getName()), $this->device());
        }

        $outcomes = $this->runOverlapping(self::WORKERS, function (int $index, EvaluationPipeline $pipeline) use ($policy): RateLimitResultDTO {
            return $pipeline->process($policy, $this->context('rotating-owner'), RateLimitCommand::recordFailure($policy->getName()), $this->device());
        }, 'old_secret', 'new_secret');

        self::assertSame(array_fill(0, self::WORKERS, 'ALLOW'), $outcomes);
        $store = $this->store();
        self::assertSame(3, $store->getBudget($this->auxiliaryKey($policy->getName(), 'rotating-owner', 'old_secret'))?->count);
        self::assertSame(3 + self::WORKERS, $store->getBudget($this->auxiliaryKey($policy->getName(), 'rotating-owner', 'new_secret'))?->count);
        self::assertSame(3 * (3 + self::WORKERS - 4), $store->get($this->key($policy->getName(), 'k4', 'rotating-owner', 'new_secret'))?->value);
    }

    /**
     * @param callable(int, EvaluationPipeline): RateLimitResultDTO $command
     * @return list<string> per worker: the returned decision, or "conflict"
     */
    private function runOverlapping(int $workers, callable $command, ?string $previousSecret = null, string $secret = 'test_secret', string $wave = 'only'): array
    {
        // Workers never touch the parent's connection: the clock is read here,
        // and each worker opens its own Redis connections after the fork.
        $now = $this->redisNow();
        $barrier = $this->coordination . ':' . $wave . ':barrier';
        $pids = [];
        for ($index = 0; $index < $workers; $index++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Unable to fork Redis concurrency worker.');
            }
            if ($pid === 0) {
                $this->runWorker($index, $workers, $barrier, $now, $command, $previousSecret, $secret, $wave);
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            $status = 0;
            self::assertSame($pid, pcntl_waitpid($pid, $status));
            if (! is_int($status)) {
                self::fail('Redis concurrency worker status was malformed.');
            }
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }

        $outcomes = [];
        for ($index = 0; $index < $workers; $index++) {
            $outcome = $this->executor->execute(['GET', $this->coordination . ':' . $wave . ':outcome:' . $index]);
            self::assertIsString($outcome);
            $outcomes[] = $outcome;
        }

        return $outcomes;
    }

    /**
     * @param callable(int, EvaluationPipeline): RateLimitResultDTO $command
     */
    private function runWorker(int $index, int $workers, string $barrier, int $now, callable $command, ?string $previousSecret, string $secret, string $wave): never
    {
        try {
            $executor = new RespRedisCommandExecutor($this->host, $this->port);
            $pipeline = $this->pipeline($this->store(), $previousSecret, $secret, $now);
            $executor->execute(['INCR', $barrier]);
            $deadline = microtime(true) + 10.0;
            while ((int) $this->asString($executor->execute(['GET', $barrier])) < $workers) {
                if (microtime(true) > $deadline) {
                    exit(2);
                }
                usleep(200);
            }
            try {
                $outcome = $command($index, $pipeline)->decision;
            } catch (RateLimitConcurrencyException) {
                $outcome = 'conflict';
            }
            $executor->execute(['SET', $this->coordination . ':' . $wave . ':outcome:' . $index, $outcome, 'EX', '600']);
            exit(0);
        } catch (\Throwable) {
            exit(1);
        }
    }

    private function asString(mixed $reply): string
    {
        return is_string($reply) ? $reply : '0';
    }

    private function pipeline(RedisFullCapabilityStore $store, ?string $previousSecret, string $secret, int $now): EvaluationPipeline
    {
        $clock = new FixedClock('@' . $now);

        return new EvaluationPipeline(
            $store,
            $store,
            new BudgetTracker($store, $clock),
            new AntiEquilibriumGate($store),
            new DecayCalculator($clock),
            new EphemeralBucket($store),
            $secret,
            'prod',
            $clock,
            $previousSecret,
        );
    }

    private function store(): RedisFullCapabilityStore
    {
        return new RedisFullCapabilityStore(new RespRedisCommandExecutor($this->host, $this->port), $this->namespace);
    }

    private function context(string $account): RateLimitContextDTO
    {
        return new RateLimitContextDTO('198.51.100.60', 'Chrome/123', $account, ['device' => 'stable']);
    }

    private function device(bool $trusted = false): DeviceIdentityDTO
    {
        return new DeviceIdentityDTO('concurrent-fp', $trusted ? 'HIGH' : 'MEDIUM', $trusted, false, 'chrome/123', ! $trusted);
    }

    private function key(string $policy, string $type, string $scope, string $secret = 'test_secret'): string
    {
        return hash_hmac('sha256', "{$policy}:rate_limiter:{$type}:v2:prod:{$scope}", $secret);
    }

    private function auxiliaryKey(string $policy, string $account, string $secret): string
    {
        return hash_hmac('sha256', "{$policy}:rate_limiter:aux:known_device_allowance:v1:prod:{$account}", $secret);
    }

    private function storePattern(): string
    {
        return self::STORE_PREFIX . ':' . hash('sha256', $this->namespace) . ':*';
    }

    /**
     * @return list<string>
     */
    private function scan(string $pattern): array
    {
        $keys = [];
        $cursor = '0';
        do {
            $reply = $this->executor->execute(['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '500']);
            self::assertIsArray($reply);
            self::assertArrayHasKey(0, $reply);
            self::assertArrayHasKey(1, $reply);
            self::assertIsString($reply[0]);
            self::assertIsArray($reply[1]);
            $cursor = $reply[0];
            foreach ($reply[1] as $key) {
                self::assertIsString($key);
                $keys[] = $key;
            }
        } while ($cursor !== '0');

        return $keys;
    }

    private function redisNow(): int
    {
        $time = $this->executor->execute(['TIME']);
        self::assertIsArray($time);
        self::assertArrayHasKey(0, $time);
        self::assertIsString($time[0]);

        return (int) $time[0];
    }
}

/**
 * Opted-in authentication policy whose thresholds are out of reach, so the
 * allowance is the only state transition the concurrency proof observes.
 */
final class WideAllowancePolicy implements KnownDeviceAccountAllowancePolicyInterface
{
    public function getName(): string
    {
        return 'wide_allowance_flow';
    }

    public function getScoreThresholds(): PolicyThresholdsDTO
    {
        return new PolicyThresholdsDTO(k4: new ScoreThresholdsDTO(1000, 2000, 3000), k5: new ScoreThresholdsDTO(1000, 2000, 3000));
    }

    public function getScoreDeltas(): ScoreDeltasDTO
    {
        return new ScoreDeltasDTO(k2_missing_fp: 4, k4_failure: 3, k5_failure: 2);
    }

    public function getFailureMode(): string
    {
        return 'FAIL_CLOSED';
    }

    public function getBudgetConfig(): BudgetConfigDTO
    {
        return new BudgetConfigDTO(1000, 3);
    }

    public function getKnownDeviceAccountAllowance(): int
    {
        return 4;
    }
}
