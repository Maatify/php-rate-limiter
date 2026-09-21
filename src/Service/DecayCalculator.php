<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Calculates elapsed score decay using scope-specific rates and block level.
 */
class DecayCalculator
{
    private const RATE_ACCOUNT = 600; // 10m
    private const RATE_DEVICE = 300;  // 5m
    private const RATE_IP = 180;      // 3m

    /**
     * @param ClockInterface $clock Source of the current timestamp.
     */
    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Calculate whole score points to remove since the last update.
     *
     * Account, device, and IP scopes use different base rates. L2 and higher
     * blocks halve the effective decay rate; unsupported scope names use the
     * account rate for backward-compatible behavior.
     */
    public function calculateDecay(
        int $currentScore,
        int $lastUpdateTimestamp,
        int $currentBlockLevel,
        string $scope, // 'account', 'device', 'ip'
    ): int {
        if ($currentScore <= 0) {
            return 0;
        }

        $now = $this->clock->now()->getTimestamp();
        $elapsed = $now - $lastUpdateTimestamp;

        if ($elapsed <= 0) {
            return 0;
        }

        $decayAmount = (int) floor($elapsed / $this->effectiveInterval($scope, $currentBlockLevel));

        return $decayAmount;
    }

    /**
     * Calculate the time from now until the score is strictly below a threshold.
     *
     * The stored score and timestamp are used together so completed decay
     * intervals and the partial current interval are both reflected. A
     * non-positive threshold is invalid because score values are clamped at
     * zero and cannot produce a meaningful "below threshold" wait.
     *
     * @throws RateLimiterException When the threshold is not positive.
     */
    public function secondsUntilBelowThreshold(
        int $currentScore,
        int $lastUpdateTimestamp,
        int $currentBlockLevel,
        string $scope,
        int $threshold,
    ): int {
        if ($threshold <= 0) {
            throw new RateLimiterException('Decay threshold must be greater than zero.');
        }

        if ($currentScore < $threshold) {
            return 0;
        }

        $interval = $this->effectiveInterval($scope, $currentBlockLevel);
        $elapsed = max(0, $this->clock->now()->getTimestamp() - $lastUpdateTimestamp);
        $completedIntervals = intdiv($elapsed, $interval);
        $pointsToLose = $currentScore - $threshold + 1;
        $remainingPoints = $pointsToLose - $completedIntervals;

        if ($remainingPoints <= 0) {
            return 0;
        }

        return ($remainingPoints * $interval) - ($elapsed % $interval);
    }

    private function effectiveInterval(string $scope, int $currentBlockLevel): int
    {
        $baseInterval = match ($scope) {
            'account' => self::RATE_ACCOUNT,
            'device' => self::RATE_DEVICE,
            'ip' => self::RATE_IP,
            default => self::RATE_ACCOUNT,
        };

        return $currentBlockLevel >= 2 ? $baseInterval * 2 : $baseInterval;
    }
}
