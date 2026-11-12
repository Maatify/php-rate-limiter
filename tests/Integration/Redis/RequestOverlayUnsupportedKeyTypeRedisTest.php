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
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-5 (DEC-037), a key of a type the request overlay does not model (a list where a script expects a string), through
 * the public API. On `main` the real Redis answers WRONGTYPE and the store classifies it as a RateLimiterException; the
 * overlay must answer the same way for every executor, in particular for phpredis, which throws an untyped RedisException
 * for a script error that carries no Redis error code. The request is rejected before it writes anything, and the
 * rejection is not a backend failure: no circuit-breaker state, no signal and no fallback result.
 */
#[Group('t5-request-atomicity')]
final class RequestOverlayUnsupportedKeyTypeRedisTest extends TestCase
{
    private const NAMESPACE = 't5-key-type';

    private RespRedisCommandExecutor $executor;

    private string $host;

    private int $port;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->host = $host;
        $this->port = (int) $port;
        $this->executor = new RespRedisCommandExecutor($host, $this->port);
        $this->executor->execute(['FLUSHDB']);
    }

    /** @return array<string, array{string}> */
    public static function executors(): array
    {
        return ['reference executor' => ['reference'], 'phpredis rawCommand' => ['phpredis']];
    }

    private function storeExecutor(string $kind): RespRedisCommandExecutor|CallableRedisCommandExecutor
    {
        if ($kind === 'reference') {
            return $this->executor;
        }
        if (! extension_loaded('redis') || ! class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis (phpredis) is required for this case.');
        }
        $redis = new \Redis();
        $redis->connect($this->host, $this->port);

        return new CallableRedisCommandExecutor(
            static fn(array $command): mixed => $redis->rawCommand((string) $command[0], ...array_slice($command, 1)),
        );
    }

    private function limiter(RespRedisCommandExecutor|CallableRedisCommandExecutor $executor, RecordingFailureSignalEmitter $emitter): RateLimiterInterface
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('test_secret', 'test_fp', 'prod'),
            new RedisFullCapabilityStore($executor, self::NAMESPACE),
            $emitter,
        )->withClock(new FixedClock('@' . time()))->build();
    }

    private function context(int $i): RateLimitContextDTO
    {
        return new RateLimitContextDTO(ip: '198.51.100.7', ua: 'ua-' . ($i % 2), accountId: 'acct-' . $i);
    }

    /** @return array<string, string> every key and its content, so two states compare whole */
    private function keyspace(): array
    {
        $keys = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        $state = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $dump = $this->executor->execute(['DUMP', $key]);
            $state[$key] = is_string($dump) ? bin2hex($dump) : '';
        }
        ksort($state);

        return $state;
    }

    /** @return array<string, array{string, int}> DUMP and remaining lifetime of every key */
    private function snapshot(): array
    {
        $snapshot = [];
        $keys = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        foreach ($keys as $key) {
            self::assertIsString($key);
            $dump = $this->executor->execute(['DUMP', $key]);
            $ttl = $this->executor->execute(['PTTL', $key]);
            self::assertIsString($dump);
            self::assertIsInt($ttl);
            $snapshot[$key] = [$dump, $ttl];
        }

        return $snapshot;
    }

    /** @param array<string, array{string, int}> $snapshot */
    private function restore(array $snapshot): void
    {
        $this->executor->execute(['FLUSHDB']);
        foreach ($snapshot as $key => [$dump, $ttl]) {
            $this->executor->execute(['RESTORE', $key, max(0, $ttl), $dump]);
        }
    }

    #[DataProvider('executors')]
    public function testAnUnsupportedKeyTypeIsAClassifiedErrorWithoutWritesCircuitOrFallback(string $kind): void
    {
        $setup = $this->limiter($this->executor, new RecordingFailureSignalEmitter());
        for ($round = 0; $round < 2; $round++) {
            for ($i = 0; $i < 6; $i++) {
                $setup->limit($this->context($i), RateLimitCommand::recordFailure('login_protection'));
            }
        }
        $snapshot = $this->snapshot();
        $watchKeys = array_values(array_filter(array_keys($snapshot), static fn(string $key): bool => str_contains($key, ':watch:')));
        self::assertNotEmpty($watchKeys, 'The scenario created watch counters (string keys).');

        $classified = 0;
        foreach ($watchKeys as $watchKey) {
            foreach (['checkOnly', 'recordFailure'] as $command) {
                for ($i = 0; $i < 6; $i++) {
                    $this->restore($snapshot);
                    $ttl = $snapshot[$watchKey][1];
                    $this->executor->execute(['DEL', $watchKey]);
                    $this->executor->execute(['LPUSH', $watchKey, 'x']);
                    if ($ttl > 0) {
                        $this->executor->execute(['PEXPIRE', $watchKey, $ttl]);
                    }
                    $before = $this->keyspace();
                    $emitter = new RecordingFailureSignalEmitter();
                    $limiter = $this->limiter($this->storeExecutor($kind), $emitter);

                    try {
                        $limiter->limit($this->context($i), $command === 'checkOnly' ? RateLimitCommand::checkOnly('login_protection') : RateLimitCommand::recordFailure('login_protection'));
                    } catch (RateLimiterException $exception) {
                        self::assertSame($before, $this->keyspace(), 'A rejected request leaves Redis byte for byte unchanged.');
                        self::assertSame([], $emitter->getEmitted(), 'No circuit signal.');
                        $circuit = $this->executor->execute(['KEYS', '*:circuit:*']);
                        self::assertSame([], $circuit, 'No circuit-breaker state was written.');
                        $classified++;
                    }
                    // Any other throwable (an untyped RedisException, a BackendFailureException) escapes the try and fails the test.
                }
            }
        }
        self::assertGreaterThan(0, $classified, 'At least one request read the wrong-type key.');
    }
}
