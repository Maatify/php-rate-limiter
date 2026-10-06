<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Repository\Redis;

use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DEC-018: official Redis adapter interpretation of the circuit-state GET reply.
 */
final class RedisFullCapabilityStoreCircuitLoadReplyTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function missingReplies(): iterable
    {
        yield 'null' => [null];
        yield 'false (ext-redis nil bulk)' => [false];
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedReplies(): iterable
    {
        yield 'true' => [true];
        yield 'int 0' => [0];
        yield 'int 1' => [1];
        yield 'float' => [1.5];
        yield 'empty array' => [[]];
        yield 'object' => [new \stdClass()];
    }

    #[DataProvider('missingReplies')]
    public function testMissingValueIsAbsent(mixed $reply): void
    {
        self::assertNull($this->store(static fn(array $command): mixed => $reply)->load('api'));
    }

    #[DataProvider('malformedReplies')]
    public function testOtherShapesAreExplicitlyMalformed(mixed $reply): void
    {
        $store = $this->store(static fn(array $command): mixed => $reply);

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Malformed circuit-breaker response.');
        $store->load('api');
    }

    public function testExistingStringValueIsStillLoaded(): void
    {
        $state = new CircuitBreakerStateDTO('OPEN', [10], 10, 10, 0, [], 0);
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        $loaded = $this->store(static fn(array $command): mixed => $json)->load('api');

        self::assertNotNull($loaded);
        self::assertSame($state->jsonSerialize(), $loaded->jsonSerialize());
    }

    public function testBackendFailureStillPropagatesFromLoad(): void
    {
        $store = $this->store(static function (array $command): mixed {
            throw new BackendFailureException('backend unavailable');
        });

        $this->expectException(BackendFailureException::class);
        $store->load('api');
    }

    /** @param callable(non-empty-list<int|string|float>): mixed $executor */
    private function store(callable $executor): RedisFullCapabilityStore
    {
        return new RedisFullCapabilityStore(new CallableRedisCommandExecutor($executor), 'circuit-load');
    }
}
