<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Command;

use Maatify\RateLimiter\Exception\RateLimiterException;

final readonly class RateLimitCommand
{
    public function __construct(
        public string $policyName,
        public int $cost = 1,
        public bool $isPreCheck = false,
        public bool $isFailure = false,
        public bool $isSuccess = false
    ) {
        $trueCount = ($this->isPreCheck ? 1 : 0) + ($this->isFailure ? 1 : 0) + ($this->isSuccess ? 1 : 0);
        if ($trueCount > 1) {
            throw new RateLimiterException('Invalid command state: contradictory execution intent modes.');
        }
    }

    public static function checkOnly(string $policyName, int $cost = 1): self
    {
        return new self($policyName, $cost, true, false, false);
    }

    public static function recordFailure(string $policyName, int $cost = 1): self
    {
        return new self($policyName, $cost, false, true, false);
    }

    public static function recordSuccess(string $policyName, int $cost = 1): self
    {
        return new self($policyName, $cost, false, false, true);
    }
}
