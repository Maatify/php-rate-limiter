<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Fixtures\S0Baseline;

use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;

/**
 * The Login preset as shipped at main@4d37be5: K4 thresholds only, so its
 * known-device allowance resolves to null (disabled), exactly as on S0.
 */
final class S0LoginProtectionPolicy extends LoginProtectionPolicy
{
    public function getScoreThresholds(): PolicyThresholdsDTO
    {
        return new PolicyThresholdsDTO(
            k4: new ScoreThresholdsDTO(5, 8, 12),
        );
    }
}
