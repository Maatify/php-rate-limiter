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
 * F92-03 / DEC-018: strict tagged circuit-state read protocol. A bare GET
 * reply (false/null) is never a semantic absence contract.
 */
final class RedisFullCapabilityStoreCircuitLoadReplyTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function malformedReplies(): iterable
    {
        yield 'bare false' => [false];
        yield 'bare null' => [null];
        yield 'bare true' => [true];
        yield 'bare string' => ['{}'];
        yield 'int' => [0];
        yield 'object' => [new \stdClass()];
        yield 'empty array' => [[]];
        yield 'non-list' => [['absent' => 1]];
        yield 'non-list value' => [[1 => 'value', 2 => '{}']];
        yield 'numeric tag' => [[0]];
        yield 'unknown tag' => [['missing']];
        yield 'absent with extra' => [['absent', 'extra']];
        yield 'value missing payload' => [['value']];
        yield 'value false payload' => [['value', false]];
        yield 'value null payload' => [['value', null]];
        yield 'value int payload' => [['value', 1]];
        yield 'value extra payload' => [['value', '{}', 'extra']];
        yield 'error missing payload' => [['error']];
        yield 'error non-string payload' => [['error', 1]];
        yield 'error extra payload' => [['error', 'WRONGTYPE', 'extra']];
    }

    #[DataProvider('malformedReplies')]
    public function testEveryOtherShapeIsExplicitlyMalformed(mixed $reply): void
    {
        $store = $this->store(static fn(array $command): mixed => $reply);

        try {
            $store->load('api');
            self::fail('Malformed circuit read reply was accepted.');
        } catch (BackendFailureException) {
            self::fail('Malformed reply must not be a backend outage.');
        } catch (RateLimiterException $exception) {
            self::assertSame('Malformed circuit-breaker response.', $exception->getMessage());
        }
    }

    public function testAbsentTagIsAbsentState(): void
    {
        self::assertNull($this->store(static fn(array $command): mixed => ['absent'])->load('api'));
    }

    public function testErrorTagIsExplicitNonBackendFailure(): void
    {
        $store = $this->store(static fn(array $command): mixed => ['error', 'WRONGTYPE Operation against a key holding the wrong kind of value']);

        try {
            $store->load('api');
            self::fail('Redis error reply was treated as absent state.');
        } catch (BackendFailureException) {
            self::fail('Redis error reply must not be a backend outage.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('WRONGTYPE', $exception->getMessage());
        }
    }

    public function testValueTagIsLoadedAndUsesAtomicEvalNotDirectGet(): void
    {
        $state = new CircuitBreakerStateDTO('OPEN', [10], 10, 10, 0, [], 0);
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        $commands = [];
        $loaded = $this->store(static function (array $command) use ($json, &$commands): mixed {
            $commands[] = $command;

            return ['value', $json];
        })->load('api');

        self::assertNotNull($loaded);
        self::assertSame($state->jsonSerialize(), $loaded->jsonSerialize());
        self::assertCount(1, $commands);
        self::assertSame('EVAL', $commands[0][0]);
        self::assertStringContainsString("redis.pcall('GET'", (string) $commands[0][1]);
        self::assertSame(1, $commands[0][2]);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPersistedPayloads(): iterable
    {
        yield 'not json' => ['not-json'];
        yield 'json scalar' => ['1'];
        yield 'missing fields' => ['{"status":"OPEN"}'];
        yield 'bad status' => ['{"status":"X","failures":[],"reEntries":[],"lastFailure":0,"openSince":0,"lastSuccess":0,"failClosedUntil":0}'];
        yield 'negative timestamp' => ['{"status":"OPEN","failures":[],"reEntries":[],"lastFailure":-1,"openSince":0,"lastSuccess":0,"failClosedUntil":0}'];
        yield 'non-int failure' => ['{"status":"OPEN","failures":["a"],"reEntries":[],"lastFailure":0,"openSince":0,"lastSuccess":0,"failClosedUntil":0}'];
    }

    #[DataProvider('invalidPersistedPayloads')]
    public function testValuePayloadStillGetsStructuralValidation(string $payload): void
    {
        $store = $this->store(static fn(array $command): mixed => ['value', $payload]);

        $this->expectException(RateLimiterException::class);
        $store->load('api');
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
