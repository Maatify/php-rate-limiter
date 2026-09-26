<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Config;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Package-owned immutable convenience implementation of SimpleThrottlePolicyInterface.
 *
 * Name, limit, and interval remain explicit caller inputs; the package does not
 * supply default simple-throttle limits (DEC-010).
 */
final readonly class FixedWindowThrottlePolicy implements SimpleThrottlePolicyInterface
{
    /**
     * @throws RateLimiterException When the name is blank or limit/interval are not positive.
     */
    public function __construct(
        private string $name,
        private int $limit,
        private int $intervalSeconds,
    ) {
        if (trim($this->name) === '') {
            throw new RateLimiterException('Simple throttle policy name must not be empty or whitespace-only.');
        }

        if ($this->limit <= 0) {
            throw new RateLimiterException('Simple throttle policy limit must be a positive integer.');
        }

        if ($this->intervalSeconds <= 0) {
            throw new RateLimiterException('Simple throttle policy interval must be a positive integer of seconds.');
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }
}
