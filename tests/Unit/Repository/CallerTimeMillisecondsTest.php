<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Repository;

use DateTimeImmutable;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\CallerTimeMilliseconds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** The canonical decimal-millisecond caller time (#215, DEC-039): exact on 32-bit and 64-bit PHP. */
#[Group('php32')]
final class CallerTimeMillisecondsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function instants(): iterable
    {
        yield 'contemporary epoch' => ['2026-11-14 10:00:00.123456 UTC', '1794650400123'];
        yield 'whole second' => ['2026-11-14 10:00:00 UTC', '1794650400000'];
        yield 'last millisecond of a second' => ['2026-11-14 10:00:00.999999 UTC', '1794650400999'];
        yield 'the epoch itself' => ['1970-01-01 00:00:00 UTC', '0'];
        yield 'milliseconds only, no leading zero' => ['1970-01-01 00:00:00.007 UTC', '7'];
        yield 'one second and one millisecond' => ['1970-01-01 00:00:01.001 UTC', '1001'];
    }

    #[DataProvider('instants')]
    public function testAnInstantBecomesItsExactCanonicalDecimal(string $instant, string $expected): void
    {
        $decimal = CallerTimeMilliseconds::fromInstant(new DateTimeImmutable($instant));

        self::assertSame($expected, $decimal);
        self::assertSame($expected, CallerTimeMilliseconds::validate($decimal));
    }

    public function testAnInstantBeforeTheEpochIsRejectedAsNegativeBySemanticTimeRules(): void
    {
        $decimal = CallerTimeMilliseconds::fromInstant(new DateTimeImmutable('1969-12-31 23:59:58 UTC'));

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Caller time must be a non-negative Unix timestamp.');
        CallerTimeMilliseconds::validate($decimal);
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejected(): iterable
    {
        yield 'negative' => ['-1', 'Caller time must be a non-negative Unix timestamp.'];
        yield 'empty' => ['', 'Caller time in milliseconds must be a canonical decimal integer.'];
        yield 'leading zero' => ['01794650400123', 'Caller time in milliseconds must be a canonical decimal integer.'];
        yield 'plus sign' => ['+1794650400123', 'Caller time in milliseconds must be a canonical decimal integer.'];
        yield 'fraction' => ['1794650400123.0', 'Caller time in milliseconds must be a canonical decimal integer.'];
        yield 'exponent' => ['1e12', 'Caller time in milliseconds must be a canonical decimal integer.'];
        yield 'padding space' => [' 1794650400123', 'Caller time in milliseconds must be a canonical decimal integer.'];
        yield 'one above the exact range' => ['9007199254740992', 'Caller time in milliseconds is not representable.'];
        yield 'seventeen digits' => ['10000000000000000', 'Caller time in milliseconds is not representable.'];
    }

    #[DataProvider('rejected')]
    public function testAMalformedOrUnrepresentableValueIsRejectedWithATypedException(string $value, string $message): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage($message);
        CallerTimeMilliseconds::validate($value);
    }

    public function testTheExactRangeBoundaryIsAccepted(): void
    {
        self::assertSame('9007199254740991', CallerTimeMilliseconds::validate('9007199254740991'));
    }

    public function testSecondsAreTheValueWithItsLastThreeDigitsDropped(): void
    {
        self::assertSame('1794650400', CallerTimeMilliseconds::seconds('1794650400123'));
        self::assertSame('1', CallerTimeMilliseconds::seconds('1999'));
        self::assertSame('0', CallerTimeMilliseconds::seconds('999'));
        self::assertSame('0', CallerTimeMilliseconds::seconds('0'));
    }

    public function testTheNativeIntegerHopIsLosslessOrATypedFailureNeverASaturatedValue(): void
    {
        self::assertSame(1001, CallerTimeMilliseconds::nativeInteger('1001'));

        if (PHP_INT_SIZE === 8) {
            self::assertSame(1794650400123, CallerTimeMilliseconds::nativeInteger('1794650400123'));

            return;
        }

        // Where PHP_INT_SIZE is 4 a current epoch does not fit: the cast would give 2147483647.
        self::assertSame(PHP_INT_MAX, (int) '1794650400123');
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('not representable as a native integer on this PHP build');
        CallerTimeMilliseconds::nativeInteger('1794650400123');
    }
}
