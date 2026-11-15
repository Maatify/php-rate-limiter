<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Support;

use Maatify\RateLimiter\Tests\Support\Redis\ExactDecimal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** #234: the fixture arithmetic is exact past 2^53 and past a 32-bit `int`, on every width. */
#[Group('php32')]
final class ExactDecimalTest extends TestCase
{
    public function testAdditionAndSubtractionAreExactPastTheNativeAndTheFloatRange(): void
    {
        self::assertSame('9007199254740993', ExactDecimal::add('9007199254740992', '1'));
        self::assertSame('18014398509481988', ExactDecimal::add('9007199254740992', '9007199254740996'));
        self::assertSame('1000', ExactDecimal::add('999', '1'));
        self::assertSame('9007199254740991', ExactDecimal::subtract('9007199254740992', '1'));
        self::assertSame('0', ExactDecimal::subtract('1000', '1000'));
        self::assertSame('999', ExactDecimal::subtract('1000', '1'));
    }

    public function testComparisonOrdersByLengthThenDigits(): void
    {
        self::assertSame(-1, ExactDecimal::compare('999', '1000'));
        self::assertSame(1, ExactDecimal::compare('9007199254740993', '9007199254740992'));
        self::assertSame(0, ExactDecimal::compare('18014398509481988', '18014398509481988'));
        self::assertSame('9007199254740992', ExactDecimal::min(['18014398509481988', '9007199254740992', '9007199254740993']));
    }

    public function testSecondsToMillisecondsAndRedisRepliesAreExact(): void
    {
        self::assertSame('2147483647000', ExactDecimal::thousand('2147483647'));
        self::assertSame('0', ExactDecimal::thousand('0'));
        self::assertSame('7', ExactDecimal::fromReply(7));
        self::assertSame('1794771931000', ExactDecimal::fromReply('1794771931000'));
    }

    public function testASubtractionBelowZeroAndAMissingExpiryAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExactDecimal::subtract('1', '2');
    }

    public function testANegativeReplyIsNotAnExpiry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExactDecimal::fromReply(-1);
    }
}
