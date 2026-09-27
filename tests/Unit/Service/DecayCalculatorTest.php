<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use PHPUnit\Framework\TestCase;

class DecayCalculatorTest extends TestCase
{
    private FixedClock $clock;
    private DecayCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->calculator = new DecayCalculator($this->clock);
    }

    public function testThresholdWaitUsesAccountDeviceAndIpIntervals(): void
    {
        self::assertSame(600, $this->calculator->secondsUntilBelowThreshold(5, $this->timestamp(), 0, 'account', 5));
        self::assertSame(300, $this->calculator->secondsUntilBelowThreshold(5, $this->timestamp(), 0, 'device', 5));
        self::assertSame(180, $this->calculator->secondsUntilBelowThreshold(5, $this->timestamp(), 0, 'ip', 5));
    }

    public function testThresholdWaitUsesTheL2PlusPackageModifier(): void
    {
        self::assertSame(1200, $this->calculator->secondsUntilBelowThreshold(5, $this->timestamp(), 2, 'account', 5));
    }

    public function testThresholdWaitAccountsForPartialElapsedInterval(): void
    {
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:05:00'));

        self::assertSame(300, $this->calculator->secondsUntilBelowThreshold(5, $this->timestamp(), 0, 'account', 5));
    }

    public function testThresholdWaitConsumesCompletedDecayIntervals(): void
    {
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:20:00'));

        self::assertSame(600, $this->calculator->secondsUntilBelowThreshold(7, $this->timestamp(), 0, 'account', 5));
    }

    public function testScoreAlreadyBelowThresholdHasNoWait(): void
    {
        self::assertSame(0, $this->calculator->secondsUntilBelowThreshold(4, $this->timestamp(), 0, 'account', 5));
    }

    public function testNonPositiveThresholdIsRejected(): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Decay threshold must be greater than zero.');

        $this->calculator->secondsUntilBelowThreshold(5, $this->timestamp(), 0, 'account', 0);
    }

    private function timestamp(): int
    {
        return new \DateTimeImmutable('2025-01-01 12:00:00')->getTimestamp();
    }
}
