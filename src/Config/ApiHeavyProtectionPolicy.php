<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Config;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;

/**
 * Policy for high-volume API traffic using IP and IP/user-agent scopes.
 */
class ApiHeavyProtectionPolicy implements BlockPolicyInterface
{
    private const DISABLED_THRESHOLD = PHP_INT_MAX;

    /** @var array<string, int> */
    private array $limits;

    /**
     * @param array<string, int> $limits
     */
    public function __construct(array $limits = [])
    {
        $this->limits = array_merge([
            'k2' => 120,
            'k3' => 300,
            'k1' => 600,
        ], $limits);
    }

    /**
     * Return the policy identifier consumed by the engine.
     */
    public function getName(): string
    {
        return 'api_heavy_protection';
    }

    /**
     * Return the configured K1, K2, and K3 score thresholds.
     */
    public function getScoreThresholds(): PolicyThresholdsDTO
    {
        return new PolicyThresholdsDTO(
            k1: new ScoreThresholdsDTO($this->limits['k1'], $this->limits['k1'], $this->limits['k1']),
            k2: new ScoreThresholdsDTO($this->limits['k2'], self::DISABLED_THRESHOLD, self::DISABLED_THRESHOLD),
            k3: new ScoreThresholdsDTO($this->limits['k3'], $this->limits['k3'], self::DISABLED_THRESHOLD),
        );
    }

    /**
     * Return the per-access score increment for API traffic.
     */
    public function getScoreDeltas(): ScoreDeltasDTO
    {
        return new ScoreDeltasDTO(access: 1);
    }

    /**
     * Return the fail-open policy used when the backing store is unavailable.
     */
    public function getFailureMode(): string
    {
        return 'FAIL_OPEN';
    }

    /**
     * API protection does not use an account budget.
     */
    public function getBudgetConfig(): ?BudgetConfigDTO
    {
        return null;
    }
}
