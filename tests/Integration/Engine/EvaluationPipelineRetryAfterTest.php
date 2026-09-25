<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Engine;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

class EvaluationPipelineRetryAfterTest extends TestCase
{
    private FixedClock $clock;
    private InMemoryRateLimitStore $store;
    private StatefulInMemoryCorrelationStore $correlationStore;
    private EvaluationPipeline $pipeline;
    private OtpProtectionPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new InMemoryRateLimitStore($this->clock);
        $this->correlationStore = new StatefulInMemoryCorrelationStore($this->clock);

        $this->policy = new OtpProtectionPolicy();
        $this->pipeline = $this->createPipeline();
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

    public function testCurrentGenerationActiveBlockWinsOverPreviousGeneration(): void
    {
        $pipeline = $this->createPipeline('previous_secret');
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');
        $currentKey = hash_hmac('sha256', 'otp_protection:rate_limiter:k4:v2:prod:acct_123', 'test_secret');
        $previousKey = hash_hmac('sha256', 'otp_protection:rate_limiter:k4:v2:prod:acct_123', 'previous_secret');

        $this->store->block($currentKey, 2, 600);
        $this->store->block($previousKey, 3, 3600);
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:02:00'));

        $result = $pipeline->process($this->policy, $context, RateLimitCommand::checkOnly('otp_protection'), $device);

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $result->blockLevel);
        self::assertSame(480, $result->retryAfter);
    }

    public function testPreviousGenerationActiveBlockRemainsAuthoritativeDuringRotation(): void
    {
        $pipeline = $this->createPipeline('previous_secret');
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');
        $previousKey = hash_hmac('sha256', 'otp_protection:rate_limiter:k4:v2:prod:acct_123', 'previous_secret');

        $this->store->block($previousKey, 3, 3600);
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:02:00'));

        $result = $pipeline->process($this->policy, $context, RateLimitCommand::checkOnly('otp_protection'), $device);

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(3, $result->blockLevel);
        self::assertSame(3480, $result->retryAfter);
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
        $this->assertEquals(60, $result->retryAfter); // Fresh lifecycle publication uses the L2 penalty duration.
        $this->assertEquals(60, $this->store->checkBlock($k4Key)?->expiresAt - $this->clock->now()->getTimestamp());
    }

    public function testScoreUpdateDuringActivePauseIncludesRemainingPauseInRetryAfter(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');
        $k4Key = hash_hmac('sha256', 'otp_protection:rate_limiter:k4:v2:prod:acct_123', 'test_secret');
        $base = $this->clock->now()->getTimestamp();

        $this->store->set($k4Key, 2, 3600);
        $this->store->blockWithCycleTracking($k4Key, null, 2, 1, $base, 21600, 2, 600, 86400);

        $secondCycleAt = $base + 2;
        $this->clock->setNow(new \DateTimeImmutable('@' . $secondCycleAt));
        $pause = $this->store->blockWithCycleTracking(
            $k4Key,
            null,
            2,
            1,
            $secondCycleAt,
            21600,
            2,
            600,
            86400,
        );
        self::assertTrue($pause->pauseActivated);

        // The second block expires at this exact timestamp, while its pause remains active.
        $updateAt = $base + 3;
        $this->clock->setNow(new \DateTimeImmutable('@' . $updateAt));
        $result = $this->pipeline->process(
            $this->policy,
            $context,
            RateLimitCommand::recordFailure('otp_protection'),
            $device,
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $result->blockLevel);
        // Fresh lifecycle publication uses the L2 penalty duration and does not
        // extend the response with the legacy score-decay pause calculation.
        self::assertSame(60, $result->retryAfter);
    }

    public function testAccountL1ScoreUsesAccountDecayInterval(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');
        $k4Key = hash_hmac('sha256', 'otp_protection:rate_limiter:k4:v2:prod:acct_123', 'test_secret');
        $this->store->set($k4Key, 4, 3600);

        $result = $this->pipeline->process(
            $this->policy,
            $context,
            RateLimitCommand::checkOnly('otp_protection'),
            $device,
        );

        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $result->decision);
        self::assertSame(1, $result->blockLevel);
        self::assertSame(600, $result->retryAfter);
    }

    public function testAccountL3ScoreWaitsUntilBelowL2(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');
        $k4Key = hash_hmac('sha256', 'otp_protection:rate_limiter:k4:v2:prod:acct_123', 'test_secret');
        $this->store->set($k4Key, 9, 3600);

        $result = $this->pipeline->process(
            $this->policy,
            $context,
            RateLimitCommand::recordFailure('otp_protection'),
            $device,
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(3, $result->blockLevel);
        self::assertSame(1800, $result->retryAfter);
        self::assertSame(300, $this->store->checkBlock($k4Key)?->expiresAt - $this->clock->now()->getTimestamp());
    }

    public function testDeviceScoreUsesDeviceDecayInterval(): void
    {
        $policy = new ApiHeavyProtectionPolicy(['k1' => 1000, 'k2' => 1000, 'k3' => 300]);
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');
        $k3Key = hash_hmac('sha256', 'api_heavy_protection:rate_limiter:k3:v2:prod:127.0.0.1:hash_123', 'test_secret');
        $this->store->set($k3Key, 299, 3600);

        $result = $this->pipeline->process(
            $policy,
            $context,
            new RateLimitCommand('api_heavy_protection'),
            $device,
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(300, $result->retryAfter);
    }

    public function testIpScoreUsesIpDecayInterval(): void
    {
        $policy = new ApiHeavyProtectionPolicy(['k1' => 600, 'k2' => 1000, 'k3' => 1000]);
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');
        $k1Key = hash_hmac('sha256', 'api_heavy_protection:rate_limiter:k1:v2:prod:127.0.0.1', 'test_secret');
        $this->store->set($k1Key, 599, 3600);

        $result = $this->pipeline->process(
            $policy,
            $context,
            new RateLimitCommand('api_heavy_protection'),
            $device,
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(180, $result->retryAfter);
    }

    public function testLowConfidenceK3RemainsHardL2AndPersistsAgainstK2(): void
    {
        $policy = new ApiHeavyProtectionPolicy(['k1' => 1000, 'k2' => 1000, 'k3' => 300]);
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'LOW', false, false, 'Mozilla');
        $k3Key = hash_hmac('sha256', 'api_heavy_protection:rate_limiter:k3:v2:prod:127.0.0.1:hash_123', 'test_secret');
        $this->store->set($k3Key, 300, 3600);

        $result = $this->pipeline->process($policy, $context, RateLimitCommand::checkOnly('api_heavy_protection'), $device);

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $result->blockLevel);
        self::assertSame(300, $result->retryAfter);
        self::assertSame(2, $this->store->checkBlock(hash_hmac(
            'sha256',
            'api_heavy_protection:rate_limiter:k2:v2:prod:127.0.0.1:Mozilla',
            'test_secret',
        ))?->level);
        self::assertNull($this->store->checkBlock($k3Key));
    }

    public function testLowConfidenceK3UpdateRemainsHardL2AndTargetsK2Persistence(): void
    {
        $policy = new ApiHeavyProtectionPolicy(['k1' => 1000, 'k2' => 1000, 'k3' => 300]);
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'LOW', false, false, 'Mozilla');
        $k2Key = hash_hmac('sha256', 'api_heavy_protection:rate_limiter:k2:v2:prod:127.0.0.1:Mozilla', 'test_secret');
        $k3Key = hash_hmac('sha256', 'api_heavy_protection:rate_limiter:k3:v2:prod:127.0.0.1:hash_123', 'test_secret');
        $this->store->set($k3Key, 299, 3600);

        $result = $this->pipeline->process($policy, $context, new RateLimitCommand('api_heavy_protection'), $device);

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $result->blockLevel);
        self::assertSame(2, $this->store->checkBlock($k2Key)?->level);
        self::assertNull($this->store->checkBlock($k3Key));
    }

    public function testApiHeavyIgnoresCurrentAndPreviousK4AndK5Blocks(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');
        $currentK4 = hash_hmac('sha256', 'api_heavy_protection:rate_limiter:k4:v2:prod:acct_123', 'test_secret');
        $currentK5 = hash_hmac('sha256', 'api_heavy_protection:rate_limiter:k5:v2:prod:acct_123:hash_123', 'test_secret');
        $this->store->block($currentK4, 3, 600);
        $this->store->block($currentK5, 3, 600);

        $currentResult = $this->pipeline->process(
            new ApiHeavyProtectionPolicy(),
            $context,
            RateLimitCommand::checkOnly('api_heavy_protection'),
            $device,
        );
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $currentResult->decision);

        $rotatedPipeline = $this->createPipeline('previous_secret');
        $previousK4 = hash_hmac('sha256', 'api_heavy_protection:rate_limiter:k4:v2:prod:acct_123', 'previous_secret');
        $previousK5 = hash_hmac('sha256', 'api_heavy_protection:rate_limiter:k5:v2:prod:acct_123:hash_123', 'previous_secret');
        $this->store->block($previousK4, 3, 600);
        $this->store->block($previousK5, 3, 600);

        $previousResult = $rotatedPipeline->process(
            new ApiHeavyProtectionPolicy(),
            $context,
            RateLimitCommand::checkOnly('api_heavy_protection'),
            $device,
        );
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $previousResult->decision);
    }

    public function testNMinusOneWatchEscalationKeepsPenaltyLadderRetryAfter(): void
    {
        $correlationStore = new \Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore($this->clock);
        $pipeline = new EvaluationPipeline(
            $this->store,
            $correlationStore,
            new BudgetTracker($this->store, $this->clock),
            new AntiEquilibriumGate($correlationStore),
            new DecayCalculator($this->clock),
            new \Maatify\RateLimiter\Service\EphemeralBucket($correlationStore),
            'test_secret',
            'prod',
            $this->clock,
        );
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');
        $k4Key = hash_hmac('sha256', 'otp_protection:rate_limiter:k4:v2:prod:acct_123', 'test_secret');
        $this->store->set($k4Key, 1, 3600);
        $correlationStore->incrementWatchFlag("watch:{$k4Key}", 1800);

        $result = $pipeline->process($this->policy, $context, RateLimitCommand::recordFailure('otp_protection'), $device);

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $result->blockLevel);
        self::assertSame(60, $result->retryAfter);
    }

    public function testMultipleScoreScopesUseLongestWaitWithinWinningClass(): void
    {
        $policy = new ApiHeavyProtectionPolicy(['k1' => 1, 'k2' => 1000, 'k3' => 1]);
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');

        $result = $this->pipeline->process(
            $policy,
            $context,
            new RateLimitCommand('api_heavy_protection'),
            $device,
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(300, $result->retryAfter);
    }

    public function testEqualApiThresholdsIncrementNMinusOneWatchOnlyOnce(): void
    {
        $policy = new ApiHeavyProtectionPolicy(['k1' => 3, 'k2' => 1000, 'k3' => 1000]);
        $correlationStore = new \Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore($this->clock);
        $pipeline = new EvaluationPipeline(
            $this->store,
            $correlationStore,
            new BudgetTracker($this->store, $this->clock),
            new AntiEquilibriumGate($correlationStore),
            new DecayCalculator($this->clock),
            new \Maatify\RateLimiter\Service\EphemeralBucket($correlationStore),
            'test_secret',
            'prod',
            $this->clock,
        );
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');
        $k1Key = hash_hmac('sha256', 'api_heavy_protection:rate_limiter:k1:v2:prod:127.0.0.1', 'test_secret');
        $this->store->set($k1Key, 1, 3600);

        $result = $pipeline->process($policy, $context, new RateLimitCommand('api_heavy_protection'), $device);

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame(1, $correlationStore->watchValue("watch:{$k1Key}"));
    }

    public function testApiK3WatchEscalationUsesImpliedL2(): void
    {
        $policy = new ApiHeavyProtectionPolicy(['k1' => 1000, 'k2' => 1000, 'k3' => 3]);
        $correlationStore = new \Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore($this->clock);
        $pipeline = new EvaluationPipeline(
            $this->store,
            $correlationStore,
            new BudgetTracker($this->store, $this->clock),
            new AntiEquilibriumGate($correlationStore),
            new DecayCalculator($this->clock),
            new \Maatify\RateLimiter\Service\EphemeralBucket($correlationStore),
            'test_secret',
            'prod',
            $this->clock,
        );
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');
        $k3Key = hash_hmac('sha256', 'api_heavy_protection:rate_limiter:k3:v2:prod:127.0.0.1:hash_123', 'test_secret');
        $this->store->set($k3Key, 1, 3600);
        $correlationStore->incrementWatchFlag("watch:{$k3Key}", 1800);

        $result = $pipeline->process($policy, $context, new RateLimitCommand('api_heavy_protection'), $device);

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $result->blockLevel);
        self::assertSame(2, $this->store->checkBlock($k3Key)?->level);
    }

    public function testTrustedK1AdvisoryDoesNotSetFinalRetryAfter(): void
    {
        $policy = new class extends LoginProtectionPolicy {
            public function getScoreThresholds(): PolicyThresholdsDTO
            {
                return new PolicyThresholdsDTO(
                    k1: new ScoreThresholdsDTO(1, 1, 1),
                    k5: new ScoreThresholdsDTO(100, 101, 102),
                );
            }
        };
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', true, false, 'Mozilla');
        $k5Key = hash_hmac('sha256', 'login_protection:rate_limiter:k5:v2:prod:acct_123:hash_123', 'test_secret');
        $this->store->set($k5Key, 105, 3600);

        $result = $this->pipeline->process($policy, $context, RateLimitCommand::recordFailure('login_protection'), $device);

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2100, $result->retryAfter);
    }

    public function testPersistedScoreBlockTtlWinsOnTheNextRequest(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');
        $k4Key = hash_hmac('sha256', 'otp_protection:rate_limiter:k4:v2:prod:acct_123', 'test_secret');
        $this->store->set($k4Key, 2, 3600);
        $result = $this->pipeline->process($this->policy, $context, RateLimitCommand::recordFailure('otp_protection'), $device);

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(60, $result->retryAfter);

        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:00:10'));
        $next = $this->pipeline->process($this->policy, $context, RateLimitCommand::checkOnly('otp_protection'), $device);

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $next->decision);
        self::assertSame(50, $next->retryAfter);
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
        $this->assertEquals(1200, $result->retryAfter);

        $k4Key = hash_hmac('sha256', 'otp_protection:rate_limiter:k4:v2:prod:acct_123', 'test_secret');
        $k5Key = hash_hmac('sha256', 'otp_protection:rate_limiter:k5:v2:prod:acct_123:known-fingerprint', 'test_secret');

        $this->assertEquals(5, $this->store->get($k4Key)?->value);
        $this->assertNull($this->store->get($k5Key));
        $this->assertEquals(1, $this->store->getBudget($k4Key)?->count);
        $this->assertEquals(15, $this->store->checkBlock($k4Key)?->expiresAt - $this->clock->now()->getTimestamp());
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

    private function createPipeline(?string $previousSecret = null): EvaluationPipeline
    {
        return new EvaluationPipeline(
            $this->store,
            $this->correlationStore,
            new BudgetTracker($this->store, $this->clock),
            new AntiEquilibriumGate($this->correlationStore),
            new DecayCalculator($this->clock),
            new \Maatify\RateLimiter\Service\EphemeralBucket($this->correlationStore),
            'test_secret',
            'prod',
            $this->clock,
            $previousSecret,
        );
    }
}
