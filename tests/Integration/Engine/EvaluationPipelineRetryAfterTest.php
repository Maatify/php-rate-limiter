<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Engine;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\NullCorrelationStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

class EvaluationPipelineRetryAfterTest extends TestCase
{
    private FixedClock $clock;
    private InMemoryRateLimitStore $store;
    private NullCorrelationStore $correlationStore;
    private EvaluationPipeline $pipeline;
    private OtpProtectionPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new InMemoryRateLimitStore($this->clock);
        $this->correlationStore = new NullCorrelationStore();

        $decayCalculator = new DecayCalculator($this->clock);
        $budgetTracker = new BudgetTracker($this->store, $this->clock);
        $antiEquilibriumGate = new AntiEquilibriumGate($this->correlationStore);

        $this->policy = new OtpProtectionPolicy();

        $ephemeralBucket = new \Maatify\RateLimiter\Service\EphemeralBucket($this->correlationStore);

        $this->pipeline = new EvaluationPipeline(
            $this->store,
            $this->correlationStore,
            $budgetTracker,
            $antiEquilibriumGate,
            $decayCalculator,
            $ephemeralBucket,
            'test_secret',
            'prod',
            $this->clock,
        );
    }

    public function testCleanPreCheckReturnsAllowWithoutUpdates(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');
        $command = RateLimitCommand::checkOnly('otp_protection');

        $result = $this->pipeline->process($this->policy, $context, $command, $device);

        $this->assertEquals(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        $this->assertEquals(0, $result->blockLevel);
        $this->assertEquals(0, $result->retryAfter);

        // Assert no updates were made to the store
        $k4Key = hash_hmac('sha256', 'otp_protection:rate_limiter:k4:v2:prod:acct_123', 'test_secret');
        $this->assertNull($this->store->getBudget($k4Key));
        $k1 = hash_hmac('sha256', 'otp_protection:rate_limiter:k1:v2:prod:127.0.0.1', 'test_secret');
        $this->assertNull($this->store->get($k1));
    }

    public function testExistingActiveBlockReturnsCorrectDecisionAndRetryAfter(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');
        $command = RateLimitCommand::checkOnly('otp_protection');

        $k4Key = hash_hmac('sha256', 'otp_protection:rate_limiter:k4:v2:prod:acct_123', 'test_secret');
        $this->store->block($k4Key, 3, 3600);
        $this->store->set($k4Key, 50, 3600); // L3 threshold

        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:02:00')); // +120 seconds

        $result = $this->pipeline->process($this->policy, $context, $command, $device);

        $this->assertEquals(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertEquals(3, $result->blockLevel);
        // Original duration was 3600, 120 passed.
        $this->assertEquals(3480, $result->retryAfter);
    }

    public function testScoreThresholdAndDecayProducesExpectedOutcome(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');

        $k4Key = hash_hmac('sha256', 'otp_protection:rate_limiter:k4:v2:prod:acct_123', 'test_secret');

        // Thresholds for K4 on OTP: 4, 7, 10
        $this->store->set($k4Key, 3, 3600); // Initial score 3 (below L1 = 4)

        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:10:00')); // Advance 10 mins (decay = 1)

        $command = RateLimitCommand::checkOnly('otp_protection');
        $result = $this->pipeline->process($this->policy, $context, $command, $device);

        $this->assertEquals(RateLimitResultDTO::DECISION_ALLOW, $result->decision);

        // Add 1 failure. decayed is 2. +5 = 7. == L2 (7).
        $command = RateLimitCommand::recordFailure('otp_protection', 1);
        $result = $this->pipeline->process($this->policy, $context, $command, $device);

        $this->assertEquals(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertEquals(2, $result->blockLevel);
        $this->assertEquals(60, $result->retryAfter); // L2 duration = 60
    }

    public function testOtpBudgetActiveCheckOnlyDoesNotEnforceBudget(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');

        $k4Key = hash_hmac('sha256', 'otp_protection:rate_limiter:k4:v2:prod:acct_123', 'test_secret');

        // Increment budget directly to threshold
        $this->store->incrementBudget($k4Key, 86400, 10);

        $command = RateLimitCommand::checkOnly('otp_protection');
        $result = $this->pipeline->process($this->policy, $context, $command, $device);

        $this->assertEquals(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        $this->assertEquals(0, $result->blockLevel);
        $this->assertEquals(0, $result->retryAfter);
        $this->assertSame(10, $this->store->getBudget($k4Key)?->count);
    }

    public function testFailureScoring(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('known-fingerprint', 'MEDIUM', false, false, 'chrome/123');

        $command = RateLimitCommand::recordFailure('otp_protection', 1);

        $result = $this->pipeline->process($this->policy, $context, $command, $device);

        $this->assertEquals(RateLimitResultDTO::DECISION_SOFT_BLOCK, $result->decision);
        $this->assertEquals(1, $result->blockLevel);
        $this->assertEquals(15, $result->retryAfter);

        $k4Key = hash_hmac('sha256', 'otp_protection:rate_limiter:k4:v2:prod:acct_123', 'test_secret');
        $k5Key = hash_hmac('sha256', 'otp_protection:rate_limiter:k5:v2:prod:acct_123:known-fingerprint', 'test_secret');

        $this->assertEquals(5, $this->store->get($k4Key)?->value);
        $this->assertNull($this->store->get($k5Key));
        $this->assertEquals(1, $this->store->getBudget($k4Key)?->count);
    }

    public function testApiHeavyNormalAccessBehavior(): void
    {
        $context = new RateLimitContextDTO('203.0.113.10', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('known-fingerprint', 'HIGH', false, false, 'chrome/123');
        $command = new RateLimitCommand('api_heavy_protection', 1, false, false, false);

        $apiHeavyPolicy = new \Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy();

        $result = $this->pipeline->process($apiHeavyPolicy, $context, $command, $device);
        $this->assertEquals(RateLimitResultDTO::DECISION_ALLOW, $result->decision);

        $k1Key = hash_hmac('sha256', 'api_heavy_protection:rate_limiter:k1:v2:prod:203.0.113.10', 'test_secret');
        $k2Key = hash_hmac('sha256', 'api_heavy_protection:rate_limiter:k2:v2:prod:203.0.113.10:chrome/123', 'test_secret');
        $k3Key = hash_hmac('sha256', 'api_heavy_protection:rate_limiter:k3:v2:prod:203.0.113.10:known-fingerprint', 'test_secret');

        $this->assertEquals(1, $this->store->get($k1Key)?->value);
        $this->assertEquals(1, $this->store->get($k2Key)?->value);
        $this->assertEquals(1, $this->store->get($k3Key)?->value);
    }

    public function testSuccessCommandBehavior(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');
        $command = RateLimitCommand::recordSuccess('otp_protection', 1);

        $result = $this->pipeline->process($this->policy, $context, $command, $device);

        $this->assertEquals(RateLimitResultDTO::DECISION_ALLOW, $result->decision);

        $k4Key = hash_hmac('sha256', 'otp_protection:rate_limiter:k4:v2:prod:acct_123', 'test_secret');

        $this->assertNull($this->store->get($k4Key));
        $this->assertNull($this->store->getBudget($k4Key));
    }
}
