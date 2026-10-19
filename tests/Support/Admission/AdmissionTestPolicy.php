<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\Config\AdmissionReservationPolicyInterface;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\FailureFallbackConfigurationProviderInterface;
use Maatify\RateLimiter\Config\PolicyCapabilityProviderInterface;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\FailureFallbackConfigurationDTO;
use Maatify\RateLimiter\DTO\FailureFallbackRuleDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Enum\AdmissionHostModeEnum;
use Maatify\RateLimiter\Enum\FailureFallbackDimensionEnum;
use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;

/**
 * Valid authentication-class policy that declares the admission capability.
 * Every constructor argument can break exactly one registration rule.
 */
final class AdmissionTestPolicy implements
    BlockPolicyInterface,
    PolicyCapabilityProviderInterface,
    FailureFallbackConfigurationProviderInterface,
    AdmissionReservationPolicyInterface
{
    /** @param list<PolicyCapabilityEnum> $capabilities */
    public function __construct(
        private string $name = 'admission-test',
        private array $capabilities = [PolicyCapabilityEnum::ADMISSION_RESERVATION],
        private string $budgetGroup = 'login',
        private string $mode = 'FAIL_CLOSED',
        private ?BudgetConfigDTO $budget = new BudgetConfigDTO(20, 3),
        private ?FailureFallbackConfigurationDTO $configuration = null,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    /** @return list<PolicyCapabilityEnum> */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    public function getFailureFallbackConfiguration(): FailureFallbackConfigurationDTO
    {
        return $this->configuration ?? new FailureFallbackConfigurationDTO([
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
        return $this->mode;
    }

    public function getBudgetConfig(): ?BudgetConfigDTO
    {
        return $this->budget;
    }

    public function getAdmissionHostMode(): AdmissionHostModeEnum
    {
        return AdmissionHostModeEnum::PRE_AND_OUT;
    }

    public function getAdmissionBudgetGroup(): string
    {
        return $this->budgetGroup;
    }
}
