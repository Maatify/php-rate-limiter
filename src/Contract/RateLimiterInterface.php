<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Contract;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;

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
