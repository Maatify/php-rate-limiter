<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Command;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Immutable command representing one rate-limiter operation for a named policy.
 *
 * Explicit execution-intent modes are mutually exclusive. When none of the
 * intent flags is enabled, the command represents the normal evaluation and
 * access-update path.
 */
final readonly class RateLimitCommand
{
    /**
     * Create a rate-limit command and validate its execution intent.
     *
     * @throws RateLimiterException When more than one intent flag is enabled.
     */
    public function __construct(
        public string $policyName,
        public int $cost = 1,
        public bool $isPreCheck = false,
        public bool $isFailure = false,
        public bool $isSuccess = false,
    ) {
        $trueCount = ($this->isPreCheck ? 1 : 0) + ($this->isFailure ? 1 : 0) + ($this->isSuccess ? 1 : 0);
        if ($trueCount > 1) {
            throw new RateLimiterException('Invalid command state: contradictory execution intent modes.');
        }
    }

    /**
     * Create a pre-check command that does not record scoring success/failure.
     *
     * Bounded correlation state may be observed or updated during this pre-check.
     */
    public static function checkOnly(string $policyName, int $cost = 1): self
    {
        return new self($policyName, $cost, true, false, false);
    }

    /**
     * Create a command that records a failed operation.
     */
    public static function recordFailure(string $policyName, int $cost = 1): self
    {
        return new self($policyName, $cost, false, true, false);
    }

    /**
     * Create a command that records a successful operation.
     */
    public static function recordSuccess(string $policyName, int $cost = 1): self
    {
        return new self($policyName, $cost, false, false, true);
    }
}
