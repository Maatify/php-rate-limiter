<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\FailureFallbackConfigurationProviderInterface;
use Maatify\RateLimiter\Config\KnownDeviceAccountAllowancePolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\FailureFallbackConfigurationDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Enum\FailureFallbackProfileEnum;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Known-device verifier bound through the public Builder / limit() surface
 * (#114 hardening slice of #110, F117-02 and F117-04).
 */
final class KnownDeviceAllowancePublicApiTest extends TestCase
{
    private FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FixedClock('2025-01-01 12:00:00');
    }

    public function testTrustedSessionOnOwnAccountReachesVerifierFourTimesThroughPublicApi(): void
    {
        $limiter = $this->limiter(new FullCapabilityInMemoryStore($this->clock));
        $context = $this->trustedContext('public-owner');

        $reached = 0;
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $pre = $limiter->limit($context, RateLimitCommand::checkOnly('login_protection'));
            if ($pre->decision === RateLimitResultDTO::DECISION_ALLOW) {
                $reached++;
                $limiter->limit($context, RateLimitCommand::recordFailure('login_protection'));
            }
            $this->advance(3);
        }

        $this->assertSame(4, $reached);
    }

    /**
     * A valid custom authentication policy opting in: the allowance is the only
     * active bound here (K5 thresholds far away), so the third known-device
     * failure is the first one scored on K4.
     */
    public function testValidCustomAllowancePolicyScoresK4AfterItsAllowance(): void
    {
        $store = new FullCapabilityInMemoryStore($this->clock);
        $limiter = $this->limiter($store, new CustomAllowancePolicy());
        $context = $this->trustedContext('custom-owner');
        $k4 = hash_hmac('sha256', 'custom_allowance_flow:rate_limiter:k4:v2:prod:custom-owner', 'key-secret');

        $decisions = [];
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $decisions[] = $limiter->limit($context, RateLimitCommand::recordFailure('custom_allowance_flow'))->decision;
        }

        $this->assertSame([
            RateLimitResultDTO::DECISION_ALLOW,
            RateLimitResultDTO::DECISION_ALLOW,
            RateLimitResultDTO::DECISION_ALLOW,
            RateLimitResultDTO::DECISION_SOFT_BLOCK,
        ], $decisions);
        $this->assertSame(6, $store->get($k4)?->value);
    }

    /**
     * A Host subclass of the official preset that overrides thresholds without
     * K5 keeps S0 known-device behavior (allowance disabled), and still registers.
     */
    public function testPresetSubclassWithoutK5ThresholdsKeepsS0KnownDeviceBehavior(): void
    {
        $policy = new class extends LoginProtectionPolicy {
            public function getScoreThresholds(): PolicyThresholdsDTO
            {
                return new PolicyThresholdsDTO(k4: new ScoreThresholdsDTO(5, 8, 12));
            }
        };
        $this->assertNull($policy->getKnownDeviceAccountAllowance());

        $store = new FullCapabilityInMemoryStore($this->clock);
        $limiter = $this->limiter($store, $policy);
        $context = $this->trustedContext('subclass-owner');

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $result = $limiter->limit($context, RateLimitCommand::recordFailure('login_protection'));
            $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }
        $this->assertNull($store->get(hash_hmac('sha256', 'login_protection:rate_limiter:k4:v2:prod:subclass-owner', 'key-secret')));
    }

    public function testPresetSubclassInheritingThresholdsKeepsTheAllowance(): void
    {
        $policy = new class extends LoginProtectionPolicy {
            public function getName(): string
            {
                return 'renamed_login';
            }
        };

        $this->assertSame(8, $policy->getKnownDeviceAccountAllowance());
    }

    /**
     * DEC-015 through the public API: a backend failure while consuming the
     * allowance takes the engine's existing typed backend-failure path. The
     * first failures fail closed; once the circuit opens, the bounded local
     * fallback (AUTHENTICATION_PRIMARY: 3 per account per 600 s) applies. No
     * result is ever a normal-mode decision. This slice does not change DEC-015.
     */
    public function testAllowanceBackendFailureUsesTheExistingDec015PathThroughPublicApi(): void
    {
        $store = new AllowanceFailingStore($this->clock);
        $limiter = $this->limiter($store);
        $context = $this->trustedContext('outage-owner');

        $store->failBudgetIncrements = true;
        $observed = [];
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $result = $limiter->limit($context, RateLimitCommand::recordFailure('login_protection'));
            $observed[] = [$result->decision, $result->failureMode, $result->metadata?->cause];
        }

        $this->assertSame([
            [RateLimitResultDTO::DECISION_HARD_BLOCK, 'FAIL_CLOSED', null],
            [RateLimitResultDTO::DECISION_HARD_BLOCK, 'FAIL_CLOSED', null],
            [RateLimitResultDTO::DECISION_ALLOW, 'DEGRADED_MODE', null],
            [RateLimitResultDTO::DECISION_ALLOW, 'DEGRADED_MODE', null],
            [RateLimitResultDTO::DECISION_ALLOW, 'DEGRADED_MODE', null],
            [RateLimitResultDTO::DECISION_HARD_BLOCK, 'DEGRADED_MODE', 'fallback_limit_exceeded'],
        ], $observed);
        $this->assertNull($store->get(hash_hmac('sha256', 'login_protection:rate_limiter:k4:v2:prod:outage-owner', 'key-secret')));
    }

    /**
     * FixedClock::sleep() is a no-op; time must be advanced explicitly.
     */
    private function advance(int $seconds): void
    {
        $this->clock->setNow($this->clock->now()->modify("+{$seconds} seconds"));
    }

    private function limiter(FullCapabilityInMemoryStore $store, ?BlockPolicyInterface $policy = null): RateLimiterInterface
    {
        $builder = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod'),
            $store,
            new RecordingFailureSignalEmitter(),
        )->withClock($this->clock);
        if ($policy !== null) {
            $builder = $builder->withPolicy($policy);
        }

        return $builder->build();
    }

    private function trustedContext(string $account): RateLimitContextDTO
    {
        return new RateLimitContextDTO('198.51.100.70', 'Mozilla/5.0 Chrome/126', $account, ['canvas' => 'owner-laptop'], "session-{$account}", true);
    }
}

final class CustomAllowancePolicy implements KnownDeviceAccountAllowancePolicyInterface, FailureFallbackConfigurationProviderInterface
{
    public function getName(): string
    {
        return 'custom_allowance_flow';
    }

    public function getFailureFallbackConfiguration(): FailureFallbackConfigurationDTO
    {
        return FailureFallbackProfileEnum::AUTHENTICATION_PRIMARY->configuration();
    }

    public function getScoreThresholds(): PolicyThresholdsDTO
    {
        return new PolicyThresholdsDTO(k4: new ScoreThresholdsDTO(5, 8, 12), k5: new ScoreThresholdsDTO(100, 200, 300));
    }

    public function getScoreDeltas(): ScoreDeltasDTO
    {
        return new ScoreDeltasDTO(k2_missing_fp: 4, k4_failure: 3, k5_failure: 2);
    }

    public function getFailureMode(): string
    {
        return 'FAIL_CLOSED';
    }

    public function getBudgetConfig(): BudgetConfigDTO
    {
        return new BudgetConfigDTO(20, 3);
    }

    public function getKnownDeviceAccountAllowance(): int
    {
        return 2;
    }
}

final class AllowanceFailingStore extends FullCapabilityInMemoryStore
{
    public bool $failBudgetIncrements = false;

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        if ($this->failBudgetIncrements) {
            throw new BackendFailureException('allowance backend down');
        }

        return parent::incrementBudget($key, $epochDurationSeconds, $amount);
    }
}
