<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\SimpleRateLimitResultDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-21 (#185, LT-185-21, the Lead's adv6/r6): `FixedWindowSimpleRateLimiter` answers `FAIL_CLOSED` for an
 * operational backend failure only (`BackendFailureException`, DEC-015). On `e513638` it caught every `\Throwable`, so a
 * structurally malformed persisted budget (`epochStart = "abc"`), which the store reports as `malformed budget state`
 * (DEC-017), came back as a plain `FAIL_CLOSED` denial. It now propagates, and the hash is not deleted.
 */
#[Group('f-185-21')]
final class SimpleMalformedBudgetRedisTest extends TestCase
{
    private RespRedisCommandExecutor $executor;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->executor = new RespRedisCommandExecutor($host, (int) $port);
        $this->executor->execute(['FLUSHDB']);
    }

    private function limiter(RedisCommandExecutorInterface $executor): CompositeRateLimiterRuntimeInterface
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('f185-21-key', 'f185-21-fingerprint', 'prod'),
            new RedisFullCapabilityStore($executor, 'f185-21'),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('2026-11-04 12:00:00'))->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('api', 10, 60))->build();
    }

    /** The one budget hash the first consume created. */
    private function budgetKey(): string
    {
        $keys = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        self::assertCount(1, $keys);
        self::assertIsString($keys[0]);

        return $keys[0];
    }

    public function testAMalformedBudgetPropagatesInsteadOfAFailClosedDenial(): void
    {
        $limiter = $this->limiter($this->executor);
        self::assertTrue($limiter->consume('api', 'subject-1')->allowed);
        $key = $this->budgetKey();
        $this->executor->execute(['HSET', $key, 'epochStart', 'abc']);
        $before = $this->executor->execute(['HGETALL', $key]);

        try {
            $result = $limiter->consume('api', 'subject-1');
            self::fail('A malformed budget was answered as ' . $result->failureMode . ' instead of propagating.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('malformed budget state', $exception->getMessage());
        }

        self::assertSame($before, $this->executor->execute(['HGETALL', $key]), 'The malformed hash is neither deleted nor repaired.');
    }

    public function testControlABackendFailureIsStillFailClosed(): void
    {
        $failing = new class implements RedisCommandExecutorInterface {
            public function execute(array $command): mixed
            {
                throw new BackendFailureException('Redis connection lost.');
            }
        };

        $result = $this->limiter($failing)->consume('api', 'subject-1');

        self::assertFalse($result->allowed);
        self::assertSame(SimpleRateLimitResultDTO::FAIL_CLOSED, $result->failureMode);
        self::assertNull($result->retryAfter);
        self::assertNull($result->resetAt);
    }
}
