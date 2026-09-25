<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Config;

/**
 * Declares the finite package capabilities a policy explicitly opts into.
 */
interface PolicyCapabilityProviderInterface
{
    /** @return list<PolicyCapability> */
    public function getCapabilities(): array;
}
