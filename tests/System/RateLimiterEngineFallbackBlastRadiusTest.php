<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Contract\BlockPolicyInterface;
use Maatify\RateLimiter\Contract\RateLimitStoreInterface;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Device\DeviceIdentityResolver;
use Maatify\RateLimiter\Device\EphemeralBucket;
use Maatify\RateLimiter\Device\FingerprintHasher;
use Maatify\RateLimiter\Engine\CircuitBreaker;
use Maatify\RateLimiter\Engine\EvaluationPipeline;
use Maatify\RateLimiter\Engine\FailureModeResolver;
use Maatify\RateLimiter\Engine\LocalFallbackLimiter;
use Maatify\RateLimiter\Engine\RateLimiterEngine;
use Maatify\RateLimiter\Penalty\AntiEquilibriumGate;
use Maatify\RateLimiter\Penalty\BudgetTracker;
use Maatify\RateLimiter\Penalty\DecayCalculator;
use Maatify\RateLimiter\Policy\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Policy\LoginProtectionPolicy;
use Maatify\RateLimiter\Policy\OtpProtectionPolicy;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\NullCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\ThrowingRateLimitStore;
use PHPUnit\Framework\TestCase;

class RateLimiterEngineFallbackBlastRadiusTest extends TestCase
{
    private const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';
    private const FIREFOX_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0';
    private const SAFARI_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';

    private FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->resetLocalFallbackState();
    }

    public function testHealthyBackendUsesNormalEnginePipelineForChromeAndFirefox(): void
    {
        $store = new InMemoryRateLimitStore($this->clock);
        $engine = $this->createEngineWithStore($store, new ApiHeavyProtectionPolicy());

        $chromeResult = $engine->limit(
            new RateLimitContextDTO('203.0.113.10', self::CHROME_UA, 'normal-account'),
            new RateLimitCommand('api_heavy_protection')
        );
        $firefoxResult = $engine->limit(
            new RateLimitContextDTO('203.0.113.10', self::FIREFOX_UA, 'normal-account'),
            new RateLimitCommand('api_heavy_protection')
        );

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $chromeResult->decision);
        $this->assertSame('NORMAL', $chromeResult->failureMode);
        $this->assertNull($chromeResult->metadata);
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $firefoxResult->decision);
        $this->assertSame('NORMAL', $firefoxResult->failureMode);
        $this->assertNull($firefoxResult->metadata);

        $chromeKey = hash_hmac(
            'sha256',
            'api_heavy_protection:rate_limiter:k2:v2:prod:203.0.113.10:' . DeviceIdentityResolver::normalizeUserAgent(self::CHROME_UA),
            'test_secret'
        );
        $firefoxKey = hash_hmac(
            'sha256',
            'api_heavy_protection:rate_limiter:k2:v2:prod:203.0.113.10:' . DeviceIdentityResolver::normalizeUserAgent(self::FIREFOX_UA),
            'test_secret'
        );

        $this->assertSame(1, $store->get($chromeKey)?->value);
        $this->assertSame(1, $store->get($firefoxKey)?->value);

        // A healthy request must not consume local fallback capacity. Prove this through the public Engine
        // workflow by failing the backend afterward and requiring the full 60-request K2 allowance.
        $fallbackEngine = $this->createEngineWithStore(new ThrowingRateLimitStore(), new ApiHeavyProtectionPolicy());
        for ($i = 0; $i < 60; $i++) {
            $result = $this->limit($fallbackEngine, 'api_heavy_protection', '203.0.113.10', self::CHROME_UA, null);

            $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }
    }

    public function testLoginFallbackAccountCapIsIndependentOfUa(): void
    {
        $engine = $this->createEngineWithStore(new ThrowingRateLimitStore(), new LoginProtectionPolicy());
        $ip = '198.51.100.20';
        $accountId = 'login-account';

        $this->enterDegradedMode($engine, 'login_protection', $ip, $accountId);

        foreach ([self::CHROME_UA, self::FIREFOX_UA, self::CHROME_UA] as $ua) {
            $result = $this->limit($engine, 'login_protection', $ip, $ua, $accountId);

            $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
            $this->assertSame('DEGRADED_MODE', $result->failureMode);
        }

        $blocked = $this->limit($engine, 'login_protection', $ip, self::FIREFOX_UA, $accountId);

        $this->assertFallbackLimitExceeded($blocked);
        // Locked fallback cap: 3 requests per account in 10 minutes.
    }

    public function testLoginFallbackIpCapIsIndependentOfUa(): void
    {
        $engine = $this->createEngineWithStore(new ThrowingRateLimitStore(), new LoginProtectionPolicy());
        $ip = '198.51.100.21';

        $this->enterDegradedMode($engine, 'login_protection', $ip, 'login-warmup-account');

        for ($i = 0; $i < 20; $i++) {
            $result = $this->limit(
                $engine,
                'login_protection',
                $ip,
                $i % 2 === 0 ? self::CHROME_UA : self::FIREFOX_UA,
                'login-ip-account-' . $i
            );

            $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }

        $blocked = $this->limit($engine, 'login_protection', $ip, self::FIREFOX_UA, 'login-ip-account-20');

        $this->assertFallbackLimitExceeded($blocked);
        // Locked fallback cap: 20 requests per IP in 10 minutes.
    }

    public function testOtpFallbackAccountCapIsIndependentOfUa(): void
    {
        $engine = $this->createEngineWithStore(new ThrowingRateLimitStore(), new OtpProtectionPolicy());
        $ip = '198.51.100.22';
        $accountId = 'otp-account';

        $this->enterDegradedMode($engine, 'otp_protection', $ip, $accountId);

        foreach ([self::CHROME_UA, self::FIREFOX_UA] as $ua) {
            $result = $this->limit($engine, 'otp_protection', $ip, $ua, $accountId);

            $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
            $this->assertSame('DEGRADED_MODE', $result->failureMode);
        }

        $blocked = $this->limit($engine, 'otp_protection', $ip, self::FIREFOX_UA, $accountId);

        $this->assertFallbackLimitExceeded($blocked);
        // Locked fallback cap: 2 requests per account in 15 minutes.
    }

    public function testHourlyGcPreservesActiveOtpFallbackBucketThroughEngine(): void
    {
        $engine = $this->createEngineWithStore(new ThrowingRateLimitStore(), new OtpProtectionPolicy());
        $ip = '198.51.100.26';

        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:04:30'));
        $this->enterDegradedMode($engine, 'otp_protection', $ip, 'otp-gc-warmup-account');

        // The first fallback execution establishes lastGc at an unaligned time inside the 15-minute bucket.
        $warmup = $this->limit($engine, 'otp_protection', $ip, self::CHROME_UA, 'otp-gc-warmup-account');
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $warmup->decision);
        $this->assertSame('DEGRADED_MODE', $warmup->failureMode);

        // 13:04:29 is still before the hourly GC threshold and remains in the 13:00-13:14:59 OTP bucket.
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 13:04:29'));
        $first = $this->limit($engine, 'otp_protection', $ip, self::CHROME_UA, 'otp-gc-target-account');
        $second = $this->limit($engine, 'otp_protection', $ip, self::CHROME_UA, 'otp-gc-target-account');

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $first->decision);
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $second->decision);
        $this->assertSame('DEGRADED_MODE', $first->failureMode);
        $this->assertSame('DEGRADED_MODE', $second->failureMode);

        // 13:04:31 is still in the same OTP bucket, but crosses the one-hour GC threshold.
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 13:04:31'));
        $third = $this->limit($engine, 'otp_protection', $ip, self::CHROME_UA, 'otp-gc-target-account');

        $this->assertFallbackLimitExceeded($third);
    }

    public function testOtpFallbackNaturallyResetsOnlyWhenFixedWindowRollsOver(): void
    {
        $engine = $this->createEngineWithStore(new ThrowingRateLimitStore(), new OtpProtectionPolicy());
        $ip = '198.51.100.27';
        $accountId = 'otp-window-account';

        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:04:30'));
        $this->enterDegradedMode($engine, 'otp_protection', $ip, 'otp-window-warmup-account');

        // Establish degraded mode and consume only the separate warm-up account.
        $warmup = $this->limit($engine, 'otp_protection', $ip, self::CHROME_UA, 'otp-window-warmup-account');
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $warmup->decision);
        $this->assertSame('DEGRADED_MODE', $warmup->failureMode);

        $first = $this->limit($engine, 'otp_protection', $ip, self::CHROME_UA, $accountId);
        $second = $this->limit($engine, 'otp_protection', $ip, self::CHROME_UA, $accountId);
        $third = $this->limit($engine, 'otp_protection', $ip, self::CHROME_UA, $accountId);

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $first->decision);
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $second->decision);
        $this->assertFallbackLimitExceeded($third);

        // The next fixed 15-minute bucket starts at 12:15:00; GC has not been crossed.
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:15:00'));
        $afterRollover = $this->limit($engine, 'otp_protection', $ip, self::CHROME_UA, $accountId);

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $afterRollover->decision);
        $this->assertSame('DEGRADED_MODE', $afterRollover->failureMode);
    }

    public function testOtpFallbackIpCapIsIndependentOfUa(): void
    {
        $engine = $this->createEngineWithStore(new ThrowingRateLimitStore(), new OtpProtectionPolicy());
        $ip = '198.51.100.23';

        $this->enterDegradedMode($engine, 'otp_protection', $ip, 'otp-warmup-account');

        for ($i = 0; $i < 10; $i++) {
            $result = $this->limit(
                $engine,
                'otp_protection',
                $ip,
                $i % 2 === 0 ? self::CHROME_UA : self::FIREFOX_UA,
                'otp-ip-account-' . $i
            );

            $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }

        $blocked = $this->limit($engine, 'otp_protection', $ip, self::CHROME_UA, 'otp-ip-account-10');

        $this->assertFallbackLimitExceeded($blocked);
        // Locked fallback cap: 10 requests per IP in 15 minutes.
    }

    public function testApiCrossUaFallbackUsesDistinctK2BucketsThroughEngine(): void
    {
        $engine = $this->createEngineWithStore(new ThrowingRateLimitStore(), new ApiHeavyProtectionPolicy());
        $ip = '198.51.100.24';

        for ($i = 0; $i < 60; $i++) {
            $result = $this->limit($engine, 'api_heavy_protection', $ip, self::CHROME_UA, null);

            $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }

        $firefoxResult = $this->limit($engine, 'api_heavy_protection', $ip, self::FIREFOX_UA, null);

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $firefoxResult->decision);
        $this->assertSame('DEGRADED_MODE', $firefoxResult->failureMode);
    }

    public function testApiFallbackK1AggregateCapIsIndependentOfK2AcrossRawUas(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $ip = '198.51.100.25';

        foreach ([self::CHROME_UA, self::FIREFOX_UA] as $ua) {
            for ($i = 0; $i < 60; $i++) {
                $this->assertTrue(
                    LocalFallbackLimiter::check($clock, 'api_heavy_protection', 'FAIL_OPEN', $ip, null, $ua)
                );
            }
        }

        // The third K2 bucket is fresh, so request 121 isolates the K1 IP aggregate cap.
        $this->assertFalse(
            LocalFallbackLimiter::check($clock, 'api_heavy_protection', 'FAIL_OPEN', $ip, null, self::SAFARI_UA)
        );
    }

    private function createEngineWithStore(RateLimitStoreInterface $store, BlockPolicyInterface ...$policies): RateLimiterEngine
    {
        $correlationStore = new NullCorrelationStore();
        $emitter = new RecordingFailureSignalEmitter();
        $pipeline = new EvaluationPipeline(
            $store,
            $correlationStore,
            new BudgetTracker($store, $this->clock),
            new AntiEquilibriumGate($correlationStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($correlationStore),
            'test_secret',
            'prod',
            $this->clock
        );

        return new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher('test_secret')),
            $pipeline,
            new CircuitBreaker(new InMemoryCircuitBreakerStore(), $emitter, $this->clock),
            new FailureModeResolver(),
            $emitter,
            $this->clock,
            $policies
        );
    }

    private function enterDegradedMode(RateLimiterEngine $engine, string $policyName, string $ip, string $accountId): void
    {
        for ($i = 0; $i < 2; $i++) {
            $result = $this->limit($engine, $policyName, $ip, self::CHROME_UA, $accountId);

            $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
            $this->assertSame('FAIL_CLOSED', $result->failureMode);
        }
    }

    private function limit(RateLimiterEngine $engine, string $policyName, string $ip, string $ua, ?string $accountId): RateLimitResultDTO
    {
        return $engine->limit(
            new RateLimitContextDTO($ip, $ua, $accountId),
            RateLimitCommand::checkOnly($policyName)
        );
    }

    private function assertFallbackLimitExceeded(RateLimitResultDTO $result): void
    {
        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertSame(2, $result->blockLevel);
        $this->assertSame(60, $result->retryAfter);
        $this->assertSame('DEGRADED_MODE', $result->failureMode);
        $metadata = $result->metadata;
        $this->assertNotNull($metadata);
        $this->assertNotNull($metadata->context);
        $this->assertSame('fallback_limit_exceeded', $metadata->context->reason);
    }

    private function resetLocalFallbackState(): void
    {
        $reflection = new \ReflectionClass(LocalFallbackLimiter::class);

        $countersProperty = $reflection->getProperty('counters');
        $countersProperty->setAccessible(true);
        $countersProperty->setValue(null, []);

        $lastGcProperty = $reflection->getProperty('lastGc');
        $lastGcProperty->setAccessible(true);
        $lastGcProperty->setValue(null, 0);
    }
}
