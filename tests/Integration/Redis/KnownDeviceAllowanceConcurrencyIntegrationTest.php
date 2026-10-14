<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
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
 * (#114 hardening slice of #110): concurrent known-device failures on one
 * account must consume the fixed-epoch allowance exactly once each, so no
 * more than the allowance escapes K4 scoring.
 */
final class KnownDeviceAllowanceConcurrencyIntegrationTest extends TestCase
{
    private const NAMESPACE = 'integration:known-device-allowance';
    private const WORKERS = 16;

    private string $host;
    private int $port;
    private RespRedisCommandExecutor $executor;

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
        $this->executor->execute(['FLUSHDB']);
    }

    public function testConcurrentKnownDeviceFailuresConsumeTheAccountAllowanceExactlyOnceEach(): void
    {
        $now = $this->redisNow();
        $pids = [];
        for ($index = 0; $index < self::WORKERS; $index++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Unable to fork Redis concurrency worker.');
            }
            if ($pid === 0) {
                $this->runWorker($index, $now);
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            $status = 0;
            self::assertSame($pid, pcntl_waitpid($pid, $status));
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }

        $store = $this->store();
        $conflicts = 0;
        for ($index = 0; $index < self::WORKERS; $index++) {
            $outcome = $this->executor->execute(['GET', 'known-device-allowance-worker:' . $index]);
            self::assertContains($outcome, ['ok', 'conflict']);
            $conflicts += $outcome === 'conflict' ? 1 : 0;
        }

        $allowance = $store->getBudget($this->auxiliaryKey('known_device_allowance', 'concurrent-owner'));
        self::assertSame(self::WORKERS, $allowance?->count);
        self::assertSame(2 * self::WORKERS, $store->get($this->key('k5', 'concurrent-owner:concurrent-fp'))?->value);
        self::assertSame(3 * (self::WORKERS - 8 - $conflicts), $store->get($this->key('k4', 'concurrent-owner'))?->value);
    }

    private function runWorker(int $index, int $now): never
    {
        try {
            $store = $this->store();
            $clock = new FixedClock('@' . $now);
            $pipeline = new EvaluationPipeline(
                $store,
                $store,
                new BudgetTracker($store, $clock),
                new AntiEquilibriumGate($store),
                new DecayCalculator($clock),
                new EphemeralBucket($store),
                'test_secret',
                'prod',
                $clock,
                null,
            );
            $outcome = 'ok';
            try {
                $pipeline->process(
                    new LoginProtectionPolicy(),
                    new RateLimitContextDTO('198.51.100.60', 'Chrome/123', 'concurrent-owner', ['device' => 'stable']),
                    RateLimitCommand::recordFailure('login_protection'),
                    new DeviceIdentityDTO('concurrent-fp', 'HIGH', true, false, 'chrome/123'),
                );
            } catch (RateLimitConcurrencyException) {
                $outcome = 'conflict';
            }
            (new RespRedisCommandExecutor($this->host, $this->port))->execute(['SET', 'known-device-allowance-worker:' . $index, $outcome, 'EX', '60']);
            exit(0);
        } catch (\Throwable) {
            exit(1);
        }
    }

    private function store(): RedisFullCapabilityStore
    {
        return new RedisFullCapabilityStore(new RespRedisCommandExecutor($this->host, $this->port), self::NAMESPACE);
    }

    private function key(string $type, string $scope): string
    {
        return hash_hmac('sha256', "login_protection:rate_limiter:{$type}:v2:prod:{$scope}", 'test_secret');
    }

    private function auxiliaryKey(string $purpose, string $account): string
    {
        return hash_hmac('sha256', "login_protection:rate_limiter:aux:{$purpose}:v1:prod:{$account}", 'test_secret');
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
