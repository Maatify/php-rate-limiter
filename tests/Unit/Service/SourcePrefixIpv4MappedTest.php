<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Maatify\RateLimiter\Service\SourcePrefix;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F-185-38 / LT-185-38 regression suite: IPv4-mapped IPv6 (::ffff:0:0/96) normalization and isolation.
 */
final class SourcePrefixIpv4MappedTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function mappedAddresses(): iterable
    {
        yield 'standard dotted' => ['::ffff:203.0.113.9', '203.0.113.9'];
        yield 'another dotted' => ['::ffff:198.51.100.7', '198.51.100.7'];
        yield 'private class a' => ['::ffff:10.0.0.1', '10.0.0.1'];
        yield 'hex notation' => ['::ffff:c000:0201', '192.0.2.1'];
        yield 'full zero expanded' => ['0000:0000:0000:0000:0000:ffff:c000:0201', '192.0.2.1'];
        yield 'partially expanded' => ['0:0:0:0:0:ffff:192.0.2.1', '192.0.2.1'];
    }

    #[DataProvider('mappedAddresses')]
    public function testUnwrapIpv4MappedReturnsCanonicalIpv4(string $mapped, string $expectedIpv4): void
    {
        self::assertSame($expectedIpv4, SourcePrefix::unwrapIpv4Mapped($mapped));
        self::assertSame($expectedIpv4, SourcePrefix::of($mapped));
        self::assertSame($expectedIpv4, SourcePrefix::of($mapped, 48));
        self::assertFalse(SourcePrefix::isPureIpv6($mapped));
    }

    public function testRawIpv4IsUnchanged(): void
    {
        self::assertSame('198.51.100.7', SourcePrefix::of('198.51.100.7'));
        self::assertSame('198.51.100.7', SourcePrefix::unwrapIpv4Mapped('198.51.100.7'));
        self::assertFalse(SourcePrefix::isPureIpv6('198.51.100.7'));
    }

    public function testPureIpv6IsNotUnwrappedAndRetainsPrefix(): void
    {
        $v6 = '2001:db8:85a3::8a2e:370:7334';
        self::assertSame($v6, SourcePrefix::unwrapIpv4Mapped($v6));
        self::assertTrue(SourcePrefix::isPureIpv6($v6));

        // /64 is leading 16 hex chars
        self::assertSame('20010db885a30000', SourcePrefix::of($v6, 64));
        // /48 is leading 12 hex chars
        self::assertSame('20010db885a3', SourcePrefix::of($v6, 48));
    }

    public function testIpv4CompatibleTransitionFormatIsOutOfScopeForUnwrapping(): void
    {
        // ::198.51.100.7 is deprecated IPv4-compatible (::/96), NOT IPv4-mapped (::ffff:0:0/96).
        // Per LT-185-38, it is out of scope and measured as leading /64 prefix without unwrapping.
        $compat = '::198.51.100.7';
        self::assertSame($compat, SourcePrefix::unwrapIpv4Mapped($compat));
        self::assertSame('0000000000000000', SourcePrefix::of($compat, 64));
    }
}
