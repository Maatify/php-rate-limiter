<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-08 (#185, DEC-030): `RedisFullCapabilityStore::incrementIfSnapshot()` sets a score only while its stored
 * `(value, updatedAt)` equals the expected snapshot (a null snapshot: the score is absent). A miss writes nothing and
 * returns null; malformed state is an error, never a miss; the TTL applies only on creation.
 */
#[Group('f-185-08')]
final class SnapshotFencedScoreWriteRedisTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const NAMESPACE = 'f185-08-store';

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

    private function key(): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':score:' . hash('sha256', 'score');
    }

    private function seed(string $value, string $updatedAt): void
    {
        $this->executor->execute(['HSET', $this->key(), 'value', $value, 'updatedAt', $updatedAt]);
        $this->executor->execute(['EXPIRE', $this->key(), '600']);
    }

    private function dump(): ?string
    {
        $bytes = $this->executor->execute(['DUMP', $this->key()]);

        return is_string($bytes) ? $bytes : null;
    }

    public function testAMatchingSnapshotIsWrittenAndKeepsTheTtl(): void
    {
        $this->seed('5', (string) (self::NOW - 1500));
        $this->executor->execute(['EXPIRE', $this->key(), '300']);

        self::assertSame(2, $this->store->incrementIfSnapshotAt('score', 86400, 5, self::NOW - 1500, 2, self::NOW));
        self::assertSame(['2', (string) self::NOW], $this->executor->execute(['HMGET', $this->key(), 'value', 'updatedAt']));
        $ttl = $this->executor->execute(['TTL', $this->key()]);
        self::assertIsInt($ttl);
        self::assertLessThanOrEqual(300, $ttl, 'An existing score keeps its TTL, as with increment().');
    }

    public function testAnAbsentScoreIsCreatedOnlyForANullSnapshot(): void
    {
        self::assertNull($this->store->incrementIfSnapshotAt('score', 600, 5, self::NOW, 2, self::NOW), 'An expected snapshot for an absent score is a miss.');
        self::assertSame(0, $this->executor->execute(['EXISTS', $this->key()]));

        self::assertSame(2, $this->store->incrementIfSnapshotAt('score', 600, null, null, 2, self::NOW));
        $ttl = $this->executor->execute(['TTL', $this->key()]);
        self::assertIsInt($ttl);
        self::assertGreaterThan(590, $ttl, 'The TTL applies on creation.');
    }

    /** @return iterable<string, array{?int, ?int}> */
    public static function misses(): iterable
    {
        yield 'another value' => [6, self::NOW - 1500];
        yield 'another updatedAt' => [5, self::NOW - 1499];
        yield 'a null snapshot for a present score' => [null, null];
    }

    #[DataProvider('misses')]
    public function testAStaleSnapshotIsAMissThatWritesNothing(?int $value, ?int $updatedAt): void
    {
        $this->seed('5', (string) (self::NOW - 1500));
        $before = $this->dump();

        self::assertNull($this->store->incrementIfSnapshotAt('score', 600, $value, $updatedAt, 2, self::NOW));
        self::assertSame($before, $this->dump());
    }

    public function testTheWrittenValueIsExactAbove2Pow53(): void
    {
        $this->seed('9007199254740993', (string) self::NOW);

        self::assertSame(9007199254740995, $this->store->incrementIfSnapshotAt('score', 600, 9007199254740993, self::NOW, 9007199254740995, self::NOW));
        self::assertSame('9007199254740995', $this->executor->execute(['HGET', $this->key(), 'value']));
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformed(): iterable
    {
        yield 'non-canonical value' => ['05', (string) (self::NOW - 1500)];
        yield 'non-integer updatedAt' => ['5', 'soon'];
    }

    #[DataProvider('malformed')]
    public function testMalformedStateIsAnErrorNotAMiss(string $value, string $updatedAt): void
    {
        $this->seed($value, $updatedAt);
        $before = $this->dump();

        try {
            $this->store->incrementIfSnapshotAt('score', 600, 6, self::NOW - 1500, 2, self::NOW);
            self::fail('Malformed score state was treated as a miss or written.');
        } catch (BackendFailureException $exception) {
            self::fail('Malformed state raised BackendFailureException: ' . $exception->getMessage());
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('malformed score state', $exception->getMessage());
        }
        self::assertSame($before, $this->dump());
    }

    public function testAHalfSnapshotIsAContractViolation(): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('A score snapshot has both a value and an updatedAt, or neither.');

        $this->store->incrementIfSnapshotAt('score', 600, 5, null, 2, self::NOW);
    }
}
