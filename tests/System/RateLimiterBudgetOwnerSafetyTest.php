<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Device\EphemeralBucket;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Engine\EvaluationPipeline;
use Maatify\RateLimiter\Penalty\AntiEquilibriumGate;
use Maatify\RateLimiter\Penalty\BudgetTracker;
use Maatify\RateLimiter\Penalty\DecayCalculator;
use Maatify\RateLimiter\Policy\LoginProtectionPolicy;
use Maatify\RateLimiter\Policy\OtpProtectionPolicy;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class RateLimiterBudgetOwnerSafetyTest extends TestCase
{
    private FixedClock $clock;
    private InMemoryRateLimitStore $store;
    private StatefulInMemoryCorrelationStore $correlationStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new InMemoryRateLimitStore($this->clock);
        $this->correlationStore = new StatefulInMemoryCorrelationStore($this->clock);
    }

    public function testLoginCheckOnlyBudgetEligibilityIsClassAware(): void
    {
        $policy = new LoginProtectionPolicy();
        $device = $this->device();

        $allowAccount = 'budget-check-allow';
        $allowKey = $this->key('login_protection', 'k4', $allowAccount);
        $this->store->incrementBudget($allowKey, 86400, 20);
        $allow = $this->pipeline()->process($policy, $this->context($allowAccount), RateLimitCommand::checkOnly('login_protection'), $device);
        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $allow->decision);
        $this->assertSame(3, $allow->blockLevel);
        $this->assertSame(3600, $allow->retryAfter);

        foreach ([
            ['budget-check-soft', 5, RateLimitResultDTO::DECISION_SOFT_BLOCK, 1],
            ['budget-check-hard', 8, RateLimitResultDTO::DECISION_HARD_BLOCK, 2],
        ] as [$account, $score, $decision, $level]) {
            $k4Key = $this->key('login_protection', 'k4', $account);
            $this->store->set($k4Key, $score, 86400);
            $this->store->incrementBudget($k4Key, 86400, 20);

            $result = $this->pipeline()->process(
                $policy,
                $this->context($account),
                RateLimitCommand::checkOnly('login_protection'),
                $device
            );

            $this->assertSame($decision, $result->decision);
            $this->assertSame($level, $result->blockLevel);
            $this->assertNull($this->store->get($this->cooldownKey('login_protection', $account)));
        }

        $this->assertNull($this->store->checkBlock($allowKey));
    }

    public function testOtpCheckOnlyAndSuccessNeverEnforceBudget(): void
    {
        $policy = new OtpProtectionPolicy();
        $account = 'otp-command-eligibility';
        $k4Key = $this->key('otp_protection', 'k4', $account);
        $this->store->incrementBudget($k4Key, 86400, 10);

        $pipeline = $this->pipeline();
        $checkOnly = $pipeline->process($policy, $this->context($account), RateLimitCommand::checkOnly('otp_protection'), $this->device('otp-fp', 'HIGH', true));
        $success = $pipeline->process($policy, $this->context($account), RateLimitCommand::recordSuccess('otp_protection'), $this->device('otp-fp', 'HIGH', true));

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $checkOnly->decision);
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $success->decision);
        $this->assertSame(10, $this->store->getBudget($k4Key)?->count);
        $this->assertNull($this->store->get($this->cooldownKey('otp_protection', $account)));
    }

    public function testKnownTrustedAndPreviouslyVerifiedDevicesDoNotUseNewDeviceK4Scoring(): void
    {
        $policy = new LoginProtectionPolicy();
        $pipeline = $this->pipeline();

        $trustedAccount = 'known-trusted';
        $trusted = $pipeline->process(
            $policy,
            $this->context($trustedAccount),
            RateLimitCommand::recordFailure('login_protection'),
            $this->device('trusted-fp', 'HIGH', true)
        );
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $trusted->decision);
        $this->assertSame(2, $this->store->get($this->key('login_protection', 'k5', "{$trustedAccount}:trusted-fp"))?->value);
        $this->assertNull($this->store->get($this->key('login_protection', 'k4', $trustedAccount)));
        $this->assertNull($this->store->getBudget($this->key('login_protection', 'k4', $trustedAccount)));

        $verifiedAccount = 'known-verified';
        $verified = $pipeline->process(
            $policy,
            $this->context($verifiedAccount, '198.51.100.41'),
            RateLimitCommand::recordFailure('login_protection'),
            $this->device('verified-fp', 'MEDIUM', false, true)
        );
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $verified->decision);
        $this->assertSame(2, $this->store->get($this->key('login_protection', 'k5', "{$verifiedAccount}:verified-fp"))?->value);
        $this->assertNull($this->store->get($this->key('login_protection', 'k4', $verifiedAccount)));

        $newAccount = 'new-device';
        $new = $pipeline->process(
            $policy,
            $this->context($newAccount, '198.51.100.42'),
            RateLimitCommand::recordFailure('login_protection'),
            $this->device('new-fp')
        );
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $new->decision);
        $this->assertSame(3, $this->store->get($this->key('login_protection', 'k4', $newAccount))?->value);
        $this->assertSame(1, $this->store->getBudget($this->key('login_protection', 'k4', $newAccount))?->count);
    }

    public function testKnownDeviceFirstEightFailuresBuildMicroCapAndNinthEntersAccountBudget(): void
    {
        $policy = new LoginProtectionPolicy();
        $account = 'known-micro-cap';
        $device = $this->device('known-fp', 'MEDIUM', false, true);
        $pipeline = $this->pipeline();

        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $result = $pipeline->process($policy, $this->context($account), RateLimitCommand::recordFailure('login_protection'), $device);
            $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
            $this->assertNull($this->store->getBudget($this->key('login_protection', 'k4', $account)));
        }

        $microKey = $this->microCapKey('login_protection', $account, 'known-fp');
        $k4Key = $this->key('login_protection', 'k4', $account);
        $this->assertSame(8, $this->store->getBudget($microKey)?->count);

        $ninth = $pipeline->process($policy, $this->context($account), RateLimitCommand::recordFailure('login_protection'), $device);
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $ninth->decision);
        $this->assertSame(9, $this->store->getBudget($microKey)?->count);
        $this->assertSame(1, $this->store->getBudget($k4Key)?->count);
    }

    public function testTrustedSessionFloorsBudgetLevelsForLoginAndOtp(): void
    {
        $loginAccount = 'trusted-login-floor';
        $loginKey = $this->key('login_protection', 'k4', $loginAccount);
        $this->store->incrementBudget($loginKey, 86400, 20);
        $login = $this->pipeline()->process(
            new LoginProtectionPolicy(),
            $this->context($loginAccount),
            RateLimitCommand::checkOnly('login_protection'),
            $this->device('login-trusted', 'HIGH', true)
        );

        $otpAccount = 'trusted-otp-floor';
        $otpKey = $this->key('otp_protection', 'k4', $otpAccount);
        $this->store->incrementBudget($otpKey, 86400, 10);
        $otp = $this->pipeline()->process(
            new OtpProtectionPolicy(),
            $this->context($otpAccount),
            RateLimitCommand::recordFailure('otp_protection'),
            $this->device('otp-trusted', 'HIGH', true)
        );

        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $login->decision);
        $this->assertSame(2, $login->blockLevel);
        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $otp->decision);
        $this->assertSame(3, $otp->blockLevel);
    }

    public function testNormalSoftAndBudgetSoftAggregateWithinSoftClassWithoutBudgetBlockState(): void
    {
        $account = 'soft-class-aggregation';
        $k4Key = $this->key('login_protection', 'k4', $account);
        $this->store->set($k4Key, 2, 86400);
        $this->store->incrementBudget($k4Key, 86400, 19);

        $result = $this->pipeline()->process(
            new LoginProtectionPolicy(),
            $this->context($account),
            RateLimitCommand::recordFailure('login_protection'),
            $this->device('new-fp')
        );

        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $result->decision);
        $this->assertSame(3, $result->blockLevel);
        $this->assertSame(3600, $result->retryAfter);
        $this->assertSame(1, $this->store->checkBlock($k4Key)?->level);
    }

    public function testRecoveryGuardHandlesNineToTenThenBudgetHandlesEleven(): void
    {
        $account = 'recovery-guard';
        $k4Key = $this->key('otp_protection', 'k4', $account);
        $this->store->incrementBudget($k4Key, 86400, 9);
        $device = $this->device('recovery-fp', 'HIGH', true);
        $pipeline = $this->pipeline();

        $guard = $pipeline->process(new OtpProtectionPolicy(), $this->context($account), RateLimitCommand::recordFailure('otp_protection'), $device);
        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $guard->decision);
        $this->assertSame(2, $guard->blockLevel);
        $this->assertSame(60, $guard->retryAfter);
        $this->assertSame(10, $this->store->getBudget($k4Key)?->count);
        $this->assertNull($this->store->get($this->cooldownKey('otp_protection', $account)));

        $next = $pipeline->process(new OtpProtectionPolicy(), $this->context($account), RateLimitCommand::recordFailure('otp_protection'), $device);
        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $next->decision);
        $this->assertSame(3, $next->blockLevel);
        $this->assertSame(7200, $next->retryAfter);
        $this->assertSame(11, $this->store->getBudget($k4Key)?->count);
        $this->assertSame(1, $this->store->get($this->cooldownKey('otp_protection', $account))?->value);
    }

    public function testAntiEquilibriumThirdSoftStaysSoftAndNextFailureBecomesHard(): void
    {
        $account = 'anti-equilibrium';
        $k4Key = $this->key('login_protection', 'k4', $account);
        $this->store->set($k4Key, 5, 86400);
        $policy = new LoginProtectionPolicy();
        $pipeline = $this->pipeline();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $soft = $pipeline->process($policy, $this->context($account), RateLimitCommand::checkOnly('login_protection'), $this->device('anti-fp'));
            $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $soft->decision);
            $this->assertSame(1, $soft->blockLevel);
        }
        $this->assertSame(3, $this->correlationStore->getWatchFlag("gate:soft:{$account}"));

        $this->store->set($k4Key, 0, 86400);
        $allow = $pipeline->process($policy, $this->context($account), RateLimitCommand::checkOnly('login_protection'), $this->device('anti-fp'));
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $allow->decision);
        $this->assertSame(3, $this->correlationStore->getWatchFlag("gate:soft:{$account}"));

        $this->store->incrementBudget($k4Key, 86400, 20);
        $failure = $pipeline->process($policy, $this->context($account), RateLimitCommand::recordFailure('login_protection'), $this->device('anti-fp'));
        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $failure->decision);
        $this->assertSame(2, $failure->blockLevel);
        $this->assertNull($this->store->get($this->cooldownKey('login_protection', $account)));
        $this->assertSame(3, $this->correlationStore->getWatchFlag("gate:soft:{$account}"));
    }

    public function testCooldownRotationReadsPreviousWritesCurrentAndDoesNotExtend(): void
    {
        $account = 'cooldown-rotation';
        $oldBudgetKey = $this->key('login_protection', 'k4', $account, 'old_secret');
        $this->store->incrementBudget($oldBudgetKey, 86400, 20);
        $pipeline = $this->pipeline('old_secret', 'new_secret');

        $first = $pipeline->process(new LoginProtectionPolicy(), $this->context($account), RateLimitCommand::checkOnly('login_protection'), $this->device('rotation-fp'));
        $currentCooldown = $this->cooldownKey('login_protection', $account, 'new_secret');
        $previousCooldown = $this->cooldownKey('login_protection', $account, 'old_secret');
        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $first->decision);
        $this->assertSame(1, $this->store->get($currentCooldown)?->value);
        $this->assertNull($this->store->get($previousCooldown));
        $createdAt = $this->store->get($currentCooldown)?->updatedAt;

        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:00:30'));
        $second = $pipeline->process(new LoginProtectionPolicy(), $this->context($account), RateLimitCommand::checkOnly('login_protection'), $this->device('rotation-fp'));
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $second->decision);
        $this->assertSame($createdAt, $this->store->get($currentCooldown)?->updatedAt);

        $previousActiveAccount = 'cooldown-previous-active';
        $this->store->incrementBudget($this->key('login_protection', 'k4', $previousActiveAccount, 'old_secret'), 86400, 20);
        $this->store->increment($this->cooldownKey('login_protection', $previousActiveAccount, 'old_secret'), 3600);
        $previousActive = $pipeline->process(new LoginProtectionPolicy(), $this->context($previousActiveAccount), RateLimitCommand::checkOnly('login_protection'), $this->device('rotation-fp'));
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $previousActive->decision);
        $this->assertNull($this->store->get($this->cooldownKey('login_protection', $previousActiveAccount, 'new_secret')));
    }

    private function pipeline(?string $previousSecret = null, string $currentSecret = 'test_secret'): EvaluationPipeline
    {
        return new EvaluationPipeline(
            $this->store,
            $this->correlationStore,
            new BudgetTracker($this->store, $this->clock),
            new AntiEquilibriumGate($this->correlationStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($this->correlationStore),
            $currentSecret,
            'prod',
            $this->clock,
            $previousSecret
        );
    }

    private function context(string $account, string $ip = '198.51.100.40'): RateLimitContextDTO
    {
        return new RateLimitContextDTO($ip, 'Chrome/123', $account, ['device' => 'stable']);
    }

    private function device(string $fingerprint = 'fp', string $confidence = 'MEDIUM', bool $trusted = false, bool $previouslyVerified = false): DeviceIdentityDTO
    {
        return new DeviceIdentityDTO($fingerprint, $confidence, $trusted, false, 'chrome/123', $previouslyVerified);
    }

    private function key(string $policy, string $type, string $scope, string $secret = 'test_secret'): string
    {
        return hash_hmac('sha256', "{$policy}:rate_limiter:{$type}:v2:prod:{$scope}", $secret);
    }

    private function microCapKey(string $policy, string $account, string $fingerprint, string $secret = 'test_secret'): string
    {
        return hash_hmac('sha256', "{$policy}:rate_limiter:microcap:k5:v1:{$account}:{$fingerprint}", $secret);
    }

    private function cooldownKey(string $policy, string $account, string $secret = 'test_secret'): string
    {
        return hash_hmac('sha256', "{$policy}:rate_limiter:budget_cooldown:v1:prod:{$account}", $secret);
    }
}
