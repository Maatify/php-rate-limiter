<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use PHPUnit\Framework\TestCase;

/**
 * Issue #91 / DEC-018: real Redis + real ext-redis (phpredis) rawCommand()
 * through a direct raw bridge. The executor performs no reply normalization;
 * it only maps transport exceptions to BackendFailureException.
 *
 * The suite is skipped only when the Redis lifecycle orchestration variables
 * are absent (same as the sibling integration suite). Once orchestrated, a
 * missing ext-redis is a hard failure, never a skip.
 */
final class RedisFullCapabilityStoreExtRedisIntegrationTest extends TestCase
{
    private \Redis $redis;

    private RedisFullCapabilityStore $store;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $portValue = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $portValue === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        if (! extension_loaded('redis') || ! class_exists(\Redis::class)) {
            self::fail('ext-redis is required for the Issue #91 regression gate and is not loaded.');
        }

        $this->redis = new \Redis();
        if (! $this->redis->connect($host, (int) $portValue, 5.0)) {
            self::fail('ext-redis could not connect to the orchestrated Redis service.');
        }
        $this->redis->rawCommand('FLUSHDB');
        $this->store = new RedisFullCapabilityStore($this->executor(), 'issue-91-ext-redis');
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->close();
        }
    }

    public function testRealRawCommandPingRepresentationIsHealthy(): void
    {
        $reply = $this->redis->rawCommand('PING');

        // The exact representation is phpredis-owned; the adapter supports
        // precisely these two (DEC-018).
        self::assertTrue($reply === true || $reply === 'PONG', 'Unexpected phpredis PING representation: ' . get_debug_type($reply));
        self::assertTrue($this->store->isHealthy());
    }

    public function testMissingCircuitStateIsAbsentThroughDirectPhpRedisGet(): void
    {
        $reply = $this->redis->rawCommand('GET', 'issue-91-missing-key');

        self::assertTrue($reply === null || $reply === false, 'Unexpected phpredis missing-key GET representation: ' . get_debug_type($reply));
        self::assertNull($this->store->load('api_heavy_protection'));
    }

    public function testFirstRuntimeRequestOnEmptyRedisWorksWithoutSeedingOrHostNormalization(): void
    {
        $limiter = $this->limiter(new FixedClock('2025-01-01 12:00:00'));

        $result = $limiter->limit(
            new RateLimitContextDTO('198.51.100.91', 'Mozilla/5.0', 'issue-91-account'),
            RateLimitCommand::checkOnly('api_heavy_protection'),
        );

        self::assertSame('NORMAL', $result->failureMode);
    }

    public function testRealRedisCircuitRecoversThroughHealthyPhpRedisPing(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $limiter = $this->limiter($clock);
        $context = new RateLimitContextDTO('198.51.100.91', 'Mozilla/5.0', 'issue-91-account');
        $openedAt = $clock->now()->getTimestamp();
        $this->store->save('api_heavy_protection', new CircuitBreakerStateDTO('OPEN', [$openedAt, $openedAt, $openedAt], $openedAt, $openedAt, 0, [$openedAt], 0));

        $clock->setNow(new \DateTimeImmutable('@' . ($openedAt + 300)));
        $half = $limiter->limit($context, new RateLimitCommand('api_heavy_protection'));
        self::assertSame('DEGRADED_MODE', $half->failureMode);
        $halfState = $this->store->load('api_heavy_protection');
        self::assertNotNull($halfState);
        self::assertSame('HALF_OPEN', $halfState->status);

        $clock->setNow(new \DateTimeImmutable('@' . ($openedAt + 420)));
        $closed = $limiter->limit($context, new RateLimitCommand('api_heavy_protection'));
        self::assertSame('NORMAL', $closed->failureMode);
        $closedState = $this->store->load('api_heavy_protection');
        self::assertNotNull($closedState);
        self::assertSame('CLOSED', $closedState->status);
    }

    private function limiter(FixedClock $clock): \Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('issue-91-key', 'issue-91-fingerprint', 'prod'),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->build();
    }

    private function executor(): CallableRedisCommandExecutor
    {
        return new CallableRedisCommandExecutor(function (array $command): mixed {
            try {
                return $this->redis->rawCommand((string) $command[0], ...array_slice($command, 1));
            } catch (\RedisException $exception) {
                throw new BackendFailureException('ext-redis transport failure: ' . $exception->getMessage(), 0, $exception);
            }
        });
    }
}
