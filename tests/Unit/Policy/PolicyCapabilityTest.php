<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Policy;

use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use PHPUnit\Framework\TestCase;

final class PolicyCapabilityTest extends TestCase
{
    public function testOfficialPresetsDeclareTheLockedCapabilitySets(): void
    {
        self::assertSame(
            [PolicyCapabilityEnum::CREDENTIAL_SPRAY, PolicyCapabilityEnum::DISTRIBUTED_ACCOUNT, PolicyCapabilityEnum::TRUSTED_AUTHENTICATION],
            (new LoginProtectionPolicy())->getCapabilities(),
        );
        self::assertSame(
            [PolicyCapabilityEnum::CREDENTIAL_SPRAY, PolicyCapabilityEnum::DISTRIBUTED_ACCOUNT, PolicyCapabilityEnum::TRUSTED_AUTHENTICATION],
            (new OtpProtectionPolicy())->getCapabilities(),
        );
        self::assertSame([PolicyCapabilityEnum::API_OVERUSE], (new ApiHeavyProtectionPolicy())->getCapabilities());
    }

    public function testCapabilitiesTravelWithADifferentlyNamedCustomPolicy(): void
    {
        $auth = new class extends LoginProtectionPolicy {
            public function getName(): string
            {
                return 'account_recovery_protection';
            }
        };
        $api = new class extends ApiHeavyProtectionPolicy {
            public function getName(): string
            {
                return 'partner_api_protection';
            }
        };

        self::assertContains(PolicyCapabilityEnum::CREDENTIAL_SPRAY, $auth->getCapabilities());
        self::assertContains(PolicyCapabilityEnum::DISTRIBUTED_ACCOUNT, $auth->getCapabilities());
        self::assertContains(PolicyCapabilityEnum::TRUSTED_AUTHENTICATION, $auth->getCapabilities());
        self::assertSame([PolicyCapabilityEnum::API_OVERUSE], $api->getCapabilities());
    }

    public function testInvalidCapabilityValueIsRejectedAtRegistration(): void
    {
        $invalid = new class implements \Maatify\RateLimiter\Config\BlockPolicyInterface, \Maatify\RateLimiter\Config\PolicyCapabilityProviderInterface {
            public function getName(): string
            {
                return 'invalid_capability_policy';
            }

            public function getCapabilities(): array
            {
                // @phpstan-ignore-next-line return.type
                return ['not-a-package-capability'];
            }

            public function getScoreThresholds(): \Maatify\RateLimiter\DTO\PolicyThresholdsDTO
            {
                return new \Maatify\RateLimiter\DTO\PolicyThresholdsDTO();
            }

            public function getScoreDeltas(): \Maatify\RateLimiter\DTO\ScoreDeltasDTO
            {
                return new \Maatify\RateLimiter\DTO\ScoreDeltasDTO();
            }

            public function getFailureMode(): string
            {
                return 'FAIL_CLOSED';
            }

            public function getBudgetConfig(): ?\Maatify\RateLimiter\DTO\BudgetConfigDTO
            {
                return null;
            }
        };

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Capabilities must be PolicyCapabilityEnum values');
        $this->builder()->withPolicy($invalid)->build();
    }

    private function builder(): RateLimiterBuilder
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $rateLimitStore = new InMemoryRateLimitStore($clock);
        $correlationStore = new StatefulInMemoryCorrelationStore($clock);
        $circuitBreakerStore = new InMemoryCircuitBreakerStore();
        $emitter = new RecordingFailureSignalEmitter();

        return new RateLimiterBuilder(
            new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod'),
            $rateLimitStore,
            $correlationStore,
            $circuitBreakerStore,
            $emitter,
        );
    }
}
