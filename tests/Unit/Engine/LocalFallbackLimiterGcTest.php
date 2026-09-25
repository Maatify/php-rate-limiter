<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Engine;

use Maatify\RateLimiter\Service\LocalFallbackLimiter;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use PHPUnit\Framework\TestCase;

class LocalFallbackLimiterGcTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new \ReflectionClass(LocalFallbackLimiter::class);

        $countersProperty = $reflection->getProperty('counters');
        $countersProperty->setValue(null, []);

        $lastGcProperty = $reflection->getProperty('lastGc');
        $lastGcProperty->setValue(null, 0);
    }

    public function testHourlyGcRemovesExpiredBucketsAndPreservesCurrentBuckets(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $ip = '198.51.100.40';

        $this->assertTrue(
            LocalFallbackLimiter::check($clock, 'otp_protection', 'DEGRADED_MODE', $ip),
        );
        $namespace = 'fallback:' . hash('sha256', 'otp_protection:AUTHENTICATION_STEP_UP');
        $staleBucketKey = $namespace . ':ip:' . $ip . ':' . intdiv($clock->now()->getTimestamp(), 900);

        // At the exact hourly boundary the current OTP bucket is created without running GC.
        $clock->setNow(new \DateTimeImmutable('2025-01-01 13:00:00'));
        $this->assertTrue(
            LocalFallbackLimiter::check($clock, 'otp_protection', 'DEGRADED_MODE', $ip),
        );
        $currentBucketKey = $namespace . ':ip:' . $ip . ':' . intdiv($clock->now()->getTimestamp(), 900);

        // Crossing the threshold triggers selective cleanup while the current bucket remains valid.
        $clock->setNow(new \DateTimeImmutable('2025-01-01 13:00:01'));
        $this->assertTrue(
            LocalFallbackLimiter::check($clock, 'otp_protection', 'DEGRADED_MODE', $ip),
        );

        $reflection = new \ReflectionClass(LocalFallbackLimiter::class);
        $countersProperty = $reflection->getProperty('counters');
        /** @var array<string, mixed> $counters */
        $counters = $countersProperty->getValue();
        $bucketKeys = array_keys($counters);

        $this->assertNotContains($staleBucketKey, $bucketKeys);
        $this->assertContains($currentBucketKey, $bucketKeys);
        $this->assertCount(1, $bucketKeys);
    }

    public function testSameProfilePoliciesDoNotShareProcessLocalCounters(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $first = new class extends LoginProtectionPolicy {
            public function getName(): string
            {
                return 'custom-primary-one';
            }
        };
        $second = new class extends LoginProtectionPolicy {
            public function getName(): string
            {
                return 'custom-primary-two';
            }
        };

        for ($index = 0; $index < 3; $index++) {
            self::assertTrue(LocalFallbackLimiter::check($clock, $first, 'DEGRADED_MODE', '198.51.100.41', 'shared-account'));
        }
        self::assertFalse(LocalFallbackLimiter::check($clock, $first, 'DEGRADED_MODE', '198.51.100.41', 'shared-account'));
        self::assertTrue(LocalFallbackLimiter::check($clock, $second, 'DEGRADED_MODE', '198.51.100.41', 'shared-account'));
    }
}
