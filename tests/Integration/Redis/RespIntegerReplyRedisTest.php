<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The test harness returns a RESP integer reply exactly (#219). Redis integers are signed 64-bit; where `PHP_INT_SIZE` is 4 a
 * reply such as `PEXPIRETIME` (about 1.8 × 10^12) used to raise "out of range". It now comes back as its canonical decimal string,
 * and a reply that fits the native integer is still an `int`, so existing in-range behaviour is unchanged on both widths.
 *
 * Isolated Redis, exact replies: `SET` then `INCRBY` / `DECRBY` return the new value as an integer reply.
 */
#[Group('integration')]
#[Group('php32')]
final class RespIntegerReplyRedisTest extends TestCase
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

    /** @return array<string, array{string}> */
    public static function replies(): array
    {
        return [
            'zero' => ['0'],
            'one' => ['1'],
            'minus one (no expiry)' => ['-1'],
            'native maximum on 32-bit' => ['2147483647'],
            'one past it' => ['2147483648'],
            'native minimum on 32-bit' => ['-2147483648'],
            'one before it' => ['-2147483649'],
            'a millisecond epoch value' => ['1794650400123'],
            'the next millisecond' => ['1794650400124'],
            '2^53 - 1' => ['9007199254740991'],
            '2^53 + 1, not representable in a double' => ['9007199254740993'],
            'the 64-bit maximum' => ['9223372036854775807'],
            'the 64-bit minimum' => ['-9223372036854775807'],
        ];
    }

    #[DataProvider('replies')]
    public function testAnIntegerReplyIsExactAndAnIntOnlyWhenItFitsTheNativeInteger(string $value): void
    {
        $reply = $this->executor->execute(['SET', 'k', '0']);
        self::assertSame('OK', $reply);

        $actual = $this->executor->execute(['INCRBY', 'k', $value]);

        $native = filter_var($value, FILTER_VALIDATE_INT);
        if ($native !== false) {
            self::assertSame($native, $actual, 'A reply that fits the native integer is an int.');
        } else {
            self::assertSame($value, $actual, 'A reply that does not fit is its exact decimal string.');
        }
    }

    public function testTwoRepliesOneMillisecondApartStayDistinct(): void
    {
        $this->executor->execute(['SET', 'k', '1794650400123']);
        $first = $this->executor->execute(['INCRBY', 'k', '0']);
        $second = $this->executor->execute(['INCRBY', 'k', '1']);

        self::assertNotSame($first, $second);
        self::assertSame(PHP_INT_SIZE === 8 ? 1794650400123 : '1794650400123', $first);
        self::assertSame(PHP_INT_SIZE === 8 ? 1794650400124 : '1794650400124', $second);
    }

    public function testALengthLineIsStillANativeInteger(): void
    {
        $this->executor->execute(['RPUSH', 'list', 'a', 'b', 'c']);

        self::assertSame(['a', 'b', 'c'], $this->executor->execute(['LRANGE', 'list', '0', '-1']));
        self::assertSame(3, $this->executor->execute(['LLEN', 'list']));
    }
}
