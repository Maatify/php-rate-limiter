<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Enum;

use Maatify\RateLimiter\DTO\FailureFallbackConfigurationDTO;
use Maatify\RateLimiter\DTO\FailureFallbackRuleDTO;

/**
 * Package-owned zero-configuration fallback presets.
 *
 * This is the single canonical source of the official Login, OTP, and API
 * Heavy fallback caps. It is a convenience factory over the generic
 * {@see FailureFallbackConfigurationDTO} runtime contract, not a separate
 * runtime path: `LoginProtectionPolicy`, `OtpProtectionPolicy`, and
 * `ApiHeavyProtectionPolicy` each resolve one case here so a Host never has
 * to assemble these values. It is not part of the
 * {@see FailureFallbackConfigurationProviderInterface} contract itself and a
 * direct custom policy never needs to reference it: the generic
 * configuration is the only thing the runtime consumes.
 */
enum FailureFallbackProfileEnum
{
    case AUTHENTICATION_PRIMARY;
    case AUTHENTICATION_STEP_UP;
    case API_OVERUSE;

    /**
     * Return the locked generic configuration for this official preset.
     */
    public function configuration(): FailureFallbackConfigurationDTO
    {
        return match ($this) {
            self::AUTHENTICATION_PRIMARY => new FailureFallbackConfigurationDTO([
                new FailureFallbackRuleDTO(FailureFallbackDimensionEnum::ACCOUNT, 3, 600),
                new FailureFallbackRuleDTO(FailureFallbackDimensionEnum::IP_PREFIX, 20, 600),
            ]),
            self::AUTHENTICATION_STEP_UP => new FailureFallbackConfigurationDTO([
                new FailureFallbackRuleDTO(FailureFallbackDimensionEnum::ACCOUNT, 2, 900),
                new FailureFallbackRuleDTO(FailureFallbackDimensionEnum::IP_PREFIX, 10, 900),
            ]),
            self::API_OVERUSE => new FailureFallbackConfigurationDTO([
                new FailureFallbackRuleDTO(FailureFallbackDimensionEnum::IP_PREFIX, 120, 60),
                new FailureFallbackRuleDTO(FailureFallbackDimensionEnum::IP_PREFIX_NORMALIZED_USER_AGENT, 60, 60),
            ]),
        };
    }
}
