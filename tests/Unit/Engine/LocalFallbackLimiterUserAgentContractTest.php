<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Engine;

use Maatify\RateLimiter\Service\LocalFallbackLimiter;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use PHPUnit\Framework\TestCase;

class LocalFallbackLimiterUserAgentContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new \ReflectionClass(LocalFallbackLimiter::class);
        $countersProperty = $reflection->getProperty('counters');
        $countersProperty->setAccessible(true);
        $countersProperty->setValue(null, []);
    }


    public function testExistingK2CapWithOneRawUa(): void
    {
        $clock = new FixedClock();
        $ip = '192.168.1.1';
        $rawChromeUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';

        // 60 requests should be allowed
        for ($i = 0; $i < 60; $i++) {
            $allowed = LocalFallbackLimiter::check(
                $clock,
                'api_heavy_protection',
                'FAIL_OPEN',
                $ip,
                null,
                $rawChromeUa,
            );
            $this->assertTrue($allowed, "Call $i should be allowed");
        }

        // 61st request should be rejected (K2 limit is 60)
        $allowed = LocalFallbackLimiter::check(
            $clock,
            'api_heavy_protection',
            'FAIL_OPEN',
            $ip,
            null,
            $rawChromeUa,
        );
        $this->assertFalse($allowed, 'Call 61 should be rejected due to K2 cap');
    }

    public function testDistinctRawUasRemainDistinct(): void
    {
        $clock = new FixedClock();
        $ip = '192.168.1.1';
        $rawChromeUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';
        $rawFirefoxUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0';

        // 60 requests from Chrome allowed
        for ($i = 0; $i < 60; $i++) {
            $allowed = LocalFallbackLimiter::check(
                $clock,
                'api_heavy_protection',
                'FAIL_OPEN',
                $ip,
                null,
                $rawChromeUa,
            );
            $this->assertTrue($allowed, "Chrome call $i should be allowed");
        }

        // 1 request from Firefox should be allowed because they resolve to different K2 buckets
        $allowed = LocalFallbackLimiter::check(
            $clock,
            'api_heavy_protection',
            'FAIL_OPEN',
            $ip,
            null,
            $rawFirefoxUa,
        );
        $this->assertTrue($allowed, 'Firefox call should be allowed');
    }

    public function testFallbackUsesTheCanonicalBrowserMajorNormalizer(): void
    {
        $rawChromeUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';
        $rawFirefoxUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0';

        $normalizedChrome = \Maatify\RateLimiter\Service\DeviceIdentityResolver::normalizeUserAgent($rawChromeUa);
        $normalizedFirefox = \Maatify\RateLimiter\Service\DeviceIdentityResolver::normalizeUserAgent($rawFirefoxUa);

        $this->assertEquals('chrome/123', $normalizedChrome);
        $this->assertEquals('firefox/124', $normalizedFirefox);
    }

    public function testFallbackDoesNotIncludeOperatingSystemInK2UaComponent(): void
    {
        $clock = new FixedClock();
        $ip = '192.168.1.1';
        $windowsChromeUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';
        $macChromeUa = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';

        // The same browser major consumes the same K2 fallback bucket across operating systems.
        for ($i = 0; $i < 60; $i++) {
            $allowed = LocalFallbackLimiter::check(
                $clock,
                'api_heavy_protection',
                'FAIL_OPEN',
                $ip,
                null,
                $windowsChromeUa,
            );
            $this->assertTrue($allowed, "Windows Chrome call $i should be allowed");
        }

        $allowed = LocalFallbackLimiter::check(
            $clock,
            'api_heavy_protection',
            'FAIL_OPEN',
            $ip,
            null,
            $macChromeUa,
        );
        $this->assertFalse($allowed, 'Mac Chrome call should share the exhausted Chrome/123 K2 bucket');
    }
}
