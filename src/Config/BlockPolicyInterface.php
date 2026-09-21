<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Config;

use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;

/**
 * Supplies the policy-specific thresholds, score deltas, and failure rules
 * used by the evaluation pipeline.
 */
interface BlockPolicyInterface
{
    /**
     * Return the stable identifier used to select this policy at runtime.
     */
    public function getName(): string;

    /**
     * Return thresholds mapping each configured scope score to block levels.
     */
    public function getScoreThresholds(): PolicyThresholdsDTO;

    /**
     * Return score increments for access and failure scenarios.
     */
    public function getScoreDeltas(): ScoreDeltasDTO;

    /**
     * Return the backend-failure mode, normally `FAIL_CLOSED` or `FAIL_OPEN`.
     */
    public function getFailureMode(): string;

    /**
     * Return account-budget rules, or `null` when this policy has no budget.
     */
    public function getBudgetConfig(): ?BudgetConfigDTO;
}
