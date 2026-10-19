<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\FailureFallbackConfigurationProviderInterface;
use Maatify\RateLimiter\Config\PolicyCapabilityProviderInterface;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\FailureFallbackConfigurationDTO;
use Maatify\RateLimiter\DTO\FailureFallbackRuleDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Enum\FailureFallbackDimensionEnum;
use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;

/**
 * A valid authentication-class custom policy. With `$declaresAdmission` it
 * declares the capability but lacks the declaration interface; without it the
 * policy is an ordinary custom policy that never opts in.
 */
final class DeclaresCapabilityWithoutInterfacePolicy implements
    BlockPolicyInterface,
    PolicyCapabilityProviderInterface,
    FailureFallbackConfigurationProviderInterface
{
    public function __construct(
        private string $name = 'plain-auth',
        private bool $declaresAdmission = false,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    /** @return list<PolicyCapabilityEnum> */
    public function getCapabilities(): array
    {
        return $this->declaresAdmission
            ? [PolicyCapabilityEnum::ADMISSION_RESERVATION]
            : [PolicyCapabilityEnum::TRUSTED_AUTHENTICATION];
    }

    public function getFailureFallbackConfiguration(): FailureFallbackConfigurationDTO
    {
        return new FailureFallbackConfigurationDTO([
            new FailureFallbackRuleDTO(FailureFallbackDimensionEnum::ACCOUNT, 3, 600),
            new FailureFallbackRuleDTO(FailureFallbackDimensionEnum::IP_PREFIX, 20, 600),
        ]);
    }

    public function getScoreThresholds(): PolicyThresholdsDTO
    {
        return new PolicyThresholdsDTO(k4: new ScoreThresholdsDTO(5, 8, 12), k5: new ScoreThresholdsDTO(8, 12, 16));
    }

    public function getScoreDeltas(): ScoreDeltasDTO
    {
        return new ScoreDeltasDTO(k2_missing_fp: 4, k4_failure: 3, k5_failure: 2);
    }

    public function getFailureMode(): string
    {
        return 'FAIL_CLOSED';
    }

    public function getBudgetConfig(): ?BudgetConfigDTO
    {
        return new BudgetConfigDTO(20, 3);
    }
}
