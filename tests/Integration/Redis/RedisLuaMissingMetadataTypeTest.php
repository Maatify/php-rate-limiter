<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-39 / LT-185-39 regression suite: Missing metadata key or field returns classified malformed error without raw Lua crash, leaving store unmutated.
 */
#[Group('f-185-39')]
final class RedisLuaMissingMetadataTypeTest extends TestCase
{
    private const NOW = 1_793_793_600;

    private const NAMESPACE = 'f185-39';

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

    /** @return array<string, string> `DUMP` of every key. */
    private function dump(): array
    {
        $keys = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        $out = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $bytes = $this->executor->execute(['DUMP', $key]);
            self::assertIsString($bytes);
            $out[$key] = $bytes;
        }
        ksort($out);

        return $out;
    }

    private function assertMalformedWithoutMutation(callable $operation, string $expectedMessage): void
    {
        $before = $this->dump();
        try {
            $operation();
            self::fail('Expected RateLimiterException was not thrown.');
        } catch (RateLimiterException $e) {
            self::assertStringContainsString($expectedMessage, $e->getMessage());
            self::assertStringNotContainsString('user_script', $e->getMessage());
            self::assertStringNotContainsString('bad argument', $e->getMessage());
        }
        self::assertSame($before, $this->dump(), 'Store must remain byte-identical after malformed error');
    }

    public function testIncrementWatchFlagAtClassifiedErrorWhenMetaDeleted(): void
    {
        $this->store->incrementWatchFlagAt('w_key', 600, self::NOW);
        $metaKeys = $this->executor->execute(['KEYS', '*watch-meta*']);
        self::assertIsArray($metaKeys);
        $k = $metaKeys[0] ?? '';
        assert(is_string($k));
        $this->executor->execute(['DEL', $k]);

        $this->assertMalformedWithoutMutation(
            fn() => $this->store->incrementWatchFlagAt('w_key', 600, self::NOW + 1),
            'malformed watch metadata',
        );
    }

    public function testIncrementWatchFlagAtClassifiedErrorWhenExpiresAtFieldDeleted(): void
    {
        $this->store->incrementWatchFlagAt('w_key2', 600, self::NOW);
        $metaKeys = $this->executor->execute(['KEYS', '*watch-meta*']);
        self::assertIsArray($metaKeys);
        $k = $metaKeys[0] ?? '';
        assert(is_string($k));
        $this->executor->execute(['HDEL', $k, 'expiresAt']);

        $this->assertMalformedWithoutMutation(
            fn() => $this->store->incrementWatchFlagAt('w_key2', 600, self::NOW + 1),
            'malformed watch metadata',
        );
    }

    public function testAddDistinctAtClassifiedErrorWhenMetaFieldDeleted(): void
    {
        $this->store->addDistinctAt('d_key', 'item1', 600, self::NOW);
        $metaKeys = $this->executor->execute(['KEYS', '*distinct-meta*']);
        self::assertIsArray($metaKeys);
        $k = $metaKeys[0] ?? '';
        assert(is_string($k));
        $this->executor->execute(['HDEL', $k, 'expiresAt']);

        $this->assertMalformedWithoutMutation(
            fn() => $this->store->addDistinctAt('d_key', 'item2', 600, self::NOW + 1),
            'malformed distinct metadata',
        );
    }

    public function testAddDistinctBoundedAtClassifiedErrorWhenMetaFieldDeleted(): void
    {
        $this->store->addDistinctBoundedAt('b_key', 'item1', 600, 10, self::NOW);
        $metaKeys = $this->executor->execute(['KEYS', '*distinct-meta*']);
        self::assertIsArray($metaKeys);
        $k = $metaKeys[0] ?? '';
        assert(is_string($k));
        $this->executor->execute(['HDEL', $k, 'expiresAt']);

        $this->assertMalformedWithoutMutation(
            fn() => $this->store->addDistinctBoundedAt('b_key', 'item2', 600, 10, self::NOW + 1),
            'malformed bounded metadata',
        );
    }

    public function testAddDistinctBoundedWithSnapshotAtClassifiedErrorWhenMetaFieldDeleted(): void
    {
        $this->store->addDistinctBoundedWithSnapshotAt('bs_key', 'item1', 600, 10, self::NOW);
        $metaKeys = $this->executor->execute(['KEYS', '*distinct-meta*']);
        self::assertIsArray($metaKeys);
        $k = $metaKeys[0] ?? '';
        assert(is_string($k));
        $this->executor->execute(['HDEL', $k, 'expiresAt']);

        $this->assertMalformedWithoutMutation(
            fn() => $this->store->addDistinctBoundedWithSnapshotAt('bs_key', 'item2', 600, 10, self::NOW + 1),
            'malformed bounded metadata',
        );
    }

    public function testAddDistinctBoundedAcrossRotationAtClassifiedErrorWhenMetaFieldDeleted(): void
    {
        $this->store->addDistinctBoundedAcrossRotationAt('cur', 'br', 'prev', 'm1', 'm0', 600, 10, self::NOW * 1000);
        $metaKeys = $this->executor->execute(['KEYS', '*distinct-meta*']);
        self::assertIsArray($metaKeys);
        foreach ($metaKeys as $mk) {
            assert(is_string($mk));
            $this->executor->execute(['HDEL', $mk, 'expiresAt']);
        }

        $this->assertMalformedWithoutMutation(
            fn() => $this->store->addDistinctBoundedAcrossRotationAt('cur', 'br', 'prev', 'm2', 'm0', 600, 10, (self::NOW + 1) * 1000),
            'malformed',
        );
    }

    public function testIncrementWatchFlagAcrossRotationAtClassifiedErrorWhenMetaFieldDeleted(): void
    {
        $this->store->incrementWatchFlagAcrossRotationAt('cur_w', 'prev_w', 600, self::NOW);
        $metaKeys = $this->executor->execute(['KEYS', '*watch-meta*']);
        self::assertIsArray($metaKeys);
        foreach ($metaKeys as $mk) {
            assert(is_string($mk));
            $this->executor->execute(['HDEL', $mk, 'expiresAt']);
        }

        $this->assertMalformedWithoutMutation(
            fn() => $this->store->incrementWatchFlagAcrossRotationAt('cur_w', 'prev_w', 600, self::NOW + 1),
            'malformed',
        );
    }

    public function testGeneralRuntimeCheckOnlyClassifiedErrorWhenDistinctMetaCorrupted(): void
    {
        $config = new RateLimiterConfig('test_secret', 'test_fp', 'prod');
        $emitter = new RecordingFailureSignalEmitter();
        $limiter = RateLimiterBuilder::fromFullCapabilityStore($config, $this->store, $emitter)->build();

        // Distinct accounts trigger spray correlation on K1 (distinct-meta)
        for ($i = 0; $i < 4; $i++) {
            $limiter->limit(new RateLimitContextDTO('192.0.2.1', 'acct_' . $i, 'ua'), RateLimitCommand::checkOnly('login_protection'));
        }

        $metas = $this->executor->execute(['KEYS', '*distinct-meta*']);
        self::assertIsArray($metas);
        self::assertNotEmpty($metas);
        $k = $metas[0] ?? '';
        assert(is_string($k));
        $this->executor->execute(['HDEL', $k, 'expiresAt']);

        // Next checkOnly touches the corrupted distinct-meta.
        // It must raise the classified RateLimiterException('malformed bounded metadata') rather than a raw Lua script error.
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('malformed bounded metadata');
        $limiter->limit(new RateLimitContextDTO('192.0.2.1', 'acct_test', 'ua'), RateLimitCommand::checkOnly('login_protection'));
    }

    public function testPublicRuntimePropagatesClassifiedStoreRejectionWithoutChangingState(): void
    {
        $probe = new class {
            public bool $reject = false;
            public int $rejections = 0;
        };
        $executor = new CallableRedisCommandExecutor(function (array $command) use ($probe): mixed {
            $metadataKey = $command[4] ?? null;
            if ($probe->reject && $command[0] === 'EVAL' && is_string($metadataKey) && str_contains($metadataKey, ':distinct-meta:')) {
                $probe->rejections++;
                throw new RateLimiterException('malformed bounded metadata');
            }

            return $this->executor->execute($command);
        });
        $emitter = new RecordingFailureSignalEmitter();
        $limiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('test_secret', 'test_fp', 'prod'),
            new RedisFullCapabilityStore($executor, self::NAMESPACE),
            $emitter,
        )->withClock(new FixedClock('@' . self::NOW))->build();
        $command = RateLimitCommand::checkOnly('login_protection');
        $limiter->limit(new RateLimitContextDTO(ip: '192.0.2.1', ua: 'ua', accountId: 'account-one'), $command);
        self::assertNotEmpty($this->dump());

        $probe->reject = true;
        $this->assertMalformedWithoutMutation(
            static fn() => $limiter->limit(new RateLimitContextDTO(ip: '192.0.2.1', ua: 'ua', accountId: 'account-two'), $command),
            'malformed bounded metadata',
        );
        self::assertSame(1, $probe->rejections);
    }
}
