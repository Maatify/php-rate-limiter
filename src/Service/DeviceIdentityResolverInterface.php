<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;

/**
 * Resolves request identity inputs into the device signals used by policies.
 */
interface DeviceIdentityResolverInterface
{
    /**
     * Resolve device identity from context.
     *
     * @param RateLimitContextDTO $context
     * @return DeviceIdentityDTO
     */
    public function resolve(RateLimitContextDTO $context): DeviceIdentityDTO;
}
