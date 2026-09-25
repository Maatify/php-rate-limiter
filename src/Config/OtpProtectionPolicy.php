<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Config;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;

/**
 * Default OTP failure policy with account-scoped thresholds and recovery guard.
 *
 * It opts into DEC-007 generation-bound K4 lifecycle behavior. The policy
 * supplies positive monotonic K4 thresholds and fail-closed failure semantics;
 * the Builder additionally requires lifecycle-capable storage for this opt-in.
 */
class OtpProtectionPolicy implements PostPunishmentReentryPolicyInterface, PolicyCapabilityProviderInterface, FailureFallbackProfileProviderInterface
{
    public function getFailureFallbackProfile(): FailureFallbackProfile
    {
        return FailureFallbackProfile::AUTHENTICATION_STEP_UP;
    }

    /** @return list<PolicyCapability> */
    public function getCapabilities(): array
    {
        return [
            PolicyCapability::CREDENTIAL_SPRAY,
            PolicyCapability::DISTRIBUTED_ACCOUNT,
            PolicyCapability::TRUSTED_AUTHENTICATION,
        ];
    }

    /**
     * Return the policy identifier consumed by the engine.
     */
    public function getName(): string
    {
        return 'otp_protection';
    }

    /**
     * Return the K4 thresholds for soft and hard OTP blocking.
     */
    public function getScoreThresholds(): PolicyThresholdsDTO
    {
        return new PolicyThresholdsDTO(
            k4: new ScoreThresholdsDTO(4, 7, 10),
        );
    }

    /**
     * Return the score increments for OTP risk signals.
     */
    public function getScoreDeltas(): ScoreDeltasDTO
    {
        return new ScoreDeltasDTO(
            k2_missing_fp: 6,
            k4_failure: 5,
            k4_repeated_missing_fp: 8,
            k5_failure: 4,
        );
    }

    /**
     * Return the fail-closed policy used when the backing store is unavailable.
     */
    public function getFailureMode(): string
    {
        return 'FAIL_CLOSED';
    }

    /**
     * Return the account budget and recovery-collision rules.
     */
    public function getBudgetConfig(): ?BudgetConfigDTO
    {
        return new BudgetConfigDTO(
            threshold: 10,
            block_level: 4,
            cooldown_seconds: 7200,
            trusted_session_floor_level: 3,
            precheck_enforcement: false,
            known_device_micro_cap: null,
            recovery_collision_guard_enabled: true,
        );
    }
}
