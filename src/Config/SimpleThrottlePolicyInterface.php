<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Config;

/**
 * Policy contract for the generic/simple fixed-window throttling capability.
 *
 * This contract is independent of BlockPolicyInterface, ScoreThresholdsDTO,
 * ScoreDeltasDTO, and BudgetConfigDTO: simple throttling is a separate
 * semantic family from the package's score-based security-policy model
 * (DEC-009).
 */
interface SimpleThrottlePolicyInterface
{
    /**
     * Return the stable policy identifier consumed by SimpleRateLimiterInterface::consume().
     */
    public function getName(): string;

    /**
     * Return the positive maximum number of consumes allowed per fixed window.
     */
    public function getLimit(): int;

    /**
     * Return the positive fixed-window duration in seconds.
     */
    public function getIntervalSeconds(): int;
}
