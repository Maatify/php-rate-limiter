<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Config;

use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\SimpleThrottlePolicyInterface;
use Maatify\RateLimiter\Exception\RateLimiterException;
use PHPUnit\Framework\TestCase;

final class FixedWindowThrottlePolicyTest extends TestCase
{
    public function testValidPolicyExposesItsExplicitInputs(): void
    {
        $policy = new FixedWindowThrottlePolicy('checkout_attempts', 5, 60);

        self::assertInstanceOf(SimpleThrottlePolicyInterface::class, $policy);
        self::assertSame('checkout_attempts', $policy->getName());
        self::assertSame(5, $policy->getLimit());
        self::assertSame(60, $policy->getIntervalSeconds());
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(RateLimiterException::class);
        new FixedWindowThrottlePolicy('', 5, 60);
    }

    public function testBlankNameIsRejected(): void
    {
        $this->expectException(RateLimiterException::class);
        new FixedWindowThrottlePolicy("   \t  ", 5, 60);
    }

    public function testZeroLimitIsRejected(): void
    {
        $this->expectException(RateLimiterException::class);
        new FixedWindowThrottlePolicy('checkout_attempts', 0, 60);
    }

    public function testNegativeLimitIsRejected(): void
    {
        $this->expectException(RateLimiterException::class);
        new FixedWindowThrottlePolicy('checkout_attempts', -1, 60);
    }

    public function testZeroIntervalIsRejected(): void
    {
        $this->expectException(RateLimiterException::class);
        new FixedWindowThrottlePolicy('checkout_attempts', 5, 0);
    }

    public function testNegativeIntervalIsRejected(): void
    {
        $this->expectException(RateLimiterException::class);
        new FixedWindowThrottlePolicy('checkout_attempts', 5, -30);
    }
}
