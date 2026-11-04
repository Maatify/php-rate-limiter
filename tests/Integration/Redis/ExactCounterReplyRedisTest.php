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
 * F-185-12 (#185): a counter the store increments in Lua is returned as the stored decimal string, not through a Lua
 * number (a double, exact only up to 2^53). `increment()` returns "the new value" (`RateLimitStoreInterface`), and the
 * watch-flag increments return the count; each must equal the value Redis stores. On `4e90064` a score of 2^53 incremented
 * by 1 was stored as 2^53 + 1 and returned as 2^53.
 *
 * F-185-13: the rotated watch flag accepts only a canonical count ('0' or no leading zero) for the current and the
 * previous value, as `getWatchFlag()` does; `"5.0"`, `"+5"` and `" 5"` are malformed state and leave every key unchanged.
 */
#[Group('f-185-12')]
#[Group('f-185-13')]
final class ExactCounterReplyRedisTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const NAMESPACE = 'f185-12';

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

    /** @return iterable<string, array{int}> Stored values incremented by 1; 2^53 - 1 is the control. */
    public static function starts(): iterable
    {
        yield 'control 2^53 - 1' => [9007199254740991];
        yield '2^53' => [9007199254740992];
        yield '2^53 + 1' => [9007199254740993];
        yield 'PHP_INT_MAX - 1' => [PHP_INT_MAX - 1];
    }

    #[DataProvider('starts')]
    public function testScoreIncrementReturnsTheStoredValue(int $start): void
    {
        $score = $this->key('score', 'counter');
        $this->executor->execute(['HSET', $score, 'value', (string) $start, 'updatedAt', (string) self::NOW]);
        $this->executor->execute(['EXPIRE', $score, '600']);

        $returned = $this->store->incrementAt('counter', 600, 1, self::NOW);

        self::assertSame((string) ($start + 1), $this->executor->execute(['HGET', $score, 'value']));
        self::assertSame($start + 1, $returned, 'increment() must return the value Redis stores.');
    }

    #[DataProvider('starts')]
    public function testWatchFlagIncrementReturnsTheStoredCount(int $start): void
    {
        $this->seedWatch('watch', $start);

        $returned = $this->store->incrementWatchFlagAt('watch', 600, self::NOW);

        self::assertSame((string) ($start + 1), $this->executor->execute(['GET', $this->key('watch', 'watch')]));
        self::assertSame($start + 1, $returned);
    }

    #[DataProvider('starts')]
    public function testRotatedWatchFlagIncrementReturnsTheExactSum(int $start): void
    {
        $this->seedWatch('current', $start - 1);
        $this->seedWatch('previous', 1);

        $returned = $this->store->incrementWatchFlagAcrossRotationAt('current', 'previous', 600, self::NOW);

        self::assertSame((string) $start, $this->executor->execute(['GET', $this->key('watch', 'current')]));
        self::assertSame($start + 1, $returned, 'The rotated count is the current count plus the live previous count.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function nonCanonical(): iterable
    {
        foreach (['current', 'previous'] as $slot) {
            yield $slot . ' "5.0"' => [$slot, '5.0'];
            yield $slot . ' "+5"' => [$slot, '+5'];
            yield $slot . ' " 5"' => [$slot, ' 5'];
        }
    }

    #[DataProvider('nonCanonical')]
    public function testANonCanonicalWatchValueIsMalformedInTheRotatedIncrementAsInGetWatchFlag(string $slot, string $raw): void
    {
        $this->seedWatch('current', 5);
        $this->seedWatch('previous', 1);
        $this->executor->execute(['SET', $this->key('watch', $slot), $raw, 'KEEPTTL']);
        $before = $this->dump();

        self::assertMalformed(fn(): int => $this->store->getWatchFlag($slot), 'getWatchFlag');
        self::assertMalformed(fn(): int => $this->store->incrementWatchFlagAcrossRotationAt('current', 'previous', 600, self::NOW), 'the rotated increment');
        self::assertSame($before, $this->dump(), 'Malformed state is left byte-identical.');
    }

    public function testControlACanonicalCurrentCountIncrementsInTheRotatedIncrement(): void
    {
        $this->seedWatch('current', 5);

        self::assertSame(6, $this->store->incrementWatchFlagAcrossRotationAt('current', 'previous', 600, self::NOW));
        self::assertSame('6', $this->executor->execute(['GET', $this->key('watch', 'current')]));
    }

    private static function assertMalformed(callable $operation, string $label): void
    {
        try {
            $operation();
            self::fail($label . ': a non-canonical watch value was accepted.');
        } catch (BackendFailureException $exception) {
            self::fail($label . ': raised BackendFailureException: ' . $exception->getMessage());
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('alformed', $exception->getMessage(), $label);
        }
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

    private function seedWatch(string $logical, int $value): void
    {
        $this->executor->execute(['SET', $this->key('watch', $logical), (string) $value, 'EX', '600']);
        $this->executor->execute(['HSET', $this->key('watch-meta', $logical), 'expiresAt', (string) (self::NOW + 600)]);
        $this->executor->execute(['EXPIRE', $this->key('watch-meta', $logical), '600']);
    }
}
