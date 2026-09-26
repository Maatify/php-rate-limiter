<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;

/**
 * Public entry point for evaluating a request against a registered policy.
 */
interface RateLimiterInterface
{
    /**
     * Evaluate a request against the rate limiter policies.
     *
     * @param RateLimitContextDTO $context
     * @param RateLimitCommand $request
     * @return RateLimitResultDTO
     */
    public function limit(RateLimitContextDTO $context, RateLimitCommand $request): RateLimitResultDTO;
}
