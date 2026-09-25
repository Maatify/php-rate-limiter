<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Config;

/**
 * Declares the finite package capabilities a policy explicitly opts into.
 */
interface PolicyCapabilityProviderInterface
{
    /**
     * @return list<PolicyCapability|string> Invalid string values are rejected by runtime validation.
     */
    public function getCapabilities(): array;
}
