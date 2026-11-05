<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\FailureStateDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-17 / F-185-18 (#185, DEC-031): the two fenced auxiliary writes of `RedisFullCapabilityStore`.
 * `replaceScoreAt()` writes a score with a fresh TTL and returns the one it replaced; `saveIfVersion()` saves the
 * circuit-breaker state only while the stored text is the version that was read. Malformed state is an error and is
 * left as it is.
 */
#[Group('f-185-17')]
#[Group('f-185-18')]
final class FencedAuxiliaryStoreRedisTest extends TestCase
{
    private const NOW = 1_793_793_600;

    private const NAMESPACE = 'f185-31';

    private RespRedisCommandExecutor $executor;

    private RedisFullCapabilityStore $store;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->executor = new RespRedisCommandExecutor($host, (int) $port);
        $this->executor->execute(['FLUSHDB']);
        $this->store = new RedisFullCapabilityStore($this->executor, self::NAMESPACE);
    }

    private function key(string $family, string $logical): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':' . $family . ':' . hash('sha256', $logical);
    }

    public function testReplaceReturnsThePreviousScoreAndRefreshesTheTtl(): void
    {
        self::assertNull($this->store->replaceScoreAt('m', self::NOW, 3600, self::NOW), 'Nothing was replaced.');
        $this->executor->execute(['PEXPIRE', $this->key('score', 'm'), '100000']);

        $previous = $this->store->replaceScoreAt('m', self::NOW + 3500, 3600, self::NOW + 3500);

        self::assertNotNull($previous);
        self::assertSame([self::NOW, self::NOW], [$previous->value, $previous->updatedAt]);
        self::assertSame([(string) (self::NOW + 3500), (string) (self::NOW + 3500)], $this->executor->execute(['HMGET', $this->key('score', 'm'), 'value', 'updatedAt']));
        $ttl = $this->executor->execute(['TTL', $this->key('score', 'm')]);
        self::assertIsInt($ttl);
        self::assertGreaterThan(3590, $ttl, 'Every write sets a fresh TTL, as set() does.');
    }

    public function testReplaceDoesNotOverwriteAMalformedScore(): void
    {
        $this->executor->execute(['HSET', $this->key('score', 'm'), 'value', '05', 'updatedAt', (string) self::NOW]);
        $this->executor->execute(['EXPIRE', $this->key('score', 'm'), '600']);
        $before = $this->executor->execute(['DUMP', $this->key('score', 'm')]);

        try {
            $this->store->replaceScoreAt('m', self::NOW, 3600, self::NOW);
            self::fail('A malformed score was overwritten.');
        } catch (BackendFailureException $exception) {
            self::fail('Malformed state raised BackendFailureException: ' . $exception->getMessage());
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('malformed score state', $exception->getMessage());
        }
        self::assertSame($before, $this->executor->execute(['DUMP', $this->key('score', 'm')]));
    }

    private static function state(int $failures): CircuitBreakerStateDTO
    {
        return new CircuitBreakerStateDTO(FailureStateDTO::STATE_CLOSED, array_fill(0, $failures, self::NOW), self::NOW, 0, 0, [], 0);
    }

    public function testTheCircuitSaveIsAppliedOnlyOnTheVersionThatWasRead(): void
    {
        [$absent, $empty] = $this->store->loadWithVersion('p');
        self::assertNull($absent);
        self::assertSame('', $empty);
        self::assertTrue($this->store->saveIfVersion('p', '', self::state(1)), 'An absent state is saved against the empty version.');
        self::assertFalse($this->store->saveIfVersion('p', '', self::state(9)), 'The empty version no longer matches.');

        [$one, $version] = $this->store->loadWithVersion('p');
        self::assertSame(1, count($one->failures ?? []));
        $this->store->save('p', self::state(2));
        self::assertFalse($this->store->saveIfVersion('p', $version, self::state(9)), 'A concurrent save changed the version.');
        self::assertSame(2, count($this->store->load('p')->failures ?? []), 'A miss writes nothing.');

        [, $current] = $this->store->loadWithVersion('p');
        self::assertTrue($this->store->saveIfVersion('p', $current, self::state(3)));
        self::assertSame(3, count($this->store->load('p')->failures ?? []));
    }
}
