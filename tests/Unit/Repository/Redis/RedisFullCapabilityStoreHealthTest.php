<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Repository\Redis;

use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #91 / DEC-018: strict health PING reply matrix.
 */
final class RedisFullCapabilityStoreHealthTest extends TestCase
{
    /** @return iterable<string, array{mixed, bool}> */
    public static function pingReplies(): iterable
    {
        yield "'PONG'" => ['PONG', true];
        yield 'true (ext-redis rawCommand)' => [true, true];
        yield 'false' => [false, false];
        yield 'null' => [null, false];
        yield 'int 1' => [1, false];
        yield "'1'" => ['1', false];
        yield "'OK'" => ['OK', false];
        yield "'true'" => ['true', false];
        yield "'NOT_PONG'" => ['NOT_PONG', false];
        yield "'pong'" => ['pong', false];
        yield 'empty array' => [[], false];
        yield 'object' => [new \stdClass(), false];
    }

    #[DataProvider('pingReplies')]
    public function testPingReplyMatrixIsStrict(mixed $reply, bool $expected): void
    {
        $commands = [];
        $store = new RedisFullCapabilityStore(
            new CallableRedisCommandExecutor(static function (array $command) use ($reply, &$commands): mixed {
                $commands[] = $command;

                return $reply;
            }),
            'health-matrix',
        );

        self::assertSame($expected, $store->isHealthy());
        self::assertSame([['PING']], $commands);
    }

    public function testBackendFailureExceptionIsUnhealthy(): void
    {
        $store = new RedisFullCapabilityStore(
            new CallableRedisCommandExecutor(static function (array $command): mixed {
                throw new BackendFailureException('backend unavailable');
            }),
            'health-backend-failure',
        );

        self::assertFalse($store->isHealthy());
    }

    public function testUnknownThrowablePropagatesUnchanged(): void
    {
        $failure = new \RuntimeException('unknown failure');
        $store = new RedisFullCapabilityStore(
            new CallableRedisCommandExecutor(static function (array $command) use ($failure): mixed {
                throw $failure;
            }),
            'health-unknown',
        );

        try {
            $store->isHealthy();
            self::fail('Unknown throwable was swallowed.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testTypeErrorPropagatesUnchanged(): void
    {
        $failure = new \TypeError('programming failure');
        $store = new RedisFullCapabilityStore(
            new CallableRedisCommandExecutor(static function (array $command) use ($failure): mixed {
                throw $failure;
            }),
            'health-type-error',
        );

        try {
            $store->isHealthy();
            self::fail('TypeError was swallowed.');
        } catch (\TypeError $caught) {
            self::assertSame($failure, $caught);
        }
    }
}
