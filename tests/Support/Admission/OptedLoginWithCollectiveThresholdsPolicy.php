<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;

/**
 * Opted Login variant that also carries K1, K2 and K3 score thresholds (the preset has none), so the P-lane rule
 * "no K1/K2/K3 threshold candidate" has something to refuse.
 */
final class OptedLoginWithCollectiveThresholdsPolicy extends OptedLoginPolicy
{
    public function getScoreThresholds(): PolicyThresholdsDTO
    {
        return new PolicyThresholdsDTO(
            k1: new ScoreThresholdsDTO(3, 6, 9),
            k2: new ScoreThresholdsDTO(3, 6, 9),
            k3: new ScoreThresholdsDTO(3, 6, 9),
            k4: new ScoreThresholdsDTO(5, 8, 12),
            k5: new ScoreThresholdsDTO(8, 12, 16),
        );
    }
}
