<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Config;

use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;

/**
 * Declares the finite package capabilities a policy explicitly opts into.
 */
interface PolicyCapabilityProviderInterface
{
    /** @return list<PolicyCapabilityEnum> */
    public function getCapabilities(): array;
}
