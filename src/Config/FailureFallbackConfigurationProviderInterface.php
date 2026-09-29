<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Config;

use Maatify\RateLimiter\DTO\FailureFallbackConfigurationDTO;

/**
 * Declares the effective typed bounded backend-failure fallback configuration
 * for a reusable policy.
 *
 * Official presets and direct custom policies implement this same contract.
 * An official policy resolves its package-owned preset internally and
 * returns the generic configuration produced from it; a custom policy
 * composes its own bounded {@see FailureFallbackConfigurationDTO} directly.
 * The runtime never distinguishes the two by identity, name, or origin.
 */
interface FailureFallbackConfigurationProviderInterface
{
    /**
     * Return the policy's effective typed bounded backend-failure fallback
     * configuration. Runtime consumers use this typed contract uniformly for
     * official and custom policies without relying on policy identity or
     * origin.
     */
    public function getFailureFallbackConfiguration(): FailureFallbackConfigurationDTO;
}
