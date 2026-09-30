<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Engine;

use Maatify\RateLimiter\Service\LocalFallbackLimiter;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
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
        $trackedProperty = $reflection->getProperty('trackedSubjects');
        $trackedProperty->setValue(null, []);
        $expiryProperty = $reflection->getProperty('trackedSubjectExpiries');
        $expiryProperty->setValue(null, []);

    }

    public function testGcRemovesExpiredBucketsAndPreservesCurrentBuckets(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $ip = '198.51.100.40';
        $policy = new OtpProtectionPolicy();

        $this->assertTrue(
            LocalFallbackLimiter::check($clock, $policy, 'DEGRADED_MODE', $ip),
        );
        $namespace = 'fallback:' . hash('sha256', $policy->getName());
        $staleBucketKey = $namespace . ':ip_prefix:' . intdiv($clock->now()->getTimestamp(), 900) . ':subject:' . hash('sha256', $namespace . ':ip_prefix:' . $ip);

        // A new window is created and expired historical state is collected.
        $clock->setNow(new \DateTimeImmutable('2025-01-01 13:00:00'));
        $this->assertTrue(
            LocalFallbackLimiter::check($clock, $policy, 'DEGRADED_MODE', $ip),
        );
        $currentBucketKey = $namespace . ':ip_prefix:' . intdiv($clock->now()->getTimestamp(), 900) . ':subject:' . hash('sha256', $namespace . ':ip_prefix:' . $ip);

        // The current window remains valid while the old one is gone.
        $clock->setNow(new \DateTimeImmutable('2025-01-01 13:00:01'));
        $this->assertTrue(
            LocalFallbackLimiter::check($clock, $policy, 'DEGRADED_MODE', $ip),
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

    public function testCapacityUsesConservativeOverflowWithoutEvictingTrackedSubjects(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $policy = new class extends LoginProtectionPolicy {
            public function getName(): string
            {
                return 'bounded-fallback-capacity';
            }
        };

        for ($index = 1; $index <= 4096; $index++) {
            self::assertTrue(LocalFallbackLimiter::check(
                $clock,
                $policy,
                'DEGRADED_MODE',
                'subject-' . $index,
                'account-' . $index,
            ));
        }

        self::assertTrue(LocalFallbackLimiter::check(
            $clock,
            $policy,
            'DEGRADED_MODE',
            'subject-4097',
            'account-4097',
        ));
        self::assertTrue(LocalFallbackLimiter::check(
            $clock,
            $policy,
            'DEGRADED_MODE',
            'subject-1',
            'account-1',
        ));

        $reflection = new \ReflectionClass(LocalFallbackLimiter::class);
        $trackedProperty = $reflection->getProperty('trackedSubjects');
        /** @var array<string, array<string, true>> $tracked */
        $tracked = $trackedProperty->getValue();
        self::assertCount(2, $tracked);
        foreach (array_values($tracked) as $subjects) {
            self::assertCount(4096, $subjects);
        }

        for ($index = 0; $index < 120; $index++) {
            LocalFallbackLimiter::check($clock, $policy, 'DEGRADED_MODE', 'overflow-' . $index, 'overflow-' . $index);
        }
        self::assertFalse(LocalFallbackLimiter::check(
            $clock,
            $policy,
            'DEGRADED_MODE',
            'overflow-final',
            'overflow-final',
        ));

        $clock->setNow(new \DateTimeImmutable('2025-01-01 12:15:00'));
        self::assertTrue(LocalFallbackLimiter::check(
            $clock,
            $policy,
            'DEGRADED_MODE',
            'subject-after-rollover',
            'account-after-rollover',
        ));
        $tracked = $trackedProperty->getValue();
        self::assertIsArray($tracked);
        self::assertCount(2, $tracked);
        foreach (array_values($tracked) as $subjects) {
            self::assertIsArray($subjects);
            self::assertCount(1, $subjects);
        }
    }
}
