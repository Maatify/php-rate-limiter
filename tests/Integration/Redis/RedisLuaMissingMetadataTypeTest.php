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
use Maatify\RateLimiter\Tests\Support\Redis\RecordingRedisCommandExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testRotatedWatchRejectsNoncanonicalExpiryBeforeWriting(): void
    {
        $this->store->incrementWatchFlagAt('previous-watch', 600, self::NOW);
        $keys = $this->executor->execute(['KEYS', '*watch-meta*']);
        self::assertIsArray($keys);
        self::assertCount(1, $keys);
        $key = $keys[0] ?? null;
        self::assertIsString($key);
        $this->executor->execute(['HSET', $key, 'expiresAt', '01']);

        $this->assertMalformedWithoutMutation(
            fn() => $this->store->incrementWatchFlagAcrossRotationAt('current-watch', 'previous-watch', 600, self::NOW + 1),
            'malformed previous watch state',
        );
    }

    public function testPublicRuntimeRejectsNoncanonicalDistinctExpiryForPreviouslySeenAccountsWithoutWriting(): void
    {
        $config = new RateLimiterConfig('test_secret', 'test_fp', 'prod');
        $emitter = new RecordingFailureSignalEmitter();
        $limiter = RateLimiterBuilder::fromFullCapabilityStore($config, $this->store, $emitter)
            ->withClock(new FixedClock('@' . self::NOW))
            ->build();
        $check = RateLimitCommand::checkOnly('login_protection');
        $limiter->limit(new RateLimitContextDTO(ip: '192.0.2.1', ua: 'ua', accountId: 'account-one'), $check);

        // Establish both account scopes before the rejection check. This does not assert
        // request-wide rollback when an earlier independent scope writes before a later rejection.
        $secondAccount = new RateLimitContextDTO(ip: '192.0.2.1', ua: 'ua', accountId: 'account-two');
        $limiter->limit($secondAccount, $check);

        $keys = $this->executor->execute(['KEYS', '*distinct-meta*']);
        self::assertIsArray($keys);
        self::assertNotEmpty($keys);
        foreach ($keys as $key) {
            self::assertIsString($key);
            $this->executor->execute(['HSET', $key, 'expiresAt', '01']);
        }

        $this->assertMalformedWithoutMutation(
            static fn() => $limiter->limit($secondAccount, $check),
            'malformed bounded metadata',
        );
    }

    public function testBlockReadAndPublicationRejectNoncanonicalExpiryBeforeWriting(): void
    {
        $this->store->blockWithCycleTracking('block', null, 2, 30, self::NOW, 60, 2, 120, 300);
        $keys = $this->executor->execute(['KEYS', '*:block:*']);
        self::assertIsArray($keys);
        self::assertCount(1, $keys);
        $key = $keys[0] ?? null;
        self::assertIsString($key);
        $this->executor->execute(['HSET', $key, 'expiresAt', '01']);

        $this->assertMalformedWithoutMutation(fn() => $this->store->checkBlockAt('block', self::NOW), 'malformed block state');
        $this->assertMalformedWithoutMutation(fn() => $this->store->peekBlockAt('block', self::NOW), 'malformed block state');
        $this->assertMalformedWithoutMutation(
            fn() => $this->store->blockWithCycleTracking('block', null, 2, 30, self::NOW, 60, 2, 120, 300),
            'malformed hard-block state',
        );
    }

    public function testDeviceCapPairValidatesBothScopesBeforeWriting(): void
    {
        $this->store->addDistinctBoundedAt('existing-source', 'known-device', 60, 10, self::NOW);
        $keys = $this->executor->execute(['KEYS', '*:distinct-meta:*']);
        self::assertIsArray($keys);
        self::assertCount(1, $keys);
        $key = $keys[0] ?? null;
        self::assertIsString($key);
        $this->executor->execute(['HSET', $key, 'expiresAt', '01']);

        $this->assertMalformedWithoutMutation(
            fn() => $this->store->addDeviceCapPairAt('new-account', 'new-device', 10, 'existing-source', 'new-device', 10, 60, self::NOW),
            'malformed bounded metadata',
        );
    }

    public function testProductionExpiryValidatorsRejectInvalidValuesInReadOnlyLua(): void
    {
        $recording = new RecordingRedisCommandExecutor($this->executor);
        $store = new RedisFullCapabilityStore($recording, self::NAMESPACE);
        $store->checkBlockAt('unused-block', self::NOW);
        $store->blockWithCycleTracking('healthy-block', null, 2, 30, self::NOW, 60, 2, 120, 300);
        $store->incrementWatchFlagAcrossRotationAt('current-watch', 'previous-watch', 60, self::NOW);

        $before = $this->dump();
        foreach (['validBackendExpiry', 'validHardBlockExpiry', 'validBackendExpiry'] as $index => $validator) {
            $script = $recording->commands[$index][1] ?? null;
            self::assertIsString($script);
            self::assertSame(1, preg_match('/^local function ' . $validator . '\(value\)\n.*?^end$/ms', $script, $match));
            $definition = $match[0] ?? null;
            self::assertIsString($definition);
            $exercise = $definition . "\n" . <<<LUA
                return {
                    {$validator}(true) and 1 or 0,
                    {$validator}({}) and 1 or 0,
                    {$validator}(ARGV[1]) and 1 or 0,
                    {$validator}(ARGV[2]) and 1 or 0,
                    {$validator}(ARGV[3]) and 1 or 0
                }
                LUA;
            self::assertSame([0, 0, 0, 1, 1], $this->executor->execute(['EVAL_RO', $exercise, 0, '01', '0', '1793793600']));
        }
        self::assertSame($before, $this->dump());
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function classifiedStoreRejections(): iterable
    {
        yield 'runtime block read' => ['check', 1, 'malformed block state'];
        yield 'operational block read' => ['peek', 1, 'malformed block state'];
        yield 'hard-block publication' => ['publish', 6, 'malformed cycle history'];
        yield 'rotated watch increment' => ['watch', 4, 'malformed previous watch state'];
    }

    #[DataProvider('classifiedStoreRejections')]
    public function testClassifiedStoreRejectionHasNoFallbackWrite(string $operation, int $keys, string $message): void
    {
        $this->executor->execute(['SET', 'defensive-contract-sentinel', 'preserved']);
        $before = $this->dump();
        $rejection = new RateLimiterException($message);
        $calls = 0;
        $store = new RedisFullCapabilityStore(new CallableRedisCommandExecutor(
            static function (array $command) use (&$calls, $keys, $rejection): never {
                $calls++;
                self::assertSame('EVAL', $command[0]);
                self::assertSame($keys, $command[2] ?? null);
                throw $rejection;
            },
        ), self::NAMESPACE);

        try {
            match ($operation) {
                'check' => $store->checkBlockAt('current', self::NOW),
                'peek' => $store->peekBlockAt('current', self::NOW),
                'publish' => $store->blockWithCycleTracking('current', 'previous', 2, 30, self::NOW, 60, 2, 120, 300),
                'watch' => $store->incrementWatchFlagAcrossRotationAt('current', 'previous', 60, self::NOW),
                default => self::fail('Unknown store operation.'),
            };
            self::fail('Expected the classified rejection to reach the caller.');
        } catch (RateLimiterException $caught) {
            self::assertSame($rejection, $caught);
        }

        self::assertSame(1, $calls, 'The store must not retry or issue a fallback command.');
        self::assertSame($before, $this->dump());
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
